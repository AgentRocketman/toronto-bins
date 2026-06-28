<?php
/**
 * Reusable patch/iteration creation logic for Mission Control.
 * Used by both POST /projects/patch.php and auto-iteration on rejection.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../agents/prompts.php';

/**
 * Create a patch/iteration child project and trigger its first stage.
 *
 * @param string $parentId Airtable record ID of the parent project
 * @param string $mode One of: 'quick_fix', 'bug_fix', 'add_feature'
 * @param string $request The change request text
 * @param float $budgetCap Budget cap for the child project
 * @param string $internalSecret Secret for triggering agents/run.php
 * @return array Associative array with keys:
 *   - success (bool)
 *   - project_id (string|null)
 *   - project_name (string|null)
 *   - iteration_label (string|null)
 *   - first_stage (string|null)
 *   - error (string|null) on failure
 */
function mcCreatePatchProject($parentId, $mode, $request, $budgetCap, $internalSecret, $queue = false) {
    try {
        if (!in_array($mode, ['quick_fix', 'bug_fix', 'add_feature'], true)) {
            return ['success' => false, 'error' => 'mode must be quick_fix, bug_fix, or add_feature'];
        }

        // Load parent project
        $parentResult = airtableRequest('GET', MC_PROJECTS_TABLE, [], $parentId);
        if ($parentResult['code'] !== 200) {
            return ['success' => false, 'error' => 'Parent project not found'];
        }
        $parent = $parentResult['body'];
        $parentFields = $parent['fields'];

        // Determine iteration label by counting existing children
        $childrenResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
            'filterByFormula' => "parent_project_id='" . addslashes($parentId) . "'"
        ]);
        $existingChildren = $childrenResult['body']['records'] ?? [];
        $iterationNum = count($existingChildren) + 1;
        $parentLabel = $parentFields['iteration_label'] ?? 'v1';
        // For patches under v1, label as v1.1, v1.2, etc.
        // For patches under v1.1, label as v1.2 (flat at second decimal — keeps things simple)
        if (strpos($parentLabel, '.') === false) {
            $iterationLabel = $parentLabel . '.' . $iterationNum;
        } else {
            // sibling of an existing patch — bump the patch number
            $parts = explode('.', $parentLabel);
            $iterationLabel = $parts[0] . '.' . $iterationNum;
        }

        // Mode-specific friendly description for the project name suffix
        $modeShort = [
            'quick_fix' => 'fix',
            'bug_fix' => 'bug',
            'add_feature' => 'feat'
        ];

        $newName = ($parentFields['name'] ?? 'Project') . ' ' . $iterationLabel . ' (' . $modeShort[$mode] . ')';

        // Build the requirements: include the patch request + reference to parent
        $newRequirements = "PATCH OF: " . ($parentFields['name'] ?? 'parent project') .
            " (parent record id: $parentId)\n\n" .
            "MODE: $mode\n\n" .
            "ORIGINAL PARENT REQUIREMENTS:\n" . ($parentFields['requirements'] ?? 'N/A') . "\n\n" .
            "═══════════════════════════════════════\n" .
            "CHANGE REQUEST FOR THIS ITERATION:\n" .
            "═══════════════════════════════════════\n\n" . $request;

        // Inherit auto-approve flags and deploy settings from parent
        $childFields = [
            'name' => $newName,
            'requirements' => $newRequirements,
            'status' => 'draft',
            'budget_cap' => $budgetCap,
            'budget_spent' => 0.00,
            'notes' => '',
            'deploy_path' => $parentFields['deploy_path'] ?? '',
            'deploy_status' => 'not_deployed',
            'parent_project_id' => $parentId,
            'iteration_label' => $iterationLabel,
            'patch_mode' => $mode,
            'patch_request' => $request
        ];

        // Inherit all auto-approve and enable flags from parent
        $inheritedFlags = [
            'auto_scout', 'auto_architect', 'auto_designer', 'auto_tester', 'auto_reviewer',
            'auto_builder', 'auto_code_auditor', 'auto_smoke_test', 'auto_security',
            'auto_performance', 'auto_deploy',
            'enable_designer', 'enable_security', 'enable_performance'
        ];
        foreach ($inheritedFlags as $flag) {
            if (!empty($parentFields[$flag])) {
                $childFields[$flag] = true;
            }
        }

        // QUICK FIX SHORTCUT: small surgical changes — auto-approve everything end-to-end.
        // Pipeline for quick_fix is [builder, code_reviewer, smoke_test, deploy], so we only
        // need those flags ON. We also DISABLE optional audit stages so nothing waits.
        if ($mode === 'quick_fix') {
            $childFields['auto_builder'] = true;
            $childFields['auto_code_auditor'] = true;
            $childFields['auto_smoke_test'] = true;
            $childFields['auto_deploy'] = true;
            $childFields['enable_security'] = false;
            $childFields['enable_performance'] = false;
        }

        // Create the child project
        $createResult = airtableRequest('POST', MC_PROJECTS_TABLE, ['fields' => $childFields]);
        if ($createResult['code'] !== 200) {
            return [
                'success' => false,
                'error' => 'Failed to create patch project',
                'details' => $createResult['body']
            ];
        }

        $newProject = $createResult['body'];
        $newProjectId = $newProject['id'];

        // QUEUE MODE: park the project in the Queue column instead of starting it.
        // Mark current_stage='queued' (do NOT touch the status singleSelect), do not
        // determine/patch first-stage fields, and do not fire run.php. The Pipeline's
        // queue runner (or a manual "Run This Job" click) will start it later.
        if ($queue) {
            airtableRequest('PATCH', MC_PROJECTS_TABLE, ['fields' => [
                'current_stage' => 'queued',
                'current_agent' => 'Queued'
            ]], $newProjectId);

            return [
                'success' => true,
                'project_id' => $newProjectId,
                'project_name' => $newName,
                'iteration_label' => $iterationLabel,
                'first_stage' => null,
                'queued' => true,
                'message' => "Patch '$iterationLabel' queued. Start it from the Pipeline."
            ];
        }

        // Ensure the project record we pass to getFirstStage has patch_mode set
        // (Airtable POST sometimes returns partial fields)
        if (!isset($newProject['fields']['patch_mode'])) {
            $newProject['fields']['patch_mode'] = $mode;
        }
        foreach (['enable_designer', 'enable_security', 'enable_performance'] as $ef) {
            if (!isset($newProject['fields'][$ef]) && !empty($childFields[$ef])) {
                $newProject['fields'][$ef] = true;
            }
        }

        // Determine first stage for this mode
        $firstStage = getFirstStage($newProject);

        // CRITICAL: set current_stage BEFORE firing the async trigger. If the curl
        // fire-and-forget fails (PHP-FPM cold start), the Builder Runner long-poll
        // or the webhook can still pick this up. Also prevents the dashboard from
        // showing the draft state with a misleading "Start Pipeline" button that
        // would fire Scout instead of the patch's correct first stage.
        $stagePatchFields = ['current_stage' => $firstStage];
        if ($firstStage === 'builder') {
            $stagePatchFields['current_agent'] = 'Builder-Runner (queued)';
            $stagePatchFields['status'] = 'builder';
        } else {
            $stagePatchFields['current_agent'] = ucfirst($firstStage) . ' (queued)';
            $legacy = ['scout','architect','tester','reviewer','builder'];
            if (in_array($firstStage, $legacy, true)) {
                $stagePatchFields['status'] = $firstStage;
            }
        }
        airtableRequest('PATCH', MC_PROJECTS_TABLE, ['fields' => $stagePatchFields], $newProjectId);

        // Fire-and-forget the first agent
        $cookieStr = isset($_COOKIE['PHPSESSID']) ? 'PHPSESSID=' . $_COOKIE['PHPSESSID'] : '';
        $ch = curl_init('https://agentrocketman.com/mission-control/api/agents/run.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'project_id' => $newProjectId,
            'stage' => $firstStage,
            'internal_secret' => $internalSecret
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Cookie: ' . $cookieStr
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // More generous so PHP-FPM cold-start doesn't kill the trigger
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);

        return [
            'success' => true,
            'project_id' => $newProjectId,
            'project_name' => $newName,
            'iteration_label' => $iterationLabel,
            'first_stage' => $firstStage
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
