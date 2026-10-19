<?php
declare(strict_types=1);

/**
 * php-micro-router example: a tiny JSON API.
 *
 * Run the built-in server and try it:
 *   php -S 127.0.0.1:8080 examples/router-example.php
 *   curl http://127.0.0.1:8080/users/42
 */

require __DIR__ . '/../src/Router.php';

use AzanUmer\MicroRouter\Request;
use AzanUmer\MicroRouter\Response;
use AzanUmer\MicroRouter\Router;

$router = new Router();

// Log every request (global middleware: first registered = outermost).
$router->middleware(function (Request $req, callable $next): Response {
    $resp = $next($req);
    error_log(sprintf('%s %s -> %d', $req->method(), $req->path(), $resp->status()));
    return $resp;
});

// Tiny auth middleware: protects every /admin/* route.
$requireToken = function (Request $req, callable $next) {
    if (strpos($req->path(), '/admin') === 0 && $req->header('X-Api-Token') !== 'secret') {
        return Response::json(['error' => 'Unauthorized'], 401);
    }
    return $next($req);
};
$router->middleware($requireToken);

$router->get('/', function (Request $req): Response {
    return Response::html('<h1>php-micro-router</h1><p>Try <a href="/users/42">/users/42</a></p>');
});

$router->get('/users/{id}', function (Request $req, array $params): Response {
    return Response::json(['id' => $params['id'], 'name' => 'Demo User ' . $params['id']]);
});

// Custom per-param regex: only lowercase slugs with dashes.
$router->get('/files/{name:[a-z0-9-]+}', function (Request $req, array $params): Response {
    return Response::json(['file' => $params['name'] . '.txt']);
});

$router->post('/echo', function (Request $req): Response {
    return Response::json([
        'received' => $req->json() ?? $req->input('message', '(nothing sent)'),
    ]);
});

$router->group('/admin', function (Router $r) {
    $r->get('/stats', function (Request $req): array {
        return ['visits' => 1234, 'uptime' => '99.9%'];
    });
});

$router->notFound(function (Request $req): Response {
    return Response::json(['error' => 'No route for ' . $req->path()], 404);
});

$router->methodNotAllowed(function (Request $req, array $allowed): Response {
    return Response::json(['error' => 'Use one of: ' . implode(', ', $allowed)], 405);
});

$router->run();
