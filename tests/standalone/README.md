Run from the plugin directory after installing its Composer dependencies:

```sh
php tests/standalone/webhook-settings.php
```

These regression checks exercise the real settings, webhook helpers, API clients,
and signature validation with in-memory WordPress functions and an HTTP transport
that never connects to a server. They require PHP 8.0+ and no PHPUnit or WordPress
test database. They do not exercise the browser or WooCommerce's settings renderer.
