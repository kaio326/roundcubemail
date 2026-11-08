# portfolio_sso

Signs members in to Roundcube with their portfolio website session. Roundcube's
login form is never shown.

## How it works

1. The website's reverse proxy (OpenResty, `helpers/webmail_gate.lua` in the
   portfolio repo) checks the website session on every `/webmail/` request. It
   forwards two headers and replaces any the client sent:
   - `X-Portfolio-Mail-User`: the member's mail address
   - `X-Portfolio-Mail-Key`: a shared secret
2. On every request, the `startup` hook checks the key with `hash_equals` and
   returns 403 when it's wrong or missing. With no Roundcube session, or a session
   for a different address, it starts a login.
3. The `authenticate` hook logs in as the header's address. The password is a
   placeholder. With `imap_auth_cid`, `smtp_auth_cid` and `managesieve_auth_cid`
   set to a Dovecot master user, Roundcube authenticates as that master user and
   sends the member's address as the SASL authorization id.
4. If the mail server refuses the login, the member gets a "mail is unavailable"
   page (HTTP 503), never the login form. A posted login form is refused.
5. A main view opened as the top-level page is sent to the dashboard page that
   frames webmail (`portfolio_sso.js`).

## Configuration

See `config.inc.php.dist`. The key file must hold at least 32 bytes; otherwise
every request gets 503.

## Security notes

- Roundcube must only be reachable through the gate. In the portfolio's compose
  file the container publishes no port, and the key stops other containers on the
  network from using it.
- Signing out happens on the website, which also expires Roundcube's cookies.
  A logout request inside webmail just shows mail.

## Licence

GPL-3.0-or-later, like Roundcube.
