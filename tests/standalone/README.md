Run from the plugin directory after installing its Composer dependencies:

```sh
php tests/standalone/webhook-settings.php
```

These regression checks exercise the real settings, webhook helpers, API clients,
and signature validation with in-memory WordPress functions and an HTTP transport
that never connects to a server. They require PHP 8.0+ and no PHPUnit or WordPress
test database. They do not exercise the browser or WooCommerce's settings renderer.

Webhook processing checks:

```sh
php tests/standalone/webhook-processing.php
```

These exercise the real webhook handler, signature validation and invoice client
with in-memory WooCommerce orders and mocked request/HTTP boundaries. Each request
runs in a subprocess so WordPress response stubs terminate normally. They cover
verification failures returning 503, successful redelivery, duplicate suppression,
legacy payloads, and HTTP 200 for unmatched orders and verified non-settled invoices.
They do not exercise a real WordPress HTTP endpoint or BTCPay's delivery queue.

API setup checks:

```sh
php tests/standalone/api-setup.php
```

These exercise the actual wizard and callback with mocked WordPress and HTTP
boundaries, covering server-reported key scope, setup state, and preservation of
the existing connection on failure. The wizard check uses a PHP subprocess.

Order return checks:

```sh
php tests/standalone/order-return.php
```

These use the real return handler and WooCommerce session/cart cleanup classes,
with in-memory orders and WordPress response functions. They cover repeat visits,
the 24-hour expiry boundary, guest access after cart cleanup, ownership checks,
and fallback responses. They require a sibling WooCommerce plugin checkout (or
`WC_PLUGIN_DIR` pointing to one), but no Composer dependencies, database or server.
