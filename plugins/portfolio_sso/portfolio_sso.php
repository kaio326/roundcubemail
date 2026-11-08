<?php

/**
 * Portfolio single sign-on.
 *
 * Signs the member in to webmail with their website session. The website's
 * OpenResty gate (helpers/webmail_gate.lua in the portfolio repo) checks the
 * website session on every request and forwards two headers:
 *
 *   X-Portfolio-Mail-User  the member's mail address
 *   X-Portfolio-Mail-Key   a shared secret that proves the request came through the gate
 *
 * Roundcube then logs in to IMAP, SMTP and ManageSieve as that address through
 * the Dovecot master user configured in imap_auth_cid / smtp_auth_cid /
 * managesieve_auth_cid. No password is ever typed or stored, and Roundcube's
 * login form is never shown.
 *
 * @license GNU GPLv3+
 * @author Kaio Fernandes
 */
class portfolio_sso extends rcube_plugin
{
    public $task = '.*';

    /** Minimum secret length in bytes; the portfolio generates 48 characters */
    private const MIN_KEY_BYTES = 32;

    /** Request parameters never carried over to the page shown after a login */
    private const DROPPED_PARAMS = ['_token', '_err', '_remote', '_user', '_pass', '_url', '_unlock'];

    /** @var string Shared secret read from portfolio_sso_key_file */
    private $key = '';

    /** @var string|null Address from X-Portfolio-Mail-User, once validated */
    private $user;

    /** @var bool True when startup() switched this request to the login action */
    private $sso_login = false;

    /** @var array<string, string> Query of the original request, restored after the login */
    private $return_query = [];

    #[\Override]
    public function init()
    {
        $this->load_config();
        $this->add_texts('localization/');

        $rcmail = rcmail::get_instance();
        $key_file = (string) $rcmail->config->get('portfolio_sso_key_file', '/run/secrets/webmail_sso_key');
        $key = is_readable($key_file) ? trim((string) file_get_contents($key_file)) : '';

        if (strlen($key) < self::MIN_KEY_BYTES) {
            rcube::raise_error("portfolio_sso: key file {$key_file} is missing or shorter than "
                . self::MIN_KEY_BYTES . ' bytes; refusing all requests', true, false);
            $this->send_status_page(503, $this->gettext('mail_unavailable'));
        }

        $this->key = $key;

        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('login_failed', [$this, 'login_failed']);
        $this->add_hook('unauthenticated', [$this, 'unauthenticated']);

        if ($rcmail->output && $rcmail->output->type == 'html') {
            $rcmail->output->set_env('portfolio_sso_frame_url',
                (string) $rcmail->config->get('portfolio_sso_frame_url', '/userdashboard/email'));
            $this->include_script('portfolio_sso.js');
        }
    }

    /**
     * Runs on every request before Roundcube checks the session.
     *
     * @param array $args Hook arguments (task, action)
     *
     * @return array Hook arguments
     */
    public function startup($args)
    {
        $rcmail = rcmail::get_instance();
        $user = strtolower(trim((string) ($_SERVER['HTTP_X_PORTFOLIO_MAIL_USER'] ?? '')));
        $key = (string) ($_SERVER['HTTP_X_PORTFOLIO_MAIL_KEY'] ?? '');

        if (!hash_equals($this->key, $key) || !filter_var($user, \FILTER_VALIDATE_EMAIL)) {
            $this->send_status_page(403, 'Forbidden.');
        }

        $this->user = $user;

        // A different member signed in to the website on this browser: end the old mail session first.
        if (!empty($_SESSION['user_id']) && strcasecmp((string) ($_SESSION['username'] ?? ''), $user) !== 0) {
            $rcmail->kill_session();
        }

        // Signing out of mail alone isn't possible: the website owns the session.
        // The skin has no logout button; a crafted logout request just shows mail.
        if ($args['task'] === 'logout') {
            $args['task'] = 'mail';
            $args['action'] = '';
        }

        if (empty($_SESSION['user_id'])) {
            if (!$rcmail->output->ajax_call && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $this->return_query = $this->return_query_from($_GET);
            }

            $this->sso_login = true;
            $args['task'] = 'login';
            $args['action'] = 'login';
        }

        return $args;
    }

    /**
     * Supplies the credentials for the login that startup() requested.
     *
     * The password is a placeholder that never reaches a server: with *_auth_cid set,
     * Roundcube authenticates as the master user and sends $args['user'] as the
     * SASL authorization id.
     *
     * @param array $args Hook arguments
     *
     * @return array Hook arguments
     */
    public function authenticate($args)
    {
        if (!$this->sso_login) {
            // Only startup() may start a login; a posted login form is refused.
            $args['abort'] = true;

            return $args;
        }

        $args['user'] = $this->user;
        $args['pass'] = 'portfolio-sso';
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    /**
     * Sends the member on to the page they asked for before the login.
     *
     * @param array $args Redirect query
     *
     * @return array Redirect query
     */
    public function login_after($args)
    {
        return $this->return_query ?: $args;
    }

    /**
     * The mail server refused the login. Show an error page, never the login form.
     *
     * @param array $args Hook arguments (code, host, user)
     */
    public function login_failed($args)
    {
        rcube::raise_error("portfolio_sso: login failed for {$args['user']} (code {$args['code']})", true, false);
        $this->send_status_page(503, $this->gettext('mail_unavailable'), true);
    }

    /**
     * Roundcube found no valid session after startup, for example because the session
     * expired between two requests. Start over instead of showing the login form:
     * the next request has no session, so startup() logs in again.
     *
     * @param array $args Hook arguments
     *
     * @return array Hook arguments
     */
    public function unauthenticated($args)
    {
        if ($this->sso_login) {
            // A login was attempted in this request and login_failed() didn't end it.
            $this->send_status_page(503, $this->gettext('mail_unavailable'), true);
        }

        $query = $_SERVER['REQUEST_METHOD'] === 'GET' ? $this->return_query_from($_GET) : [];
        rcmail::get_instance()->output->redirect($query ?: ['_task' => 'mail'], 0);

        return $args;
    }

    /**
     * Keeps the request's Roundcube parameters (names starting with "_"), so a login
     * in the middle of a session returns to the same view, including framed previews.
     *
     * @param array $query Request query
     *
     * @return array<string, string>
     */
    private function return_query_from(array $query)
    {
        $result = [];

        foreach ($query as $name => $value) {
            if (is_string($name) && $name !== '' && $name[0] === '_' && is_string($value)
                && !in_array($name, self::DROPPED_PARAMS, true)
            ) {
                $result[$name] = $value;
            }
        }

        if (isset($result['_task']) && in_array($result['_task'], ['login', 'logout'], true)) {
            unset($result['_task'], $result['_action']);
        }

        return $result;
    }

    /**
     * Ends the request with a minimal page. Used for every refusal, so Roundcube's
     * login form is never rendered.
     *
     * @param int    $code      HTTP status
     * @param string $message   Plain-text message
     * @param bool   $with_link Add a link back to the dashboard
     */
    private function send_status_page($code, $message, $with_link = false)
    {
        $rcmail = rcmail::get_instance();
        $is_ajax = !empty($_SERVER['HTTP_X_ROUNDCUBE_REQUEST']) || ($rcmail->output && $rcmail->output->ajax_call);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($code);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if ($is_ajax) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo $message;
            exit;
        }

        $link = '';
        if ($with_link) {
            $url = (string) $rcmail->config->get('portfolio_sso_dashboard_url', '/userdashboard');
            $link = '<p><a href="' . htmlspecialchars($url, \ENT_QUOTES) . '" target="_top">'
                . htmlspecialchars($this->gettext('back_to_dashboard'), \ENT_QUOTES) . '</a></p>';
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($message, \ENT_QUOTES) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:3rem 1rem;text-align:center;color:#333}'
            . '@media (prefers-color-scheme:dark){body{background:#2a2a2a;color:#e5e5e5}a{color:#d4a843}}</style>'
            . '</head><body><p>' . htmlspecialchars($message, \ENT_QUOTES) . '</p>' . $link . '</body></html>';
        exit;
    }
}
