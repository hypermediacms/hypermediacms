#!/bin/bash
# Deploy Hypermedia CMS as a systemd service
# Usage: sudo ./deploy-systemd.sh [install-path] [user]

set -e

INSTALL_PATH=${1:-/var/www/hypermediacms}
SERVICE_USER=${2:-www-data}
SERVICE_NAME="hypermedia-cms"

echo "🚀 Deploying Hypermedia CMS as systemd service..."

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Please run as root (sudo)"
    exit 1
fi

# Verify installation
if [ ! -f "$INSTALL_PATH/hcms" ]; then
    echo "❌ Hypermedia CMS not found at $INSTALL_PATH"
    exit 1
fi

# Set permissions
echo "📁 Setting permissions..."
chown -R $SERVICE_USER:$SERVICE_USER "$INSTALL_PATH"
chmod -R 755 "$INSTALL_PATH"
chmod 600 "$INSTALL_PATH/.env" 2>/dev/null || true

# Create systemd service
echo "⚙️ Creating systemd service..."
cat > "/etc/systemd/system/${SERVICE_NAME}.service" << EOF
[Unit]
Description=Hypermedia CMS (Origen + Rufinus)
After=network.target

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_USER
WorkingDirectory=$INSTALL_PATH
ExecStart=/usr/bin/php hcms serve:all
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

# Environment
Environment=SERVER_HOST=0.0.0.0

# Security
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

# Reload and enable
echo "🔄 Enabling service..."
systemctl daemon-reload
systemctl enable "$SERVICE_NAME"
systemctl start "$SERVICE_NAME"

# Show status
sleep 2
systemctl status "$SERVICE_NAME" --no-pager

echo ""
echo "✅ Deployment complete!"
echo ""
echo "Service commands:"
echo "  sudo systemctl status $SERVICE_NAME"
echo "  sudo systemctl restart $SERVICE_NAME"
echo "  sudo journalctl -u $SERVICE_NAME -f"
echo ""
echo "Ports:"
echo "  Origen API: 8080"
echo "  Rufinus:    8081"
