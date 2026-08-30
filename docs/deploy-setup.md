# Auto-deploy setup (self-hosted server)

`deploy/pull-and-deploy.sh` checks GitHub for new commits on `Prod` —
this repo's actual default/production branch; there's no `main` here —
and updates the server's clone when there's something new. A cron job
on the server runs it every few minutes. Nothing pushes to the server;
the server pulls, on its own schedule.

**Why pull instead of push:** this server is self-hosted at home. A
push-based setup (GitHub Actions SSHing in) would need port 22 forwarded
from the router to the internet — and since GitHub's runners come from a
large, changing range of IPs rather than a fixed one, "open to GitHub"
in practice means "open to everyone." Pulling needs no inbound port
opened at all — the server already reaches the internet outbound (that's
how it serves the site), so checking GitHub and pulling asks nothing new
of the router or firewall.

**What this changes about your workflow:** once this is live, the
theme/plugin folders on the server are a git clone, not a place for
manual edits or FTP uploads anymore. Any hand-edit made directly on the
server will be **overwritten** the next time `Prod` moves (the script
does `git reset --hard`, which discards anything not committed). Going
forward, changes need to go through a commit to `Prod` — which fits how
this project already works, since that's where finished work gets
merged to anyway.

## Step 1 — Turn the theme/plugin folders into a git clone

SSH into your server with your normal account, then:

```bash
# Pick a location for the clone — outside the two folders you're about
# to replace, e.g. next to your WordPress install:
cd /path/to/somewhere/outside/wp-content
git clone https://github.com/yeffo-boop/Yeffoprint.git yeffoprint-deploy
cd yeffoprint-deploy
git checkout Prod

# Back up what's currently there (don't skip this — it's your safety
# net if anything doesn't line up):
mv /path/to/wp-content/themes/yeffoprint /path/to/wp-content/themes/yeffoprint.bak
mv /path/to/wp-content/plugins/yeffoprint-core /path/to/wp-content/plugins/yeffoprint-core.bak

# Symlink the live paths into the clone, so WordPress reads from git-
# tracked files but the clone only has to live in one place:
ln -s "$(pwd)/yeffoprint" /path/to/wp-content/themes/yeffoprint
ln -s "$(pwd)/yeffoprint-core" /path/to/wp-content/plugins/yeffoprint-core
```

Then load the site and confirm it still looks right before deleting the
`.bak` folders — if the symlinked version is missing something the
`.bak` version had (e.g. an uploaded logo lives in `wp-content/uploads`,
untouched by any of this, so that's not a concern, but double-check
anything else you may have hand-edited directly on the server before
this).

If the user your cron job runs as is different from whichever user your
web server (PHP-FPM/Apache) runs as, you may need to adjust ownership so
both can read/write the files:

```bash
sudo chown -R your-web-server-user:your-web-server-user yeffoprint-deploy
```

Every path in Steps 1–2 needs to actually be reachable by your web
server user — if you hit a permissions wall getting here, `namei -l
/path/to/wp-content/themes/yeffoprint` (walks every directory in the
path, showing owner/permissions for each) is the fastest way to spot
which segment is blocking it. A clone sitting inside a home directory
(`~`) is the single most common cause — home directories are almost
always locked down (`700`/`750`), while wherever WordPress itself
already lives has to be web-server-readable by definition, so keeping
the clone there (as in the `cd` command above) avoids the problem
entirely rather than requiring you to loosen home-directory permissions.

## Step 2 — Test the script once, by hand

```bash
cd /path/to/somewhere/outside/wp-content/yeffoprint-deploy
./deploy/pull-and-deploy.sh
```

Since the clone from Step 1 is already at `Prod`'s current tip, this
should print nothing and exit immediately — that's correct (the script
is deliberately quiet when there's nothing new, so a tight cron interval
doesn't spam a log file). To actually see it deploy something, either
wait for the next real change to land on `Prod`, or force a test run:

```bash
git reset --hard HEAD~1   # steps the clone back one commit, just for this test
./deploy/pull-and-deploy.sh   # should now print a "deploying ..." / "done" pair and fast-forward back
```

## Step 3 — Add the cron job

```bash
crontab -e
```

Add a line (adjust the path, and redirect to wherever you want the log
to live):

```cron
*/5 * * * * /path/to/somewhere/outside/wp-content/yeffoprint-deploy/deploy/pull-and-deploy.sh >> /path/to/somewhere/outside/wp-content/yeffoprint-deploy/deploy/pull.log 2>&1
```

Every 5 minutes is a reasonable default — frequent enough that a merge
to `Prod` shows up on the live site quickly, infrequent enough not to be
noisy. The script only writes to the log on an actual deploy, so an
empty (or slowly-growing) log file is the expected, healthy state.

## Step 4 — Keep merging into `Prod`

Session work in this project lands on its own branch first (like the
one this was built on) — merge that into `Prod` once you're happy with
it, and the cron job picks it up on its next run (within the interval
set in Step 3).

## Step 5 — Move WP-Cron off page loads

Direct concern: "I'm concerned the site is getting a bit slower with
these new features being added." By default, WordPress runs its
scheduled jobs (WP-Cron) by spawning a loopback HTTP request the moment
any visitor loads a page after a job's due time — that request ties up
a full PHP worker for however long the job takes, competing with real
visitor requests for the same limited worker pool. On a small
self-hosted server that's genuine, felt contention, not just a
theoretical concern — and this project now has two hourly jobs
(proof-approval reminders, and a delivery-tracking sweep that makes a
real outbound HTTP call per shipped order, the heavier of the two).

`wp-content/mu-plugins/disable-wp-cron.php` (ships through the same
git-pull deploy as everything else — see that file's own docblock for
why this couldn't be a wp-config.php constant instead) turns off that
default, page-load-triggered spawning. That alone doesn't run anything
anymore, though — something now has to trigger `wp-cron.php` on a real
schedule instead. Add a second line to the same crontab from Step 3:

```bash
crontab -e
```

```cron
*/5 * * * * curl -s --max-time 30 "https://your-domain.example/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

Replace `your-domain.example` with the site's real domain. Every 5
minutes is a reasonable default here too — the hourly jobs still only
actually run once their own schedule is due; this just makes sure
*something* checks in often enough for that to happen close to on time,
without ever waiting on a real visitor to trigger it. If WP-CLI is
already installed on this server, `wp cron event run --due-now --path=/path/to/wordpress`
works as a drop-in replacement for the `curl` line — it runs cron
in-process rather than over HTTP, marginally more efficient, but not
required.

To confirm it's working: `wp cron event list --path=/path/to/wordpress`
should show `yeffoprint_proof_reminder_sweep` and
`yeffoprint_delivery_tracking_sweep` with a `next_run` that keeps
advancing on its own hourly schedule, not stuck in the past waiting for
a page load.

