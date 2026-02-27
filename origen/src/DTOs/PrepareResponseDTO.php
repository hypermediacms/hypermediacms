<?php

namespace Origen\DTOs;

class PrepareResponseDTO
{
    public string $endpoint;
    public string $payload;
    public array $values = [];
    public array $labels = [];
    public array $responseTemplates = [];

    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'payload' => $this->payload,
            'values' => $this->values,
            'labels' => $this->labels,
            'responseTemplates' => $this->responseTemplates,
        ];
    }
}
