<?php

/*
 * Roundcube configuration for the portfolio image.
 *
 * Every value here is fixed by the image or comes from the environment and the
 * secrets the entrypoint copies to /run/roundcube/. Defaults for anything not
 * set here are in config/defaults.inc.php.
 *
 * Environment:
 *   MAIL_HOSTNAME          mail server name; also the name on its TLS certificate (required)
 *   MAIL_PRODUCT_NAME      shown in the page title (default "Mail")
 *   WEBMAIL_TRUSTED_HOSTS  comma-separated Host header patterns (default: any)
 *   MAIL_CA_FILE           development only: a CA file to trust for the mail server
 */

$secret = static function (string $name): string {
    $value = @file_get_contents("/run/roundcube/{$name}");
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Roundcube secret {$name} is missing");
    }

    return trim($value);
};

$host = getenv('MAIL_HOSTNAME') ?: '';
if ($host === '') {
    throw new RuntimeException('MAIL_HOSTNAME is not set');
}
$master = $secret('mail_master_password');

// Storage: SQLite in the roundcube_db volume (contacts, identities, preferences, cache).
$config['db_dsnw'] = 'sqlite:////var/roundcube/db/roundcube.sqlite?mode=0640';

// Mail server. Every connection authenticates as the Dovecot master user "sso" and
// passes the member's address as the authorization id (plugins/portfolio_sso).
$config['imap_host'] = "ssl://{$host}:993";
$config['imap_auth_type'] = 'PLAIN';
$config['imap_auth_cid'] = 'sso';
$config['imap_auth_pw'] = $master;

$config['smtp_host'] = "ssl://{$host}:465";
$config['smtp_auth_type'] = 'PLAIN';
$config['smtp_auth_cid'] = 'sso';
$config['smtp_auth_pw'] = $master;
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';

$config['managesieve_host'] = "tls://{$host}:4190";
$config['managesieve_auth_type'] = 'PLAIN';
$config['managesieve_auth_cid'] = 'sso';
$config['managesieve_auth_pw'] = $master;
$config['managesieve_vacation'] = 1;
$config['managesieve_forward'] = 1;

// Development only: trust the local CA that signed the test certificate.
if ($ca = getenv('MAIL_CA_FILE')) {
    $tls = ['ssl' => ['cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true]];
    $config['imap_conn_options'] = $tls;
    $config['smtp_conn_options'] = $tls;
    $config['managesieve_conn_options'] = $tls;
}

// Sign-in through the website only.
$config['plugins'] = [
    'portfolio_sso', // first, so its startup hook runs before any other plugin's
    'archive',
    'zipdownload',
    'managesieve',
    'markasjunk',
    'newmail_notifier',
    'vcard_attachments',
    'attachment_reminder',
    'identity_select',
    'hide_blockquote',
];
$config['portfolio_sso_key_file'] = '/run/roundcube/webmail_sso_key';
$config['portfolio_sso_frame_url'] = '/userdashboard/email';
$config['portfolio_sso_dashboard_url'] = '/userdashboard';
$config['login_lc'] = 2;
$config['auto_create_user'] = true;

// Session: cookies only for /webmail/, never sent cross-site.
$config['des_key'] = $secret('roundcube_des_key');
$config['cipher_method'] = 'AES-256-CBC';
$config['session_path'] = '/webmail/';
$config['session_samesite'] = 'Strict';
$config['session_lifetime'] = 60;
$config['use_https'] = true;

// Behind the website's OpenResty on the compose network.
$config['proxy_whitelist'] = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
$config['trusted_host_patterns'] = array_values(array_filter(array_map('trim',
    explode(',', getenv('WEBMAIL_TRUSTED_HOSTS') ?: ''))));
$config['x_frame_options'] = 'sameorigin';

// Look and behaviour.
$config['skin'] = 'portfolio';
$config['skins_allowed'] = ['portfolio'];
$config['product_name'] = getenv('MAIL_PRODUCT_NAME') ?: 'Mail';
$config['support_url'] = '';
$config['htmleditor'] = 1;
$config['archive_mbox'] = 'Archive';
$config['max_message_size'] = '25M';

// Postfix's SPOOF_PROTECTION decides which From addresses a member may use.
$config['identities_level'] = 0;

// Operations.
$config['enable_installer'] = false;
$config['log_driver'] = 'stdout';
$config['temp_dir'] = '/var/www/roundcube/temp/';
