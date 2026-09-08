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

The image extends the existing kour1er/Z-Push 2.7.6 image at a pinned digest and
retains its PHP 8.1 runtime. Runtime modernization is a separate follow-up.
The existing named volumes are external and are reused. Do not run the old
Coolify service at the same time as this application, or remove these volumes.
The old service configuration remains available for rollback.

The GitHub push webhook is configured for this repository, and Coolify tracks
`imap-idle`. Builds run tests before starting the replacement container.

## iPhone

Settings → Apps → Mail → Mail Accounts → Add Account → Microsoft Exchange.
Enter the full Purelymail email address and choose Configure Manually.
Use `zpush.kniff.at` as Server, leave Domain blank, and use the full mailbox
login as Username with its Purelymail password. Enable Mail only. Enable Push
under Fetch New Data and enable notifications for this account.

Once receiving, sending, and read-state synchronization work, disable Mail on
the old IMAP account to avoid duplicate inboxes. Keep the original account until
the new connection is verified. No DNS/MX migration is needed.
