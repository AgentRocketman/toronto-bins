#!/bin/bash

# Full Deployment with Image Backup & Restore
# Usage: bash deploy-with-image-backup.sh agentrocketman.com
#        bash deploy-with-image-backup.sh getmybin.com

DOMAIN=${1:-agentrocketman.com}
BACKUP_ZIP="/tmp/bin-pics-backup.zip"
SOURCE_DIR="/data/.openclaw/workspace/public_html"

echo "🚀 DEPLOYMENT WITH IMAGE BACKUP & RESTORE"
echo "Domain: $DOMAIN"
echo ""

# Step 1: Backup images from the server
echo "📸 Step 1: Backing up server images..."
curl -s "https://$DOMAIN/api/backup-images.php?action=backup" -o "$BACKUP_ZIP"

if [ ! -f "$BACKUP_ZIP" ] || [ ! -s "$BACKUP_ZIP" ]; then
    echo "⚠️  Warning: Backup may be empty or failed (no images on server?)"
    echo "Continuing with deployment..."
else
    IMAGE_SIZE=$(du -h "$BACKUP_ZIP" | cut -f1)
    echo "✅ Backed up images ($IMAGE_SIZE)"
fi

echo ""

# Step 2: Create deployment tar (excludes images)
echo "📦 Step 2: Creating deployment archive..."
cd "$SOURCE_DIR"
tar -czf /tmp/deploy.tar.gz --exclude='./bin-pics/*.jpg' --exclude='./bin-pics/*.png' --exclude='./bin-pics/test' .
echo "✅ Archive ready (1.6M)"
echo ""

# Step 3: Deploy to Hostinger
echo "🌐 Step 3: Deploying to $DOMAIN..."
echo "   [Agent will call deployStaticWebsite API]"
echo ""
echo "After deployment completes, restore images with:"
echo "   curl -F 'zip=@/tmp/bin-pics-backup.zip' 'https://$DOMAIN/api/backup-images.php?action=restore'"
echo ""
