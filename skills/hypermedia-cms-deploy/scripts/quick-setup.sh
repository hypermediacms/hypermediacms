#!/bin/bash
# Quick Setup Script for Hypermedia CMS
# Usage: ./quick-setup.sh [site-name] [domain]

set -e

SITE_NAME=${1:-main}
DOMAIN=${2:-localhost}

echo "🚀 Setting up Hypermedia CMS..."

# Check prerequisites
command -v php >/dev/null 2>&1 || { echo "❌ PHP required"; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "❌ Composer required"; exit 1; }

# Install dependencies
if [ -f composer.json ] && [ ! -d vendor ]; then
    echo "📦 Installing dependencies..."
    composer install --no-dev --quiet
fi

# Create .env if missing
if [ ! -f .env ]; then
    echo "⚙️ Creating .env..."
    cp .env.example .env
    APP_KEY=$(php -r "echo base64_encode(random_bytes(32));")
    sed -i "s/^APP_KEY=.*/APP_KEY=$APP_KEY/" .env
    sed -i "s/^SERVER_HOST=.*/SERVER_HOST=0.0.0.0/" .env
fi

# Create site directory
SITE_DIR="content/$SITE_NAME"
if [ ! -d "$SITE_DIR" ]; then
    echo "📁 Creating site: $SITE_NAME..."
    mkdir -p "$SITE_DIR"
    API_KEY="htx-${SITE_NAME}-$(openssl rand -hex 6)"
    cat > "$SITE_DIR/_site.yaml" << EOF
name: $SITE_NAME
domain: $DOMAIN
api_key: $API_KEY
active: true
settings: {}
EOF
    echo "🔑 API Key: $API_KEY"
    
    # Update serve.php with the API key
    if [ -f rufinus/site/serve.php ]; then
        sed -i "s/'htx-starter-key-001'/'$API_KEY'/" rufinus/site/serve.php
    fi
fi

# Create storage directory
mkdir -p storage/index

echo ""
echo "✅ Setup complete!"
echo ""
echo "Start the servers:"
echo "  php hcms serve:all"
echo ""
echo "Then visit:"
echo "  Site:  http://$DOMAIN:8081"
echo "  Admin: http://$DOMAIN:8080"
