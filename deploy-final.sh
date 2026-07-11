#!/bin/bash

# Deployment Script - Graphify → Deploy
# Usage: bash deploy-final.sh agentrocketman.com
#        bash deploy-final.sh getmybin.com
#
# Bin-pics images are stored EXTERNALLY at /data/.openclaw/workspace/bin-pics-data/
# — they survive deploys, no backup/restore needed.

DOMAIN=${1:-agentrocketman.com}
DEPLOY_TAR="/tmp/deploy-$(date +%s).tar.gz"
SOURCE_DIR="/data/.openclaw/workspace/public_html"

echo "════════════════════════════════════════════════════════════"
echo "🚀 DEPLOY"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "Domain: $DOMAIN"
echo "Time: $(date)"
echo ""

# STEP 1: Refresh knowledge graph (incremental, free for code-only changes)
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🧠 STEP 1: Updating Graphify knowledge graph..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

export OPENAI_API_KEY="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"
export OPENAI_BASE_URL="https://openrouter.ai/api/v1"
export GRAPHIFY_VIZ_NODE_LIMIT=25000

cd /data/.openclaw/workspace
if graphify update . 2>&1; then
    echo "✅ Graph updated"
    mkdir -p "$SOURCE_DIR/graphify"
    cp -f graphify-out/graph.html graphify-out/GRAPH_REPORT.md graphify-out/graph.json "$SOURCE_DIR/graphify/" 2>/dev/null
    echo "✅ Graph files staged for deployment"
else
    echo "⚠️  Graph update had warnings (non-fatal, continuing)"
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
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Ready for deployment!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Note: Bin-pics images survive deploys (external storage)."
echo ""
