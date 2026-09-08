FROM kour1er/z-push@sha256:83e6ceac612e2871c03c7afefc34e9952763ff97e46ea268cac59d3b5d5ed23a
USER root
COPY --chown=nginx:nginx src/backend/imap/imap.php src/backend/imap/idle.php src/backend/imap/rawimap.php /usr/share/z-push/backend/imap/
COPY --chown=nginx:nginx src/lib/request/request.php /usr/share/z-push/lib/request/request.php
COPY tests/idle /opt/idle-tests
RUN php81 -l backend/imap/imap.php && php81 -l backend/imap/idle.php && php81 -l lib/request/request.php \
    && php81 /opt/idle-tests/idle.php
USER nginx
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1:8080/ >/dev/null || exit 1
