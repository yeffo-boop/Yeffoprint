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

## Alternate setup: Google Compute Engine + GitHub Actions (push-triggered)

Direct request, once the site moved off the original self-hosted-at-home
server onto a GCE instance: the reasoning above for pulling instead of
pushing ("port 22 open to GitHub's whole IP range means open to
everyone") was specific to a home router with no real firewall control.
A GCE instance has a real one (GCP VPC firewall rules, scoped by source
IP range), so a push-triggered deploy — GitHub Actions SSHing in the
moment `Prod` moves, instead of waiting up to 5 minutes for the next
cron tick — became genuinely viable without that downside. Chosen over
a self-hosted Actions runner (which would need zero inbound firewall
rules at all) as an explicit tradeoff: this needs a firewall rule kept
in sync with GitHub's published Actions IP ranges, which do change
periodically, in exchange for not running an extra long-lived agent
process on the server.

Steps 1–2 above (turning the theme/plugin folders into a git clone,
testing `pull-and-deploy.sh` by hand) are unchanged and still the
starting point — this replaces Step 3's cron job with a GitHub Actions
workflow instead, reusing the exact same deploy script either way.

1. **Reserve a static external IP** for the instance, so the firewall
   rule and the GitHub secret both target something that doesn't move:
   ```bash
   gcloud compute addresses create yeffoprint-static-ip --region=YOUR_REGION
   gcloud compute instances add-access-config your-instance-name \
     --zone=YOUR_ZONE --address=yeffoprint-static-ip
   ```

2. **Generate a dedicated deploy key** (not your own SSH key — this
   one's only job is triggering the deploy script):
   ```bash
   ssh-keygen -t ed25519 -f ./gh-actions-deploy-key -C "github-actions-deploy" -N ""
   ```

3. **Authorize the public key on the instance, pinned to only the
   deploy script**, added directly to the deploy user's
   `~/.ssh/authorized_keys` on the box — not through GCP's
   instance/project "SSH Keys" metadata field, since the guest agent
   periodically re-syncs keys added that way and would silently strip a
   forced-command restriction:
   ```bash
   echo 'command="/path/to/yeffoprint-deploy/deploy/pull-and-deploy.sh",no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA...your-pubkey... github-actions-deploy' >> ~/.ssh/authorized_keys
   ```
   Even if this private key ever leaked, it can only ever run that one
   script on this one box — never an arbitrary shell.

4. **Firewall rule scoped to GitHub's Actions IP ranges** — the one
   piece that needs periodic upkeep, since those ranges change:
   ```bash
   gcloud compute firewall-rules create allow-github-actions-ssh \
     --network=YOUR_VPC_NETWORK --direction=INGRESS --action=ALLOW \
     --rules=tcp:22 \
     --source-ranges=$(curl -s https://api.github.com/meta | jq -r '.actions | join(",")') \
     --target-tags=yeffoprint-deploy-target
   gcloud compute instances add-tags your-instance-name \
     --zone=YOUR_ZONE --tags=yeffoprint-deploy-target
   ```
   Re-run with `firewall-rules update` and a fresh `curl` whenever
   GitHub's ranges change — there's no automatic refresh set up for
   this yet.

5. **Repo secrets** (Settings → Secrets and variables → Actions):
   `GCE_SSH_PRIVATE_KEY` (the private key from step 2), `GCE_HOST` (the
   static IP from step 1), `GCE_USER` (the SSH username from step 3).

6. **The workflow** — `.github/workflows/deploy.yml`, triggers on push
   to `Prod`, SSHes in and runs the same `pull-and-deploy.sh` the cron
   job would have run. The forced command in `authorized_keys` (step 3)
   means the key can't be used to run anything else even if this file
   changed — the `script:` line is there for clarity, not as the only
   thing enforcing what actually executes.

Test by merging something into `Prod` and watching the Actions tab —
should land on the live site within seconds rather than the cron
interval.
