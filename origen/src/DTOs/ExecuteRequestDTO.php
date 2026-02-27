<?php

namespace Origen\DTOs;

class ExecuteRequestDTO
{
    public ?string $recordId;
    public string $context;
    public string $token;
    public array $formData = [];

    public function __construct(array $data)
    {
        $this->recordId = $data['htx-recordId'] ?? null;
        $this->context = $data['htx-context'] ?? '';
        $this->token = $data['htx-token'] ?? '';

        // Exclude system fields from form data (responseTemplates is used for output, not storage)
        $reserved = ['htx-recordId', 'htx-context', 'htx-token', 'current_site', 'htx_claims', 'responseTemplates'];
        $this->formData = array_diff_key($data, array_flip($reserved));
    }
}
