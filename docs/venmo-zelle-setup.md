# Venmo / Zelle automatic payment matching — setup guide

This is the piece that can't live in the codebase: a small Google Apps
Script (free, runs inside your own Gmail account) that watches for
Venmo/Zelle notification emails and tells the site about them. The
site side (checkout options, order matching, order notes) is already
built and deploys with the rest of the plugin — this guide is just the
one-time setup for the bridge between your inbox and the site.

## How it works, end to end

1. A customer checks out choosing "Venmo" or "Zelle." Their order is
   created and set to **On hold** — nothing else happens automatically
   yet.
2. They send you the money directly (outside the site, the normal way)
   and, ideally, put their order number in the payment note.
3. Venmo/Zelle emails you a notification, like they always do.
4. The Apps Script (running on a timer, e.g. every 5 minutes) notices
   that new email, pulls the dollar amount and note out of it, and
   sends that to the site.
5. The site looks for an **On hold** order paid by that gateway with
   that exact total:
   - If the note contained an order number and it matches an on-hold
     order at that amount → matched immediately.
   - Otherwise, if exactly one on-hold order has that exact total →
     matched.
   - If **zero** or **more than one** order match → nothing is
     changed automatically. You get an email instead, listing the
     candidates (if any) to resolve by hand. This is intentional —
     guessing wrong here means shipping something unpaid or leaving a
     real payment unmatched, so ambiguous cases always go to a human.
6. A matched order moves to **Processing** automatically, the same as
   any other paid order.

## Step 1 — Turn the gateways on and grab your webhook URLs

1. In wp-admin: **WooCommerce → Settings → Payments**.
2. Enable **Venmo** and/or **Zelle**, then open each one's settings.
3. Fill in your Venmo username / Zelle email or phone — this is what's
   shown to customers at checkout and in their confirmation email.
4. Under **Automatic matching**, copy the webhook URL shown there.
   **It already includes this site's secret token** — anyone with that
   exact URL could report fake payments, so treat it like a password
   (don't post it anywhere public). There's one URL per gateway
   (Venmo's URL has `method=venmo`, Zelle's has `method=zelle`) — copy
   both if you're using both.

## Step 2 — Filter the notification emails in Gmail

You want a Gmail label that captures exactly the payment-received
emails, so the script only ever looks at those. In Gmail:

1. Click the search-options arrow in the search bar.
2. For **Venmo**, something like: `from:venmo@venmo.com subject:(received)`
   — open one of your actual past "you got paid" emails first and
   check the exact sender address and subject wording, since Venmo has
   changed these before. Adjust the filter to match what you actually
   see.
3. For **Zelle**, the sender is your *bank*, not Zelle itself (e.g.
   "Chase", "Bank of America"), and the wording varies a lot by bank —
   again, open a real one you've received and filter on its actual
   sender/subject.
4. Under "Create filter," check **Apply the label** and create a new
   label for each, e.g. `YP-Venmo-Incoming` / `YP-Zelle-Incoming`.

## Step 3 — The Apps Script

1. Go to [script.google.com](https://script.google.com), **New project**.
2. Delete the placeholder code and paste this in:

```javascript
// ===== Fill these in =====
var VENMO_WEBHOOK_URL = 'PASTE_YOUR_VENMO_WEBHOOK_URL_HERE';
var ZELLE_WEBHOOK_URL = 'PASTE_YOUR_ZELLE_WEBHOOK_URL_HERE';
var VENMO_GMAIL_LABEL = 'YP-Venmo-Incoming';
var ZELLE_GMAIL_LABEL = 'YP-Zelle-Incoming';
// ==========================

function checkForPayments() {
  processLabel(VENMO_GMAIL_LABEL, 'venmo', VENMO_WEBHOOK_URL);
  processLabel(ZELLE_GMAIL_LABEL, 'zelle', ZELLE_WEBHOOK_URL);
}

function processLabel(labelName, method, webhookUrl) {
  if (!webhookUrl || webhookUrl.indexOf('PASTE_YOUR') === 0) {
    return; // Not configured for this method yet.
  }

  var processedLabelName = labelName + '-Processed';
  var label = GmailApp.getUserLabelByName(labelName);
  var processedLabel = GmailApp.getUserLabelByName(processedLabelName) ||
    GmailApp.createLabel(processedLabelName);

  if (!label) {
    return; // Filter/label from Step 2 doesn't exist yet.
  }

  // Only threads with the label but NOT yet marked processed — avoids
  // reporting the same payment twice on every run.
  var threads = label.getThreads(0, 20);

  threads.forEach(function (thread) {
    if (thread.getLabels().some(function (l) { return l.getName() === processedLabelName; })) {
      return;
    }

    var message = thread.getMessages()[thread.getMessages().length - 1];
    var body = message.getPlainBody();

    // Adjust this regex if your actual emails format the amount
    // differently — this matches "$25.00" / "$1,234.56" style amounts.
    var amountMatch = body.match(/\$([0-9,]+\.\d{2})/);
    if (!amountMatch) {
      // Logged, not silent — without this, a regex that doesn't match
      // your real email's wording fails exactly like a webhook problem
      // (label applied, nothing reaches the site, no error anywhere)
      // with no way to tell the two apart from the Execution log.
      Logger.log(method + ' payment: could not find a dollar amount in this email (subject: "' + thread.getFirstMessageSubject() + '") — check the regex above against this email\'s actual plain-text body.');
      thread.addLabel(processedLabel); // Don't retry something we can't parse.
      return;
    }
    var amount = amountMatch[1].replace(/,/g, '');

    // Best-effort: if the sender included something like "Order #123"
    // or "#123" in their note, this passes it along so the site can
    // match by order number instead of amount alone. Fine if this
    // finds nothing — the site falls back to amount-only matching.
    var noteMatch = body.match(/#\s?(\d{2,})/);
    var note = noteMatch ? noteMatch[0] : '';

    var response = UrlFetchApp.fetch(webhookUrl, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify({ amount: amount, method: method, note: note }),
      muteHttpExceptions: true
    });

    Logger.log(method + ' payment $' + amount + ': ' + response.getContentText());

    thread.addLabel(processedLabel);
  });
}
```

3. Replace `PASTE_YOUR_VENMO_WEBHOOK_URL_HERE` and
   `PASTE_YOUR_ZELLE_WEBHOOK_URL_HERE` with the URLs from Step 1 (leave
   one as-is if you're only using one method for now).
4. If you used different label names in Step 2, update
   `VENMO_GMAIL_LABEL` / `ZELLE_GMAIL_LABEL` to match.
5. Save the project (give it a name like "YeffoPrint Payment Matching").

## Step 4 — Test it once by hand

1. In the Apps Script editor, select the `checkForPayments` function
   from the dropdown at the top and click **Run**.
2. The first run will ask you to authorize the script (it needs Gmail
   access and permission to make external web requests) — approve it.
3. Check the **Execution log** (View → Logs) for what it found. If it
   logs a payment, check that order in wp-admin to confirm it either
   matched or you got the "unmatched/ambiguous" email — either way
   confirms the whole chain is working.
4. If nothing logs at all, double check the Gmail label actually has a
   message in it, and that the regex in Step 3 actually matches your
   real email's wording (open the email, view its plain-text body, and
   test the pattern).

**The single most common silent failure**: the thread gets labeled
`-Processed` (so it looks like the script "handled" it) but the site
never receives anything, because the amount regex didn't match your
real email's exact wording — the script gives up on an email it can't
parse rather than retrying it forever. The Execution log now says so
explicitly ("could not find a dollar amount in this email"); if you're
running an older copy of this script without that log line, add it (see
the `if (!amountMatch)` block in Step 3) or just re-check the regex by
hand against a real email's plain-text body. This is different from an
actual webhook problem, where the log instead shows a real HTTP
response — `{"status":"unmatched"}`/`{"status":"matched",...}` from the
site, or an error if the URL/token is wrong.

## Step 5 — Automate it

1. In the Apps Script editor, click the clock icon (**Triggers**) on
   the left sidebar.
2. **Add Trigger** → function `checkForPayments` → Event source
   "Time-driven" → Minutes timer → every 5 or 10 minutes.
3. Save.

From here it runs on its own. If you ever need to stop it, delete the
trigger (Step 5) rather than the script itself, so you can turn it back
on later without redoing the setup.

## If the webhook URL ever leaks

Regenerate the secret by deleting the `yeffoprint_payment_webhook_secret`
option (`wp option delete yeffoprint_payment_webhook_secret` via
WP-CLI, or ask your developer) — a fresh one is generated automatically
the next time it's needed, and the gateway settings pages will show the
new URL. You'll need to update the Apps Script with the new URL too.
