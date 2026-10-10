# Dose Tracker

Customers' peptide dose log at **/tracker/**: a phone-friendly web app
(installable to the Home Screen) served by `yeffoprint-core`
(`includes/tracker/`, `includes/rest/class-tracker-controller.php`,
`assets/tracker/`). Customers sign in with their normal YeffoDesign
account.

## Calculator tab (no sign-in needed)

Signed out, `/tracker/` opens on the dose calculator (Peptides, HGH / HCG,
Hormones, Blends) with Today, Progress and Supply shown locked; tapping one,
or Save, explains what a free account adds and links to sign in or sign up.
Save keeps the calculation in `localStorage` `ypt-calc-draft` (no `ypt:`
prefix, so the signed-out cleanup on wp-login.php leaves it) for 7 days;
the next signed-in load opens it as a filled-in "Save as a vial" sheet,
which goes on to the schedule if there isn't one for that peptide. Nothing
else typed into the calculator is stored.

Signed in, Calculator is the fifth bottom tab (Me moved to the initial in
the app bar). "Start from a vial I have" fills it from a saved vial and its
schedule. The math is the same as the Mix a vial sheet. Shared protocol
links opened signed out still show the old share landing page.

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

**Snooze.** Each dose reminder has a "Remind me in 30 min" button on
Android and desktop. It posts the reminder's signed token (HMAC with the
site's auth salt: one customer, those dose slots, good for 12 hours) to
`POST /tracker/snooze/push`, which needs no session because the service
worker has none. iPhone notifications can't show buttons, so a due,
unlogged dose on Today has the same button (`POST /tracker/snooze`).
Either way a `snooze` record keeps only the slot ids and the time; the
next sweep after that time rebuilds the reminder from the protocols and
skips it if the dose was logged meanwhile. A dose can be snoozed 6 times.
In the Android app (tracker-android/) reminders are scheduled on the
phone, so the Today button snoozes there too: tracker.js keeps the snooze
in localStorage `ypt-native-snooze:<user>` and adds one more local
reminder 30 minutes out, dropped again if the dose gets logged. The
server isn't involved.

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
  were counted. **Bac water** is a `stock` with `form: 'water'`: bottle
  size in `volume`, mL left in `ml`. Mixing a new vial offers "Use bac
  water from your supply", which takes that vial's water off. It runs low
  when there isn't enough for the upcoming mixes across every vial schedule
  (each new vial mixed like the one in use; `waterPlan()` in tracker.js).
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

## Android app

`tracker-android/` is a Capacitor app that opens the live **/tracker/**
page full screen (see `tracker-android/README.md` for building and
publishing). It adds no second copy of the tracker: every change to the
web app shows up in the Android app on the next open, with no app update.

- **Reminders are scheduled on the phone.** An Android WebView can't
  receive Web Push, so inside the app tracker.js (the "Android app"
  section) works out the next 14 days of due doses plus running-low and
  mix-day alerts and schedules them as local notifications. They're
  replaced whenever anything changes or the app opens, so a dose logged
  early cancels its reminder. Nothing goes through a push server, and the
  Show names in reminders switch applies. Each open covers the next 14
  days, so someone who never opens the app for two weeks stops getting
  reminders until they do.
- Android 14+ turns "Alarms & reminders" off for new apps; without it a
  reminder can arrive late. Turning reminders on asks for it once, and the
  Me tab keeps offering it while it's off.
- Signing out (in the app or on any signed-out page) cancels the phone's
  reminders along with the saved copy.
- Inside the app, "Continue with Google" and "Continue with Telegram" are
  hidden on sign-in pages: Google blocks sign-in from an app's built-in
  browser, and Telegram's needs a pop-up. Email/password, Discord and
  Apple work.
- The app is called **YeffoHealth** (`com.yeffohealth.app`), with the
  same striped vial icon as the web app.
- Me › Privacy links to "Delete my account" (`deleteAccountUrl`), which
  Google Play requires to be reachable from inside the app.
- **Health Connect** (app 1.1+, Me › Health Connect): read only. The
  app's own plugin (`YeffoHealthPlugin.kt`) asks for weight, body fat,
  nutrition and hydration, then tracker.js brings them in on open (at most
  every 30 minutes) and on Sync now: the first sync covers 30 days, later
  ones re-read the last 3 whole days. A day's last weigh-in becomes a
  Progress entry `hc-<date>` (`src: 'hc'`) unless the customer typed a
  weight that day; protein, calories and water are Health Connect's own
  day totals (so two apps logging one meal count once) and go in that
  day's food record under `hc`, separate from what the customer added.
  The link is per phone (`ypt-hc:<user>` in local storage). Play needs the
  Health apps declaration for these permissions, and Health Connect's
  privacy link opens `HealthPrivacyActivity` (the privacy policy page).
- **Home Screen widget** (app 1.1+): `YeffoWidget.kt` shows today's
  count and the next dose not yet logged. tracker.js `widgetData()` sends
  today's and tomorrow's doses whenever they change; the widget never
  reads the tracker itself, and hides names when Show names in reminders
  is off. Signing out clears it.
- Both features check the plugin is there (`isPluginAvailable`), so older
  installs and the website just don't show them. iPhone has neither: Apple
  Health and widgets need a native iPhone app.

## Injection site rotation

Injections (under the skin or into the muscle) suggest the next spot on
each Today card: the spot in that peptide's rotation that has gone
longest without a dose, counting every injection the customer logged,
so two peptides rotate around each other. Tapping **Take** logs the dose
at the suggested spot; **Change** opens a body map (front and back,
drawn mirror-style so the customer's left is on the left) to pick
another spot and take the dose there. Under-the-skin spots: belly (four
quarters), thighs, backs of the arms, love handles and glutes.
Into-the-muscle spots: shoulders, thighs and glutes.

The spot is stored on the dose record (`site`), encrypted with the rest
of it. Editing a peptide has **Rotate injection sites** (on by default)
and a map to choose the spots the customer uses. History shows a map
shaded by how recently each spot was used, the day list shows each
dose's spot, and the CSV export has an Injection site column. Reminders
don't include the spot.

## How you felt (side effects & notes)

A taken dose can carry tags (`tags` on the dose record: nausea, headache,
good sleep, more energy… plus up to 20 of the customer's own, saved in
`settings.me.tags`) and a note. Today shows "+ How do you feel?" under
each taken dose, and the log and extra-dose sheets have the same picker.
History has a "Side effects & notes" card counting each tag over 30 / 90
days / all, the compound it mostly came with, and how many landed in a
week the dose had just gone up. The CSV export has a "How you felt" column.

## Cycles and titration

A protocol can have `cycle` `{ on, off }` (weeks on, weeks off, repeating
from the start date) and `steps` `[{ week, dose }]` (the dose from that
many weeks after the start; the first step is the protocol's own dose).
The editor's Cycle planner sets both, with a "change by X every N weeks
up to Y" helper and a bar chart preview. Today, reminders
(`Tracker_Schedule::is_due_on` / `dose_on`), the supply planner and
shared protocol links all follow it: off weeks have no doses and show a
"back on" line, and each dose uses that week's amount.

## Vial expiry

A mixed vial is good for 28 days from its mix date unless the customer
sets another number on that vial (`goodFor`, days). Supply shows the
days left, the planner starts a new vial when one expires even if it
isn't empty, Today shows a banner a few days before, and "Remind me
before a vial expires" (Supply › Plan, `settings.me.expiryReminders`)
adds an `exp-` alert sent two days ahead.

## Reorder labels

Supply's low-stock banners and each plan card that needs more vials have
**Order labels**, the same flow as Supply's Order labels (one template
cart line, a batch row per vial) with the low compounds ticked and their
strength filled in.

## Progress log

History › Progress records weight, measurements (waist, chest, hips,
arm, thigh, neck), body fat and up to 3 photos per entry (`progress`
records, units stored with each entry so lb/kg and in/cm can be switched
on the Progress tab). The chart shows the chosen measure with the doses
taken in lanes underneath and dashed lines where a titration step raised
a dose.

Photos are their own `photo` records: the app shrinks them to 1280 px
JPEG (dropping location metadata), uploads them to
`PUT /tracker/photos/{id}`, and the server checks they're real images
(JPEG/PNG/WebP, 1.5 MB max, 300 per customer) before encrypting them like
any other record. They are fetched one at a time
(`GET /tracker/photos/{id}`), kept only in memory, never in the offline
copy, and are erased with Delete my data.

## Lab results

The Progress tab's **Lab results** (also Add › Lab result) keeps
bloodwork the customer types in from their report: test name (with
suggestions and usual units for common tests), value, unit, the report's
normal range and a note, one `lab` record each. Results of the same test
group together; each test's sheet charts them with the normal range
shaded and the doses taken in lanes underneath, and marks results High
or Low against the range. The PDF report has a Lab results section.

## Compound library

Me › Tools › **Compound library** (also from Estimated levels) lists
common peptides, hormones and medications from
`class-tracker-compounds.php`: what it's also called, approval status,
half-life in plain words and storage. Facts only, never a dose or a
mixing amount, so it reads as a reference for the app stores (Apple
guideline 1.4.2). Half-lives are rounded published figures; entries with
no good figure for people say so and aren't charted. Names match loosely
("Ozempic (semaglutide)" finds Semaglutide); blends never match.

## Estimated levels

The top of the Progress tab estimates how much of each medication is
still active: every taken dose added up with first-order elimination
from its half-life, plus a slower rise for injections the library marks
as slowly absorbed (`abs`). The next week is dashed (no more doses). One
compound charts in its own unit (mg, IU…); several are each scaled to
their own peak. Half-lives under 2 hours aren't charted. A customer can
set their own half-life for anything (`settings.me.halfLives`, keyed by
the library name or the compound), which wins over the library's.

## Food & water

Off until turned on from Add › Food & water, which asks for optional
daily goals (`settings.me.food`: protein g, water mL, calories). Then
Today shows a card with bars and quick adds (+10 g protein, +8 oz or
250 mL water), and Progress charts each day against the goal. One `food`
record per day, id = the date; Health Connect's numbers sit in its `hc`
field and are added on top. Water shows in oz or mL with the lb/kg
setting.

## Appearance

The app follows the phone's light or dark setting. Me › Appearance can
force Light or Dark on that device (saved in the browser, not synced).
Dark mode only swaps the color tokens at the top of `tracker.css`; new
CSS should use those tokens rather than fixed colors.

## Printable report

History's **Create PDF report** (also Me › Tools) builds a PDF in the
browser, nothing is uploaded: a summary, each medication's schedule,
titration, cycle and doses taken vs scheduled, progress (first, latest,
change, weight chart), lab results, side effects, injection spots and the full dose
log, for 30 / 90 days, 6 months or everything. Sections can be switched
off. It can be downloaded or shared from the phone's share sheet.
