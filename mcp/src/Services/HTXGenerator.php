<?php
/**
 * HTX Generator Service
 * 
 * Generates HTX template content based on configuration.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Services;

class HTXGenerator
{
    /**
     * Generate HTX content based on configuration
     */
    public function generate(array $config): string
    {
        $contentType = $config['content_type'] ?? 'article';
        $displayMode = $config['display_mode'] ?? 'list';
        $templateStyle = $config['template_style'] ?? 'card';
        $fields = $config['fields'] ?? null;
        $isAdmin = $config['is_admin'] ?? false;
        $action = $config['action'] ?? null;

        return match ($displayMode) {
            'list' => $this->generateListTemplate($contentType, $templateStyle, $fields, $isAdmin),
            'single' => $this->generateSingleTemplate($contentType, $templateStyle, $fields),
            'form' => $this->generateFormTemplate($contentType, $action ?? 'create', $isAdmin),
            default => $this->generateListTemplate($contentType, $templateStyle, $fields, $isAdmin)
        };
    }

    /**
     * Generate a list display template
     */
    private function generateListTemplate(string $contentType, string $style, ?array $fields, bool $isAdmin): string
    {
        $howMany = 10;
        
        if ($isAdmin) {
            return $this->generateAdminListTemplate($contentType);
        }

        $itemTemplate = match ($style) {
            'card' => $this->getCardItemTemplate($contentType, $fields),
            'table' => $this->getTableTemplate($contentType, $fields),
            'minimal' => $this->getMinimalItemTemplate($contentType, $fields),
            default => $this->getCardItemTemplate($contentType, $fields)
        };

        $noneTemplate = $this->getNoneTemplate($contentType);

        return <<<HTX
<htx:type>{$contentType}</htx:type>
<htx:howmany>{$howMany}</htx:howmany>

<htx>
  <htx:each>
{$itemTemplate}
  </htx:each>

  <htx:none>
{$noneTemplate}
  </htx:none>
</htx>
HTX;
    }

    /**
     * Generate admin list template
     */
    private function generateAdminListTemplate(string $contentType): string
    {
        $plural = $contentType . 's';
        $title = ucfirst($plural);

        return <<<HTX
<htx:type>{$contentType}</htx:type>
<htx:howmany>50</htx:howmany>

<htx>
  <div class="admin-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1>{$title}</h1>
    <a href="/admin/{$plural}/new" hx-get="/admin/{$plural}/new" hx-target=".admin-main" hx-push-url="true" class="btn btn-primary">
      + New {$contentType}
    </a>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Status</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <htx:each>
          <tr>
            <td>
              <a href="/admin/{$plural}/__id__" hx-get="/admin/{$plural}/__id__" hx-target=".admin-main" hx-push-url="true">
                __title__
              </a>
            </td>
            <td><span class="status-badge status-__status__">__status__</span></td>
            <td>{{ time_ago(updated_at) }}</td>
            <td>
              <a href="/admin/{$plural}/__id__" hx-get="/admin/{$plural}/__id__" hx-target=".admin-main" hx-push-url="true" class="btn btn-sm">Edit</a>
            </td>
          </tr>
        </htx:each>
      </tbody>
    </table>

    <htx:none>
      <p style="padding: 2rem; text-align: center; color: #666;">No {$plural} yet.</p>
    </htx:none>
  </div>
</htx>
HTX;
    }

    /**
     * Generate a single item display template
     */
    private function generateSingleTemplate(string $contentType, string $style, ?array $fields): string
    {
        $defaultFields = $fields ?? ['title', 'body', 'status', 'updated_at'];

        $fieldDisplay = '';
        foreach ($defaultFields as $field) {
            if ($field === 'title') {
                $fieldDisplay .= "      <h1>__title__</h1>\n";
            } elseif ($field === 'body') {
                $fieldDisplay .= "      <div class=\"content-body\">{{! body_html }}</div>\n";
            } elseif ($field === 'status') {
                $fieldDisplay .= "      <span class=\"status-badge\">__status__</span>\n";
            } else {
                $fieldDisplay .= "      <div class=\"field-{$field}\">__{$field}__</div>\n";
            }
        }

        return <<<HTX
<htx:type>{$contentType}</htx:type>
<htx:howmany>1</htx:howmany>

<htx>
  <htx:each>
    <article class="content-single {$style}">
{$fieldDisplay}
      <div class="meta">
        Updated: {{ time_ago(updated_at) }}
      </div>
    </article>
  </htx:each>

  <htx:none>
    <div class="not-found" style="text-align: center; padding: 3rem;">
      <p>{$contentType} not found.</p>
      <a href="/{$contentType}s">&larr; Back to all</a>
    </div>
  </htx:none>
</htx>
HTX;
    }

    /**
     * Generate a form template (create or update)
     */
    private function generateFormTemplate(string $contentType, string $action, bool $isAdmin): string
    {
        $isUpdate = $action === 'update';
        $htxAction = $isUpdate ? 'update' : 'save';
        $title = $isUpdate ? "Edit " . ucfirst($contentType) : "New " . ucfirst($contentType);
        $buttonText = $isUpdate ? "Update" : "Create";
        $plural = $contentType . 's';
        
        $redirectPath = $isAdmin ? "/admin/{$plural}?saved=1" : "/{$plural}";

        $recordIdTag = $isUpdate ? '' : '';

        return <<<HTX
<htx:action>{$htxAction}</htx:action>
<htx:type>{$contentType}</htx:type>
<htx:responseRedirect>{$redirectPath}</htx:responseRedirect>

<htx>
  <h1>{$title}</h1>
  <p class="text-muted text-sm" style="margin-bottom: 1.5rem;">Fill in the details below.</p>

  <div class="admin-card">
    <form hx-post="__endpoint__" hx-vals='__payload__' hx-target=".admin-main" hx-swap="innerHTML">
      <div class="form-group">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="__title__" required>
      </div>
      <div class="form-group">
        <label for="slug">Slug</label>
        <input type="text" id="slug" name="slug" value="__slug__" placeholder="auto-generated from title">
      </div>
      <div class="form-group">
        <label for="body">Body (Markdown)</label>
        <textarea id="body" name="body">__body__</textarea>
      </div>
      __custom_fields_html__
      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status">
          __status_options__
        </select>
      </div>
      <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
        <button type="submit" class="btn btn-primary">{$buttonText}</button>
        <a href="/admin/{$plural}" hx-get="/admin/{$plural}" hx-target=".admin-main" hx-push-url="true" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</htx>
HTX;
    }

    /**
     * Get card-style item template
     */
    private function getCardItemTemplate(string $contentType, ?array $fields): string
    {
        $plural = $contentType . 's';
        
        return <<<TEMPLATE
    <article class="card" style="margin-bottom: 1rem; padding: 1.5rem;">
      <h3 style="margin-bottom: 0.5rem;">
        <a href="/{$plural}/__slug__" hx-get="/{$plural}/__slug__" hx-target="main" hx-push-url="true">
          __title__
        </a>
      </h3>
      <p style="color: #666; margin-bottom: 0.5rem;">{{ truncate(body, 150) }}</p>
      <div style="font-size: 0.85rem; color: #888;">
        {{ time_ago(updated_at) }} &middot; <span class="status-__status__">__status__</span>
      </div>
    </article>
TEMPLATE;
    }

    /**
     * Get table-style template
     */
    private function getTableTemplate(string $contentType, ?array $fields): string
    {
        $plural = $contentType . 's';
        
        return <<<TEMPLATE
    <tr>
      <td>
        <a href="/{$plural}/__slug__" hx-get="/{$plural}/__slug__" hx-target="main" hx-push-url="true">
          __title__
        </a>
      </td>
      <td>__status__</td>
      <td>{{ time_ago(updated_at) }}</td>
    </tr>
TEMPLATE;
    }

    /**
     * Get minimal item template
     */
    private function getMinimalItemTemplate(string $contentType, ?array $fields): string
    {
        $plural = $contentType . 's';
        
        return <<<TEMPLATE
    <li>
      <a href="/{$plural}/__slug__" hx-get="/{$plural}/__slug__" hx-target="main" hx-push-url="true">
        __title__
      </a>
    </li>
TEMPLATE;
    }

    /**
     * Get "none found" template
     */
    private function getNoneTemplate(string $contentType): string
    {
        $plural = $contentType . 's';
        
        return <<<TEMPLATE
    <div style="text-align: center; padding: 3rem; color: #666;">
      <p>No {$plural} found.</p>
    </div>
TEMPLATE;
    }
}
