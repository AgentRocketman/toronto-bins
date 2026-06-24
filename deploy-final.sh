#!/bin/bash

# Final Deployment Script - Backup → Deploy → Restore
# Usage: bash deploy-final.sh agentrocketman.com
#        bash deploy-final.sh getmybin.com

DOMAIN=${1:-agentrocketman.com}
BACKUP_ZIP="/tmp/bin-pics-backup-$(date +%s).zip"
DEPLOY_TAR="/tmp/deploy-$(date +%s).tar.gz"
SOURCE_DIR="/data/.openclaw/workspace/public_html"

echo "════════════════════════════════════════════════════════════"
echo "🚀 DEPLOY WITH IMAGE BACKUP & RESTORE"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "Domain: $DOMAIN"
echo "Time: $(date)"
echo ""

# STEP 1: Backup images from server
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📸 STEP 1: Backing up images from server..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

curl -s "https://$DOMAIN/api/backup-images.php?action=backup" -o "$BACKUP_ZIP"

if [ -s "$BACKUP_ZIP" ]; then
    BACKUP_SIZE=$(du -h "$BACKUP_ZIP" | cut -f1)
    echo "✅ Backup successful ($BACKUP_SIZE)"
else
    echo "⚠️  No images to backup (first deploy or empty bin-pics)"
    echo "   Continuing..."
fi

echo ""

# STEP 2: Create and deploy new version
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📦 STEP 2: Creating deployment archive..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

cd "$SOURCE_DIR"
tar -czf "$DEPLOY_TAR" --exclude='./bin-pics/*.jpg' --exclude='./bin-pics/*.png' .
DEPLOY_SIZE=$(du -h "$DEPLOY_TAR" | cut -f1)
echo "✅ Archive created ($DEPLOY_SIZE)"
echo "   Path: $DEPLOY_TAR"

echo ""
echo "🌐 Deploying to Hostinger..."
echo "   Note: Agent will call the Hostinger API deploy function"
echo "   Archive path: $DEPLOY_TAR"
echo "   This will wipe the server and unpack the new code."

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Ready for deployment!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "After Hostinger deployment completes, restore images:"
echo ""
if [ -s "$BACKUP_ZIP" ]; then
    echo "  curl -F 'zip=@$BACKUP_ZIP' 'https://$DOMAIN/api/backup-images.php?action=restore'"
    echo ""
    echo "  Backup file will expire. Keep until restore is confirmed."
else
    echo "  (No images to restore)"
fi

echo ""
