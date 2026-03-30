#!/usr/bin/env bash
set -euo pipefail

target_ref="${1:-upstream/master}"
expected_branch="${2:-surgemail-safe-backport}"

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

current_branch="$(git symbolic-ref --quiet --short HEAD || true)"
if [[ -z "$current_branch" ]]; then
    echo "Detached HEAD is not supported. Check out ${expected_branch} first." >&2
    exit 1
fi

if [[ "$current_branch" != "$expected_branch" ]]; then
    echo "Refusing to rebase branch ${current_branch}. Check out ${expected_branch} first or pass it as arg 2." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Working tree is not clean. Commit or stash changes first." >&2
    exit 1
fi

if ! git remote get-url upstream >/dev/null 2>&1; then
    echo "Remote 'upstream' is missing. Add it before running this script." >&2
    exit 1
fi

git fetch upstream --tags
git rebase "$target_ref"

echo "Rebase complete."
echo "If upstream already contains the backport, Git may have skipped that commit automatically."
echo "Refresh patches/surgemail-safe-backport.patch if the backport changed."
