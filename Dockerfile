FROM alpine:3.23@sha256:fd791d74b68913cbb027c6546007b3f0d3bc45125f797758156952bc2d6daf40
RUN apk upgrade --no-cache && apk add --no-cache ca-certificates curl nginx supervisor \
    php84 php84-fpm php84-pecl-imap php84-curl php84-dom php84-iconv php84-intl \
    php84-mbstring php84-openssl php84-pcntl php84-simplexml php84-sysvsem \
    php84-sysvshm php84-xml php84-xmlreader php84-xmlwriter php84-xsl php84-session php84-pdo \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    && mkdir -p /run/nginx /var/lib/z-push /var/log/z-push /usr/share/z-push
RUN curl -fsSL https://gitlab.com/davical-project/awl/-/archive/r0.65/awl-r0.65.tar.gz -o /tmp/awl.tar.gz \
    && echo '76feb587a6682580687d651f496bc9aa0d0858e409fc782c81735f3642cc1c59  /tmp/awl.tar.gz' | sha256sum -c - \
    && mkdir -p /usr/share/awl && tar -xzf /tmp/awl.tar.gz --strip-components=1 -C /usr/share/awl \
    && rm /tmp/awl.tar.gz
WORKDIR /usr/share/z-push
COPY src/ ./
COPY docker/ /opt/z-push/
COPY tests/ /opt/z-push-tests/
RUN cp /opt/z-push/nginx.conf /etc/nginx/nginx.conf \
    && cp /opt/z-push/php-fpm.conf /etc/php84/php-fpm.d/www.conf \
    && cp /opt/z-push/php.ini /etc/php84/conf.d/99-zpush.ini \
    && php /opt/z-push/configure.php \
    && find backend/combined backend/imap backend/caldav backend/carddav include autodiscover -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null \
    && php /opt/z-push-tests/idle/idle.php \
    && php /opt/z-push-tests/security/dav.php \
    && php /opt/z-push-tests/security/combined.php \
    && php /opt/z-push-tests/security/smtp.php \
    && php /opt/z-push-tests/calendar/bridge.php \
    && php /opt/z-push-tests/oof/oof.php \
    && php /opt/z-push-tests/oof/tls.php \
    && php -r 'require "vendor/autoload.php"; require "config.php"; ZPush::CheckConfig(); ZPush::GetBackend(); class_exists("CalDAVClient");'  \
    && chown -R nginx:nginx /run/nginx /var/lib/nginx /var/log/nginx /var/lib/z-push /var/log/z-push
USER nginx
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1:8080/health >/dev/null || exit 1
CMD ["/usr/bin/supervisord", "-c", "/opt/z-push/supervisord.conf"]
