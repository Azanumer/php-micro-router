<?php
declare(strict_types=1);

/**
 * php-micro-router — a dependency-free HTTP router for plain-PHP projects.
 *
 *   $router = new Router();
 *   $router->get('/users/{id}', function (Request $req, array $params) {
 *       return Response::json(['id' => $params['id']]);
 *   });
 *   $router->run();
 */

namespace AzanUmer\MicroRouter;

/* ------------------------------------------------------------------------- *
 * Request
 * ------------------------------------------------------------------------- */

/**
 * Thin wrapper around the incoming HTTP request.
 */
class Request
{
    /** @var array<string,string> */
    private array $server;
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $post;

    public function __construct(?array $server = null, ?array $get = null, ?array $post = null)
    {
        $this->server = $server ?? $_SERVER;
        $this->query  = $get ?? $_GET;
        $this->post   = $post ?? $_POST;
    }

    /** HTTP method, uppercased. Supports _method override for HTML forms. */
    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($this->post['_method'])) {
            $override = strtoupper((string) $this->post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    /** Request path without query string, e.g. "/users/42". */
    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        return $path === '' ? '/' : $path;
    }

    /** One query-string value (or $default). */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /** One POST/body value, falling back to query (or $default). */
    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        return $this->query[$key] ?? $default;
    }

    /** One request header, case-insensitive (or $default). */
    public function header(string $name, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (array_key_exists($key, $this->server)) {
            return $this->server[$key];
        }
        if (strtolower($name) === 'content-type' && isset($this->server['CONTENT_TYPE'])) {
            return $this->server['CONTENT_TYPE'];
        }
        return $default;
    }

    /** Decode a JSON request body; returns null when it is not valid JSON. */
    public function json(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

/* ------------------------------------------------------------------------- *
 * Response
 * ------------------------------------------------------------------------- */

/**
 * Immutable-ish HTTP response value object.
 */
class Response
{
    private int $status;
    /** @var array<string,string> */
    private array $headers;
    private string $body;

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json($data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '{"error":"JSON encoding failed"}';
            $status  = 500;
        }
        return new self($encoded, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /** Add/overwrite a header; returns a clone so handlers stay pure. */
    public function with(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function status(): int { return $this->status; }
    public function body(): string { return $this->body; }
    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }

    /** Emit the response to the client. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}

/* ------------------------------------------------------------------------- *
 * Router
 * ------------------------------------------------------------------------- */

/**
 * Minimal router: method + path patterns, named params, middleware, groups.
 *
 * Patterns: "/users/{id}" matches "/users/42" with $params['id'] = '42'.
 * Custom regex per param: "/files/{name:[a-z0-9.-]+}".
 */
class Router
{
    /** @var array<int,array{methods:array<string>,pattern:string,regex:string,handler:callable}> */
    private array $routes = [];
    /** @var array<int,callable> */
    private array $middleware = [];
    /** @var callable|null */
    private $notFound = null;
    /** @var callable|null */
    private $methodNotAllowed = null;
    private string $prefix = '';

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function get(string $pattern, callable $handler): self
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function post(string $pattern, callable $handler): self
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function put(string $pattern, callable $handler): self
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function patch(string $pattern, callable $handler): self
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function delete(string $pattern, callable $handler): self
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function any(string $pattern, callable $handler): self
    {
        return $this->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], $pattern, $handler);
    }

    /** @param callable $handler fn(Request $req, array $params): Response|string */
    public function add(array $methods, string $pattern, callable $handler): self
    {
        $pattern = $this->prefix . $pattern;
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'pattern' => $pattern,
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * Register global middleware. Signature: fn(Request $req, callable $next): Response|string
     */
    public function middleware(callable $fn): self
    {
        $this->middleware[] = $fn;
        return $this;
    }

    /** Group routes under a path prefix. */
    public function group(string $prefix, callable $define): self
    {
        $previous = $this->prefix;
        $this->prefix = $previous . rtrim($prefix, '/');
        $define($this);
        $this->prefix = $previous;
        return $this;
    }

    /** Custom 404 handler: fn(Request $req): Response|string */
    public function notFound(callable $handler): self
    {
        $this->notFound = $handler;
        return $this;
    }

    /** Custom 405 handler: fn(Request $req, array $allowed): Response|string */
    public function methodNotAllowed(callable $handler): self
    {
        $this->methodNotAllowed = $handler;
        return $this;
    }

    /**
     * Compile "/users/{id}" and "/files/{name:[a-z0-9.-]+}" into a PCRE pattern.
     * Literal segments are preg_quote()d; placeholders become named groups
     * with the user's regex preserved verbatim.
     */
    private function compile(string $pattern): string
    {
        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^{}]+)?\})/',
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}$/', $part, $m)) {
                $inner = $m[2] ?? '[^/]+';
                $regex .= '(?P<' . $m[1] . '>' . $inner . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#';
    }

    /** Match a request; returns [handler, params, allowedMethods] or nulls. */
    private function match(Request $request): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $m)) {
                if (in_array($request->method(), $route['methods'], true)) {
                    $params = [];
                    foreach ($m as $k => $v) {
                        if (is_string($k)) {
                            $params[$k] = $v;
                        }
                    }
                    return [$route['handler'], $params, []];
                }
                $allowed = array_merge($allowed, $route['methods']);
            }
        }
        return [null, [], array_values(array_unique($allowed))];
    }

    /** Normalize a handler's return into a Response. */
    private function toResponse($value): Response
    {
        if ($value instanceof Response) {
            return $value;
        }
        if (is_array($value)) {
            return Response::json($value);
        }
        return Response::html((string) $value);
    }

    /** Dispatch and return the Response (does not emit it). */
    public function dispatch(?Request $request = null): Response
    {
        $request = $request ?? new Request();
        [$handler, $params, $allowed] = $this->match($request);

        $final = function (Request $req) use ($handler, $params, $allowed): Response {
            if ($handler !== null) {
                return $this->toResponse($handler($req, $params));
            }
            if (!empty($allowed)) {
                $h = $this->methodNotAllowed;
                $resp = $h !== null
                    ? $this->toResponse($h($req, $allowed))
                    : Response::text('Method Not Allowed', 405);
                return $resp->with('Allow', implode(', ', $allowed));
            }
            $h = $this->notFound;
            return $h !== null
                ? $this->toResponse($h($req))
                : Response::text('Not Found', 404);
        };

        // Wrap in middleware (first registered = outermost).
        $next = $final;
        foreach (array_reverse($this->middleware) as $mw) {
            $inner = $next;
            $next = function (Request $req) use ($mw, $inner): Response {
                return $this->toResponse($mw($req, $inner));
            };
        }

        return $next($request);
    }

    /** Dispatch the current request and send the response. */
    public function run(): void
    {
        $this->dispatch()->send();
    }
}
