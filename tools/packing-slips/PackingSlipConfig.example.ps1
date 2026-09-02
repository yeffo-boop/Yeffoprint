# Local machine settings for YeffoPrint-PackingSlips.ps1.
#
# Copy this file to PackingSlipConfig.ps1 in this same folder and fill
# in your real values there. PackingSlipConfig.ps1 is deliberately NOT
# committed to git (see the .gitignore in this folder) - it holds real
# secrets (a WooCommerce API key, a GitHub token) that must never end
# up in source control. Every print station running the script keeps
# its own local copy, so a git-based script update (see the main
# script's own .NOTES) never touches it.

# Your store's base URL, no trailing slash.
$SiteUrl = 'https://yeffodesign.com'

# WooCommerce -> Settings -> Advanced -> REST API -> Add key. Read
# permission is enough - this script only ever calls GET.
$ConsumerKey    = 'ck_REPLACE_ME'
$ConsumerSecret = 'cs_REPLACE_ME'

# Which order statuses count as "ready to pack and ship." WooCommerce's
# default meaning of "processing" is "paid, not yet fulfilled" - the
# right status for most stores. If a manual payment gateway (Venmo/
# Zelle) lands orders on "on-hold" instead of moving them straight to
# "processing," add 'on-hold' here too.
$WatchedStatuses = @('processing')

# How often to check for new orders, in seconds. 60-120s is plenty
# responsive without polling faster than you'd actually ship.
$PollIntervalSeconds = 60

# Exact printer name (Get-Printer | Select Name), or '' to use whatever
# is currently set as the Windows default printer.
$PrinterName = ''

# ---- Auto-update from git --------------------------------------------
# Every run, the script fetches its own tracked copy from GitHub and
# overwrites itself if it's changed - push a fix once, every print
# station picks it up on its own next run/check, no manual re-upload.
# Set to $false to turn this off entirely and always run whatever copy
# of the script is currently on this machine.
$AutoUpdate = $true

# Raw-file URL of the tracked script in the Yeffoprint repo. Change the
# branch name here if this print station should track a different one.
$UpdateSourceUrl = 'https://raw.githubusercontent.com/yeffo-boop/Yeffoprint/Prod/tools/packing-slips/YeffoPrint-PackingSlips.ps1'

# A GitHub personal access token with read-only access to this repo -
# needed because the repo is private. Create one at:
# GitHub -> your avatar -> Settings -> Developer settings -> Personal
# access tokens -> Fine-grained tokens -> Generate new token ->
# Repository access: "Only select repositories" -> yeffo-boop/Yeffoprint
# -> Permissions -> Repository permissions -> Contents: Read-only.
$GitHubToken = 'github_pat_REPLACE_ME'

# While the script's own long-running loop is active, how often (in
# seconds) to re-check for an update - separate from
# $PollIntervalSeconds since there's no reason to hit GitHub as often
# as the WooCommerce API. Doesn't apply to -Once mode, which already
# checks once per invocation.
$UpdateCheckIntervalSeconds = 3600
