# Dose Tracker

Customers' peptide dose log at **/tracker/**: a phone-friendly web app
(installable to the Home Screen) served by `yeffoprint-core`
(`includes/tracker/`, `includes/rest/class-tracker-controller.php`,
`assets/tracker/`). Customers sign in with their normal YeffoDesign
account.

## What's stored, and how

- One table, `wp_yeffoprint_tracker_records`. Every compound, dose, time,
  vial and note is encrypted (XChaCha20-Poly1305, libsodium) before it is
  written. Only the user id, record type and last-changed time are plain.
- Each customer has their own random key, stored in user meta only in
  encrypted ("wrapped") form. **Delete my data** in the app, deleting the
  WordPress account, or Tools → Erase Personal Data removes the rows and
  that key, so old backups can't be read either.
- There is no admin screen that shows a customer's entries, by design.
- The app keeps a copy in the customer's browser so it opens offline. It
  is cleared by the app's Sign out button, on any signed-out page of the
  site (so signing out from the header or My Account clears it too), and
  when a different account opens the tracker on that browser.
- The app page sends a strict Content-Security-Policy (only this site's
  tracker.js plus its nonce'd config script may run; no framing).
- Sign-in is rate limited for the whole site (`security/class-login-throttle.php`):
  10 failed tries per IP in 15 minutes, 30 per account in an hour. The
  account password is what unlocks a customer's entries, so this matters.

**What the encryption does and doesn't cover.** It protects a copied
database or backup. It does not protect against someone who can run code
on the server (a WordPress admin account, a plugin, or server access):
the site has to decrypt entries to show them, so it can. Keep admin
accounts few and strongly protected, and only install plugins you trust.

## The master key (back this up)

The customer keys are wrapped with a master key that never goes in the
database. On first use the plugin creates it automatically, in this order:

1. `YEFFOPRINT_TRACKER_KEY` in `wp-config.php`, if you define it (a
   base64 32-byte key: `php -r 'echo base64_encode(random_bytes(32)), "\n";'`).
2. Otherwise a file named `yeffoprint-tracker.key` in the folder **above**
   the WordPress install (outside the web root, outside the git clone and
   outside wp-content backups).
3. Only if that folder isn't writable: `wp-content/yeffoprint-private/tracker-key.php`
   (a PHP file that prints nothing if requested over the web).

**If the master key is lost, every customer's tracker data is unreadable.**
Copy the key file (or the constant) somewhere safe and separate from your
database backups. To move it into `wp-config.php` later, copy the file's
contents into `define( 'YEFFOPRINT_TRACKER_KEY', '...' );`; the constant
wins when both exist.

## Reminders

Web Push, sent by the `yeffoprint_tracker_reminder_sweep` cron event
every 5 minutes (driven by the server's real cron, see
`deploy-setup.md`). Messages are encrypted to each customer's browser, so
Apple/Google push servers can't read them. iPhone needs iOS 16.4+ and the
tracker added to the Home Screen; Android and desktop browsers work
directly. The push signing key is created once and stored sealed with the
master key (`yeffoprint_tracker_vapid` option).

## Order labels

The Vials tab's **Order labels** button turns the customer's vials into
one template order: tick vials and label counts, pick a design (peptide
and pen label Templates, via `GET tracker/label-templates`), size,
material, corners and colors once, then check each label. Compound and
strength come prefilled from each vial (`5 mg`, `5000 IU`, or `2.5 mg/mL`
for a premixed vial) and stay editable. It adds a single cart line with
one batch row per vial through the storefront's own `/cart/add`, the same
as the product page's "Add another label", so pricing, validation and
"Edit customization" work unchanged, and then opens the cart. Custom
sizes (typed inches) stay on the product page. Nothing from this flow is
saved in the tracker.

## Supply (inventory and planner)

The Vials tab is now **Supply**, with three views:

- **Mixed**: vials and pens in use, as before, plus how many more are on
  hand and the date that covers them to.
- **On hand**: `stock` records (encrypted like everything else): unmixed
  powder vials, pen cartridges, premixed vials, or pills/sprays/patches
  counted one by one. Mixing a vial offers "Take it from your supply",
  which takes one off; pills count down with every dose taken after they
  were counted.
- **Plan**: for each protocol, the vial in use, then each vial on hand
  (mix dates), then any still to buy before the cycle ends, with a
  "buy by" date a week ahead.

Running low (each item's "Warn me when I have" window, default 2 weeks)
shows on Supply and Today. On the day a vial is used up, Today's dose card
shows a **Mix vial N of M** button that pre-fills the new vial and marks
the old one finished. The app works out upcoming running-low and mix-day
notifications and saves them as the `alerts` settings record; the reminder
sweep sends each one when its time comes (10 AM for running low, 7 PM the
evening before a mix day).

## Shared protocols

**Share this protocol** (in a protocol's edit sheet) makes a link
`/tracker/p/{code}` holding only the dose, schedule and, if ticked, how to
mix it, cycle length and notes. Stored in `wp_yeffoprint_tracker_shares`,
sealed with the master key; no name or history. Whoever opens it gets an
**Add to my tracker** sheet (signed-out visitors see the protocol and a
sign-in button). Link previews in texts stay generic. **Me › Shared
links** lists the customer's own links (from `GET /tracker/state`'s
`shares`) with **Stop sharing** (`DELETE /tracker/shares/{code}`, owner
only), after which the link shows "This link doesn't work". Copies already
added to someone's tracker stay. "Delete my data" removes all their links.

## Travel mode

The schedule follows the customer's home zone (`settings.baseTz`). When
the phone's zone changes, Today asks: ease in 2 hours a day, switch now,
or keep home time. Easing is stored as `settings.travel` (from/to offsets,
start time) and the reminder sweep follows the same clock
(`YeffoPrint_Tracker_Schedule::timezone()`, mirrored by
`effectiveOffset()` in tracker.js).
