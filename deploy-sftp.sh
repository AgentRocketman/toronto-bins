#!/bin/bash

# SFTP Deploy Script - Uploads only changed files (preserves server images)
# Usage: bash deploy-sftp.sh agentrocketman.com
# or:    bash deploy-sftp.sh getmybin.com

DOMAIN=${1:-agentrocketman.com}
FTP_USER="u686706869"
FTP_HOST="$DOMAIN"
SOURCE_DIR="/data/.openclaw/workspace/public_html"

# Determine remote path based on domain
if [ "$DOMAIN" = "getmybin.com" ]; then
    REMOTE_PATH="/home/u686706869/domains/getmybin.com/public_html"
elif [ "$DOMAIN" = "agentrocketman.com" ]; then
    REMOTE_PATH="/home/u686706869/domains/agentrocketman.com/public_html"
else
    echo "❌ Unknown domain: $DOMAIN"
    exit 1
fi

echo "🚀 SFTP Deploy to $DOMAIN"
echo "Local: $SOURCE_DIR"
echo "Remote: $REMOTE_PATH"
echo ""

# Create SFTP batch commands
SFTP_BATCH=$(mktemp)
cat > "$SFTP_BATCH" << EOF
cd $REMOTE_PATH
lcd $SOURCE_DIR
mput -r *
EOF

echo "📝 Running SFTP commands..."
echo ""

# Execute SFTP (will prompt for password)
sftp "$FTP_USER@$FTP_HOST" < "$SFTP_BATCH"
RESULT=$?

rm -f "$SFTP_BATCH"

echo ""
if [ $RESULT -eq 0 ]; then
    echo "✅ SFTP Deploy Complete"
    echo "   - Uploaded files to $DOMAIN"
    echo "   - Server files not in local folder remain untouched (images safe)"
else
    echo "❌ SFTP Deploy Failed (exit code: $RESULT)"
    exit $RESULT
fi
