# Live protocol checks

Copy `.env.example` to `.env.test` in the repository root and enter a Purelymail
mailbox login. The file is ignored by Git and Docker. Never commit it.

Run `python3 tests/integration/smoke.py http://127.0.0.1:18081` against a local
container. It provisions a synthetic ActiveSync client, discovers folders,
reads recent email and waits through an IDLE heartbeat.

Run `python3 tests/integration/dav_roundtrip.py http://127.0.0.1:18081 --write-fixtures`
to verify contact/calendar reads, updates, creation and deletion through
ActiveSync against Purelymail. This creates uniquely named temporary objects in
the personal addressbook/default calendar and removes them in a finally block.
No invitations or emails are sent. Never run concurrently using the same test
device ID. Remove that synthetic device's server state after testing.
