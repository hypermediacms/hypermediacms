<?php

namespace Rufinus\Runtime;

class ApiProxy
{
    private string $centralUrl;
    private string $siteKey;

    public function __construct(string $centralUrl, string $siteKey)
    {
        $this->centralUrl = rtrim($centralUrl, '/');
        $this->siteKey = $siteKey;
    }

    /**
     * Forward a request to the Central API and return the response.
     *
     * @param string $method HTTP method
     * @param string $path API path (e.g. /api/auth/login)
     * @param array $requestHeaders Browser request headers
     * @param string $body Request body
     * @param string|null $authToken Auth token from cookie
     * @return Response Edge response
     */
    public function forward(
        string $method,
        string $path,
        array $requestHeaders,
        string $body,
        ?string $authToken = null
    ): Response {
        $url = $this->centralUrl . $path;

        $headers = [
            'X-Site-Key: ' . $this->siteKey,
            'X-HTX-Version: 1',
        ];

        // Pass through Content-Type
        $contentType = $this->getHeader($requestHeaders, 'Content-Type');
        if ($contentType) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        // Pass through HX-* request headers
        foreach ($requestHeaders as $key => $value) {
            if (str_starts_with(strtolower($key), 'hx-')) {
                $headers[] = $key . ': ' . $value;
            }
        }

        // Inject auth token if present
        if ($authToken) {
            $headers[] = 'Authorization: Bearer ' . $authToken;
        }

        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($options);
        $responseBody = file_get_contents($url, false, $context);

        if ($responseBody === false) {
            return new Response(502, json_encode(['message' => 'Failed to reach Central API.']), [
                'Content-Type' => 'application/json',
            ]);
        }

        // Parse response status and headers
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->parseStatusCode($responseHeaders);
        $parsedHeaders = $this->parseHeaders($responseHeaders);

        // Build edge response with passthrough headers
        $edgeHeaders = [];

        if (isset($parsedHeaders['content-type'])) {
            $edgeHeaders['Content-Type'] = $parsedHeaders['content-type'];
        }

        // Pass through HX-* and X-HTX-* response headers
        foreach ($parsedHeaders as $key => $value) {
            if (str_starts_with($key, 'hx-') || str_starts_with($key, 'x-htx-')) {
                $edgeHeaders[$key] = $value;
            }
        }

        return new Response($statusCode, $responseBody, $edgeHeaders);
    }

    /**
     * Get a header value from the request headers (case-insensitive).
     */
    private function getHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $lower) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Extract HTTP status code from response headers.
     */
    private function parseStatusCode(array $headers): int
    {
        if (empty($headers)) {
            return 502;
        }

        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers[0], $matches)) {
            return (int) $matches[1];
        }

        return 502;
    }

    /**
     * Parse response headers into a lowercase-keyed associative array.
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $parsed = [];
        foreach ($rawHeaders as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($key))] = trim($value);
            }
        }
        return $parsed;
    }
}
