<?php

namespace Rufinus\Services;

/**
 * HTTP client for communicating with the Central API
 * 
 * Handles all HTTP requests to the central server's API endpoints
 * for the prepare and get operations.
 */
class CentralApiClient
{
    private string $baseUrl;
    private string $siteKey;
    private array $defaultHeaders;

    public function __construct(string $baseUrl, string $siteKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->siteKey = $siteKey;
        $this->defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Site-Key: ' . $siteKey,
            'X-HTX-Version: 1',
        ];
    }

    /**
     * Send a prepare request to the central API
     * 
     * @param array $meta Meta directives
     * @param array $responses Response templates
     * @return array Central API response
     * @throws \Exception If the request fails
     */
    public function prepare(array $meta, array $responses): array
    {
        // Normalize action for Central's validation (prepare-save, prepare-update, etc.)
        if (isset($meta['action']) && !str_starts_with($meta['action'], 'prepare-')) {
            $meta['action'] = 'prepare-' . $meta['action'];
        }

        $payload = [
            'site' => $this->siteKey,
            'meta' => $meta,
            'responseTemplates' => $responses,
        ];

        $response = $this->makeRequest('/api/content/prepare', 'POST', $payload);

        return $response['data'] ?? $response;
    }

    /**
     * Send a get request to the central API
     * 
     * @param array $meta Meta directives
     * @return array Central API response
     * @throws \Exception If the request fails
     */
    public function get(array $meta): array
    {
        $payload = [
            'meta' => $meta,
        ];

        return $this->makeRequest('/api/content/get', 'POST', $payload);
    }

    /**
     * Make an HTTP request to the central API
     * 
     * @param string $endpoint The API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     * @return array Response data
     * @throws \Exception If the request fails
     */
    private function makeRequest(string $endpoint, string $method = 'GET', array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $options = [
            'http' => [
                'method' => $method,
                'header' => $this->defaultHeaders,
                'content' => $method === 'POST' ? json_encode($data) : null,
                'ignore_errors' => true, // To capture HTTP errors
            ],
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception("Failed to make request to {$url}");
        }

        // Parse response headers
        $headers = $http_response_header ?? [];
        $statusCode = $this->getStatusCode($headers);

        if ($statusCode >= 400) {
            throw new \Exception("HTTP {$statusCode} error: " . $response);
        }

        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        return $decodedResponse;
    }

    /**
     * Extract HTTP status code from response headers
     * 
     * @param array $headers Response headers
     * @return int HTTP status code
     */
    private function getStatusCode(array $headers): int
    {
        if (empty($headers)) {
            return 500;
        }

        $statusLine = $headers[0];
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
            return (int)$matches[1];
        }

        return 500;
    }

    /**
     * Set additional headers for requests
     * 
     * @param array $headers Additional headers
     * @return void
     */
    public function setHeaders(array $headers): void
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);
    }

    /**
     * Get the current base URL
     * 
     * @return string Base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the current site key
     * 
     * @return string Site key
     */
    public function getSiteKey(): string
    {
        return $this->siteKey;
    }
}
