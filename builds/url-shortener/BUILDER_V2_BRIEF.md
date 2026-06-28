# 🛠️ BUILDER v2 — FIX THE CRITICAL ISSUES

## Context

You built v1 already. It's in `./output/`. Code Reviewer-1 audited it and found real bugs. The user rejected v1 ("Don't like it") — they want you to fix the critical issues.

## Required Reading (in order)

1. `CODE_REVIEWER.md` — the audit findings you must address
2. `./output/` — your existing v1 code (read what you wrote)

## Required Fixes (DO NOT SKIP)

### 🚨 CRITICAL

**CR-C1 — File Lock Release on Exception Paths**
- **Files:** `api/helpers.php` — `writeUrlData()`, `writeStatsData()`, any flock() usage
- **Fix:** Wrap every file operation in try/finally:
  ```php
  $handle = fopen($file, 'c+');
  if (!flock($handle, LOCK_EX)) { fclose($handle); throw new Exception('Lock failed'); }
  try {
      // ... read/modify/write ...
  } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
  }
  ```
- Apply this pattern EVERYWHERE you open a file under lock.

### ⚠️ HIGH

**CR-H1 — TOCTOU Race in Short Code Generation**
- **File:** `api/shorten.php` — `generateShortCode()` collision loop
- **Problem:** Two concurrent requests can both check "code X is free" then both write it.
- **Fix:** Move the collision check INSIDE the write lock. Lock the file FIRST, then generate + check + write atomically. Loop until a free code is found, all within the same lock acquisition.

**CR-H2 — Stats Increment Not Atomic**
- **File:** `redirect.php` — click tracking
- **Problem:** Read-increment-write loses updates under concurrent redirects.
- **Fix:** Acquire LOCK_EX, read current count, increment, write, release — all in same lock hold. Use the same try/finally pattern as CR-C1.

**CR-H3 — Session Fixation in Rate Limiting**
- **File:** `api/helpers.php` — `checkRateLimit()`
- **Fix:** Call `session_regenerate_id(true)` on first rate-limit check per session. OR switch to a simple IP-based bucket (use `$_SERVER['REMOTE_ADDR']`) stored in a small ratelimit.json with the same try/finally lock pattern (acceptable since CR-C1 fix makes contention tractable).

### 🟡 MEDIUM (apply if low cost)

- **CR-M1** — Sanitize error messages: don't expose file paths in JSON responses. Log details server-side via `error_log()`, return generic strings ("Service temporarily unavailable", "Invalid request").
- **CR-M4** — Add a startup check: if `BASE_URL === 'http://localhost:8000'`, log a warning via `error_log()`.

### LOW (skip if budget tight)

Skip L1-L4. Focus on the criticals.

## Build Constraints

- **Output directory:** Modify files in-place in `./output/`. Don't create v2/ — edit v1 files directly.
- **Test files:** Update any test cases that need to match new lock/race semantics.
- **No new dependencies.** No Composer. No new libraries.

## Acceptance Criteria

- **Every flock() in the code is wrapped in try/finally.**
- **generateShortCode() collision check happens inside the same lock as the write.**
- **Stats increment is fully inside a single lock hold.**
- **Rate limiter either regenerates session ID or uses IP-based bucket.**
- All v1 functionality still works (don't break what was already passing).

## When Done

Produce `BUILDER_V2_REPORT.md` summarizing:
1. Every file you modified + what changed
2. Diff summary (before → after) for each critical fix
3. Whether all 4 critical/high issues are fully addressed
4. Any caveats or trade-offs you accepted
5. Re-self-assessment: predicted pass rate after fixes

## Budget Awareness

v1 cost $3.50. v2 target: ≤ $1.50 (you're editing, not rebuilding). Be surgical. Don't rewrite files just because you can.
