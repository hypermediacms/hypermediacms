<?php

namespace Rufinus\Runtime;

use Rufinus\EdgeHTX;

class RequestHandler
{
    private Router $router;
    private LayoutResolver $layoutResolver;
    private AuthGuard $authGuard;

    public function __construct()
    {
        $this->router = new Router();
        $this->layoutResolver = new LayoutResolver();
        $this->authGuard = new AuthGuard();
    }

    /**
     * Handle a full HTTP request lifecycle.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $uri Request URI
     * @param array $headers Request headers (from getallheaders())
     * @param string $siteRoot Path to the site's pages directory
     * @param string $centralUrl Central server URL
     * @param string $siteKey API key for the site
     * @return Response|null Null means the request is for a static file (let web server handle)
     */
    public function handle(
        string $method,
        string $uri,
        array $headers,
        string $siteRoot,
        string $centralUrl,
        string $siteKey
    ): ?Response {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // 1. Static files — let web server handle
        if (str_starts_with(trim($path, '/'), 'public/')) {
            return null;
        }

        // 2. API proxy — forward /api/* to Central
        if (str_starts_with(trim($path, '/'), 'api/')) {
            return $this->handleApiProxy($method, $path, $headers, $centralUrl, $siteKey);
        }

        // 3. Auth guard — protect /admin/* (except /admin/login)
        if ($this->authGuard->requiresAuth($path) && ! $this->authGuard->getToken()) {
            return $this->authGuard->redirectToLogin();
        }

        // 4. Route + DSL execution
        $match = $this->router->resolve($uri, $siteRoot);

        if ($match === null) {
            return $this->handleError($siteRoot, 404);
        }

        // Load the .htx file
        $dsl = file_get_contents($match->filePath);
        if ($dsl === false) {
            return $this->handleError($siteRoot, 500);
        }

        // Inject dynamic params into DSL as meta directives
        $dsl = $this->injectParams($dsl, $match->params);

        // Static page detection: if no data-requiring meta directives, render without Central
        if ($this->isStaticPage($dsl)) {
            $htx = new EdgeHTX($centralUrl, $siteKey);
            $html = $htx->getParser()->extractTemplate($dsl) ?: $dsl;

            // Hydrate route params into admin static pages (e.g. /admin/types/[ct])
            if (!empty($match->params) && str_starts_with(trim($path, '/'), 'admin/')) {
                foreach ($match->params as $name => $value) {
                    $html = str_replace('__' . $name . '__', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
                }
            }

            $isHxRequest = $this->isHtmxRequest($headers);
            $html = $this->layoutResolver->wrap($html, $match->filePath, $match->siteRoot, skipRoot: $isHxRequest);

            return new Response(200, $html, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-HTX-Version' => '1',
            ]);
        }

        // Execute via EdgeHTX
        $htx = new EdgeHTX($centralUrl, $siteKey);

        // Inject auth header if session cookie exists
        $authToken = $this->authGuard->getToken();
        if ($authToken) {
            $htx->setHeaders(['Authorization: Bearer ' . $authToken]);
        }

        try {
            $html = $this->executeDsl($htx, $dsl);
        } catch (\Exception $e) {
            return $this->handleError($siteRoot, 500);
        }

        // Apply layouts. For HTMX fragments, skip only the root layout.
        $isHxRequest = $this->isHtmxRequest($headers);
        $html = $this->layoutResolver->wrap($html, $match->filePath, $match->siteRoot, skipRoot: $isHxRequest);

        return new Response(200, $html, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-HTX-Version' => '1',
        ]);
    }

    /**
     * Handle /api/* requests by proxying to Central.
     */
    private function handleApiProxy(
        string $method,
        string $path,
        array $headers,
        string $centralUrl,
        string $siteKey
    ): Response {
        $proxy = new ApiProxy($centralUrl, $siteKey);
        $authToken = $this->authGuard->getToken();
        $body = file_get_contents('php://input') ?: '';

        $response = $proxy->forward($method, $path, $headers, $body, $authToken);

        // Special handling for auth login — extract token, set cookie
        if ($path === '/api/auth/login' && $response->status === 200) {
            $data = json_decode($response->body, true);
            if (isset($data['token'])) {
                $this->authGuard->setAuthCookie($response, $data['token']);
                $response->headers['HX-Redirect'] = '/admin';

                // Strip token from response body
                unset($data['token']);
                $response->body = json_encode($data);
            }
        }

        // Special handling for auth logout — clear cookie
        if ($path === '/api/auth/logout') {
            $this->authGuard->clearAuthCookie($response);
        }

        // Any 401 from Core — clear stale cookie
        if ($response->status === 401 && $authToken) {
            $this->authGuard->clearAuthCookie($response);
        }

        return $response;
    }

    /**
     * Check if DSL has no data-requiring meta directives (static page).
     */
    private function isStaticPage(string $dsl): bool
    {
        $dataDirectives = ['type', 'action', 'recordId', 'slug', 'id', 'status', 'howmany', 'order', 'where', 'fields'];

        foreach ($dataDirectives as $directive) {
            if (preg_match('/<htx:' . preg_quote($directive, '/') . '>/i', $dsl)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine the operation type from DSL and execute accordingly.
     */
    private function executeDsl(EdgeHTX $htx, string $dsl): string
    {
        $parsed = $htx->parse($dsl);
        $action = $parsed['meta']['action'] ?? null;

        if ($action === null) {
            // No action = get content (display)
            return $htx->getContent($dsl);
        }

        $action = strtolower($action);

        if (in_array($action, ['save', 'prepare-save', 'update', 'prepare-update'])) {
            return $htx->setContent($dsl);
        }

        if (in_array($action, ['delete', 'prepare-delete'])) {
            return $htx->deleteContent($dsl);
        }

        // Default to get
        return $htx->getContent($dsl);
    }

    /**
     * Inject route params as meta directives into the DSL string.
     * Prepends <htx:recordId> for 'slug'/'id' params, and <htx:param> for others.
     */
    private function injectParams(string $dsl, array $params): string
    {
        if (empty($params)) {
            return $dsl;
        }

        $injected = '';

        foreach ($params as $name => $value) {
            $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            // Inject as a named meta directive so central can filter on it
            $injected .= "<htx:{$name}>{$safeValue}</htx:{$name}>\n";
            // Only inject recordId for numeric IDs (not slugs)
            if ($name === 'id' && is_numeric($value)) {
                $injected .= "<htx:recordId>{$safeValue}</htx:recordId>\n";
            }
        }

        return $injected . $dsl;
    }

    /**
     * Check if the request is an HTMX fragment request.
     */
    private function isHtmxRequest(array $headers): bool
    {
        // Headers from getallheaders() may have mixed case
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'hx-request' && $value === 'true') {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle error responses, optionally using _error.htx.
     */
    private function handleError(string $siteRoot, int $statusCode): Response
    {
        $errorFile = rtrim($siteRoot, '/') . '/_error.htx';

        if (file_exists($errorFile)) {
            $content = file_get_contents($errorFile);
            if ($content !== false) {
                // Replace error placeholders
                $content = str_replace('__status_code__', (string)$statusCode, $content);
                return new Response($statusCode, $content, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);
            }
        }

        $defaultMessages = [
            404 => 'Page Not Found',
            500 => 'Internal Server Error',
        ];
        $message = $defaultMessages[$statusCode] ?? 'Error';

        return new Response($statusCode, "<h1>{$statusCode} {$message}</h1>", [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
