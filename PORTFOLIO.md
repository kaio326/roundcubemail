# Portfolio fork of Roundcube

This fork runs the webmail of the portfolio website (kaiofernandes.com): Roundcube
inside the website's dashboard, signed in by the website session. Upstream is
https://github.com/roundcube/roundcubemail.

## Branches and tags

- `master` follows upstream and is never changed here.
- `portfolio` is upstream's release tag plus the paths below. It started from
  upstream tag `1.7.5`.
- Release tags are `<upstream version>-p<n>`: `1.7.5-p1` is the first portfolio
  release on top of upstream 1.7.5. Pushing such a tag builds and publishes
  `ghcr.io/kaio326/roundcube:<tag>` with a provenance attestation
  (`.github/workflows/portfolio-image.yml`).

## What this fork adds

Everything lives in new paths, so upstream releases merge without conflicts.

| Path | What it is |
|---|---|
| `plugins/portfolio_sso/` | Signs members in with the website session; Roundcube's login form is never shown (its README explains the flow) |
| `skins/portfolio/` | Elastic with the website's colours, font and logo; no theme toggle, About or Logout buttons |
| `docker/` | The image: Dockerfile, Apache and PHP settings, entrypoint, Roundcube config, dependency locks |
| `.github/workflows/portfolio-image.yml` | Builds and publishes the image on release tags |
| `PORTFOLIO.md` | This file |

**Core patches: none.** If one is ever needed, mark it in the code with a comment
`PORTFOLIO PATCH: <reason>` and list it here.

## The image

- Built from `docker/Dockerfile`, which repeats upstream's release build
  (`Makefile` targets `roundcubemail-git` and `complete`) from locked dependencies:
  `docker/locks/composer.lock` and `docker/locks/package-lock.json`.
- Base images are pinned by digest at the top of the Dockerfile.
- PHP 8.3 with Apache. Roundcube is served at `/webmail/`, its SQLite database is in
  the volume `/var/roundcube/db`, and `/healthz.php` answers `ok`.
- The container needs three secrets (`webmail_sso_key`, at least 32 characters;
  `mail_master_password`; `roundcube_des_key`, exactly 32 characters) and
  `MAIL_HOSTNAME`. Without them it refuses to start. Optional: `MAIL_PRODUCT_NAME`,
  `WEBMAIL_TRUSTED_HOSTS`, and `MAIL_CA_FILE` for development.
- The portfolio's `docker-compose.yml` runs it with no published port, behind the
  website's OpenResty gate.

## Updating to a new upstream release

```sh
git fetch upstream --tags
git switch portfolio
git merge <new upstream tag>                      # e.g. 1.7.6
# Refresh the dependency locks, then review the diff:
docker build -f docker/Dockerfile --target locks --output type=local,dest=docker/locks .
# Bump the version in the Dockerfile's RC_VERSION default if the tag differs.
docker build -f docker/Dockerfile -t roundcube:test .   # and run the tests in the portfolio plan
git commit -am "chore: update to Roundcube <new tag>"
git tag <new tag>-p1 && git push origin portfolio <new tag>-p1
```

Then bump the tag and digest of the `roundcube` service in the portfolio's
`docker-compose.yml`. Security releases are applied within 48 hours.

Base images: update the digests in `docker/Dockerfile` (`docker buildx imagetools
inspect <image>`), rebuild, and release as the next `-p<n>`.

## Licences

- Roundcube, `plugins/portfolio_sso` and `docker/`: GPL-3.0-or-later.
- `skins/portfolio`: CC BY-SA 3.0, like Elastic, which it extends. The logo is the
  website owner's and isn't covered.
