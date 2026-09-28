Run from the plugin directory after installing its Composer dependencies:

```sh
php tests/standalone/webhook-settings.php
```

These regression checks exercise the real settings, webhook helpers, API clients,
and signature validation with in-memory WordPress functions and an HTTP transport
that never connects to a server. They require PHP 8.0+ and no PHPUnit or WordPress
test database. They do not exercise the browser or WooCommerce's settings renderer.

Order return checks:

```sh
php tests/standalone/order-return.php
```

These use the real return handler and WooCommerce session/cart cleanup classes,
with in-memory orders and WordPress response functions. They cover repeat visits,
the 24-hour expiry boundary, guest access after cart cleanup, ownership checks,
and fallback responses. They require a sibling WooCommerce plugin checkout (or
`WC_PLUGIN_DIR` pointing to one), but no Composer dependencies, database or server.
