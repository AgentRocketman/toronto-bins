# 🛠️ BUILDER BRIEF — URL Shortener

## Your Mission

Build the URL Shortener web app per the planning agents' specs. Read the four agent outputs in this directory:

- `REQUIREMENTS.md` — original user prompt
- `SCOUT.md` — scope analysis + recommended features
- `ARCHITECT.md` — technical design (THIS IS YOUR BLUEPRINT)
- `TESTER.md` — test plan you must satisfy
- `REVIEWER.md` — issues to address before/during build

## Apply Reviewer's Required Fixes

The Reviewer flagged these — address them in your build:

1. **C1 (custom aliases)** — Mark custom aliases as OPTIONAL. If implementing, document it. If not, skip TC-A03 / TC-B04 / TC-B05.
2. **C2 (rate limit contention)** — Use a SIMPLER in-memory or session-based rate limit OR accept the latency. Don't compound the ratelimit.json write contention with urls.json.
3. **H1 (QR library)** — If phpqrcode doesn't work in PHP 8.x cleanly, fall back to omitting the qrCode field. Don't burn budget debugging it.
4. **H3 (file lock backoff)** — Use exponential backoff (100ms, 200ms, 400ms) with small random jitter for flock retries.

## Build Constraints

- **Stack:** Vanilla HTML/CSS/JS (no React/Vue) + PHP 8.x + JSON file storage. NO database.
- **Output directory:** Place all files in `./output/` (current working dir).
- **File structure:** Follow Architect's spec.
- **Run tests:** After writing code, write test files matching Tester's spec. Run them. They must pass.

## Acceptance Criteria

- All core functionality test cases pass (TC-A01 to TC-A12 minus any explicitly marked optional)
- All security test cases pass (TC-D01 to TC-D04)
- All error handling test cases pass (TC-C01 to TC-C06)
- Code is deploy-ready (no debug statements, no commented-out code, no hardcoded paths beyond `output/`)

## When Done

Produce a `BUILDER_REPORT.md` with:
- List of files created and their purpose
- Test results (X/Y passed)
- Any issues encountered & how resolved
- Cost summary (your iteration count)
- Deployment notes
