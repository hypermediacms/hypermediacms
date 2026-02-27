<?php

namespace Origen\Http\Controllers;

use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Services\SchemaService;
use Origen\Storage\Database\ContentRepository;
use Origen\Sync\WriteThrough;

class ContentTypeController
{
    public function __construct(
        private SchemaService $schemaService,
        private ContentRepository $contentRepo,
        private WriteThrough $writeThrough,
    ) {}

    /**
     * List all content types.
     */
    public function index(Request $request): Response
    {
        $site = $request->input('current_site');
        $types = $this->schemaService->listTypes($site);
        sort($types);

        if ($request->header('hx-request')) {
            $html = '<h1>Content Types</h1>'
                . '<p class="text-muted text-sm" style="margin-bottom: 1.5rem;">Manage your content type schemas.</p>'
                . '<div style="margin-bottom: 1rem;">'
                . '<a href="/admin/types/new" hx-get="/admin/types/new" hx-target=".admin-main" hx-push-url="true" class="btn btn-primary">+ New Type</a>'
                . '</div>'
                . '<div class="admin-card">';

            if (empty($types)) {
                $html .= '<p class="text-muted">No content types defined yet.</p>';
            } else {
                $html .= '<table class="admin-table"><thead><tr>'
                    . '<th>Type</th><th>Fields</th><th></th>'
                    . '</tr></thead><tbody>';
                foreach ($types as $type) {
                    $fields = $this->schemaService->getSchemaForType($site, $type);
                    $fieldCount = count($fields);
                    $html .= '<tr>'
                        . '<td><strong>' . $this->e(ucfirst($type)) . '</strong> <span class="text-muted text-sm">(' . $this->e($type) . ')</span></td>'
                        . '<td>' . $fieldCount . ' custom field' . ($fieldCount !== 1 ? 's' : '') . '</td>'
                        . '<td><a href="/admin/types/' . $this->e($type) . '" hx-get="/admin/types/' . $this->e($type) . '" hx-target=".admin-main" hx-push-url="true" class="btn btn-secondary">Edit</a></td>'
                        . '</tr>';
                }
                $html .= '</tbody></table>';
            }

            $html .= '</div>';
            return Response::html($html);
        }

        return Response::json(['types' => $types]);
    }

    /**
     * Show a content type's schema.
     */
    public function show(Request $request): Response
    {
        $type = $request->getAttribute('type');
        $site = $request->input('current_site');
        $fields = $this->schemaService->getSchemaForType($site, $type);
        $typeSettings = $this->schemaService->getTypeSettings((int) $site['id'], $type);
        $currentMode = $typeSettings['storage_mode'] ?? 'content';
        $retentionDays = $typeSettings['retention_days'] ?? '';

        if ($request->header('hx-request')) {
            $html = '<h1>Edit Type: ' . $this->e(ucfirst($type)) . '</h1>'
                . '<p class="text-muted text-sm" style="margin-bottom: 1.5rem;">Manage custom fields for the <strong>' . $this->e($type) . '</strong> content type.</p>'
                . '<div class="admin-card">'
                . '<form hx-post="/api/content-types" hx-target=".admin-main" hx-swap="innerHTML">'
                . '<input type="hidden" name="content_type" value="' . $this->e($type) . '">';

            // Storage mode settings
            $html .= '<div style="margin-bottom:1.5rem;padding:1rem;border:1px solid #e2e8f0;border-radius:6px;">'
                . '<label style="font-weight:600;margin-bottom:0.5rem;display:block;">Storage Mode</label>'
                . '<select name="storage_mode" onchange="document.getElementById(\'retention-row\').style.display=this.value===\'ephemeral\'?\'flex\':\'none\'" style="padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;margin-bottom:0.75rem;">';
            foreach (['content' => 'Content (SQLite + Markdown)', 'data' => 'Data (SQLite only)', 'ephemeral' => 'Ephemeral (SQLite + auto-purge)'] as $val => $label) {
                $sel = $val === $currentMode ? ' selected' : '';
                $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
            }
            $html .= '</select>'
                . '<div id="retention-row" style="display:' . ($currentMode === 'ephemeral' ? 'flex' : 'none') . ';align-items:center;gap:0.5rem;">'
                . '<label>Retention days:</label>'
                . '<input type="number" name="retention_days" value="' . $this->e((string) $retentionDays) . '" min="0" style="width:80px;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">'
                . '</div></div>';

            $html .= '<div id="fields-container">';

            foreach ($fields as $field) {
                $required = !empty($field['constraints']['required']);
                $html .= $this->renderFieldRow($field['field_name'], $field['field_type'], $required);
            }

            $html .= '</div>'
                . '<button type="button" onclick="addFieldRow()" class="btn btn-secondary" style="margin-bottom:1rem;">+ Add Field</button>'
                . '<div style="display:flex;gap:0.75rem;margin-top:1.5rem;">'
                . '<button type="submit" class="btn btn-primary">Save Fields</button>'
                . '<a href="/admin/types" hx-get="/admin/types" hx-target=".admin-main" hx-push-url="true" class="btn btn-secondary">Cancel</a>'
                . '<button type="button" hx-delete="/api/content-types/' . $this->e($type) . '" hx-target=".admin-main" hx-swap="innerHTML" hx-confirm="Delete all field schemas for ' . $this->e($type) . '? Content will not be deleted." class="btn btn-secondary" style="margin-left:auto;color:#ef4444;">Delete Type Schema</button>'
                . '</div>'
                . '</form></div>'
                . $this->fieldBuilderScript();

            return Response::html($html);
        }

        return Response::json([
            'type' => $type,
            'fields' => $fields,
            'storage_mode' => $currentMode,
            'retention_days' => $retentionDays,
        ]);
    }

    /**
     * Create or update a content type's schema.
     */
    public function store(Request $request): Response
    {
        $site = $request->input('current_site');
        $contentType = $request->input('content_type');

        if (empty($contentType)) {
            return Response::json(['message' => 'Content type name is required.'], 422);
        }

        $contentType = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($contentType)));
        $contentType = trim($contentType, '_');

        $fields = $request->input('fields', []);
        $validFields = [];
        foreach ($fields as $field) {
            if (!empty($field['field_name']) && !empty($field['field_type'])) {
                $fieldName = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($field['field_name'])));
                $constraints = isset($field['required']) ? ['required' => true] : [];

                if ($field['field_type'] === 'relationship') {
                    $constraints['target_type'] = $field['target_type'] ?? '';
                    $constraints['cardinality'] = $field['cardinality'] ?? 'one';

                    $relErrors = $this->schemaService->validateRelationshipConstraints([
                        'constraints' => $constraints,
                    ]);
                    if (!empty($relErrors)) {
                        return Response::json([
                            'message' => 'Invalid relationship field "' . $fieldName . '": ' . implode(' ', $relErrors),
                        ], 422);
                    }
                }

                $validFields[] = [
                    'field_name' => trim($fieldName, '_'),
                    'field_type' => $field['field_type'],
                    'constraints' => $constraints,
                    'ui_hints' => [],
                ];
            }
        }

        $this->writeThrough->saveSchema($site['slug'], (int) $site['id'], $contentType, $validFields);

        // Save storage mode settings
        $storageMode = $request->input('storage_mode', 'content');
        if (!in_array($storageMode, ['content', 'data', 'ephemeral'], true)) {
            $storageMode = 'content';
        }
        $retentionDays = $request->input('retention_days');
        $retentionDays = ($storageMode === 'ephemeral' && is_numeric($retentionDays)) ? (int) $retentionDays : null;
        $this->schemaService->saveTypeSettings((int) $site['id'], $contentType, $storageMode, $retentionDays);

        if ($request->header('hx-request')) {
            return $this->index($request);
        }

        return Response::json(['message' => 'Content type saved.', 'type' => $contentType]);
    }

    /**
     * Return custom field form inputs HTML for a content type.
     */
    public function fieldsHtml(Request $request): Response
    {
        $type = $request->getAttribute('type');
        $site = $request->input('current_site');
        $schema = $this->schemaService->getSchemaForType($site, $type);

        $html = '';
        foreach ($schema as $field) {
            $html .= '<div class="form-group">';
            $html .= '<label for="' . $this->e($field['field_name']) . '">' . $this->e(ucfirst(str_replace('_', ' ', $field['field_name']))) . '</label>';

            if ($field['field_type'] === 'textarea') {
                $html .= '<textarea id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '"></textarea>';
            } elseif ($field['field_type'] === 'boolean') {
                $html .= '<label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '" value="1"> Yes</label>';
            } elseif ($field['field_type'] === 'relationship') {
                $constraints = $field['constraints'] ?? [];
                $targetType = $constraints['target_type'] ?? '';
                $cardinality = $constraints['cardinality'] ?? 'one';
                $options = $this->contentRepo->findByType((int) $site['id'], $targetType);

                if ($cardinality === 'one') {
                    $html .= '<select id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '">';
                    $html .= '<option value="">-- Select --</option>';
                    foreach ($options as $opt) {
                        $html .= '<option value="' . $this->e($opt['id']) . '">' . $this->e($opt['title']) . '</option>';
                    }
                    $html .= '</select>';
                } else {
                    $html .= '<input type="hidden" name="' . $this->e($field['field_name']) . '" value="">';
                    foreach ($options as $opt) {
                        $html .= '<label style="display:flex;align-items:center;gap:0.5rem;">'
                            . '<input type="checkbox" name="' . $this->e($field['field_name']) . '[]" value="' . $this->e($opt['id']) . '"> '
                            . $this->e($opt['title']) . '</label>';
                    }
                }
            } else {
                $inputType = match($field['field_type']) { 'number' => 'number', 'date' => 'date', default => 'text' };
                $html .= '<input type="' . $inputType . '" id="' . $this->e($field['field_name']) . '" name="' . $this->e($field['field_name']) . '">';
            }

            $html .= '</div>';
        }

        return Response::html($html);
    }

    /**
     * Delete a content type's schema.
     */
    public function destroy(Request $request): Response
    {
        $type = $request->getAttribute('type');
        $site = $request->input('current_site');
        $this->writeThrough->deleteSchema($site['slug'], (int) $site['id'], $type);

        if ($request->header('hx-request')) {
            return $this->index($request);
        }

        return Response::json(['message' => 'Content type schema deleted.']);
    }

    private function renderFieldRow(string $name, string $type, bool $required, int $index = -1): string
    {
        static $counter = 0;
        $i = $index >= 0 ? $index : $counter++;

        $typeOptions = '';
        foreach (['text', 'textarea', 'number', 'date', 'select', 'boolean', 'relationship'] as $t) {
            $sel = $t === $type ? ' selected' : '';
            $typeOptions .= '<option value="' . $t . '"' . $sel . '>' . ucfirst($t) . '</option>';
        }

        $checked = $required ? ' checked' : '';

        $html = '<div class="field-row" style="display:flex;gap:0.75rem;margin-bottom:0.75rem;align-items:center;flex-wrap:wrap;">'
            . '<input name="fields[' . $i . '][field_name]" placeholder="Field name" value="' . $this->e($name) . '" required style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">'
            . '<select name="fields[' . $i . '][field_type]" onchange="toggleRelFields(this)" style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">' . $typeOptions . '</select>'
            . '<label style="display:flex;align-items:center;gap:0.25rem;white-space:nowrap;"><input type="checkbox" name="fields[' . $i . '][required]"' . $checked . '> Req</label>'
            . '<button type="button" onclick="this.parentElement.remove()" class="btn btn-secondary" style="padding:0.25rem 0.5rem;">x</button>';

        $relDisplay = $type === 'relationship' ? 'flex' : 'none';
        $html .= '<div class="rel-fields" style="display:' . $relDisplay . ';gap:0.75rem;width:100%;margin-top:0.25rem;">'
            . '<input name="fields[' . $i . '][target_type]" placeholder="Target type (e.g. author)" style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">'
            . '<select name="fields[' . $i . '][cardinality]" style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">'
            . '<option value="one">One</option><option value="many">Many</option>'
            . '</select></div>';

        $html .= '</div>';
        return $html;
    }

    private function fieldBuilderScript(): string
    {
        return '<script>
var fieldIndex = document.querySelectorAll(".field-row").length;
function toggleRelFields(sel) {
  var relDiv = sel.closest(".field-row").querySelector(".rel-fields");
  if (relDiv) relDiv.style.display = sel.value === "relationship" ? "flex" : "none";
}
function addFieldRow(name, type, required) {
  var row = document.createElement("div");
  row.className = "field-row";
  row.style.cssText = "display:flex;gap:0.75rem;margin-bottom:0.75rem;align-items:center;flex-wrap:wrap;";
  row.innerHTML = \'<input name="fields[\'+fieldIndex+\'][field_name]" placeholder="Field name" value="\'+(name||\'\')+\'" required style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">\'
    + \'<select name="fields[\'+fieldIndex+\'][field_type]" onchange="toggleRelFields(this)" style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">\'
    + \'<option value="text"\'+(type==="text"?" selected":"")+\'>Text</option>\'
    + \'<option value="textarea"\'+(type==="textarea"?" selected":"")+\'>Textarea</option>\'
    + \'<option value="number"\'+(type==="number"?" selected":"")+\'>Number</option>\'
    + \'<option value="date"\'+(type==="date"?" selected":"")+\'>Date</option>\'
    + \'<option value="select"\'+(type==="select"?" selected":"")+\'>Select</option>\'
    + \'<option value="boolean"\'+(type==="boolean"?" selected":"")+\'>Boolean</option>\'
    + \'<option value="relationship"\'+(type==="relationship"?" selected":"")+\'>Relationship</option>\'
    + \'</select>\'
    + \'<label style="display:flex;align-items:center;gap:0.25rem;white-space:nowrap;"><input type="checkbox" name="fields[\'+fieldIndex+\'][required]" \'+(required?"checked":"")+\'> Req</label>\'
    + \'<button type="button" onclick="this.parentElement.remove()" class="btn btn-secondary" style="padding:0.25rem 0.5rem;">x</button>\'
    + \'<div class="rel-fields" style="display:\'+(type==="relationship"?"flex":"none")+\';gap:0.75rem;width:100%;margin-top:0.25rem;">\'
    + \'<input name="fields[\'+fieldIndex+\'][target_type]" placeholder="Target type (e.g. author)" style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">\'
    + \'<select name="fields[\'+fieldIndex+\'][cardinality]" style="flex:1;padding:0.5rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;">\'
    + \'<option value="one">One</option><option value="many">Many</option>\'
    + \'</select></div>\';
  document.getElementById("fields-container").appendChild(row);
  fieldIndex++;
}
</script>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
