# CurbIn Page Versions

## Current Production
- **Live at:** https://agentrocketman.com/index.html
- **File:** `curbin-v2-final.html` (modified 2026-06-14 20:21)
- **Features:** Harmonica accordion | Save 34% badge | /week pricing | Instant scroll

---

## Version Archive

| Version | Date | File | Features | Status |
|---------|------|------|----------|--------|
| **v2-final** | 2026-06-14 20:21 | `curbin-v2-final.html` | ✅ Accordion ✅ Save 34% ✅ /week pricing ✅ Instant scroll | 🟢 LIVE |
| v2-accordion-fixed | 2026-06-12 19:21 | `curbin-v2-accordion-fixed.html` | ✅ Accordion ✅ Save 34% ❌ /event pricing ❌ 200ms scroll | ⚫ |
| v2-accordion | 2026-06-12 19:02 | `curbin-v2-accordion.html` | ✅ Accordion ❌ Save 34% ❌ /event pricing ❌ 200ms scroll | ⚫ |
| v1-backup | 2026-06-12 19:02 | `curbin-v1-backup.html` | ✅ Harmonica ❌ Save badge ❌ /week | ⚫ |
| v2 (service) | 2026-06-10 | `toronto-bins/v2.html` | ✅ Card layout ✅ /week pricing ❌ New UI | ⚫ |
| v1 (service) | 2026-06-10 | `toronto-bins/v1.html` | Early version | ⚫ |

---

## Quick Deploy Commands

### To revert to a previous version:
```bash
# Deploy curbin-v2-accordion with all features
cp /data/.openclaw/workspace/curbin-v2-accordion-fixed.html /tmp/deploy/index.html
# ... then run hostinger deploy

# Deploy toronto-bins v2 (card layout)
cp /data/.openclaw/workspace/toronto-bins/v2.html /tmp/deploy/index.html
```

### To view git history:
```bash
cd /data/.openclaw/workspace/toronto-bins
git log --oneline --all | head -20
git show <commit-hash>
```

---

## Key Changes Over Time

### Jun 14 20:21 → curbin-v2-final
- Changed `/event` → `/week` in recurring pricing
- Kept all accordion + Save 34% features

### Jun 12 19:21 → curbin-v2-accordion-fixed  
- Added: "Save 34%" gold badge on Recurring button
- Fixed: Removed 200ms setTimeout, instant scroll
- Issue: Still says `/event` instead of `/week`

### Jun 12 19:02 → curbin-v2-accordion
- Added: Full accordion/harmonica for schedule
- Issue: Missing Save 34% badge
- Issue: Scroll timing issues

### Jun 10 → v1-backup
- Old harmonica version before accordion improvements
- Has card layout from v2.html

---

## To Avoid Future Rebuilds

1. **Before deploying:** Always note which file you're deploying
2. **When making changes:** Edit the local file, commit to git, THEN deploy
3. **When reverting:** Just copy the old file and redeploy (takes 10 seconds)
4. **Use git branches:** Create a branch for experimental features
