# Z-Push
Z-Push is an open-source application to synchronize ActiveSync compatible devices such as mobile phones, tablets and Outlook 2013 and above. With a history of almost 10 years of successful synchronization with multiple backends Z-Push is the leading open source push synchronization.

Z-Push can be easily installed using our package repositories, see the [install instructions](https://github.com/Z-Hub/Z-Push/wiki/Installation) for more information.

# Maintained Surgemail Backport
This fork tracks upstream Z-Push and keeps a small Surgemail IMAP backport on the `surgemail-safe-backport` branch.

When upstream publishes an update, do not blindly re-apply the whole patch. Instead:

1. Rebase this branch onto the new upstream commit, tag, or release branch:
   `scripts/update-from-upstream.sh upstream/master`
2. Check whether upstream already implemented part or all of the backport. If Git drops the backport commit during rebase, upstream already contains the same change. If the rebase keeps the commit but produces conflicts, remove the hunks that upstream already added and keep only the remaining Surgemail-specific behavior.
3. Review the files touched by the backport: `src/backend/imap/config.php`, `src/backend/imap/imap.php`, `src/backend/imap/rawimap.php`, and `src/lib/request/request.php`.
4. If only part of the backport is still needed, rewrite the backport so it contains only those remaining hunks, then regenerate `patches/surgemail-safe-backport.patch` with `scripts/export-surgemail-patch.sh`.

The goal is to keep the local delta as small as possible: let upstream own anything it has already implemented, and carry only the Surgemail-specific pieces that are still missing.

# Contributing
Please see the [CONTRIBUTING](CONTRIBUTING.md) file for contribution information.

### Kniff calendar scheduling deployment

The Kniff Docker configuration uses `calendar.kniff.at` (SabreDAV) for calendars, while IMAP, SMTP, CardDAV contacts and Sieve automatic replies remain on Purelymail. Mac clients connect directly to CalDAV; ActiveSync clients keep their existing Exchange account.

`CALDAV_SCHEDULING_BRIDGE` enables the authenticated calendar reply endpoint on the same verified HTTPS origin as CalDAV. Both email and calendar MeetingResponse calls update the same event. Subsequent client iTIP REPLY submissions go through that endpoint so unchanged attendee responses do not send duplicate mail. The original invitation email is retained. Replies to individual recurring instances are rejected instead of accidentally responding to the whole series. Server-side scheduling queues RSVPs; bridged client reply copies are not currently added to Sent Items.
