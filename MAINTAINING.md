# Surgemail Backport Workflow

This repository keeps a small, reviewable IMAP backport on top of upstream Z-Push.

## Branch layout

- `surgemail-safe-backport` is the maintained branch.
- The code backport commit is named `IMAP: backport Surgemail safe overview path`.
- `patches/surgemail-safe-backport.patch` is a portable export of that commit.

## Remote layout

Use `upstream` for the official Z-Push repository and keep your own fork as `origin`.

If you clone this repository somewhere else, set the remotes like this:

```bash
git remote rename origin upstream
git remote add origin <your-fork-url>
git fetch upstream --tags
```

## Updating from upstream

From the maintained branch:

```bash
scripts/update-from-upstream.sh upstream/master
```

That script fetches the official upstream and rebases the maintained branch on top of it. If upstream has already absorbed the same code, Git will usually skip the backport commit automatically.

## Refreshing the exported patch

If the backport commit changes, regenerate the standalone patch file with:

```bash
scripts/export-surgemail-patch.sh
```
