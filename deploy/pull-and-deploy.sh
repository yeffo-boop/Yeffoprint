#!/usr/bin/env bash
#
# Server-side pull deploy — run this periodically via cron from inside
# the clone it lives in (see docs/deploy-setup.md).
#
# Deliberately pull-based, not push-based: this server is self-hosted at
# home, and a GitHub Actions runner pushing over SSH would need port 22
# forwarded from the router to the internet at large (GitHub's runner
# IPs aren't a fixed, allowlist-able range) — a meaningfully different
# risk than the same setup on a cloud VM behind a security group. Since
# the server can already reach the internet outbound (that's how it
# serves the site), having it check GitHub and pull needs no inbound
# port opened at all.
#
# Quiet on purpose when there's nothing new, so a tight cron interval
# (every few minutes is reasonable) doesn't spam a log file — only
# writes a line when it actually deploys something.

set -euo pipefail

cd "$(dirname "$0")/.."

branch="Prod"
before="$(git rev-parse HEAD)"

git fetch origin "$branch" --quiet
after="$(git rev-parse "origin/$branch")"

if [ "$before" = "$after" ]; then
	exit 0
fi

echo "$(date -Iseconds) deploying $branch: $before -> $after"
git reset --hard "origin/$branch"
echo "$(date -Iseconds) done"
