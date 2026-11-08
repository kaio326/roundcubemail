# syntax=docker/dockerfile:1.7
#
# Roundcube for the portfolio website: upstream Roundcube from this fork's
# `portfolio` branch, plus plugins/portfolio_sso and skins/portfolio.
# See PORTFOLIO.md for what differs from upstream and how releases are made.
#
# Build, from the repository root:
#   docker build -f docker/Dockerfile -t roundcube:dev .
#
# Refresh the dependency locks after merging an upstream release (PORTFOLIO.md):
#   docker build -f docker/Dockerfile --target locks --output type=local,dest=docker/locks .

ARG PHP_IMAGE=php:8.3-apache-trixie@sha256:a800bbd2c0aa322702259ed475556b2cbc311076a8d3c4bba9bfe96838745472
ARG NODE_IMAGE=node:22-trixie-slim@sha256:be1e2a3dc2c4fd68cdfadad2a696a280e0d2749098bee509230174d72fb768c6
ARG COMPOSER_IMAGE=composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0

FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS node

# ---------------------------------------------------------------------------
# PHP with the extensions Roundcube uses. Shared by the build and runtime
# stages, so Composer checks platform requirements against the real runtime.
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS php-base

RUN set -eux; \
    saved="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype-dev libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" exif gd intl opcache zip; \
    # Keep only the shared libraries the new extensions link against (the official images' pattern).
    apt-mark auto '.*' > /dev/null; \
    [ -z "$saved" ] || apt-mark manual $saved > /dev/null; \
    find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qx gd; php -m | grep -qx intl; php -m | grep -qx zip; php -m | grep -qx exif; \
    php -m | grep -qx pdo_sqlite

# ---------------------------------------------------------------------------
# Tools for building: Composer, Node.js and what bin/install-jsdeps.sh needs.
# ---------------------------------------------------------------------------
FROM php-base AS tools

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git make unzip; \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN set -eux; \
    ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx; \
    node --version; npm --version; composer --version
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    npm_config_update_notifier=false \
    npm_config_fund=false \
    npm_config_audit=false

WORKDIR /src
COPY . .

# Upstream's release build strips the development setup from composer.json before
# resolving (Makefile, target "complete"). The lock in docker/locks/ matches that.
RUN set -eux; \
    composer config --unset require-dev; \
    composer config --unset autoload-dev; \
    composer config --unset repositories

# ---------------------------------------------------------------------------
# Target "locks": resolve dependencies fresh and write the lock files out.
# ---------------------------------------------------------------------------
FROM tools AS locks-resolve
RUN set -eux; \
    rm -f composer.lock package-lock.json; \
    composer update --no-install --no-interaction --no-progress; \
    npm install --package-lock-only --include=dev --no-audit --no-fund

FROM scratch AS locks
COPY --from=locks-resolve /src/composer.lock /src/package-lock.json /

# ---------------------------------------------------------------------------
# The release build (Makefile targets "roundcubemail-git" and "complete"), from
# locked dependencies.
# ---------------------------------------------------------------------------
FROM tools AS build
ARG RC_VERSION=1.7.5

# JavaScript and CSS. Elastic is compiled with the portfolio's colours and font
# through its documented customisation files, _variables.less and _styles.less
# (skins/elastic/README.md); the sources live in skins/portfolio/styles/.
RUN set -eux; \
    cp docker/locks/package-lock.json package-lock.json; \
    npm ci --include=dev --omit=optional; \
    bin/jsshrink.sh; \
    bin/updatecss.sh; \
    bin/cssshrink.sh; \
    cp skins/portfolio/styles/_variables.less skins/portfolio/styles/_styles.less skins/elastic/styles/; \
    (cd skins/elastic && make css); \
    grep -q '#806c00' skins/elastic/styles/styles.min.css

# PHP dependencies and the third-party JavaScript libraries (checksummed by jsdeps.json).
RUN set -eux; \
    cp docker/locks/composer.lock composer.lock; \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
    composer check-platform-reqs --no-dev; \
    bin/install-jsdeps.sh --force; \
    bin/jsshrink.sh program/js/publickey.js; \
    bin/jsshrink.sh plugins/managesieve/codemirror/lib/codemirror.js

# The markdown_editor plugin ships its own locked build.
RUN set -eux; \
    cd plugins/markdown_editor; \
    npm ci; \
    npm run build

# Version stamp and release cleanup, as upstream's release build does.
RUN set -eux; \
    sed -i "s/'1.7-git'/'${RC_VERSION}'/" program/include/iniset.php program/lib/Roundcube/bootstrap.php; \
    grep -q "'${RC_VERSION}'" program/include/iniset.php; \
    rm -rf tests plugins/*/tests .git* .tx* .ci* .editorconfig* .eslintrc.js .php-cs-fixer*.php phpstan.neon.dist \
        package.json package-lock.json node_modules jsdeps.json bin/install-jsdeps.sh \
        bin/transifexpull.sh bin/package2composer.sh temp/js_cache \
        installer public_html/installer.php docker .github; \
    (cd plugins/markdown_editor && rm -rf node_modules package*.json rollup.config.*js build.sh javascript *.less); \
    find . -name '.gitignore' -delete; \
    rm -rf vendor/pear/*/tests vendor/*/*/.git* vendor/*/*/.travis* vendor/*/*/phpunit.xml.dist \
        vendor/pear/net_ldap2/doc vendor/bacon/bacon-qr-code/test vendor/dasprid/enum/test; \
    chmod -R a+rX .

# ---------------------------------------------------------------------------
# Runtime: Apache + mod_php, Roundcube at /webmail/.
# ---------------------------------------------------------------------------
FROM php-base AS runtime

LABEL org.opencontainers.image.title="Roundcube for the portfolio website" \
      org.opencontainers.image.description="Roundcube webmail with website single sign-on (portfolio_sso) and the portfolio skin" \
      org.opencontainers.image.source="https://github.com/kaio326/roundcubemail" \
      org.opencontainers.image.licenses="GPL-3.0-or-later"

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-roundcube.ini
COPY docker/apache-webmail.conf /etc/apache2/sites-available/webmail.conf
RUN set -eux; \
    a2enmod rewrite headers expires; \
    a2dissite 000-default; \
    a2ensite webmail; \
    echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername

COPY --from=build /src /var/www/roundcube
COPY docker/config.inc.php /var/www/roundcube/config/config.inc.php
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/roundcube-entrypoint

# The document root holds only Roundcube's public directory (as /webmail) and the health check.
RUN set -eux; \
    rm -rf /var/www/html; \
    mkdir -p /var/www/html /var/roundcube/db; \
    ln -s /var/www/roundcube/public_html /var/www/html/webmail; \
    printf '<?php\nheader("Cache-Control: no-store");\necho "ok";\n' > /var/www/html/healthz.php; \
    chown -R www-data:www-data /var/www/roundcube/temp /var/www/roundcube/logs /var/roundcube/db; \
    chmod 0640 /var/www/roundcube/config/config.inc.php; \
    chgrp www-data /var/www/roundcube/config/config.inc.php; \
    php -l /var/www/roundcube/config/config.inc.php; \
    php -l /var/www/roundcube/plugins/portfolio_sso/portfolio_sso.php

VOLUME /var/roundcube/db
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/healthz.php") === "ok" ? 0 : 1);'

ENTRYPOINT ["roundcube-entrypoint"]
CMD ["apache2-foreground"]
