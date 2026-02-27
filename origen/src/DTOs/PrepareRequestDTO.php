<?php

namespace Origen\DTOs;

class PrepareRequestDTO
{
    public array $meta;
    public array $responseTemplates;

    public function __construct(array $data)
    {
        $this->meta = $data['meta'] ?? [];
        $this->responseTemplates = $data['responseTemplates'] ?? [];
    }

    public function action(): string
    {
        $raw = $this->meta['action'] ?? 'prepare-save';
        return str_replace('prepare-', '', $raw);
    }

    public function type(): string
    {
        return $this->meta['type'] ?? 'article';
    }

    public function recordId(): ?string
    {
        return $this->meta['recordId'] ?? null;
    }
}
