# Translations

Drop compiled `aisooq-connector-<locale>.mo` files here (with their `.po`
sources). `load_plugin_textdomain()` in `aisooq-connector.php` reads this
directory.

Regenerate the template after changing any translatable string:

```sh
wp i18n make-pot . languages/aisooq-connector.pot --exclude=vendor,dist,tests,node_modules
```
