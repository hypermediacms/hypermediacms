---
name: hypermedia-cms-deploy
description: Deploy a Hypermedia CMS website from scratch. Clone repo, configure environment, create content types, build pages, populate content, and deploy to VPS/Raspberry Pi/Docker. Use for any "set up a website" or "deploy Hypermedia CMS" task.
---

# Hypermedia CMS Deployment Skill

Complete workflow for deploying a Hypermedia CMS website from GitHub to production.

## Prerequisites Check

Before starting, verify:
```bash
php --version    # Need 8.2+
composer --version
git --version
```

## Phase 1: Repository Setup

### Option A: Fresh Clone
```bash
git clone https://github.com/hypermediacms/hypermediacms.git my-site
cd my-site
composer install
```

### Option B: Fork (for customization)
```bash
gh repo fork hypermediacms/hypermediacms --clone --remote
cd hypermediacms
composer install
```

### Option C: Existing Installation
```bash
cd /path/to/existing/hypermediacms
git pull origin main
composer install
```

## Phase 2: Environment Configuration

### Create .env
```bash
cp .env.example .env
```

### Generate APP_KEY
```bash
# Generate a secure random key
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

### Configure .env
```env
APP_KEY=<generated-key>
CONTENT_PATH=./content
SCHEMA_PATH=./schemas
DB_PATH=./storage/index/origen.db
SERVER_HOST=0.0.0.0     # Use 0.0.0.0 for network access
SERVER_PORT=8080
DEBUG=false
```

### Create Site Config
```bash
mkdir -p content/main
cat > content/main/_site.yaml << 'EOF'
name: My Website
domain: example.com
api_key: htx-main-site-$(openssl rand -hex 8)
active: true
settings: {}
EOF
```

## Phase 3: Content Architecture

### Define Content Types

For each content type needed, create a schema:

```bash
# Example: Blog post
cat > schemas/main/post.yaml << 'EOF'
fields:
  - field_name: excerpt
    field_type: textarea
    constraints:
      max_length: 300
  - field_name: category
    field_type: select
    constraints:
      options:
        - news
        - tutorial
        - update
  - field_name: featured_image
    field_type: url
EOF
```

Common content types to consider:
- `post` / `article` — Blog content
- `page` — Static pages
- `project` — Portfolio items
- `product` — E-commerce items
- `event` — Calendar events
- `doc` — Documentation

## Phase 4: Page Templates

### Site Layout (`rufinus/site/_layout.htx`)
```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Site</title>
  <script src="https://unpkg.com/htmx.org@2"></script>
  <link rel="stylesheet" href="/public/style.css">
</head>
<body>
  <nav>
    <a href="/" hx-get="/" hx-target="main" hx-push-url="true">Home</a>
    <!-- Add nav links -->
  </nav>
  <main>__content__</main>
  <footer>&copy; 2024</footer>
</body>
</html>
```

### List Page Template
```html
<htx:type>post</htx:type>
<htx:howmany>10</htx:howmany>
<htx:order>recent</htx:order>

<htx>
  <h1>Blog</h1>
  <htx:each>
    <article class="card">
      <h2><a href="/posts/__slug__" hx-get="/posts/__slug__" 
             hx-target="main" hx-push-url="true">__title__</a></h2>
      <p>__excerpt__</p>
      <time>{{ time_ago(updated_at) }}</time>
    </article>
  </htx:each>
  <htx:none>
    <p>No posts yet.</p>
  </htx:none>
</htx>
```

### Single Page Template
```html
<htx:type>post</htx:type>
<htx:howmany>1</htx:howmany>

<htx>
  <htx:each>
    <article>
      <h1>__title__</h1>
      <div>{{! body_html }}</div>
    </article>
  </htx:each>
  <htx:none>
    <p>Not found.</p>
  </htx:none>
</htx>
```

## Phase 5: Admin Setup

### Update serve.php with Site Key
Edit `rufinus/site/serve.php`:
```php
$handler->handle(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    getallheaders(),
    __DIR__,
    'http://localhost:8080',     // Origen URL
    'htx-main-site-xxxxx'        // Must match _site.yaml api_key
);
```

### Start Servers & Create Admin
```bash
php hcms serve:all &
# Visit http://localhost:8080 to create first admin user
```

## Phase 6: Content Population

### Using MCP (if available)
```bash
# The MCP server can scaffold and populate content
php mcp/server.php
# Use scaffold_section, create_content tools
```

### Using API
```bash
# Get action token
TOKEN=$(curl -s -X POST http://localhost:8080/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-main-site-xxxxx" \
  -H "X-HTX-Version: 1" \
  -d '{"action":"save","type":"post"}' | jq -r '.data.payload' | jq -r '.["htx-token"]')

# Create content
curl -X POST http://localhost:8080/api/content/save \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-main-site-xxxxx" \
  -H "X-HTX-Version: 1" \
  -d "{\"htx-token\":\"$TOKEN\",\"htx-context\":\"save\",\"type\":\"post\",\"title\":\"Hello World\",\"body\":\"Welcome!\",\"status\":\"published\"}"
```

## Phase 7: Deployment

### Option A: Systemd Service (VPS/Raspberry Pi)

```bash
# Create service file
sudo tee /etc/systemd/system/hypermedia-cms.service << 'EOF'
[Unit]
Description=Hypermedia CMS
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/hypermediacms
ExecStart=/usr/bin/php hcms serve:all
Restart=always
Environment=SERVER_HOST=0.0.0.0

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable hypermedia-cms
sudo systemctl start hypermedia-cms
```

### Option B: Docker

```dockerfile
FROM php:8.2-cli
WORKDIR /app
COPY . .
RUN apt-get update && apt-get install -y git unzip
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev
EXPOSE 8080 8081
CMD ["php", "hcms", "serve:all"]
```

```bash
docker build -t hypermedia-cms .
docker run -d -p 8080:8080 -p 8081:8081 \
  -v $(pwd)/content:/app/content \
  -v $(pwd)/storage:/app/storage \
  hypermedia-cms
```

### Option C: Nginx Reverse Proxy

```nginx
server {
    listen 80;
    server_name example.com;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
    }
}
```

### SSL with Certbot
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d example.com
```

## Phase 8: Verification

### Checklist
- [ ] Site loads at public URL
- [ ] Content displays correctly
- [ ] Admin login works
- [ ] Content creation works
- [ ] HTTPS configured
- [ ] Backups configured

### Test Commands
```bash
# Health check
curl -s http://localhost:8080/api/health

# Content check
curl -s http://localhost:8081/posts | grep -c "article"

# Admin check
curl -s http://localhost:8081/admin/ | grep -c "login"
```

## Quick Reference

| Task | Command/Location |
|------|------------------|
| Start servers | `php hcms serve:all` |
| Create user | `php hcms user:create` |
| Rebuild index | `php hcms index:rebuild` |
| Site config | `content/<site>/_site.yaml` |
| Schemas | `schemas/<site>/<type>.yaml` |
| Pages | `rufinus/site/*.htx` |
| MCP server | `php mcp/server.php` |

## Troubleshooting

| Issue | Solution |
|-------|----------|
| 404 on all routes | Check API key matches in `serve.php` and `_site.yaml` |
| Empty pages | Ensure content exists and status is "published" |
| Port in use | Change `SERVER_PORT` in `.env` |
| Permission denied | `chown -R www-data:www-data /var/www/hypermediacms` |
| Database locked | Ensure only one instance running |
