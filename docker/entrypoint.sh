#!/bin/sh
# Roundcube for the portfolio: check the configuration, hand the secrets to
# Apache's user, create or update the database, then start Apache.
# Fails closed: without its secrets it doesn't start.
set -eu

fail() {
    echo "roundcube: $*" >&2
    exit 1
}

SECRETS="webmail_sso_key mail_master_password roundcube_des_key"
RUNTIME_SECRETS=/run/roundcube

for secret in $SECRETS; do
    [ -s "/run/secrets/$secret" ] || fail "missing or empty secret /run/secrets/$secret"
done

key_len=$(tr -d '\r\n' < /run/secrets/roundcube_des_key | wc -c)
[ "$key_len" -eq 32 ] || fail "roundcube_des_key must be exactly 32 characters (AES-256-CBC), found $key_len"

sso_len=$(tr -d '\r\n' < /run/secrets/webmail_sso_key | wc -c)
[ "$sso_len" -ge 32 ] || fail "webmail_sso_key must be at least 32 characters, found $sso_len"

[ -n "${MAIL_HOSTNAME:-}" ] || fail "MAIL_HOSTNAME is not set"

# Compose mounts file secrets with the host file's owner and mode (0600), which
# Apache's www-data workers can't read. Copy them for root:www-data, mode 0640.
umask 077
install -d -o root -g www-data -m 0750 "$RUNTIME_SECRETS"
for secret in $SECRETS; do
    install -o root -g www-data -m 0640 "/run/secrets/$secret" "$RUNTIME_SECRETS/$secret"
done
umask 022

install -d -o www-data -g www-data -m 0750 /var/roundcube/db
su -s /bin/sh www-data -c \
    'php /var/www/roundcube/bin/initdb.sh --dir=/var/www/roundcube/SQL --update' \
    || fail "database initialisation or update failed"

exec "$@"
