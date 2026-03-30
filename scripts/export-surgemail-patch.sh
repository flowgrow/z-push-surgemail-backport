#!/usr/bin/env bash
set -euo pipefail

patch_subject="IMAP: backport Surgemail safe overview path"
patch_file="patches/surgemail-safe-backport.patch"

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

commit_hash="$(git log --format='%H%x09%s' --grep "^${patch_subject}$" -n 1 HEAD | cut -f1)"
if [[ -z "$commit_hash" ]]; then
    echo "No backport commit named '${patch_subject}' was found on this branch." >&2
    exit 1
fi

mkdir -p "$(dirname "$patch_file")"
git format-patch --stdout --full-index --binary --no-stat "${commit_hash}^..${commit_hash}" > "$patch_file"

echo "Wrote ${patch_file} from ${commit_hash}."
