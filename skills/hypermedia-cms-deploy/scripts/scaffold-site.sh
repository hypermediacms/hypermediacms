#!/bin/bash
# Scaffold a complete site structure
# Usage: ./scaffold-site.sh [site-type]
# Types: blog, portfolio, docs, landing

set -e

SITE_TYPE=${1:-blog}

echo "🏗️ Scaffolding $SITE_TYPE site..."

case $SITE_TYPE in
    blog)
        CONTENT_TYPE="post"
        PLURAL="posts"
        FIELDS='[{"name":"excerpt","type":"textarea"},{"name":"category","type":"select","options":["news","tutorial","update"]},{"name":"featured_image","type":"url"}]'
        ;;
    portfolio)
        CONTENT_TYPE="project"
        PLURAL="projects"
        FIELDS='[{"name":"tech_stack","type":"text"},{"name":"demo_url","type":"url"},{"name":"github_url","type":"url"},{"name":"featured_image","type":"url"}]'
        ;;
    docs)
        CONTENT_TYPE="doc"
        PLURAL="docs"
        FIELDS='[{"name":"section","type":"select","options":["Getting Started","Guides","API Reference"]},{"name":"sort_order","type":"number"},{"name":"summary","type":"textarea"}]'
        ;;
    landing)
        CONTENT_TYPE="page"
        PLURAL="pages"
        FIELDS='[{"name":"template","type":"select","options":["default","hero","full-width"]}]'
        ;;
    *)
        echo "❌ Unknown site type: $SITE_TYPE"
        echo "Available types: blog, portfolio, docs, landing"
        exit 1
        ;;
esac

# Check if MCP server is available
if [ -f mcp/server.php ]; then
    echo "Using MCP to scaffold..."
    
    # Use the scaffold_section tool via PHP
    php -r "
    require 'vendor/autoload.php';
    use HyperMediaCMS\MCP\Tools\ScaffoldSectionTool;
    
    \$tool = new ScaffoldSectionTool();
    \$result = \$tool->execute([
        'name' => '$CONTENT_TYPE',
        'plural' => '$PLURAL',
        'fields' => json_decode('$FIELDS', true),
        'add_to_nav' => true,
        'description' => 'All $PLURAL'
    ]);
    
    echo json_encode(\$result, JSON_PRETTY_PRINT) . PHP_EOL;
    "
    
    echo ""
    echo "✅ Site scaffolded!"
    echo ""
    echo "Created:"
    echo "  - Schema: schemas/starter/$CONTENT_TYPE.yaml"
    echo "  - List page: rufinus/site/$PLURAL/index.htx"
    echo "  - Single page: rufinus/site/$PLURAL/[slug].htx"
    echo "  - Admin pages: rufinus/site/admin/$PLURAL/"
    echo ""
    echo "Start the servers: php hcms serve:all"
else
    echo "❌ MCP server not found. Please run from the hypermediacms directory."
    exit 1
fi
