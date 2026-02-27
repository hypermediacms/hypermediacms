<?php

namespace Origen\Services;

use Origen\Http\Response;

class TemplateHydratorService
{
    public function hydrate(string $template, array $values, ?array $allowlist = null): string
    {
        foreach ($values as $key => $value) {
            if ($allowlist !== null && !in_array($key, $allowlist)) {
                continue;
            }
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $template = str_replace("__{$key}__", $escaped, $template);
        }
        return $template;
    }

    public function resolveResponseMode(string $mode, array $templates, array $values): Response
    {
        switch ($mode) {
            case 'redirect':
                $url = $templates['redirect'] ?? '/';
                $url = $this->hydrate($url, $values);
                return (new Response('', 200, ['Content-Type' => 'text/html']))
                    ->header('HX-Redirect', $url);

            case 'none':
                return new Response('', 204);

            case 'error':
                $html = $this->hydrate(
                    $templates['error'] ?? '<div class="error">An error occurred.</div>',
                    $values
                );
                return Response::html($html, 422);

            case 'oob':
                $html = $this->hydrate($templates['oob'] ?? '', $values);
                return Response::html($html);

            case 'partial':
                $html = $this->hydrate($templates['partial'] ?? '', $values);
                return Response::html($html);

            case 'success':
            default:
                $html = $this->hydrate(
                    $templates['success'] ?? '<div>Operation completed.</div>',
                    $values
                );
                return Response::html($html);
        }
    }
}
