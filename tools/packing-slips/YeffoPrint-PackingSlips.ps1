#Requires -Version 5.1
<#
.SYNOPSIS
    Polls the YeffoDesign WooCommerce store for new orders and prints a
    4x6 packing slip for each one automatically.

.DESCRIPTION
    Runs forever (until you close the window or Ctrl+C). On each poll:
      1. Checks git for a newer version of this script (see "Auto-update
         from git" in .NOTES below) and, if one exists, downloads it,
         overwrites itself, and restarts running the new copy - no
         manual re-upload/download round trip per print station.
      2. Fetches orders in the watched status(es) from the WooCommerce
         REST API (wc/v3 - WordPress's own core WooCommerce API, not
         the site's custom yeffoprint-core endpoints, which aren't
         meant for this kind of external order-management use).
      3. Skips any order already printed (tracked in a local JSON
         state file next to this script, not on the WordPress side -
         this only ever needs read access to your store).
      4. For each new order, renders a packing slip and sends it
         straight to the configured printer at 4x6 inches, no dialog,
         no PDF step. Content includes every human-readable
         customization row the site attaches to each order line item
         for exactly this purpose (Size, Material, and every
         per-label field a Template's own customization form defines,
         e.g. Compound/Strength/Brand Name) - nothing guessed or
         reconstructed, just displayed.

    Safe to stop and restart: the state file remembers what's already
    printed, so restarting never reprints old orders. Delete a line
    from OrderState.json (or the whole file) if you ever need to force
    a reprint.

.NOTES
    -- One-time setup ----------------------------------------------
    1. Copy PackingSlipConfig.example.ps1 (next to this script) to
       PackingSlipConfig.ps1 in the same folder, then fill in your
       real values there - this script's own logic is tracked in git
       and safe to auto-update; PackingSlipConfig.ps1 holds your
       secrets, stays local-only, and is never touched by the
       auto-update or committed to git (see the .gitignore next to
       these files).
    2. In WordPress: WooCommerce -> Settings -> Advanced -> REST API ->
       Add key. Description "Packing slip printer" is fine.
       Permissions: Read/Write is WooCommerce's minimum selectable
       level, but this script only ever calls GET - it never uses the
       write access WooCommerce grants alongside it. Copy the
       Consumer Key and Consumer Secret WooCommerce shows you (only
       shown once) into PackingSlipConfig.ps1.
    3. Confirm your site is served over HTTPS. Basic Auth credentials
       travel in a request header either way, but only TLS keeps that
       header private in transit - don't point this at a plain http://
       URL.
    4. Find your printer's exact name: run `Get-Printer | Select Name`
       in PowerShell (or Settings -> Printers & Scanners). Paste the
       exact string into PrinterName in your config, or leave it blank
       to use whatever printer is currently set as the Windows default.
    5. First run: use -DryRun (see below) a few times against a couple
       of real orders to check the layout before printing anything for
       real - thermal labels/4x6 stock aren't free to waste on a typo
       in a config value.

    -- Auto-update from git -----------------------------------------
    Every run (and, in the long-running loop, every $UpdateCheckIntervalSeconds)
    this script fetches the current tracked copy of itself from GitHub
    and compares it byte-for-byte against the file on disk. A push to
    the Yeffoprint repo's tools/packing-slips/YeffoPrint-PackingSlips.ps1
    is all it takes to update every print station running this - no
    manual download/copy per machine. Since this repo is private, the
    fetch needs a read-only GitHub token:
      GitHub -> your avatar -> Settings -> Developer settings ->
      Personal access tokens -> Fine-grained tokens -> Generate new
      token -> Repository access: "Only select repositories" ->
      yeffo-boop/Yeffoprint -> Permissions -> Repository permissions
      -> Contents: Read-only. Paste the token into PackingSlipConfig.ps1
      as $GitHubToken.
    Set $AutoUpdate = $false in your config to turn this off entirely
    and always run whatever copy is currently on disk. -SkipUpdate (a
    command-line switch, below) skips just the check for one run,
    without changing your saved config.

    -- Running it --------------------------------------------------
        .\YeffoPrint-PackingSlips.ps1                  # normal use
        .\YeffoPrint-PackingSlips.ps1 -DryRun           # log what
                                                          # would print,
                                                          # print nothing
        .\YeffoPrint-PackingSlips.ps1 -Once             # single poll,
                                                          # then exit
                                                          # (good for
                                                          # Task
                                                          # Scheduler
                                                          # instead of
                                                          # this
                                                          # script's own
                                                          # loop)
        .\YeffoPrint-PackingSlips.ps1 -SkipUpdate       # this run only,
                                                          # don't check
                                                          # git for an
                                                          # update first

    To run this unattended long-term, either leave this script's own
    loop running in a scheduled PowerShell window, or use -Once on a
    Windows Task Scheduler trigger every N minutes instead - either
    works with the same state file, since "already printed" is tracked
    on disk, not in memory.
#>

[CmdletBinding()]
param(
    # Log what would be printed instead of actually printing - use this
    # to check formatting/config before committing real label stock.
    [switch]$DryRun,

    # Poll once and exit, instead of looping forever. Pairs with an
    # external scheduler (Task Scheduler, cron under WSL, etc.) if you'd
    # rather it manage the interval than this script's own loop.
    [switch]$Once,

    # Skip the git update check for this run only - your saved
    # $AutoUpdate config is untouched, this is just a one-off override
    # (e.g. while you're mid-edit testing a local change to this file).
    [switch]$SkipUpdate
)

# ---------------------------------------------------------------------
# Paths + config - the script itself carries no secrets and is safe to
# auto-update from git; PackingSlipConfig.ps1 (dot-sourced below) holds
# every real value (site URL, API keys, printer name, GitHub token) and
# stays local-only. See PackingSlipConfig.example.ps1 for the full list
# of settings this expects to find.
# ---------------------------------------------------------------------

$ScriptPath     = $MyInvocation.MyCommand.Path
$ScriptDir      = Split-Path -Parent $ScriptPath
$ConfigPath     = Join-Path $ScriptDir 'PackingSlipConfig.ps1'
$StateFilePath  = Join-Path $ScriptDir 'OrderState.json'
$LogFilePath    = Join-Path $ScriptDir 'PackingSlips.log'

if (-not (Test-Path $ConfigPath)) {
    Write-Host "Missing PackingSlipConfig.ps1 in $ScriptDir." -ForegroundColor Red
    Write-Host "Copy PackingSlipConfig.example.ps1 to PackingSlipConfig.ps1 in the same folder and fill in your real values first." -ForegroundColor Red
    exit 1
}

# Defines $SiteUrl, $ConsumerKey, $ConsumerSecret, $WatchedStatuses,
# $PollIntervalSeconds, $PrinterName, $AutoUpdate, $UpdateSourceUrl,
# $GitHubToken, $UpdateCheckIntervalSeconds at script scope - every
# function below reads these directly, exactly as if they were still
# declared inline here.
. $ConfigPath

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

# ---------------------------------------------------------------------
# Logging - every unattended script needs a trail, since nobody's
# watching the console most of the time this runs.
# ---------------------------------------------------------------------

function Write-Log {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR')][string]$Level = 'INFO'
    )

    $line = '[{0}] [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message

    $color = switch ($Level) {
        'WARN'  { 'Yellow' }
        'ERROR' { 'Red' }
        default { 'Gray' }
    }
    Write-Host $line -ForegroundColor $color

    # A logging failure (disk full, permissions) shouldn't take down
    # order polling/printing - that's the more important half of this
    # script by far.
    try {
        Add-Content -Path $LogFilePath -Value $line -Encoding UTF8
    } catch {
        Write-Host "Couldn't write to log file: $_" -ForegroundColor Red
    }
}

# ---------------------------------------------------------------------
# Auto-update from git - see .NOTES above. Keeps every print station on
# the same, current version of this script without a manual per-machine
# download each time a fix ships.
# ---------------------------------------------------------------------

function Test-ForScriptUpdate {
    <#
        Downloads the current tracked copy of this exact script from
        GitHub and overwrites the local file if it differs byte for
        byte. Wrapped the same way every other network call in this
        script is: a failure here (offline, bad/expired token, GitHub
        down) is logged and skipped, never a reason to stop printing
        labels - the whole reason this runs at the very top of every
        cycle is so it's the first thing tried, not the thing standing
        between staff and their labels if it breaks.
    #>
    if ($SkipUpdate) { return $false }
    if (-not $AutoUpdate) { return $false }
    if ([string]::IsNullOrWhiteSpace($UpdateSourceUrl)) { return $false }

    try {
        $headers = @{}
        if (-not [string]::IsNullOrWhiteSpace($GitHubToken)) {
            $headers['Authorization'] = "token $GitHubToken"
        }

        # Cache-busting query param - raw.githubusercontent.com sits
        # behind a CDN that can otherwise keep serving a stale copy for
        # a few minutes right after a push.
        $uri = '{0}?_={1}' -f $UpdateSourceUrl, [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        $latest  = Invoke-RestMethod -Uri $uri -Headers $headers -Method Get -TimeoutSec 30 -ErrorAction Stop
        $current = Get-Content -Path $ScriptPath -Raw

        if ($latest -eq $current) {
            return $false
        }

        Set-Content -Path $ScriptPath -Value $latest -Encoding UTF8 -NoNewline
        Write-Log 'Downloaded a newer version of this script from git.'
        return $true
    } catch {
        $status = $null
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }

        switch ($status) {
            401 { Write-Log 'Script update check: GitHub rejected the token (401) - check $GitHubToken in PackingSlipConfig.ps1.' -Level 'WARN' }
            404 { Write-Log 'Script update check: $UpdateSourceUrl (404) - check the branch/path is correct.' -Level 'WARN' }
            default { Write-Log "Script update check failed, continuing with the current version: $_" -Level 'WARN' }
        }
        return $false
    }
}

function Restart-WithUpdatedScript {
    <#
        Re-invokes this exact script file via the call operator, which
        re-reads and re-parses it from disk - so this picks up the copy
        Test-ForScriptUpdate just wrote, in the same console window,
        with the same -DryRun/-Once flags this run was already using.
    #>
    Write-Log 'Restarting with the updated script...'
    $restartArgs = @{}
    if ($DryRun) { $restartArgs['DryRun'] = $true }
    if ($Once)   { $restartArgs['Once']   = $true }
    & $ScriptPath @restartArgs
    exit $LASTEXITCODE
}

# ---------------------------------------------------------------------
# Config validation - fail loudly and immediately on an obvious setup
# mistake, rather than looping forever hitting the same auth error.
# ---------------------------------------------------------------------

function Test-Configuration {
    $problems = @()

    if ([string]::IsNullOrWhiteSpace($SiteUrl) -or $SiteUrl -notmatch '^https?://') {
        $problems += 'SiteUrl is missing or not a valid http(s) URL.'
    }
    if ($SiteUrl -like 'http://*') {
        Write-Log 'SiteUrl is plain http:// - your API credentials will travel unencrypted. Strongly recommend switching to https:// before running this for real.' -Level 'WARN'
    }
    if ([string]::IsNullOrWhiteSpace($ConsumerKey) -or $ConsumerKey -eq 'ck_REPLACE_ME') {
        $problems += 'ConsumerKey is still the placeholder value - set it in PackingSlipConfig.ps1 from WooCommerce -> Settings -> Advanced -> REST API.'
    }
    if ([string]::IsNullOrWhiteSpace($ConsumerSecret) -or $ConsumerSecret -eq 'cs_REPLACE_ME') {
        $problems += 'ConsumerSecret is still the placeholder value - set it in PackingSlipConfig.ps1 from WooCommerce -> Settings -> Advanced -> REST API.'
    }
    if (-not $WatchedStatuses -or $WatchedStatuses.Count -eq 0) {
        $problems += 'WatchedStatuses is empty - nothing will ever match.'
    }

    if ($problems.Count -gt 0) {
        Write-Log 'Configuration problem(s) found - fix these in PackingSlipConfig.ps1 before running again:' -Level 'ERROR'
        foreach ($p in $problems) { Write-Log "  - $p" -Level 'ERROR' }
        throw 'Invalid configuration.'
    }
}

# ---------------------------------------------------------------------
# WooCommerce REST API
# ---------------------------------------------------------------------

function Get-AuthHeader {
    $pair = '{0}:{1}' -f $ConsumerKey, $ConsumerSecret
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($pair)
    return @{ Authorization = 'Basic ' + [Convert]::ToBase64String($bytes) }
}

function Get-NewOrders {
    <#
        Fetches every order in a watched status, newest first. Actual
        "is this new" filtering happens against the local state file
        afterward (Test-OrderPrinted) - asking the API is simpler and
        more robust than trying to track "orders since date X" across
        restarts, clock drift, or a missed poll.
    #>
    $statusParam = ($WatchedStatuses -join ',')
    $uri = '{0}/wp-json/wc/v3/orders?status={1}&per_page=50&orderby=date&order=desc' -f $SiteUrl, $statusParam

    try {
        $response = Invoke-RestMethod -Uri $uri -Headers (Get-AuthHeader) -Method Get -TimeoutSec 30
        return @($response)
    } catch {
        $status = $null
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }

        switch ($status) {
            401 { Write-Log 'WooCommerce rejected the API credentials (401). Double-check ConsumerKey/ConsumerSecret.' -Level 'ERROR' }
            403 { Write-Log 'WooCommerce API key does not have permission to read orders (403).' -Level 'ERROR' }
            default { Write-Log "Couldn't reach the WooCommerce API: $_" -Level 'ERROR' }
        }
        return @()
    }
}

# ---------------------------------------------------------------------
# Local "already printed" state - a flat file, not a database; this is
# a low-volume, single-machine tool and a file is plenty durable and
# trivially inspectable/editable by hand if something ever needs a
# manual fix.
# ---------------------------------------------------------------------

function Get-PrintedOrderIds {
    if (-not (Test-Path $StateFilePath)) {
        return @{}
    }

    try {
        $raw = Get-Content -Path $StateFilePath -Raw -ErrorAction Stop
        if ([string]::IsNullOrWhiteSpace($raw)) { return @{} }

        $parsed = $raw | ConvertFrom-Json
        $map = @{}
        foreach ($prop in $parsed.PSObject.Properties) {
            $map[$prop.Name] = $prop.Value
        }
        return $map
    } catch {
        # A corrupted state file is recoverable (worst case: an order
        # reprints once) - it is not a reason to stop printing new
        # orders, which is the one thing this script actually exists
        # to do.
        Write-Log "State file couldn't be read, starting fresh (all orders will be treated as new): $_" -Level 'WARN'
        return @{}
    }
}

function Save-PrintedOrderIds {
    param([Parameter(Mandatory)][hashtable]$Map)

    try {
        $Map | ConvertTo-Json | Set-Content -Path $StateFilePath -Encoding UTF8
    } catch {
        Write-Log "Couldn't save state file - already-printed orders may reprint on next run: $_" -Level 'ERROR'
    }
}

function Test-OrderPrinted {
    param([hashtable]$Map, [Parameter(Mandatory)]$OrderId)
    return $Map.ContainsKey([string]$OrderId)
}

function Add-PrintedOrder {
    param([hashtable]$Map, [Parameter(Mandatory)]$OrderId)
    $Map[[string]$OrderId] = (Get-Date -Format 'o')
}

# ---------------------------------------------------------------------
# Packing slip content - turns one WooCommerce order object into a
# flat list of lines to print, each tagged with how it should look.
# Kept separate from the actual printer drawing code below so the
# layout logic can be tested/read independently of GDI+ specifics.
# ---------------------------------------------------------------------

function Get-OrderItemMetaValue {
    param($Item, [Parameter(Mandatory)][string]$Key)
    $match = $Item.meta_data | Where-Object { $_.key -eq $Key } | Select-Object -First 1
    if ($match) { return [string]$match.value }
    return $null
}

function Get-PackingSlipLines {
    <#
        Layout, top to bottom, sized for a 4x6 thermal slip (no color -
        hierarchy comes from weight, size, indent, and rules only):

          YEFFODESIGN                                         #1047
          PACKING SLIP                                   Aug 25, 2026
          ------------------------------------------------------------
          SHIP TO
          Jordan Alvarez
          482 Birchwood Lane
          Austin, TX 78701
          ------------------------------------------------------------
          ITEMS (7 total)
          ------------------------------------------------------------
          1. Custom Labels -- Modern Minimal                     x5
              Size:            2in x 3in
              Material:        Glossy Vinyl
              Label 1 (qty 3)
                Compound:      NAD+
                Strength:      500mg
              Label 2 (qty 2)
                Compound:      BPC-157
                Strength:      250mg

          2. Custom Sticker -- Die Cut Circle                    x2
              Size:            3in Circle
              Material:        Glossy Vinyl

          ------------------------------------------------------------
          NOTE
              Rush please -- need by Friday for a pop-up event!
          ------------------------------------------------------------
          7 item(s), 7 qty total                    Printed 9:14 AM
    #>
    param([Parameter(Mandatory)]$Order)

    $lines = New-Object System.Collections.Generic.List[object]

    function Add-Line {
        param(
            [string]$Text = '',
            [string]$Style = 'body',
            [string]$Right = $null,
            [int]$Indent = 0,
            [string]$Value = $null
        )
        $lines.Add([pscustomobject]@{
            Text   = $Text
            Style  = $Style
            Right  = $Right
            Indent = $Indent
            Value  = $Value
        })
    }

    $orderDate = $null
    if ($Order.date_created) {
        try { $orderDate = [datetime]::Parse($Order.date_created).ToString('MMM d, yyyy') } catch { $orderDate = $Order.date_created }
    }

    # Header: brand + order number share one line, "PACKING SLIP" + date
    # share the next - a stack of centered lines burned through height a
    # 4x6 slip can't spare, and the order number is exactly what you want
    # visible first when flipping through a stack of printed slips.
    Add-Line -Text 'YEFFODESIGN' -Style 'brand' -Right ("#{0}" -f $Order.number)
    Add-Line -Text 'PACKING SLIP' -Style 'kicker' -Right $orderDate
    Add-Line -Style 'rule'

    Add-Line -Text 'SHIP TO' -Style 'label'
    $ship = $Order.shipping
    $shipName = "$($ship.first_name) $($ship.last_name)".Trim()
    if ([string]::IsNullOrWhiteSpace($shipName)) {
        # Some stores don't collect a separate shipping name/address
        # when it's identical to billing - WooCommerce leaves `shipping`
        # blank in that case rather than duplicating `billing` into it.
        $ship = $Order.billing
        $shipName = "$($ship.first_name) $($ship.last_name)".Trim()
    }
    if ($shipName) { Add-Line -Text $shipName -Style 'bold' }
    if ($ship.company) { Add-Line -Text $ship.company }
    if ($ship.address_1) { Add-Line -Text $ship.address_1 }
    if ($ship.address_2) { Add-Line -Text $ship.address_2 }
    $cityLine = (@($ship.city, $ship.state, $ship.postcode) | Where-Object { $_ }) -join ', '
    if ($cityLine) { Add-Line -Text $cityLine }
    if ($ship.country -and $ship.country -ne 'US') { Add-Line -Text $ship.country }
    Add-Line -Style 'rule'

    $totalQty = ($Order.line_items | Measure-Object -Property quantity -Sum).Sum
    Add-Line -Text ("ITEMS ({0} total)" -f $totalQty) -Style 'label'
    Add-Line -Style 'rule'

    $itemNumber = 0
    foreach ($item in $Order.line_items) {
        $itemNumber++
        Add-Line -Text ("{0}. {1}" -f $itemNumber, $item.name) -Style 'item' -Right ("x{0}" -f $item.quantity)

        # A Template-flow label batch stores its personalization as JSON
        # (_yp_variants/_yp_template_snapshot), the same source of truth
        # the site's own admin order screen and order emails read from -
        # decoded independently here rather than reparsing the raw
        # "Field: value -- Field: value" joined-string meta row the REST
        # API otherwise returns for it (that row's own site-side pretty
        # reformatting only applies in the admin-screen/email context,
        # never to a plain REST fetch like this script's), since nothing
        # stops a field's own value from containing " -- " or ": "
        # itself and this avoids guessing at that split entirely.
        $variantsJson = Get-OrderItemMetaValue -Item $item -Key '_yp_variants'
        $snapshotJson = Get-OrderItemMetaValue -Item $item -Key '_yp_template_snapshot'
        $variants = $null

        if ($variantsJson -and $snapshotJson) {
            try {
                $variants    = @(($variantsJson | ConvertFrom-Json))
                $fieldSchema = @((($snapshotJson | ConvertFrom-Json)).field_schema)
            } catch {
                Write-Log ("Order #{0}: couldn't parse batch variant data, falling back to raw meta rows: {1}" -f $Order.number, $_) -Level 'WARN'
                $variants = $null
            }
        }

        if ($variants) {
            # Bug fix: this branch used to jump straight into the
            # per-label variant loop below and never looked at the
            # item's own top-level meta rows at all - so "Size" and
            # "Material" (added once per item, not per label - see
            # class-order-item-meta.php's own snapshot() /
            # add_variant_rows()) silently never made it onto a
            # Template Label order's slip, even though they print fine
            # for a Custom Design/Custom Sticker order's own item below
            # (that one goes through this same raw-meta loop, just
            # without a $variants array to split off first). Prints
            # every such row here now - explicitly skipping only the
            # "Labels in this batch" count and the per-label
            # "Label N (qty M)"/"Customization" rows, both of which are
            # already represented below (the count via this batch's own
            # line count/header, the per-label detail via $variants
            # parsed from JSON, same "don't guess-parse a joined
            # string" reasoning as that loop already documents) - so
            # this never prints the same information twice, and any
            # other current or future item-level customization row
            # (not just Size/Material specifically) shows up here too,
            # automatically.
            foreach ($meta in $item.meta_data) {
                if ($meta.key -like '_*') { continue }
                if ($meta.key -like 'Label * (qty *)') { continue }
                if ($meta.key -eq 'Customization') { continue }
                if ($meta.key -eq 'Labels in this batch') { continue }
                $value = [string]$meta.value
                if ([string]::IsNullOrWhiteSpace($value)) { continue }
                Add-Line -Text $meta.key -Style 'field' -Indent 1 -Value $value
            }

            $multiple = $variants.Count -gt 1
            $variantIndex = 0
            foreach ($variant in $variants) {
                $variantIndex++
                $heading = if ($multiple) {
                    "Label {0} (qty {1})" -f $variantIndex, [int]$variant.quantity
                } else {
                    'Customization'
                }
                Add-Line -Text $heading -Style 'variant' -Indent 1

                foreach ($field in $fieldSchema) {
                    $val = $null
                    if ($variant.values -and $variant.values.PSObject.Properties[$field.id]) {
                        $val = [string]$variant.values.($field.id)
                    }
                    if ([string]::IsNullOrWhiteSpace($val)) { continue }
                    Add-Line -Text $field.label -Style 'field' -Indent 2 -Value $val
                }
            }
        } else {
            # Every other human-readable row the site itself already
            # attaches to this line item (Size, Material, Compound, etc.
            # for a Custom Design/Custom Stickers order) - printed as-is.
            # Internal snapshot/JSON meta (keys starting with "_") is
            # always skipped: it's machine data, not for a packing slip.
            foreach ($meta in $item.meta_data) {
                if ($meta.key -like '_*') { continue }
                $value = [string]$meta.value
                if ([string]::IsNullOrWhiteSpace($value)) { continue }
                Add-Line -Text $meta.key -Style 'field' -Indent 1 -Value $value
            }
        }

        Add-Line -Style 'space'
    }

    if ($Order.customer_note) {
        Add-Line -Style 'rule'
        Add-Line -Text 'NOTE' -Style 'label'
        Add-Line -Text $Order.customer_note -Indent 1
    }

    Add-Line -Style 'rule'
    Add-Line -Text ("{0} item(s), {1} qty total" -f $Order.line_items.Count, $totalQty) -Style 'footer' -Right (Get-Date -Format 'MMM d, h:mm tt')

    return $lines
}

# ---------------------------------------------------------------------
# Printing - draws the slip directly with GDI+ and sends it straight
# to the printer at 4x6 inches. No PDF/HTML/browser step: this is a
# fully unattended script, and a browser print dialog would block it
# waiting for a click that's never coming.
# ---------------------------------------------------------------------

function Get-StyleFont {
    param([string]$Style)
    switch ($Style) {
        'brand'       { return New-Object System.Drawing.Font('Segoe UI', 13,   [System.Drawing.FontStyle]::Bold) }
        'kicker'      { return New-Object System.Drawing.Font('Segoe UI', 8,    [System.Drawing.FontStyle]::Regular) }
        'label'       { return New-Object System.Drawing.Font('Segoe UI', 8.5,  [System.Drawing.FontStyle]::Bold) }
        'item'        { return New-Object System.Drawing.Font('Segoe UI', 10.5, [System.Drawing.FontStyle]::Bold) }
        'variant'     { return New-Object System.Drawing.Font('Segoe UI', 9,    [System.Drawing.FontStyle]::Bold) }
        'field-label' { return New-Object System.Drawing.Font('Segoe UI', 9,    [System.Drawing.FontStyle]::Bold) }
        'field-value' { return New-Object System.Drawing.Font('Segoe UI', 9,    [System.Drawing.FontStyle]::Regular) }
        'bold'         { return New-Object System.Drawing.Font('Segoe UI', 9.5, [System.Drawing.FontStyle]::Bold) }
        'footer'      { return New-Object System.Drawing.Font('Segoe UI', 7.5,  [System.Drawing.FontStyle]::Italic) }
        default       { return New-Object System.Drawing.Font('Segoe UI', 9.5,  [System.Drawing.FontStyle]::Regular) } # 'body'
    }
}

function Print-PackingSlip {
    param(
        [Parameter(Mandatory)]$Order,
        [Parameter(Mandatory)][System.Collections.Generic.List[object]]$Lines
    )

    $doc = New-Object System.Drawing.Printing.PrintDocument
    if ($PrinterName) {
        $doc.PrinterSettings.PrinterName = $PrinterName
        if (-not $doc.PrinterSettings.IsValid) {
            throw "Printer '$PrinterName' was not found or is not valid. Run Get-Printer to see exact names."
        }
    }
    if (-not $doc.PrinterSettings.IsValid) {
        throw 'No valid printer available (no default printer set, and PrinterName is blank). Set PrinterName in PackingSlipConfig.ps1, or set a Windows default printer.'
    }

    # 4x6 inches, expressed in hundredths of an inch (.NET's PaperSize
    # unit) - 400 x 600. Margins small on purpose: a 4x6 slip has very
    # little room to spare compared to a full sheet.
    $paperSize = New-Object System.Drawing.Printing.PaperSize('4x6 Packing Slip', 400, 600)
    $doc.DefaultPageSettings.PaperSize = $paperSize
    $doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(18, 18, 18, 18)
    $doc.DefaultPageSettings.Landscape = $false

    # PrintPage can fire more than once if content overflows one page
    # (HasMorePages below) - this index tracks where the *next* page
    # should resume, since $Lines is shared across all pages of one
    # order rather than re-passed per page.
    $script:_nextLineIndex = 0

    $doc.add_PrintPage({
        param($sender, $e)

        $bounds = $e.MarginBounds
        $y = [float]$bounds.Top
        $lineSpacing = 3.0
        $indentUnit = 14.0          # hundredths of an inch per Indent level
        $fieldLabelColWidth = 78.0  # hundredths of an inch reserved for a 'field' row's label column

        while ($script:_nextLineIndex -lt $Lines.Count) {
            $line = $Lines[$script:_nextLineIndex]

            if ($line.Style -eq 'rule') {
                $y += 5
                $pen = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 1)
                $e.Graphics.DrawLine($pen, $bounds.Left, $y, $bounds.Right, $y)
                $pen.Dispose()
                $y += 7
                $script:_nextLineIndex++
                continue
            }

            if ($line.Style -eq 'space') {
                $y += 6
                $script:_nextLineIndex++
                continue
            }

            $indentPx = $line.Indent * $indentUnit
            $x = $bounds.Left + $indentPx
            $availWidth = $bounds.Width - $indentPx

            if ($line.Style -eq 'field') {
                # Two-column "Label:  Value" row, aligned via a fixed
                # label-column width rather than space-padding - with a
                # proportional font, padded spaces don't line up the way
                # they would in a monospace one.
                $labelFont = Get-StyleFont -Style 'field-label'
                $valueFont = Get-StyleFont -Style 'field-value'
                $valueX    = $x + $fieldLabelColWidth

                $labelRect = New-Object System.Drawing.RectangleF($x, $y, $fieldLabelColWidth, 1000)
                $valueRect = New-Object System.Drawing.RectangleF($valueX, $y, ($bounds.Right - $valueX), 1000)

                $labelText = "{0}:" -f $line.Text
                $labelMeasured = $e.Graphics.MeasureString($labelText, $labelFont, [int]$fieldLabelColWidth)
                $valueMeasured = $e.Graphics.MeasureString($line.Value, $valueFont, [int]$valueRect.Width)
                $neededHeight = [Math]::Max($labelMeasured.Height, $valueMeasured.Height) + $lineSpacing

                if (($y + $neededHeight) -gt $bounds.Bottom) {
                    $labelFont.Dispose(); $valueFont.Dispose()
                    break
                }

                $e.Graphics.DrawString($labelText, $labelFont, [System.Drawing.Brushes]::Black, $labelRect)
                $e.Graphics.DrawString($line.Value, $valueFont, [System.Drawing.Brushes]::Black, $valueRect)

                $y += $neededHeight
                $labelFont.Dispose(); $valueFont.Dispose()
                $script:_nextLineIndex++
                continue
            }

            $font = Get-StyleFont -Style $line.Style

            if ($line.Right) {
                # Two ends of one line - primary text left-anchored, a
                # second value (order number, a date, "x2") right-
                # anchored at the page's own right margin - one shared
                # baseline instead of two separate lines competing for a
                # 4x6 slip's limited height.
                $rightFormat = New-Object System.Drawing.StringFormat
                $rightFormat.Alignment = [System.Drawing.StringAlignment]::Far

                $leftRect  = New-Object System.Drawing.RectangleF($x, $y, ($availWidth * 0.68), 1000)
                $rightRect = New-Object System.Drawing.RectangleF($x, $y, $availWidth, 1000)

                $leftMeasured  = $e.Graphics.MeasureString($line.Text, $font, [int]$leftRect.Width)
                $rightMeasured = $e.Graphics.MeasureString($line.Right, $font, [int]$availWidth, $rightFormat)
                $neededHeight = [Math]::Max($leftMeasured.Height, $rightMeasured.Height) + $lineSpacing

                if (($y + $neededHeight) -gt $bounds.Bottom) {
                    $font.Dispose()
                    break
                }

                $e.Graphics.DrawString($line.Text, $font, [System.Drawing.Brushes]::Black, $leftRect)
                $e.Graphics.DrawString($line.Right, $font, [System.Drawing.Brushes]::Black, $rightRect, $rightFormat)

                $y += $neededHeight
                $font.Dispose()
                $script:_nextLineIndex++
                continue
            }

            $layoutRect = New-Object System.Drawing.RectangleF($x, $y, $availWidth, 1000)
            $measured = $e.Graphics.MeasureString($line.Text, $font, [int]$availWidth)
            $neededHeight = $measured.Height + $lineSpacing

            # Out of room on this page - stop here, print what's drawn,
            # and let the next PrintPage call (triggered by
            # HasMorePages below) resume from this exact line. Only
            # matters for an unusually large order; most packing slips
            # fit on one 4x6 page.
            if (($y + $neededHeight) -gt $bounds.Bottom) {
                $font.Dispose()
                break
            }

            $e.Graphics.DrawString($line.Text, $font, [System.Drawing.Brushes]::Black, $layoutRect)
            $y += $neededHeight
            $font.Dispose()
            $script:_nextLineIndex++
        }

        $e.HasMorePages = $script:_nextLineIndex -lt $Lines.Count
    })

    $doc.Print()
}

# ---------------------------------------------------------------------
# One poll cycle
# ---------------------------------------------------------------------

function Invoke-PollCycle {
    param([hashtable]$PrintedMap)

    $orders = Get-NewOrders
    if ($orders.Count -eq 0) {
        Write-Log 'No orders in watched status right now.'
        return
    }

    $newOrders = $orders | Where-Object { -not (Test-OrderPrinted -Map $PrintedMap -OrderId $_.id) }

    if ($newOrders.Count -eq 0) {
        Write-Log ("Checked {0} order(s), nothing new to print." -f $orders.Count)
        return
    }

    Write-Log ("{0} new order(s) to print: {1}" -f $newOrders.Count, (($newOrders | ForEach-Object { "#$($_.number)" }) -join ', '))

    foreach ($order in $newOrders) {
        try {
            $lines = Get-PackingSlipLines -Order $order

            if ($DryRun) {
                Write-Log ("[DRY RUN] Would print order #{0} - {1} line(s):" -f $order.number, $lines.Count)
                foreach ($l in $lines) {
                    $indentStr = '  ' * $l.Indent
                    $suffix    = if ($l.Right) { "  ->  $($l.Right)" } elseif ($l.Value) { ": $($l.Value)" } else { '' }
                    Write-Log ("    [{0,-12}] {1}{2}{3}" -f $l.Style, $indentStr, $l.Text, $suffix)
                }
            } else {
                Print-PackingSlip -Order $order -Lines $lines
                Write-Log ("Printed packing slip for order #{0}." -f $order.number)
            }

            # Marked as handled either way - a dry run is for checking
            # layout/config, not for leaving orders to reprint for real
            # the moment -DryRun is removed.
            Add-PrintedOrder -Map $PrintedMap -OrderId $order.id
            Save-PrintedOrderIds -Map $PrintedMap
        } catch {
            # One bad order (a missing printer, a malformed address)
            # must not stop the rest of this batch - every other new
            # order still needs its slip.
            Write-Log ("Failed to print order #{0}: {1}" -f $order.number, $_) -Level 'ERROR'
        }
    }
}

# ---------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------

if (Test-ForScriptUpdate) {
    Restart-WithUpdatedScript
}

Test-Configuration

Write-Log ("Starting. Site: {0} | Watched statuses: {1} | Printer: {2} | Dry run: {3}" -f `
    $SiteUrl, ($WatchedStatuses -join ','), $(if ($PrinterName) { $PrinterName } else { '(Windows default)' }), $DryRun.IsPresent)

$printedMap = Get-PrintedOrderIds
Write-Log ("Loaded state file - {0} order(s) already marked printed." -f $printedMap.Count)

if ($Once) {
    Invoke-PollCycle -PrintedMap $printedMap
    Write-Log 'Done (-Once was specified).'
    exit 0
}

Write-Log ("Polling every {0} second(s). Press Ctrl+C to stop." -f $PollIntervalSeconds)

$lastUpdateCheck = Get-Date

while ($true) {
    try {
        if ($AutoUpdate -and ((Get-Date) - $lastUpdateCheck).TotalSeconds -ge $UpdateCheckIntervalSeconds) {
            $lastUpdateCheck = Get-Date
            if (Test-ForScriptUpdate) {
                Restart-WithUpdatedScript
            }
        }
        Invoke-PollCycle -PrintedMap $printedMap
    } catch {
        # A single cycle failing outright (e.g. the site is briefly
        # unreachable) should not end the script - just log it and try
        # again next interval.
        Write-Log "Poll cycle failed: $_" -Level 'ERROR'
    }
    Start-Sleep -Seconds $PollIntervalSeconds
}
