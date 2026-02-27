<?php
/**
 * Create Landing Page Prompt
 * 
 * Guides the AI through creating a landing page with
 * configurable sections like hero, features, testimonials, etc.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

class CreateLandingPagePrompt implements PromptInterface
{
    public function getName(): string
    {
        return 'create_landing_page';
    }

    public function getDescription(): string
    {
        return 'Create a landing page with customizable sections like hero banner, ' .
               'features grid, testimonials, pricing, FAQ, and contact form.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'route',
                'description' => 'URL route for the page (e.g., "/pricing", "/about-us")',
                'required' => true
            ],
            [
                'name' => 'title',
                'description' => 'Page title',
                'required' => true
            ],
            [
                'name' => 'sections',
                'description' => 'Comma-separated sections: hero, features, testimonials, pricing, faq, contact, cta',
                'required' => false
            ]
        ];
    }

    public function getMessages(array $arguments): array
    {
        $route = $arguments['route'] ?? '/landing';
        $title = $arguments['title'] ?? 'Welcome';
        $sectionsStr = $arguments['sections'] ?? 'hero,features,cta';
        
        $sections = array_map('trim', explode(',', $sectionsStr));
        
        // Build section templates
        $sectionTemplates = [];
        
        foreach ($sections as $section) {
            $sectionTemplates[] = $this->getSectionTemplate($section);
        }
        
        $sectionsHtml = implode("\n\n", $sectionTemplates);

        $prompt = <<<PROMPT
Create a landing page at route "{$route}" with title "{$title}".

## Sections to Include
{$sectionsStr}

## Implementation

Use `create_htx` to create the page with this structure:

```html
<htx>
  <div class="landing-page">
    <h1 style="text-align: center; padding: 2rem 0;">{$title}</h1>
    
{$sectionsHtml}
  </div>
</htx>
```

## Section Styling Guidelines

- **Hero**: Full-width, prominent headline, clear CTA button
- **Features**: Grid layout (2-3 columns), icons or images
- **Testimonials**: Cards with quotes, author names
- **Pricing**: Clear tiers, highlight recommended option
- **FAQ**: Accordion-style, expandable answers
- **Contact**: Simple form with essential fields
- **CTA**: Strong call-to-action, contrasting colors

After creating the HTX file, verify it works by accessing the route.

If any sections need dynamic content (like testimonials or FAQ items), 
consider creating a simple content type for them using `scaffold_section`.
PROMPT;

        return [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ];
    }

    private function getSectionTemplate(string $section): string
    {
        return match ($section) {
            'hero' => <<<HTML
    <!-- Hero Section -->
    <section class="hero" style="text-align: center; padding: 4rem 2rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
      <h2 style="font-size: 2.5rem; margin-bottom: 1rem;">Your Headline Here</h2>
      <p style="font-size: 1.25rem; margin-bottom: 2rem; opacity: 0.9;">Supporting text that explains your value proposition</p>
      <a href="#" class="btn" style="background: white; color: #667eea; padding: 1rem 2rem; border-radius: 8px; text-decoration: none; font-weight: bold;">Get Started</a>
    </section>
HTML,
            'features' => <<<HTML
    <!-- Features Section -->
    <section class="features" style="padding: 4rem 2rem; max-width: 1000px; margin: 0 auto;">
      <h2 style="text-align: center; margin-bottom: 2rem;">Features</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
        <div class="card" style="padding: 1.5rem; text-align: center;">
          <div style="font-size: 2rem; margin-bottom: 1rem;">🚀</div>
          <h3>Feature One</h3>
          <p style="color: #666;">Description of this amazing feature</p>
        </div>
        <div class="card" style="padding: 1.5rem; text-align: center;">
          <div style="font-size: 2rem; margin-bottom: 1rem;">⚡</div>
          <h3>Feature Two</h3>
          <p style="color: #666;">Description of this amazing feature</p>
        </div>
        <div class="card" style="padding: 1.5rem; text-align: center;">
          <div style="font-size: 2rem; margin-bottom: 1rem;">🎯</div>
          <h3>Feature Three</h3>
          <p style="color: #666;">Description of this amazing feature</p>
        </div>
      </div>
    </section>
HTML,
            'testimonials' => <<<HTML
    <!-- Testimonials Section -->
    <section class="testimonials" style="padding: 4rem 2rem; background: #f8f9fa;">
      <h2 style="text-align: center; margin-bottom: 2rem;">What People Say</h2>
      <div style="max-width: 800px; margin: 0 auto; display: grid; gap: 2rem;">
        <blockquote class="card" style="padding: 1.5rem; border-left: 4px solid #667eea;">
          <p style="font-style: italic; margin-bottom: 1rem;">"This product changed everything for us. Highly recommended!"</p>
          <footer style="color: #666;">— Happy Customer</footer>
        </blockquote>
      </div>
    </section>
HTML,
            'pricing' => <<<HTML
    <!-- Pricing Section -->
    <section class="pricing" style="padding: 4rem 2rem;">
      <h2 style="text-align: center; margin-bottom: 2rem;">Pricing</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem; max-width: 900px; margin: 0 auto;">
        <div class="card" style="padding: 2rem; text-align: center;">
          <h3>Starter</h3>
          <div style="font-size: 2rem; font-weight: bold; margin: 1rem 0;">$9/mo</div>
          <ul style="list-style: none; padding: 0; color: #666;">
            <li>Feature A</li>
            <li>Feature B</li>
          </ul>
          <a href="#" class="btn" style="display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #667eea; color: white; border-radius: 6px; text-decoration: none;">Choose</a>
        </div>
        <div class="card" style="padding: 2rem; text-align: center; border: 2px solid #667eea;">
          <h3>Pro</h3>
          <div style="font-size: 2rem; font-weight: bold; margin: 1rem 0;">$29/mo</div>
          <ul style="list-style: none; padding: 0; color: #666;">
            <li>Everything in Starter</li>
            <li>Feature C</li>
            <li>Feature D</li>
          </ul>
          <a href="#" class="btn" style="display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #667eea; color: white; border-radius: 6px; text-decoration: none;">Choose</a>
        </div>
      </div>
    </section>
HTML,
            'faq' => <<<HTML
    <!-- FAQ Section -->
    <section class="faq" style="padding: 4rem 2rem; max-width: 800px; margin: 0 auto;">
      <h2 style="text-align: center; margin-bottom: 2rem;">Frequently Asked Questions</h2>
      <div class="card" style="padding: 1.5rem; margin-bottom: 1rem;">
        <h4 style="margin-bottom: 0.5rem;">Question one?</h4>
        <p style="color: #666; margin: 0;">Answer to the first question goes here.</p>
      </div>
      <div class="card" style="padding: 1.5rem; margin-bottom: 1rem;">
        <h4 style="margin-bottom: 0.5rem;">Question two?</h4>
        <p style="color: #666; margin: 0;">Answer to the second question goes here.</p>
      </div>
    </section>
HTML,
            'contact' => <<<HTML
    <!-- Contact Section -->
    <section class="contact" style="padding: 4rem 2rem; background: #f8f9fa;">
      <h2 style="text-align: center; margin-bottom: 2rem;">Contact Us</h2>
      <form style="max-width: 500px; margin: 0 auto;" class="card" style="padding: 2rem;">
        <div style="margin-bottom: 1rem;">
          <label style="display: block; margin-bottom: 0.5rem;">Name</label>
          <input type="text" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
        </div>
        <div style="margin-bottom: 1rem;">
          <label style="display: block; margin-bottom: 0.5rem;">Email</label>
          <input type="email" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
        </div>
        <div style="margin-bottom: 1rem;">
          <label style="display: block; margin-bottom: 0.5rem;">Message</label>
          <textarea rows="4" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;"></textarea>
        </div>
        <button type="submit" style="width: 100%; padding: 1rem; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">Send Message</button>
      </form>
    </section>
HTML,
            'cta' => <<<HTML
    <!-- CTA Section -->
    <section class="cta" style="text-align: center; padding: 4rem 2rem; background: #1a1a2e; color: white;">
      <h2 style="margin-bottom: 1rem;">Ready to Get Started?</h2>
      <p style="margin-bottom: 2rem; opacity: 0.8;">Join thousands of happy customers today.</p>
      <a href="#" class="btn" style="background: #667eea; color: white; padding: 1rem 2rem; border-radius: 8px; text-decoration: none; font-weight: bold;">Start Free Trial</a>
    </section>
HTML,
            default => "    <!-- {$section} section -->"
        };
    }
}
