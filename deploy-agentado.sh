#!/bin/bash
# Deploy agentado to agentrocketman.com
# Usage: bash deploy-final.sh agentrocketman.com

DOMAIN=${1:-agentrocketman.com}
DEPLOY_TAR="/tmp/deploy-$(date +%s).tar.gz"
SOURCE_DIR="/data/.openclaw/workspace/public_html"

echo "════════════════════════════════════════════════════════════"
echo "🚀 DEPLOY to $DOMAIN"
echo "════════════════════════════════════════════════════════════"
echo ""

# Create deployment archive
echo "📦 Creating archive..."
cd "$SOURCE_DIR"
tar -czf "$DEPLOY_TAR" --exclude='./bin-pics/*.jpg' --exclude='./bin-pics/*.png' .
echo "   Size: $(du -h "$DEPLOY_TAR" | cut -f1)"
echo ""

# Deploy via Hostinger
echo "📤 Deploying to $DOMAIN..."
echo ""

# Use Hostinger deploy static website tool
# The agentado directory will be included in the tar since we created it under public_html/