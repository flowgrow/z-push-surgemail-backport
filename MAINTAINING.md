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

After rebasing, review the backport before exporting a fresh patch:

- If Git dropped the backport commit, the change is already upstream and no local patch is needed for that part.
- If Git kept the commit but conflicts appeared, remove the hunks that upstream already implemented and keep only the remaining Surgemail-specific behavior.
- Review the four backport files directly: `src/backend/imap/config.php`, `src/backend/imap/imap.php`, `src/backend/imap/rawimap.php`, and `src/lib/request/request.php`.
- Once the backport only contains the still-missing pieces, regenerate `patches/surgemail-safe-backport.patch`.

## Refreshing the exported patch

If the backport commit changes, regenerate the standalone patch file with:

```bash
scripts/export-surgemail-patch.sh
```
