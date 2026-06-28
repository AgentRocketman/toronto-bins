# Mission Control — Build Spec

Build a 2-screen dashboard + backend scaffolding for an AI multi-agent orchestration system.

## Tech Stack
- **Frontend:** Vanilla HTML/CSS/JS (no framework)
- **Backend:** PHP (matches existing project at /data/.openclaw/workspace/public_html/)
- **Data:** Airtable
- **Live updates:** Server-Sent Events (SSE)
- **Auth:** Password-protected (single user)

## File Structure (create under /data/.openclaw/workspace/public_html/mission-control/)
```
mission-control/
├── index.html              # Login page
├── dashboard.html          # Screen 1: Project Dashboard
├── pipeline.html           # Screen 2: Agent Pipeline Board
├── assets/
│   ├── style.css           # Shared styles
│   ├── app.js              # Shared client logic
│   └── auth.js             # Login/session check
└── api/
    ├── config.php          # MC-specific config (extends main config.php)
    ├── auth.php            # Login + session
    ├── projects/
    │   ├── create.php      # POST: create new project
    │   ├── list.php        # GET: list all projects
    │   ├── get.php         # GET: single project details
    │   └── update.php      # PATCH: update project status/budget
    ├── agent-status/
    │   ├── webhook.php     # POST: agents send status updates here
    │   ├── list.php        # GET: current status of all agents
    │   └── stream.php      # SSE: live stream of status events
    ├── approvals/
    │   ├── list.php        # GET: pending approvals
    │   ├── create.php      # POST: agent requests approval
    │   └── decide.php      # POST: user approves/rejects
    └── budget/
        └── check.php       # GET: budget status for a project
```

## Airtable Tables (already created in base apptYNRJTXwItvied)

### MissionControl_Projects (tblZDjRO5OSIqzmEY)
- name (singleLineText) — primary
- requirements (multilineText)
- status (singleSelect: draft, scout, tester, reviewer, architect, innovator, builder, complete, paused, failed)
- current_agent (singleLineText)
- budget_cap (number, 2 decimals) — default 10.00
- budget_spent (number, 2 decimals)
- notes (multilineText)

### MissionControl_AgentStatus (tblwlhJRTnuHzivlb)
- event_id (singleLineText) — primary, UUID
- project_name (singleLineText)
- stage (singleSelect: scout, tester, reviewer, architect, innovator, builder)
- agent_name (singleLineText)
- status (singleSelect: idle, running, waiting_approval, complete, failed)
- output (multilineText)
- tokens_used (number, 0 decimals)
- cost (number, 4 decimals)

### MissionControl_Approvals (tblr4Wex6GwRwz4WE)
- approval_id (singleLineText) — primary, UUID
- project_name (singleLineText)
- stage (singleLineText)
- agent_output (multilineText)
- decision (singleSelect: pending, approved, rejected)
- comments (multilineText)
- telegram_sent (checkbox)

## Airtable Credentials (in config.php as constants)
- AIRTABLE_BASE: apptYNRJTXwItvied
- AIRTABLE_TOKEN: patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd
- MC_PROJECTS_TABLE: tblZDjRO5OSIqzmEY
- MC_AGENTSTATUS_TABLE: tblwlhJRTnuHzivlb
- MC_APPROVALS_TABLE: tblr4Wex6GwRwz4WE

Use the existing /data/.openclaw/workspace/public_html/api/config.php helpers (airtableRequest, corsHeaders) where possible — extend, don't duplicate.

## Auth (Simple Session-Based)
- Login page: index.html → POST password → api/auth.php
- Password: stored in config.php constant MC_ADMIN_PASSWORD (use: "MissionControl2026!")
- Session: PHP $_SESSION['mc_authenticated'] = true
- All API endpoints check session at top; redirect to index.html if not authenticated
- Add a logout link in nav

## Screen 1: Project Dashboard (dashboard.html)

**Layout:**
- Top nav bar: "Mission Control" logo (left), nav links (Dashboard, Pipeline, Logout) (right)
- Stats row: Active Projects | Pending Approvals | Total Spent (live updated)
- "Create New Project" button (top right of project list)
- Project list (card grid):
  - Each card: name, status badge (color-coded), current agent, progress bar (which of 6 stages), budget bar (spent/cap), "View" button → opens detail modal/page

**Create Project Modal:**
- Form: name, requirements (textarea), budget cap (default $10)
- POST to /api/projects/create.php
- Initial status: "draft"

**Live Updates:**
- Subscribe to SSE stream at /api/agent-status/stream.php
- Update project cards in real-time as agents progress

**Design:**
- Dark mode dashboard aesthetic (background #0f1419, cards #1a1f2e, accent #71b80c green for status, blue #3b82f6 for actions)
- Status badge colors: draft=gray, scout=blue, tester=purple, reviewer=yellow, architect=pink, innovator=orange, builder=cyan, complete=green, paused=gray, failed=red
- Modern, clean, mobile-responsive

## Screen 2: Agent Pipeline Board (pipeline.html)

**Layout:**
- Same nav bar
- 6-column kanban-style board: Scout → Tester → Reviewer → Architect → Innovator → Builder
- Each column shows project cards currently at that stage
- Cards show: project name, agent_name, status (running spinner / waiting_approval ⚠️ / complete ✅)
- Pending approvals highlighted with yellow border + pulse animation
- Click card → modal with agent output + approve/reject buttons (for pending approvals)

**Live Updates:**
- SSE stream — cards move between columns as agents update status

**Approval Modal:**
- Shows: project name, stage, agent name, full agent output
- "Approve" button (green) + "Reject" button (red)
- Comment box (optional)
- POST to /api/approvals/decide.php

## Backend Logic

### api/projects/create.php
- Validate: name (required, unique), requirements (required), budget_cap (default 10)
- Create record in MissionControl_Projects with status=draft
- Return {success, project_id}

### api/projects/list.php
- GET all projects from Airtable
- Return as JSON array
- Sort by created (latest first)

### api/projects/get.php?id=X
- Return single project + related agent status events + pending approvals

### api/projects/update.php
- PATCH a project (status, budget_spent, current_agent, notes)

### api/agent-status/webhook.php
- Webhook for agents to POST status updates
- Input: {project_name, stage, agent_name, status, output, tokens_used, cost}
- Logic:
  1. Generate event_id (UUID)
  2. Insert row in MissionControl_AgentStatus
  3. Update project's status + current_agent + budget_spent (cumulative)
  4. Check budget: if budget_spent >= budget_cap, set project status=paused + create approval request
  5. Trigger SSE event for all connected clients
- Return {success, event_id}

### api/agent-status/list.php
- Return latest status per (project, stage) combination
- Used by pipeline.html on initial load

### api/agent-status/stream.php (SSE)
- Long-polling Server-Sent Events
- Stream new events from MissionControl_AgentStatus
- Poll Airtable every 3 seconds for new events since last seen
- Format: `event: status\ndata: {json}\n\n`
- Use proper SSE headers: Content-Type: text/event-stream, Cache-Control: no-cache, Connection: keep-alive

### api/approvals/create.php
- Agent triggers this when output needs approval
- Input: {project_name, stage, agent_output}
- Creates approval record (decision=pending)
- Sends Telegram notification (POST to Telegram Bot API)
- Returns {approval_id}

### api/approvals/list.php
- Return all pending approvals

### api/approvals/decide.php
- Input: {approval_id, decision: approved|rejected, comments}
- Update approval record
- If approved: webhook back to agent to continue
- If rejected: update project status=paused

### api/budget/check.php?project=X
- Return {budget_cap, budget_spent, remaining, percent_used, warning_at_10: bool}

## Telegram Integration (for approval pings)
- Bot token: stored in config.php as TG_BOT_TOKEN (placeholder for now: "REPLACE_WITH_TELEGRAM_BOT_TOKEN")
- Chat ID: stored as TG_CHAT_ID (placeholder: "8714809782")
- Message format:
  ```
  🚨 *Approval Needed*
  Project: {project_name}
  Stage: {stage}
  Agent: {agent_name}
  
  {first 200 chars of output}...
  
  Decide: https://agentrocketman.com/mission-control/pipeline.html
  ```

## Quality Requirements
- All PHP files: include error_reporting + try/catch + return JSON errors gracefully
- All frontend: handle network errors with toast notifications
- CORS: only allow same-origin
- Sanitize all inputs before sending to Airtable
- Use existing helpers from /data/.openclaw/workspace/public_html/api/config.php where they exist

## DO NOT
- Don't deploy yet — just build to /data/.openclaw/workspace/public_html/mission-control/
- Don't modify any existing files outside the mission-control/ directory
- Don't touch existing api/config.php — create a new mission-control/api/config.php that includes/extends it
- Don't add fancy frameworks (no React, no Vue, no jQuery) — vanilla JS only

## When Done
Print a summary of:
1. All files created (full paths)
2. Any issues or assumptions made
3. Next steps (testing locally, deployment plan)
