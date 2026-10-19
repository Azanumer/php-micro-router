# php-micro-router

A dependency-free HTTP router for plain-PHP projects — no framework, no Composer required. One file (`src/Router.php`), PHP 7.4+.

## Quick start

```php
<?php
require 'src/Router.php';

use AzanUmer\MicroRouter\Request;
use AzanUmer\MicroRouter\Response;
use AzanUmer\MicroRouter\Router;

$router = new Router();

$router->get('/users/{id}', function (Request $req, array $params) {
    return Response::json(['id' => $params['id']]);
});

$router->run(); // dispatches the current request and sends the response
```

See `examples/router-example.php` for a runnable demo (middleware, groups, custom 404/405):

```bash
php -S 127.0.0.1:8080 examples/router-example.php
curl http://127.0.0.1:8080/users/42
```

## Features

- **Route verbs** — `get()`, `post()`, `put()`, `patch()`, `delete()`, `any()`, plus generic `add($methods, ...)`
- **Named params** — `/users/{id}` → `$params['id']`, with custom regex per param: `/files/{name:[a-z0-9-]+}`
- **Middleware** — `fn(Request $req, callable $next)`; first registered = outermost
- **Route groups** — `group('/admin', fn)` prefixes every route inside
- **Request helpers** — `method()` (with `_method` form override), `path()`, `query()`, `input()`, `header()`, `json()` (decoded request body)
- **Response helpers** — `Response::text()`, `::html()`, `::json()`, `::redirect()`; `with()` adds a header immutably
- **Sensible errors** — 404 for unknown paths, 405 with an `Allow` header for wrong verbs; both overridable via `notFound()` / `methodNotAllowed()`
- Handlers may return a `Response`, an array (sent as JSON), or a string (sent as HTML)

## Files

- `src/Router.php` — `Request`, `Response`, `Router` (the whole library)
- `examples/router-example.php` — tiny JSON API demo

MIT licensed.
