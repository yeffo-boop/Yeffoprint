# Auto-deploy setup (self-hosted server)

`.github/workflows/deploy.yml` makes every push to `main` automatically
run `git pull` on your server. It does nothing until you complete the
one-time setup below — the workflow file alone is inert.

**What this changes about your workflow:** once this is live, the
theme/plugin folders on the server are a git clone, not a place for
manual edits or FTP uploads anymore. Any hand-edit made directly on the
server will be **overwritten** the next time something is pushed to
`main` (the deploy does `git reset --hard`, which discards anything not
committed). Going forward, changes need to go through a commit to
`main` — which fits how this project already works, since that's where
finished work gets merged to anyway.

## Step 1 — Turn the theme/plugin folders into a git clone

SSH into your server with your normal account, then:

```bash
# Pick a location for the clone — outside the two folders you're about
# to replace, e.g. next to your WordPress install:
cd /path/to/somewhere/outside/wp-content
git clone https://github.com/yeffo-boop/Yeffoprint.git yeffoprint-deploy
cd yeffoprint-deploy

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

If the deploy user (the one the GitHub Action logs in as) is different
from whichever user your web server (PHP-FPM/Apache) runs as, you may
need to adjust ownership so both can read the files:

```bash
sudo chown -R your-web-server-user:your-web-server-user yeffoprint-deploy
```

## Step 2 — Generate a dedicated deploy key

On your own machine (not the server):

```bash
ssh-keygen -t ed25519 -f yeffoprint_deploy_key -N ""
```

This creates two files: `yeffoprint_deploy_key` (private — this goes
into GitHub, never anywhere else) and `yeffoprint_deploy_key.pub`
(public — this goes on the server). Using a dedicated key instead of
your own personal SSH key means it can be revoked independently later
without affecting your own access.

## Step 3 — Authorize that key on the server

Still SSH'd into the server:

```bash
echo "PASTE_THE_CONTENTS_OF_yeffoprint_deploy_key.pub_HERE" >> ~/.ssh/authorized_keys
```

(Or append it to the `authorized_keys` file for whichever user account
you want the deploy to run as — that user needs write access to the
clone from Step 1.)

## Step 4 — Add the secrets in GitHub

In the repo on GitHub: **Settings → Secrets and variables → Actions →
New repository secret**. Add each of these (never paste these into a
chat with me or anywhere else — only into this GitHub Secrets form):

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | Your server's hostname or IP |
| `DEPLOY_USER` | The SSH username from Step 3 |
| `DEPLOY_SSH_KEY` | The full contents of the **private** key file (`yeffoprint_deploy_key`) from Step 2 |
| `DEPLOY_PATH` | The full path to the clone from Step 1, e.g. `/path/to/somewhere/outside/wp-content/yeffoprint-deploy` |
| `DEPLOY_PORT` | Only if SSH runs on a non-standard port — otherwise skip it, the workflow defaults to 22 |

## Step 5 — Make `main` the deploy branch

The workflow watches `main`. Session work in this project lands on its
own branch first (like the one this was built on) — merge that into
`main` once you're happy with it, and every push to `main` from then on
deploys automatically. If you'd rather I merge the current branch into
`main` now to get this started, just say so.

## Step 6 — Test it

Push any small commit to `main` (or use the **Run workflow** button
under the Actions tab in GitHub — the workflow also supports being
triggered manually, no commit needed) and watch it run under the
**Actions** tab. If it fails, the log will show exactly which step
didn't work — most first-run issues are either a permissions mismatch
(Step 1's ownership note) or a typo in one of the secrets.
