<?php
/**
 * AgentCore — Skills Manager (Standalone Page)
 *
 * View/edit skills, manage schedules, and browse run history.
 */

require_once __DIR__ . '/../src/AgentCore.php';
AgentCore::init(__DIR__ . '/../config/config.php');
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AgentCore — Skills Manager</title>
<link rel="stylesheet" href="assets/agentcore.css">
</head>
<body>

<div class="ac-container">
    <div class="ac-header">
        <h1>AgentCore</h1>
        <span class="ac-version">Skills Manager</span>
    </div>

    <div class="ac-tabs">
        <button class="ac-tab active" data-tab="skills" onclick="switchTab('skills')">Skills</button>
        <button class="ac-tab" data-tab="schedules" onclick="switchTab('schedules')">Schedules</button>
        <button class="ac-tab" data-tab="runs" onclick="switchTab('runs')">Run History</button>
    </div>

    <!-- Skills Panel -->
    <div class="ac-panel active" id="panel-skills">
        <div id="skills-list"><div class="ac-empty">Loading...</div></div>
    </div>

    <!-- Schedules Panel -->
    <div class="ac-panel" id="panel-schedules">
        <div id="schedules-list"><div class="ac-empty">Loading...</div></div>
    </div>

    <!-- Runs Panel -->
    <div class="ac-panel" id="panel-runs">
        <div class="ac-flex" style="gap:0.75rem;margin-bottom:1rem">
            <select class="ac-select" id="filter-skill" onchange="loadRuns(0)">
                <option value="">All skills</option>
            </select>
            <select class="ac-select" id="filter-status" onchange="loadRuns(0)">
                <option value="">All statuses</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="running">Running</option>
                <option value="timeout">Timeout</option>
            </select>
        </div>
        <div id="runs-list"><div class="ac-empty">Loading...</div></div>
    </div>
</div>

<!-- Modal -->
<div class="ac-modal-overlay" id="modal-overlay">
    <div class="ac-modal">
        <h3 id="modal-title"></h3>
        <div id="modal-body"></div>
    </div>
</div>

<script src="assets/agentcore.js"></script>
</body>
</html>
