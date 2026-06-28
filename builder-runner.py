#!/usr/bin/env python3
"""
Mission Control Builder Runner
==============================
Polls Mission Control for pending Builder jobs and runs them via Claude Code CLI.
Lives inside the OpenClaw container — always-on, no extra cost.

Polls every 15s. When a builder-stage project has no running/completed builder
event for the current iteration, runs Claude Code CLI with the full context.

Posts results back via Mission Control webhook.
Heartbeats every poll cycle to a heartbeat file the dashboard can monitor.
"""

import json
import os
import re
import subprocess
import sys
import time
import traceback
import urllib.request
import urllib.error
from pathlib import Path
from datetime import datetime, timezone

# ─── Configuration ─────────────────────────────────────────────────────────────
MC_BASE_URL = "https://agentrocketman.com/mission-control"
MC_PASSWORD = "MissionControl2026!"
POLL_INTERVAL_SECONDS = 3  # fallback polling when long-poll unavailable
LONG_POLL_SECONDS = 25  # how long server can hang the connection
RUNNER_SECRET = "mc-runner-heartbeat-2026"
BUILDS_DIR = Path("/data/.openclaw/workspace/builds")
PUBLIC_HTML = Path("/data/.openclaw/workspace/public_html")
HEARTBEAT_FILE = Path("/data/.openclaw/workspace/builder-runner-heartbeat.json")
LOG_FILE = Path("/data/.openclaw/workspace/builder-runner.log")
ANTHROPIC_KEY_FILE = Path.home() / ".anthropic_key"
CLAUDE_CLI = "claude"  # must be in PATH
MAX_BUILD_TIME_SECONDS = 600  # 10 min cap per build
RUNNER_VERSION = "1.8-direct-deploy"

# Hostinger deploy API
HOSTINGER_TOKEN = "B4V2bxKyjkRgso0JS9CkiCqkqUZ32PhAzA16cxcB87d7b57e"
HOSTINGER_DOMAIN = "agentrocketman.com"

# Deploy cron job id — we trigger it on-demand instead of waiting for the 10s tick
DEPLOY_CRON_JOB_ID = "1468a760-1425-4b84-a0b8-2579ee79d976"

# ─── Helpers ───────────────────────────────────────────────────────────────────
def log(msg, level="INFO"):
    ts = datetime.now(timezone.utc).isoformat(timespec='seconds')
    line = f"[{ts}] [{level}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_FILE, "a") as fh:
            fh.write(line + "\n")
    except Exception:
        pass


def session_cookie():
    """Authenticate against Mission Control and return cookie string."""
    req = urllib.request.Request(
        f"{MC_BASE_URL}/api/auth.php",
        data=json.dumps({"password": MC_PASSWORD}).encode(),
        headers={"Content-Type": "application/json"},
        method="POST"
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            cookies = resp.headers.get_all("Set-Cookie") or []
            for c in cookies:
                if c.startswith("PHPSESSID="):
                    return c.split(";", 1)[0]
    except Exception as e:
        log(f"Auth failed: {e}", "ERROR")
    return None


def mc_get(path, cookie):
    req = urllib.request.Request(f"{MC_BASE_URL}{path}", headers={"Cookie": cookie})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read())


def mc_post(path, payload, cookie):
    data = json.dumps(payload).encode()
    req = urllib.request.Request(
        f"{MC_BASE_URL}{path}",
        data=data,
        headers={"Content-Type": "application/json", "Cookie": cookie},
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.loads(resp.read())


def write_heartbeat(state, last_job=None, last_error=None):
    """Heartbeat both locally and POST to Mission Control so dashboard can see it."""
    data = {
        "version": RUNNER_VERSION,
        "state": state,  # idle | polling | building | error
        "last_poll": datetime.now(timezone.utc).isoformat(),
        "pid": os.getpid(),
        "last_job": last_job,
        "last_error": last_error
    }
    try:
        with open(HEARTBEAT_FILE, "w") as fh:
            json.dump(data, fh)
    except Exception as e:
        log(f"Heartbeat write failed: {e}", "WARN")

    # POST to Mission Control so the dashboard can see runner status
    try:
        payload = dict(data)
        payload["secret"] = "mc-runner-heartbeat-2026"
        req = urllib.request.Request(
            f"{MC_BASE_URL}/api/runner-heartbeat.php",
            data=json.dumps(payload).encode(),
            headers={"Content-Type": "application/json"},
            method="POST"
        )
        urllib.request.urlopen(req, timeout=5).read()
    except Exception as e:
        log(f"Heartbeat POST failed: {e}", "DEBUG")


def slug(s):
    s = re.sub(r"[^a-zA-Z0-9_-]+", "-", s).strip("-").lower()
    return s[:60] or "project"


# ─── Build job execution ───────────────────────────────────────────────────────
def long_poll_for_job():
    """
    Long-poll the server-side queue endpoint. The endpoint hangs until either
    a Builder job appears or the wait timeout elapses. No session cookie needed —
    uses a shared secret.

    Returns a project dict or None.
    """
    url = f"{MC_BASE_URL}/api/runner-queue.php?secret={RUNNER_SECRET}&wait={LONG_POLL_SECONDS}"
    try:
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=LONG_POLL_SECONDS + 10) as resp:
            data = json.loads(resp.read().decode())
            if data.get("job"):
                # Reshape into the same structure as Airtable record
                return {"id": data["project_id"], "fields": data.get("fields", {})}
    except urllib.error.URLError as e:
        log(f"long-poll network error: {e}", "WARN")
    except Exception as e:
        log(f"long-poll error: {e}", "WARN")
    return None


def find_pending_builder_job(cookie):
    """
    Return a project that needs Builder, or None.
    A project needs Builder when:
      - current_stage == 'builder'
      - No recent builder event with status 'running' or 'waiting_approval'
    """
    projects = mc_get("/api/projects/list.php", cookie).get("projects", [])
    if not projects:
        return None

    statuses_resp = mc_get("/api/agent-status/list.php", cookie)
    statuses = statuses_resp.get("statuses", [])

    # Build a map of (project_name, stage) -> latest event
    latest = {}
    for s in statuses:
        f = s.get("fields", {})
        key = (f.get("project_name", ""), f.get("stage", ""))
        latest[key] = s

    for p in projects:
        f = p.get("fields", {})
        if f.get("current_stage") != "builder":
            continue
        # Check if Builder is already running or done
        builder_status = latest.get((f.get("name", ""), "builder"), {}).get("fields", {}).get("status")
        if builder_status in ("running", "waiting_approval", "complete"):
            continue
        # This project needs Builder
        return p

    return None


def collect_agent_context(project_name, cookie, patch_mode=None):
    """Pull prior agent outputs (Scout/Architect/Tester/etc.) relevant to this run.

    For Quick Fix patches we skip context collection entirely — the change is
    surgical, no planning agents ran, and dumping irrelevant context just bloats
    Claude's input tokens.
    """
    if patch_mode == "quick_fix":
        return {}
    statuses = mc_get("/api/agent-status/list.php", cookie).get("statuses", [])
    stages_we_want = ["scout", "architect", "designer", "tester", "reviewer", "code_reviewer"]
    context_map = {}
    for s in statuses:
        f = s.get("fields", {})
        if f.get("project_name") != project_name:
            continue
        stage = f.get("stage", "")
        if stage in stages_we_want:
            context_map[stage] = {
                "agent": f.get("agent_name", "?"),
                "output": f.get("output", "")
            }
    return context_map


def fetch_prior_patches(project, cookie):
    """
    Return a list of {iteration, request} for prior patches in this project's family,
    oldest first.

    Note: all Quick Fix patches typically have parent_project_id pointing to the
    ORIGINAL root (not the immediately preceding patch). So we don't walk a chain —
    we collect ALL siblings (same parent) AND walk the actual parent chain too,
    then deduplicate + sort by creation time.

    This prevents the bug where v1.12 only saw 'original' and didn't know about
    v1.11's date/time block.
    """
    pf = project.get("fields", {})
    my_id = project.get("id")
    parent_id = pf.get("parent_project_id")
    if not parent_id:
        return []
    try:
        projects = mc_get("/api/projects/list.php", cookie).get("projects", [])
    except Exception:
        return []
    if not projects:
        return []

    by_id = {p.get("id"): p for p in projects}

    # Identify the ROOT of this project family by walking back as far as we can.
    root_id = parent_id
    seen = set()
    while root_id and root_id in by_id and root_id not in seen:
        seen.add(root_id)
        parent_of_root = by_id[root_id].get("fields", {}).get("parent_project_id")
        if not parent_of_root:
            break
        root_id = parent_of_root

    # Collect ALL patches under this root (children + grandchildren) plus the root itself.
    relevant = []
    for p in projects:
        pid = p.get("id")
        if pid == my_id:
            continue  # skip the current project
        f = p.get("fields", {})
        # Patch directly under root, OR is the root itself
        if pid == root_id or f.get("parent_project_id") == root_id:
            relevant.append(p)
        # (We don't go deeper than one level under root — grandchildren are rare and
        # would mostly come from explicit chained iterations.)

    # Sort by createdTime ascending
    relevant.sort(key=lambda p: p.get("createdTime", ""))

    # Only include deployed/successful patches to avoid confusing Builder with
    # half-finished or aborted attempts.
    chain = []
    for p in relevant:
        f = p.get("fields", {})
        mode = f.get("patch_mode") or "full"
        deploy_status = f.get("deploy_status", "")
        # Skip patches that never deployed — their changes aren't actually in ./output/
        if mode != "full" and deploy_status != "deployed":
            continue
        chain.append({
            "iteration": f.get("iteration_label") or ("original" if mode == "full" else "?"),
            "request": f.get("patch_request") or ("(original build — see REQUIREMENTS.md)" if mode == "full" else "(no request)"),
            "mode": mode,
            "createdTime": p.get("createdTime", ""),
        })
    return chain


def write_context_files(work_dir, project, context_map):
    """Write REQUIREMENTS.md + per-agent .md files into the build dir."""
    work_dir.mkdir(parents=True, exist_ok=True)
    (work_dir / "output").mkdir(parents=True, exist_ok=True)

    pf = project.get("fields", {})
    patch_mode = pf.get("patch_mode", "full")

    # LEAN MODE: Quick Fix doesn't need REQUIREMENTS.md or upstream stage .md files —
    # those stages didn't even run. Skip writing them entirely (saves disk I/O AND
    # prevents Claude from accidentally reading them with `ls` + `cat`).
    if patch_mode != "quick_fix":
        reqs = pf.get("requirements", "")
        if reqs:
            (work_dir / "REQUIREMENTS.md").write_text(reqs)

        stage_order = ["scout", "architect", "designer", "tester", "reviewer", "code_reviewer"]
        for stage in stage_order:
            if stage in context_map:
                c = context_map[stage]
                (work_dir / f"{stage.upper()}.md").write_text(
                    f"# {stage.upper()} OUTPUT — {c['agent']}\n\n{c['output']}"
                )

    # If patch mode, include patch context (Quick Fix gets a slim version)
    if patch_mode and patch_mode != "full":
        if patch_mode == "quick_fix":
            # Slim PATCH_INFO — just the change request, no meta crap
            (work_dir / "PATCH_INFO.md").write_text(
                f"# Change Request\n\n{pf.get('patch_request', '(none)')}\n"
            )
        else:
            (work_dir / "PATCH_INFO.md").write_text(
                f"# Patch Information\n\n"
                f"**Mode:** {patch_mode}\n"
                f"**Iteration:** {pf.get('iteration_label', '?')}\n"
                f"**Parent project ID:** {pf.get('parent_project_id', '?')}\n\n"
                f"**Change request:**\n{pf.get('patch_request', '(none)')}\n"
            )

    # Pass through any prior patches (collected by the caller) so Builder knows
    # what previous iterations added and doesn't accidentally delete them.
    prior_patches = context_map.get("_prior_patches") or []
    if prior_patches:
        lines = ["# Prior Patches on this Project\n"]
        lines.append("_Each block below is a previous iteration that has already been merged into the\n"
                     "`./output/` codebase. **Do NOT delete what these patches added** unless this\n"
                     "iteration's change request explicitly asks for removal._\n")
        for i, pp in enumerate(prior_patches, 1):
            lines.append(f"\n## {i}. {pp.get('iteration')} ({pp.get('mode')})\n")
            lines.append(pp.get("request") or "(no request)")
            lines.append("\n")
        (work_dir / "PRIOR_PATCHES.md").write_text("\n".join(lines))

    # BUILDER_BRIEF — skip for Quick Fix (instructions inlined into the -p prompt instead)
    if patch_mode != "quick_fix":
        brief = build_builder_brief(project, context_map)
        (work_dir / "BUILDER_BRIEF.md").write_text(brief)


def build_builder_brief(project, context_map):
    pf = project.get("fields", {})
    patch_mode = pf.get("patch_mode", "full")

    if patch_mode == "quick_fix":
        change = pf.get("patch_request", "")
        # PRIOR_PATCHES.md (if present) lists what previous Quick Fixes added/changed
        prior_block = (
            "\n## Prior Quick Fixes on this project\n"
            "Read `PRIOR_PATCHES.md` to see what previous patches added. The user may be\n"
            "referring to one of those when they say 'the date/time' or 'the button'.\n"
            "NEVER DELETE existing functionality unless the change request explicitly says\n"
            "to remove it. If a prior patch added a feature, treat it as part of the spec.\n"
        )
        return (
            "# QUICK FIX — SURGICAL CHANGE\n\n"
            f"**Change requested:** {change}\n\n"
            "## Workflow (FOLLOW IN ORDER)\n"
            "1. **Read PRIOR_PATCHES.md** (if it exists) to understand what previous Quick Fixes did.\n"
            "2. **Identify the target.** Use `grep -rn` to find the EXACT element/style/function\n"
            "   that the user is referring to. If ambiguous, prefer the LAST thing prior patches\n"
            "   added — that's almost always what the user means.\n"
            "3. **Read ONLY the file(s) you need to edit.** Do NOT read the whole codebase.\n"
            "4. **Edit the minimum.** Touching extra files = wasted tokens AND risk of breaking\n"
            "   things that aren't yours to change.\n"
            "5. Write a SHORT BUILDER_REPORT.md — max 5 lines, just list files changed.\n\n"
            "## ABSOLUTE RULES\n"
            "- **DO NOT DELETE** any existing element, function, or style. Only modify.\n"
            "- **DO NOT** refactor. **DO NOT** improve. **DO NOT** add features beyond the request.\n"
            "- **DO NOT** touch README.md, .htaccess, config.php, or any docs unless the change\n"
            "  is explicitly about them.\n"
            "- If the change request is ambiguous, pick the interpretation that requires the\n"
            "  FEWEST file changes.\n"
            + prior_block +
            "\nExisting code is in ./output/. Start with `ls output/` then `grep` for relevant terms."
        )
    if patch_mode == "bug_fix":
        return (
            "# 🩹 BUILDER BRIEF — Bug Fix\n\n"
            "Read PATCH_INFO.md for the bug description. Read CODE_REVIEWER.md if present. "
            "Read existing code in ./output/. Fix the bug. Run any tests you can. "
            "Write BUILDER_REPORT.md."
        )
    if patch_mode == "add_feature":
        return (
            "# ✨ BUILDER BRIEF — Add Feature\n\n"
            "Read PATCH_INFO.md, SCOUT.md (new scope), ARCHITECT.md (design). "
            "Existing code in ./output/. ADD the new feature; don't break existing. "
            "Write BUILDER_REPORT.md."
        )

    # Default: full build
    return (
        "# 🛠️ BUILDER BRIEF — Full Build\n\n"
        "Read REQUIREMENTS.md and all prior agent outputs (SCOUT.md, ARCHITECT.md, "
        "DESIGNER.md if present, TESTER.md, REVIEWER.md). Build the project per spec into ./output/.\n\n"
        "**Address any issues flagged by Reviewer.** "
        "Run tests where possible. Be efficient — don't overengineer.\n\n"
        "When done, write BUILDER_REPORT.md summarizing files created, test results, "
        "and any issues hit/resolved."
    )


def run_claude_code(work_dir, project):
    """Run Claude Code CLI in the work directory. Return (success, output_text)."""
    pf = project.get("fields", {})

    # Build the prompt — point Claude at the brief and context files
    patch_mode = pf.get("patch_mode", "full")

    if patch_mode == "quick_fix":
        # LEAN INLINE PROMPT — no BUILDER_BRIEF.md read needed. Everything Claude needs
        # is right here. PATCH_INFO.md has just the change request, no fluff.
        change = pf.get("patch_request", "")
        has_prior = (work_dir / "PRIOR_PATCHES.md").exists()
        prior_line = (
            "First read PRIOR_PATCHES.md so you know what previous fixes added — DO NOT delete them. "
            if has_prior else ""
        )
        prompt = (
            f"SURGICAL PATCH. Change requested: {change}\n\n"
            f"{prior_line}"
            f"Workflow: (1) `grep -rn` in ./output/ to find the exact element/style/function. "
            f"(2) Read ONLY the file you'll edit. (3) Make the minimum edit. "
            f"RULES: Do not delete existing functionality. Do not refactor. Do not add features beyond the request. "
            f"Do not touch README, .htaccess, or config.php. Do NOT write BUILDER_REPORT.md — just make the edit and stop."
        )
    elif patch_mode and patch_mode != "full":
        prompt = (
            f"Read PATCH_INFO.md and BUILDER_BRIEF.md. Read existing code in ./output/. "
            f"Apply the patch as a {patch_mode} per the brief. Be surgical, don't rewrite. "
            f"After edits, write BUILDER_REPORT.md summarizing exactly which files changed and what was fixed/added."
        )
    else:
        prompt = (
            "Read REQUIREMENTS.md, SCOUT.md, ARCHITECT.md, TESTER.md, REVIEWER.md "
            "(and any other *.md files in this directory). Then build the project per spec. "
            "Put all source files in ./output/. Address any issues Reviewer flagged. "
            "When done, write BUILDER_REPORT.md summarizing files created and any decisions."
        )

    # Load API key
    if not ANTHROPIC_KEY_FILE.exists():
        return False, "ANTHROPIC_API_KEY file missing"
    api_key = ANTHROPIC_KEY_FILE.read_text().strip()

    env = os.environ.copy()
    env["ANTHROPIC_API_KEY"] = api_key

    # Model choice: Haiku for surgical Quick Fix (10× cheaper than Sonnet, fast enough),
    # Sonnet for everything else (more thorough reasoning needed).
    model = "claude-haiku-4-5" if patch_mode == "quick_fix" else "claude-sonnet-4-5"

    cmd = [
        CLAUDE_CLI,
        "-p", prompt,
        "--model", model,
        "--output-format", "json",  # so we can parse real token counts + cost
    ]

    if patch_mode == "quick_fix":
        # LEAN TOOLSET: no `cat` (forces Claude to use Read, which is cheaper +
        # gives line numbers), no `mkdir` (Quick Fix never creates dirs), no Write
        # (surgical = Edit only, no new files). Hard turn cap of 15 (was 10 —
        # but ambiguous requests like 're-add the timer' need a couple of
        # exploratory turns before the edit). Allow `git` so Claude can use
        # `git log` / `git diff` to understand history without burning turns
        # on permission_denied retries.
        cmd += [
            "--max-turns", "15",
            "--allowedTools",
            "Read", "Edit",
            "Bash(ls:*)", "Bash(grep:*)", "Bash(git:*)",
        ]
    else:
        cmd += [
            "--allowedTools",
            "Read", "Write", "Edit",
            "Bash(ls:*)", "Bash(cat:*)", "Bash(mkdir:*)", "Bash(grep:*)",
        ]

    log(f"Builder using model: {model} (max-turns: {'15' if patch_mode == 'quick_fix' else 'unlimited'})")

    log(f"Launching Claude Code in {work_dir} ({patch_mode})")
    try:
        proc = subprocess.run(
            cmd,
            cwd=str(work_dir),
            env=env,
            capture_output=True,
            text=True,
            timeout=MAX_BUILD_TIME_SECONDS
        )
        ok = proc.returncode == 0
        raw_out = (proc.stdout or "")
        # Try to parse JSON envelope from Claude Code for real token/cost stats
        meta = {}
        text_out = raw_out
        try:
            parsed = json.loads(raw_out)
            meta = {
                "input_tokens": parsed.get("usage", {}).get("input_tokens", 0),
                "output_tokens": parsed.get("usage", {}).get("output_tokens", 0),
                "cost_usd": parsed.get("total_cost_usd") or parsed.get("cost", 0),
                "duration_ms": parsed.get("duration_ms", 0),
            }
            text_out = parsed.get("result") or parsed.get("text") or raw_out
        except Exception:
            pass
        if proc.stderr:
            text_out += ("\n" + proc.stderr)
        return ok, text_out, meta
    except subprocess.TimeoutExpired:
        return False, f"Claude Code timed out after {MAX_BUILD_TIME_SECONDS}s", {}
    except Exception as e:
        return False, f"Claude Code launch failed: {e}\n{traceback.format_exc()}", {}


def deploy_build_to_live(project, build_dir, cookie):
    """
    Push the build_dir/output/ to the project's deploy_path on Hostinger.
    Returns (success, deploy_url, message).
    """
    pf = project.get("fields", {})
    deploy_path = pf.get("deploy_path", "")
    if not deploy_path:
        return False, None, "No deploy_path set on project"

    # Normalize
    if not deploy_path.startswith("/"):
        deploy_path = "/" + deploy_path
    if not deploy_path.endswith("/"):
        deploy_path += "/"

    src = build_dir / "output"
    if not src.exists():
        return False, None, f"output/ dir missing in {build_dir}"

    # Target directory in public_html (strip leading slash)
    target = PUBLIC_HTML / deploy_path.strip("/")
    target.mkdir(parents=True, exist_ok=True)

    # Preserve any production config.php (so BASE_URL etc. survive)
    saved_config = None
    cfg = target / "config.php"
    if cfg.exists():
        try:
            saved_config = cfg.read_text()
        except Exception:
            saved_config = None

    # Copy everything from output/ to target, excluding internal docs.
    # Use `cp -rf` and CHECK exit codes — the old check=False was swallowing copy errors,
    # which meant builds would silently fail to actually update the live deploy dir.
    skip_files = {"BUILDER_REPORT.md", "BUILDER_V2_REPORT.md"}
    copy_errors = []
    try:
        for item in src.iterdir():
            if item.name in skip_files:
                continue
            dst = target / item.name
            if item.is_dir():
                dst.mkdir(parents=True, exist_ok=True)
                r = subprocess.run(["cp", "-rf", str(item) + "/.", str(dst) + "/"], capture_output=True, text=True)
                if r.returncode != 0:
                    copy_errors.append(f"dir {item.name}: {r.stderr.strip()}")
            else:
                r = subprocess.run(["cp", "-f", str(item), str(dst)], capture_output=True, text=True)
                if r.returncode != 0:
                    copy_errors.append(f"file {item.name}: {r.stderr.strip()}")
    except Exception as e:
        return False, None, f"File copy failed: {e}"

    if copy_errors:
        return False, None, "Copy errors:\n" + "\n".join(copy_errors)

    # Verify the copy actually landed — spot-check that the target has the same
    # index/main file as the source.
    spot_checks = ["index.html", "index.php", "main.js", "app.js"]
    for sc in spot_checks:
        src_file = src / sc
        if src_file.exists():
            dst_file = target / sc
            if not dst_file.exists():
                return False, None, f"Spot-check failed: {sc} missing in target after copy"
            try:
                if src_file.read_bytes() != dst_file.read_bytes():
                    return False, None, f"Spot-check failed: {sc} content mismatch after copy"
            except Exception:
                pass
            break

    # Restore production config.php if we had one
    if saved_config is not None:
        try:
            cfg.write_text(saved_config)
        except Exception as e:
            log(f"Could not restore config.php: {e}", "WARN")

    # DIRECT DEPLOY: tar + push via Hostinger API ourselves. No cron, no agent boot.
    # Saves ~30-50s per build and ~$0.10/hr of idle cron token burn.
    deploy_url = f"https://{HOSTINGER_DOMAIN}{deploy_path}"
    try:
        ts = int(time.time())
        tar_path = Path(f"/tmp/mc-deploy-{ts}-{slug(pf.get('name', 'project'))}.tar.gz")

        # Tar the entire public_html (matches what the old cron did). Exclude
        # user-uploaded images so they aren't shipped or accidentally overwritten
        # by missing files in the archive (Hostinger does a sync-style deploy).
        log(f"📦 Building deploy archive...")
        tar_t0 = time.time()
        tar_cmd = [
            "tar", "-czf", str(tar_path),
            "--exclude=./bin-pics/*.jpg",
            "--exclude=./bin-pics/*.png",
            "."
        ]
        tar_proc = subprocess.run(tar_cmd, cwd=str(PUBLIC_HTML), capture_output=True, text=True, timeout=120)
        if tar_proc.returncode != 0:
            return False, deploy_url, f"tar failed: {tar_proc.stderr}"
        tar_size_mb = tar_path.stat().st_size / 1024 / 1024
        log(f"   archive: {tar_size_mb:.1f} MB in {time.time()-tar_t0:.1f}s")

        # Call the Node deploy shim directly
        log(f"🚀 Deploying to {HOSTINGER_DOMAIN}{deploy_path}...")
        deploy_t0 = time.time()
        node_cmd = ["node", "/data/.openclaw/workspace/hostinger-deploy.mjs", HOSTINGER_DOMAIN, str(tar_path)]
        deploy_env = os.environ.copy()
        deploy_env["HOSTINGER_API_TOKEN"] = HOSTINGER_TOKEN
        deploy_proc = subprocess.run(node_cmd, capture_output=True, text=True, timeout=180, env=deploy_env)

        # The shim writes logs to stderr and a JSON result on stdout
        try:
            result = json.loads((deploy_proc.stdout or "").strip().splitlines()[-1])
        except (json.JSONDecodeError, IndexError):
            result = {"success": False, "error": "could not parse deploy shim output"}

        # Clean up tar regardless
        try:
            tar_path.unlink()
        except Exception:
            pass

        if not result.get("success"):
            err = result.get("error") or deploy_proc.stderr or "unknown"
            return False, deploy_url, f"Deploy failed: {err}"

        log(f"✅ Hostinger deploy accepted in {time.time()-deploy_t0:.1f}s")

        # Mark project as deployed in Mission Control (the deploy.php endpoint
        # patches Airtable + writes a Deploy-Bot event for the timeline)
        try:
            mc_post("/api/projects/deploy.php", {
                "project_id": project.get("id"),
                "internal_secret": RUNNER_SECRET,
            }, cookie)
        except Exception as e:
            log(f"Could not mark project deployed in MC: {e}", "WARN")

        return True, deploy_url, f"Deployed directly to {deploy_url}"
    except subprocess.TimeoutExpired:
        return False, deploy_url, "Deploy timed out"
    except Exception as e:
        return False, deploy_url, f"Direct deploy failed: {e}\n{traceback.format_exc()}"


def post_builder_result(project, success, output_text, cookie, build_dir, meta=None):
    """Post Builder result to Mission Control webhook."""
    pf = project.get("fields", {})
    project_name = pf.get("name", "?")

    # FAILURE path: use Claude's actual output (and parse the error envelope if
    # present), NOT the parent project's stale BUILDER_REPORT.md — that would
    # mask the real error with a fake "success" report from the prior build.
    if not success:
        err_summary = output_text[-3000:] if len(output_text) > 3000 else output_text
        terminal_reason = (meta or {}).get("terminal_reason") or ""
        errors = (meta or {}).get("errors") or []
        report = (
            f"# ❌ Builder failed\n\n"
            f"**Terminal reason:** {terminal_reason or 'unknown'}\n\n"
            f"**Errors:** {', '.join(errors) if errors else '(none reported)'}\n\n"
            f"**Tail of Claude output:**\n```\n{err_summary}\n```"
        )
    else:
        # SUCCESS path: read BUILDER_REPORT.md if Claude wrote one, else use
        # Claude's result text. We DON'T fall back to the parent project's
        # stale BUILDER_REPORT.md (it might be cloned in from a prior build).
        report_path = build_dir / "output" / "BUILDER_REPORT.md"
        if not report_path.exists():
            report_path = build_dir / "BUILDER_REPORT.md"

        # Only trust the report if it was actually written/modified during THIS
        # build run (mtime within the last 10 minutes). Otherwise it's stale
        # from a parent-code clone.
        use_report_file = False
        if report_path.exists():
            try:
                age = time.time() - report_path.stat().st_mtime
                use_report_file = age < 600  # 10 minutes
            except Exception:
                use_report_file = False

        if use_report_file:
            report = report_path.read_text()
        else:
            # Quick Fix intentionally tells Claude NOT to write a report, so the
            # "result" text from the Claude Code JSON envelope is what we want.
            report = output_text if output_text else "✅ Build complete (no report written; Quick Fix mode)."
            if len(report) > 8000:
                report = report[-8000:]
            report = f"# ✅ Builder — surgical edit complete\n\n```\n{report}\n```"

    # Truncate for Airtable
    if len(report) > 40000:
        report = report[:40000] + "\n\n...[truncated]"

    status = "waiting_approval" if success else "failed"
    # Use real token counts + cost from Claude Code JSON output when available;
    # otherwise fall back to a rough heuristic.
    if meta and (meta.get("input_tokens") or meta.get("output_tokens")):
        in_t = int(meta.get("input_tokens", 0) or 0)
        out_t = int(meta.get("output_tokens", 0) or 0)
        estimated_tokens = in_t + out_t
        estimated_cost = round(float(meta.get("cost_usd", 0) or 0), 4)
        log(f"Builder real usage: in={in_t} out={out_t} cost=${estimated_cost}")
    else:
        estimated_cost = round(min(5.0, max(0.05, len(report) / 30000.0)), 2)
        estimated_tokens = int(estimated_cost * 1000 / 0.015)
        log(f"Builder estimated usage (no JSON): cost=${estimated_cost}")

    payload = {
        "project_name": project_name,
        "stage": "builder",
        "agent_name": "Builder-Runner (auto)",
        "status": status,
        "output": report,
        "tokens_used": estimated_tokens,
        "cost": estimated_cost
    }
    try:
        return mc_post("/api/agent-status/webhook.php", payload, cookie)
    except Exception as e:
        log(f"Failed to post Builder result: {e}", "ERROR")
        return None


def process_job(project, cookie):
    """Build + deploy the project. Returns True on success."""
    pf = project.get("fields", {})
    project_name = pf.get("name", "?")
    project_slug = slug(project_name)
    work_dir = BUILDS_DIR / project_slug

    log(f"📦 Processing job: {project_name} → {work_dir}")
    write_heartbeat("building", last_job=project_name)

    # Mark project as Builder running
    try:
        mc_post("/api/agent-status/webhook.php", {
            "project_name": project_name,
            "stage": "builder",
            "agent_name": "Builder-Runner (auto)",
            "status": "running",
            "output": "Builder runner picked up job. Pulling context + spawning Claude Code CLI.",
            "tokens_used": 0,
            "cost": 0
        }, cookie)
    except Exception as e:
        log(f"Couldn't post running status: {e}", "WARN")

    # Collect context (Quick Fix skips this — surgical change doesn't need planning agent outputs)
    patch_mode_early = pf.get("patch_mode")
    context_map = collect_agent_context(project_name, cookie, patch_mode_early)
    # For patches, also collect the prior-patch chain so the Builder doesn't
    # delete features added by earlier Quick Fixes.
    if patch_mode_early and patch_mode_early != "full":
        try:
            context_map["_prior_patches"] = fetch_prior_patches(project, cookie)
        except Exception as e:
            log(f"Couldn't fetch prior patches: {e}", "WARN")
    write_context_files(work_dir, project, context_map)

    # For patch modes, copy existing parent code into ./output/ FIRST so Builder edits in place
    patch_mode = pf.get("patch_mode")
    parent_id = pf.get("parent_project_id")
    if patch_mode and patch_mode != "full" and parent_id:
        try:
            parents = mc_get("/api/projects/list.php", cookie).get("projects", [])
            parent = next((x for x in parents if x.get("id") == parent_id), None)
            if parent:
                parent_slug = slug(parent.get("fields", {}).get("name", ""))
                parent_output = BUILDS_DIR / parent_slug / "output"
                if parent_output.exists():
                    log(f"Copying parent code from {parent_output} → {work_dir / 'output'}")
                    subprocess.run(["cp", "-r", f"{parent_output}/.", str(work_dir / "output")], check=False)
        except Exception as e:
            log(f"Parent code copy failed: {e}", "WARN")

    # Run Claude Code
    success, output_text, run_meta = run_claude_code(work_dir, project)
    log(f"Claude Code finished: success={success}")

    # Auto-deploy build to live URL so Smoke Test sees actual patched code
    deploy_msg = ""
    if success and pf.get("deploy_path"):
        log("Auto-deploying build to live URL...")
        write_heartbeat("deploying", last_job=project_name)
        deploy_ok, deploy_url, deploy_msg = deploy_build_to_live(project, work_dir, cookie)
        if deploy_ok:
            log(f"✅ Deployed: {deploy_url}")
            output_text += f"\n\n———\n✅ Auto-deployed to {deploy_url}\n"
        else:
            log(f"⚠️ Auto-deploy failed: {deploy_msg}", "WARN")
            output_text += f"\n\n———\n⚠️ Auto-deploy failed: {deploy_msg}\n"

    # Post result
    post_builder_result(project, success, output_text, cookie, work_dir, run_meta)
    return success


# ─── Queue runner (server-side auto-start) ──────────────────────────────────────
def read_queue_active():
    """Read the server-side queue-runner on/off switch via HTTP. Defaults to False."""
    try:
        url = f"{MC_BASE_URL}/api/queue-runner.php?secret={RUNNER_SECRET}"
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read())
            return bool(data.get("active"))
    except Exception:
        return False


def start_project(project_id):
    """Kick off the first pipeline stage of a project via the internal start endpoint.

    Uses the shared secret so no session cookie is required.
    """
    data = json.dumps({"project_id": project_id, "internal_secret": RUNNER_SECRET}).encode()
    req = urllib.request.Request(
        f"{MC_BASE_URL}/api/agents/start.php",
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        status = getattr(resp, "status", None) or resp.getcode()
        body = resp.read()
        try:
            data = json.loads(body)
        except Exception:
            data = {"raw": body.decode("utf-8", "replace")[:500]}
        if not (200 <= status < 300):
            raise RuntimeError(f"start.php returned HTTP {status}: {data}")
        if isinstance(data, dict) and data.get("error"):
            raise RuntimeError(f"start.php error: {data.get('error')}")
        log(f"Queue runner start_project response: {data}")
        return data


def process_queue(cookie):
    """If the server-side queue runner is active, auto-start the oldest queued project
    whenever nothing else is in progress. Runs even when the Pipeline page is closed.
    """
    if not read_queue_active():
        return

    try:
        projects = mc_get("/api/projects/list.php", cookie).get("projects", [])
    except Exception as e:
        log(f"process_queue: could not list projects: {e}", "WARN")
        return

    queued = [p for p in projects if (p.get("fields", {}).get("current_stage") == "queued")]
    if not queued:
        log("Queue runner active but no queued jobs — nothing to start")
        return

    def is_active(p):
        f = p.get("fields", {})
        stage = f.get("current_stage", "")
        if stage in ("queued", "failed", "paused"):
            return False
        if f.get("deploy_status") == "deployed":
            return False
        return True

    active = [p for p in projects if is_active(p)]
    if active:
        anames = ", ".join(p.get("fields", {}).get("name", "?") for p in active)
        log(f"Queue runner active but a job is already in progress ({anames}) — "
            f"holding {len(queued)} queued job(s)")
        return

    queued.sort(key=lambda p: p.get("createdTime", ""))
    oldest = queued[0]
    pname = oldest.get("fields", {}).get("name", "?")
    log(f"🚀 Queue runner: starting next queued project: {pname}")
    try:
        start_project(oldest.get("id"))
    except Exception as e:
        log(f"process_queue: start_project failed for {pname}: {e}", "WARN")


# ─── Main loop ─────────────────────────────────────────────────────────────────
def main():
    log(f"Builder Runner v{RUNNER_VERSION} starting (PID {os.getpid()})")
    write_heartbeat("starting")

    while True:
        try:
            # PRIMARY: long-poll the queue endpoint (returns within ~1s of a job appearing)
            write_heartbeat("idle")
            job = long_poll_for_job()

            if job:
                pname = job.get("fields", {}).get("name", "?")
                log(f"🔔 Long-poll picked up Builder job: {pname}")
                # Need cookie to actually process the job
                cookie = session_cookie()
                if not cookie:
                    log("No session cookie — retrying in 5s", "WARN")
                    write_heartbeat("error", last_error="auth failed")
                    time.sleep(5)
                    continue
                try:
                    process_job(job, cookie)
                except Exception as e:
                    log(f"process_job error: {e}\n{traceback.format_exc()}", "ERROR")
                    write_heartbeat("error", last_error=str(e), last_job=pname)
                # After a builder job finishes, advance the server-side queue if active
                process_queue(cookie)
            else:
                # No job — long-poll already waited. Check the queue once per idle
                # cycle (~every LONG_POLL_SECONDS) so queued jobs auto-start even when
                # the Pipeline page is closed.
                if read_queue_active():
                    cookie = session_cookie()
                    if cookie:
                        process_queue(cookie)

        except urllib.error.URLError as e:
            log(f"Network error: {e}", "WARN")
            write_heartbeat("error", last_error=f"network: {e}")
            time.sleep(POLL_INTERVAL_SECONDS)
        except Exception as e:
            log(f"Loop error: {e}\n{traceback.format_exc()}", "ERROR")
            write_heartbeat("error", last_error=str(e))
            time.sleep(POLL_INTERVAL_SECONDS)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("Interrupted, exiting")
        write_heartbeat("stopped")
        sys.exit(0)
