#!/bin/bash
# Preview by default. Only fetch + fast-forward tracked code; no hooks/migrations/services.
set -euo pipefail
panel_code_dir="$(cd -- "$(dirname -- "$0")/.." && pwd)"
ha_target=""
ha_apply=0
while (($#)); do
    case "$1" in
        --target) ha_target="${2:?Missing commit}"; shift 2 ;;
        --apply) ha_apply=1; shift ;;
        *) echo 'Usage: bash bin/update-code-only.sh --target FULL_COMMIT [--apply]' >&2; exit 2 ;;
    esac
done
[[ "$ha_target" =~ ^[0-9a-f]{40}$ ]] || { echo 'Require full 40-character commit SHA' >&2; exit 2; }
cd -- "$panel_code_dir"
ha_lock="$(git rev-parse --git-common-dir)/musedock-code-update.lock"
exec 9>"$ha_lock"
flock -n 9 || { echo 'Another code-only update is running' >&2; exit 1; }
[[ "$(git symbolic-ref --short HEAD)" == main ]] || { echo 'Refuse: checkout must be main' >&2; exit 1; }
[[ -z "$(git status --porcelain --untracked-files=normal)" ]] || { echo 'Refuse: local changes/untracked files; preserve and review manually' >&2; exit 1; }
git -c core.hooksPath=/dev/null fetch origin main
git merge-base --is-ancestor "$ha_target" refs/remotes/origin/main || { echo 'Target is not published on origin/main' >&2; exit 1; }
git merge-base --is-ancestor HEAD "$ha_target" || { echo 'Refuse: diverged history or downgrade' >&2; exit 1; }
ha_before="$(git rev-parse HEAD)"
git diff --stat "$ha_before" "$ha_target"
echo "Current: $ha_before"
echo "Target:  $ha_target"
echo 'Code takes effect in requests/background workers when loaded. Existing automatic failover policy may change.'
if ((ha_apply == 0)); then
    echo 'PREVIEW: tracked files unchanged. Add --apply after reviewing.'
    exit 0
fi
[[ -z "$(git status --porcelain --untracked-files=normal)" ]] || { echo 'Working tree changed during review; abort' >&2; exit 1; }
git -c core.hooksPath=/dev/null merge --ff-only "$ha_target"
echo "Updated code only. Rollback reference: $ha_before"
echo 'No services restarted, migrations run or boot guard installed. Preserve this SHA for coordinated rollback.'
