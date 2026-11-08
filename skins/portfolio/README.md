# Portfolio skin

Roundcube's **Elastic** skin with the portfolio website's colours, font and logo,
for webmail framed inside the website dashboard.

- `meta.json` extends Elastic, so every template and asset not listed here comes
  from `skins/elastic/`.
- `styles/_variables.less` sets Elastic's colour variables from the website's
  tokens (`static/css/style.css` in the portfolio repo). `styles/_styles.less`
  sets the website's font.
- **How the styles are applied:** Elastic supports these two customisation files
  in its own `styles/` folder (`skins/elastic/README.md`). The image build copies
  them there and compiles Elastic (`docker/Dockerfile`), so the git tree under
  `skins/elastic/` stays exactly upstream's. Roundcube links the stylesheet of the
  skin a template comes from, so a separate stylesheet in this child skin would
  never be loaded.
- `templates/includes/menu.html` is Elastic's task menu without the theme toggle,
  About and Logout buttons. The website owns the theme (it writes Elastic's
  `colorMode` cookie) and the sign-out.
- `images/logo.png` is the website's icon.

To preview locally without Docker, copy the two files into `skins/elastic/styles/`
(git ignores them there) and run `make css-elastic` from the repository root.

## Credits and licence

Based on the Elastic skin by Aleksander Machniak and The Roundcube Dev Team.
Like Elastic, this skin is licensed under the Creative Commons
Attribution-ShareAlike 3.0 licence: http://creativecommons.org/licenses/by-sa/3.0/

The logo is © Kaio Fernandes and is not covered by that licence.
