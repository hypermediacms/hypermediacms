<?php

namespace Rufinus\Runtime;

class Response
{
    public int $status;
    public string $body;
    public array $headers;
    public array $cookies = [];

    public function __construct(int $status = 200, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * Add a Set-Cookie to the response.
     */
    public function withCookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'maxAge' => $maxAge,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
        return $this;
    }
}
