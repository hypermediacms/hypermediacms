<?php

namespace Origen\DTOs;

class PrepareRequestDTO
{
    public array $meta;
    public array $responseTemplates;
    private array $rawData;

    public function __construct(array $data)
    {
        $this->rawData = $data;
        $this->meta = $data['meta'] ?? [];
        $this->responseTemplates = $data['responseTemplates'] ?? [];
    }

    public function action(): string
    {
        $raw = $this->meta['action'] ?? $this->rawData['action'] ?? 'prepare-save';
        return str_replace('prepare-', '', $raw);
    }

    public function type(): string
    {
        return $this->meta['type'] ?? $this->rawData['type'] ?? 'article';
    }

    public function recordId(): ?string
    {
        return $this->meta['recordId'] ?? $this->rawData['recordId'] ?? null;
    }
}
