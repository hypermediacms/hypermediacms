<?php

namespace Origen\Http\Controllers;

use Origen\DTOs\ExecuteRequestDTO;
use Origen\DTOs\PrepareRequestDTO;
use Origen\DTOs\PrepareResponseDTO;
use Origen\Exceptions\SlugConflictException;
use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Services\ActionTokenService;
use Origen\Services\AuthTokenService;
use Origen\Services\ContentService;
use Origen\Services\MarkdownService;
use Origen\Services\RelationshipResolver;
use Origen\Services\SchemaService;
use Origen\Services\TemplateHydratorService;
use Origen\Services\WorkflowService;
use Origen\Storage\Database\ContentRepository;

class ContentController
{
    public function __construct(
        private ActionTokenService $tokenService,
        private ContentService $contentService,
        private SchemaService $schemaService,
        private TemplateHydratorService $hydrator,
        private WorkflowService $workflowService,
        private AuthTokenService $authTokenService,
        private RelationshipResolver $relationshipResolver,
        private MarkdownService $markdownService,
        private ContentRepository $contentRepo,
    ) {}

    /**
     * Handle PREPARE phase — issue action token and return form contract.
     */
    public function prepare(Request $request): Response
    {
        $dto = new PrepareRequestDTO($request->input());
        $site = $request->input('current_site');

        // Look up existing record if updating/deleting
        $record = $dto->recordId()
            ? $this->contentService->findForTenant($dto->recordId(), $site)
            : null;

        // Issue secure action token
        $tokenResult = $this->tokenService->issue(
            'site:' . $site['id'],
            (int) $site['id'],
            $dto->action(),
            $dto->recordId()
        );

        $resp = new PrepareResponseDTO();
        $resp->endpoint = '/api/content/' . $dto->action();

        $payloadData = [
            'htx-recordId' => $dto->recordId(),
            'htx-context' => $dto->action(),
            'htx-token' => $tokenResult['token'],
        ];
        // Include all response templates in payload so execute can use them
        // (e.g., success template with %%placeholders%% for chained mutations)
        if (!empty($dto->responseTemplates)) {
            $payloadData['responseTemplates'] = $dto->responseTemplates;
        }
        $resp->payload = json_encode($payloadData);

        $currentStatus = $record['status'] ?? 'draft';
        $resp->values = [
            'id' => $record['id'] ?? null,
            'title' => $record['title'] ?? '',
            'slug' => $record['slug'] ?? '',
            'body' => $record['body'] ?? '',
            'status' => $currentStatus,
            'type' => $dto->type(),
        ];

        // Add custom field values for hydration
        if ($record && !empty($record['fieldValues'])) {
            foreach ($record['fieldValues'] as $fv) {
                $resp->values[$fv['field_name']] = $fv['field_value'] ?? '';
            }
        }

        // Pre-select status dropdown
        foreach (['draft', 'published', 'review', 'archived'] as $s) {
            $resp->values['selected_' . $s] = $currentStatus === $s ? 'selected' : '';
        }

        // Build status options HTML
        $userRole = $this->extractUserRole($request);
        $contentType = $record['type'] ?? $dto->type();
        $statusLabels = ['draft' => 'Draft', 'review' => 'Review', 'published' => 'Published', 'archived' => 'Archived'];

        $options = '<option value="' . $currentStatus . '" selected>' . ($statusLabels[$currentStatus] ?? ucfirst($currentStatus)) . '</option>';

        foreach ($statusLabels as $status => $label) {
            if ($status === $currentStatus) continue;
            if ($this->workflowService->canTransition($site, $contentType, $currentStatus, $status, $userRole)) {
                $options .= '<option value="' . $status . '">' . $label . '</option>';
            }
        }

        // For new content with no workflow definitions, show all statuses
        if (!$record && $options === '<option value="draft" selected>Draft</option>') {
            $options = '';
            foreach ($statusLabels as $status => $label) {
                $sel = $status === 'draft' ? ' selected' : '';
                $options .= '<option value="' . $status . '"' . $sel . '>' . $label . '</option>';
            }
        }

        $resp->values['status_options'] = $options;

        // Build type selector options
        $types = $this->schemaService->listTypes($site);
        $currentType = $record['type'] ?? $dto->type() ?? 'article';
        $typeOptions = '';
        foreach ($types as $t) {
            $sel = $t === $currentType ? ' selected' : '';
            $typeOptions .= '<option value="' . $this->e($t) . '"' . $sel . '>' . $this->e(ucfirst($t)) . '</option>';
        }
        if (!in_array($currentType, $types)) {
            $typeOptions = '<option value="' . $this->e($currentType) . '" selected>' . $this->e(ucfirst($currentType)) . '</option>' . $typeOptions;
        }
        $resp->values['type_options'] = $typeOptions;

        // Build custom field inputs
        $schema = $this->schemaService->getSchemaForType($site, $currentType);
        $customFieldsHtml = '';
        foreach ($schema as $field) {
            $value = '';
            if ($record && !empty($record['fieldValues'])) {
                foreach ($record['fieldValues'] as $fv) {
                    if ($fv['field_name'] === $field['field_name']) {
                        $value = $fv['field_value'] ?? '';
                        break;
                    }
                }
            }
            $customFieldsHtml .= $this->renderFieldInput($field, $value, $site);
        }
        $resp->values['custom_fields_html'] = $customFieldsHtml;

        $resp->labels = [
            'title' => 'Title',
            'body' => 'Body',
            'status' => 'Status',
        ];
        $resp->responseTemplates = $dto->responseTemplates;

        return Response::json(['data' => $resp->toArray()]);
    }

    /**
     * Handle GET content — query and return content rows.
     */
    public function get(Request $request): Response
    {
        $site = $request->input('current_site');
        $meta = $request->input('meta', []);

        $rows = $this->contentService->query($site, $meta);

        // Resolve relationships
        $resolved = $this->relationshipResolver->resolveForCollection($site, $rows);

        // Build response rows
        $responseRows = [];
        foreach ($rows as $row) {
            $responseRow = [
                'id' => $row['id'],
                'type' => $row['type'],
                'slug' => $row['slug'],
                'title' => $row['title'],
                'body' => $row['body'],
                'body_html' => $this->markdownService->toHtml($row['body'] ?? ''),
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];

            // Add field values
            if (!empty($row['fieldValues'])) {
                foreach ($row['fieldValues'] as $fv) {
                    $responseRow[$fv['field_name']] = $fv['field_value'];
                }
            }

            // Overlay resolved relationships
            if (isset($resolved[$row['id']])) {
                foreach ($resolved[$row['id']] as $fieldName => $resolvedData) {
                    $responseRow[$fieldName] = $resolvedData;
                }
            }

            $responseRows[] = $responseRow;
        }

        return Response::json(['rows' => $responseRows]);
    }

    /**
     * Handle SAVE — create new content.
     */
    public function save(Request $request): Response
    {
        $dto = new ExecuteRequestDTO($request->all());
        $site = $request->input('current_site');

        try {
            $content = $this->contentService->create($site, $dto->formData);
        } catch (SlugConflictException $e) {
            return Response::json(['message' => $e->getMessage()], 422);
        }

        $values = ['title' => $content['title'], 'id' => $content['id']];
        $templates = $this->resolveResponseTemplates($request);
        $mode = !empty($templates['redirect']) ? 'redirect' : 'success';

        return $this->hydrator->resolveResponseMode($mode, array_merge(
            ['success' => '<div>Content created!</div>'],
            $templates
        ), $values);
    }

    /**
     * Handle UPDATE — modify existing content.
     */
    public function update(Request $request): Response
    {
        $dto = new ExecuteRequestDTO($request->all());
        $site = $request->input('current_site');

        $content = $this->contentService->findForTenant($dto->recordId, $site);

        if (!$content) {
            $templates = $this->resolveResponseTemplates($request);
            return $this->hydrator->resolveResponseMode('error', array_merge(
                ['error' => '<div>Content not found!</div>'],
                $templates
            ), ['id' => $dto->recordId]);
        }

        try {
            $updated = $this->contentService->update($content, $site, $dto->formData);
        } catch (SlugConflictException $e) {
            return Response::json(['message' => $e->getMessage()], 422);
        }

        // Build values with all fields for template hydration
        // (needed for %%placeholder%% in chained mutation responses)
        $values = [
            'id' => $updated['id'],
            'title' => $updated['title'],
            'slug' => $updated['slug'],
            'body' => $updated['body'] ?? '',
            'status' => $updated['status'],
            'type' => $updated['type'],
        ];
        
        // Re-fetch to get updated custom field values
        $refreshed = $this->contentService->findForTenant($updated['id'], $site);
        if ($refreshed && !empty($refreshed['fieldValues'])) {
            foreach ($refreshed['fieldValues'] as $fv) {
                $values[$fv['field_name']] = $fv['field_value'] ?? '';
            }
        }

        $templates = $this->resolveResponseTemplates($request);
        $mode = !empty($templates['redirect']) ? 'redirect' : 'success';

        return $this->hydrator->resolveResponseMode($mode, array_merge(
            ['success' => '<div>Content updated!</div>'],
            $templates
        ), $values);
    }

    /**
     * Handle DELETE — remove content.
     */
    public function delete(Request $request): Response
    {
        $dto = new ExecuteRequestDTO($request->all());
        $site = $request->input('current_site');

        $content = $this->contentService->findForTenant($dto->recordId, $site);

        if (!$content) {
            $templates = $this->resolveResponseTemplates($request);
            return $this->hydrator->resolveResponseMode('error', array_merge(
                ['error' => '<div>Content not found!</div>'],
                $templates
            ), ['id' => $dto->recordId]);
        }

        $title = $content['title'];
        $contentId = $content['id'];
        $this->contentService->delete($content, $site);

        $values = ['title' => $title, 'id' => $contentId];
        $templates = $this->resolveResponseTemplates($request);
        $mode = !empty($templates['redirect']) ? 'redirect' : 'success';

        return $this->hydrator->resolveResponseMode($mode, array_merge(
            ['success' => '<div>Content deleted!</div>'],
            $templates
        ), $values);
    }

    private function resolveResponseTemplates(Request $request): array
    {
        $templates = $request->input('responseTemplates', []);
        if (is_string($templates)) {
            $templates = json_decode($templates, true) ?? [];
        }
        return $templates;
    }

    private function extractUserRole(Request $request): string
    {
        $authHeader = $request->header('authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            try {
                $claims = $this->authTokenService->validate(substr($authHeader, 7));
                return $claims['role'] ?? 'viewer';
            } catch (\Exception $e) {
                // Fall through to default
            }
        }
        return 'viewer';
    }

    /**
     * Render a custom field input based on schema.
     */
    private function renderFieldInput(array $field, string $value, array $site): string
    {
        $html = '<div class="form-group">';
        $html .= '<label for="' . $this->e($field['field_name']) . '">' . $this->e(ucfirst(str_replace('_', ' ', $field['field_name']))) . '</label>';

        if ($field['field_type'] === 'textarea') {
            $html .= '<textarea id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '">' . $this->e($value) . '</textarea>';
        } elseif ($field['field_type'] === 'boolean') {
            $checked = $value ? ' checked' : '';
            $html .= '<label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '" value="1"' . $checked . '> Yes</label>';
        } elseif ($field['field_type'] === 'relationship') {
            $constraints = $field['constraints'] ?? [];
            $targetType = $constraints['target_type'] ?? '';
            $cardinality = $constraints['cardinality'] ?? 'one';
            $options = $this->contentRepo->findByType((int) $site['id'], $targetType);

            if ($cardinality === 'one') {
                $html .= '<select id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '">';
                $html .= '<option value="">-- Select --</option>';
                foreach ($options as $opt) {
                    $sel = ((string) $opt['id'] === (string) $value) ? ' selected' : '';
                    $html .= '<option value="' . $this->e($opt['id']) . '"' . $sel . '>' . $this->e($opt['title']) . '</option>';
                }
                $html .= '</select>';
            } else {
                $storedIds = json_decode($value, true) ?? [];
                $html .= '<input type="hidden" name="' . $this->e($field['field_name']) . '" value="">';
                foreach ($options as $opt) {
                    $checked = in_array($opt['id'], $storedIds) ? ' checked' : '';
                    $html .= '<label style="display:flex;align-items:center;gap:0.5rem;">'
                        . '<input type="checkbox" name="' . $this->e($field['field_name']) . '[]" value="' . $this->e($opt['id']) . '"' . $checked . '> '
                        . $this->e($opt['title']) . '</label>';
                }
            }
        } else {
            $inputType = match($field['field_type']) { 'number' => 'number', 'date' => 'date', default => 'text' };
            $html .= '<input type="' . $inputType . '" id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '" value="' . $this->e($value) . '">';
        }

        $html .= '</div>';
        return $html;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
