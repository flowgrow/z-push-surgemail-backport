# Purelymail / Apple Mail deployment

This `imap-idle` branch extends the existing Surgemail backport. Production is
https://zpush.kniff.at, hosted by Coolify. The existing username-domain fallback
is included in `src/lib/request/request.php`. Mailbox credentials are supplied
by the iPhone over HTTPS, never stored in this repository.

## IDLE behavior

`ChangesSink()` establishes read-only, certificate-verified IMAP TLS connections
before its normal status snapshot, then waits on IDLE notifications. It wakes
the unchanged ActiveSync synchronization engine for EXISTS, EXPUNGE, FETCH flags,
and VANISHED responses. PHP stream selection waits without a busy loop.

One connection per selected folder per active ActiveSync request is required,
up to eight folders. Connections persist through that request's heartbeat loop,
are renewed after four minutes, and close at request end. A 30-second status
reconciliation remains to detect hierarchy changes and recover missed events.
Unavailable IDLE, disconnections, non-implicit-TLS configuration, and larger
folder sets use the original polling behavior. Failures back off for a minute
within the current request. Set `IMAP_IDLE_ENABLED=false` to disable the extension.

`tests/idle/idle.php` verifies quoted credentials, injection rejection, fragmented
arrival events, quiet periods, BYE, capability absence, and rejected IDLE.
The Docker build runs those tests. Actual phone delivery latency must be measured
on the iPhone; successful server wakeups do not prove phone delivery.

## Deployment

The image builds this fork on Alpine 3.23 / PHP 8.4 with the PECL IMAP extension.
The Alpine base digest and AWL 0.65 archive checksum are pinned; APK security
updates are applied at build time. CI checks high/critical package CVEs with Trivy.
The active application volumes `b480ccskccs4g4wks8cc8sw8_zpush-data` and
`b480ccskccs4g4wks8cc8sw8_zpush-logs` are reused. Coolify had created these
application-prefixed volumes during the original migration. Do not run the old
Coolify service at the same time as this application, or remove these volumes.
The old service configuration remains available for rollback.

The GitHub push webhook is configured for this repository, and Coolify tracks
`imap-idle`. Builds run tests before starting the replacement container.
The active Coolify application is named **Z-Push IDLE**. The previous service
must remain stopped because it uses the same public domains.

## iPhone

Settings → Apps → Mail → Mail Accounts → Add Account → Microsoft Exchange.
Enter the full Purelymail email address and choose Configure Manually.
Use `zpush.kniff.at` as Server, leave Domain blank, and use the full mailbox
login as Username with its Purelymail password. Enable Mail, Contacts, and Calendars. Enable Push
under Fetch New Data and enable notifications for this account.
Start with a limited Mail Days to Sync window (for example, one week); an
initial unlimited sync needs to enumerate the entire inbox and takes longer.

Production validation passed TLS IMAP login and IDLE, ActiveSync provisioning,
folder discovery, recent-message synchronization, and a complete quiet Ping
heartbeat with the live server logging its IDLE subscription. Actual arrival
latency and sending still need to be confirmed from the iPhone.

Once receiving, sending, and read-state synchronization work, disable Mail on
the old IMAP account to avoid duplicate inboxes. Keep the original account until
the new connection is verified. No DNS/MX migration is needed.

## Contacts and calendars

The combined backend uses Purelymail's authenticated well-known DAV discovery;
no account-specific numeric path or password is committed. It exposes the personal
`default` address book (not Automatically Collected) and available calendars.
New events use the default calendar. Existing iCloud contacts/events are not moved.
Enable Contacts and Calendars on the Exchange account in iPhone Settings. A
backend change changes folder IDs and can cause an initial resync. Keep mail
stored on Purelymail; do not erase accounts to troubleshoot unsynced local edits.
Combined authentication requires all three services to be reachable.

DAV polling runs before the IMAP IDLE wait so email arrivals still wake ActiveSync
without the combined backend's old fixed sleep. Calendar/contact changes use DAV
polling, not IMAP IDLE. TLS certificates and hostnames are verified, requests and
discovery redirects stay on the configured HTTPS origin, and SMTP uses mandatory
implicit TLS on port 465. The legacy raw overview helper is disabled by default
and now also uses verified TLS and rejects command-framing control characters.

AWL source/license: https://gitlab.com/davical-project/awl/-/tree/r0.65 (GPL-2.0-or-later).
The complete source and license are included in the image at /usr/share/awl.

### Purelymail calendar compatibility

Purelymail returned an empty successful calendar-query response when a time-range
filter was present, even for a matching test event. The deployment sets
`CALDAV_SERVER_TIME_RANGE=false`, so calendar items are enumerated without a
server time-window filter. This can sync more calendar history than the phone's
requested window. Absolute DAV hrefs are normalized for item lookup. Failed
calendar reports raise errors rather than being treated as an empty calendar.

Validation on 9 September 2026: the rebuilt image passed mail sync/IDLE,
mandatory-TLS SMTP authentication, and DAV ↔ ActiveSync contact/calendar
read, update, creation and deletion tests. Temporary fixtures were cleaned up.
The current Trivy database reported zero runtime package vulnerability matches.
This is a package scan, not a guarantee of application security.

## iPhone automatic replies

The combined backend now routes ActiveSync `Settings/Oof` to IMAP's ManageSieve
integration. The deployed image enables `IMAP_SIEVE_ENABLED`, connects to
`mailserver.purelymail.com:4190` with verified STARTTLS, and reuses the authenticated
mail credentials in memory. No password or reply worker is stored on the server.
Purelymail executes the vacation filter on incoming mail, including while the phone
is offline. The integration supports on/off, UTC start/end schedules, reply text,
and separate internal (same email domain) / external messages. HTML input is
converted to plain text. Replies are limited to one per sender per seven days by
Sieve, with suppression of mailing lists, automated messages and flagged spam.

In iOS, open Settings → Apps → Mail → Mail Accounts → your Exchange account →
Automatic Reply. For external replies choose all senders. Contacts-only (or distinct
known/unknown external messages) is rejected because this backend does not resolve
Sieve senders against the address book. Unknown-only replies are also rejected.

The existing active script is retained byte-for-byte inside a marked wrapper; the
OOF action follows existing filters, so an earlier `stop` also stops the reply.
Inactive scripts are preserved. Disabling retains the message for later reuse.
An existing separate vacation filter prevents enabling this feature until that
filter is removed in webmail. Manage the marked section through iOS: manual edits
to it are detected and rejected. Replacing the script through webmail removes the
iOS-managed reply. Avoid editing filters in webmail simultaneously with an iOS
settings update: ManageSieve has no atomic compare-and-swap operation.

`IMAP_SIEVE_PRE_TLS_CAPABILITIES` is enabled for Purelymail, whose server emits a
second capability response before the TLS handshake. That response is drained and
discarded; capabilities are then requested again inside verified TLS. This keeps
plaintext data from being mistaken for an authentication or script result.
Generic backend defaults leave both Sieve and this compatibility option off.
Each update is read back before returning success.

Tests: the image build runs `tests/oof/oof.php` for schedules, audience validation,
text escaping, filter preservation, upload failure, protocol parsing and disabling.
`python3 tests/integration/oof_roundtrip.py http://127.0.0.1:18081 zpush-oof-check`
uses ignored `.env.test` credentials with a running test image. It installs only a
future-dated schedule (2030), exercises ActiveSync set/get/disable, and restores the
original Sieve scripts and activation in `finally`. It never sends a test email.

## Primary calendar

The Purelymail calendar named `Kalender` (collection
`2068226B-30F7-4618-A7FD-045AD11A26FD`) is configured as `CALDAV_PERSONAL`.
It is the default calendar for ActiveSync and meeting responses. The unused, empty
`default` calendar collection was removed at the account owner’s request.
