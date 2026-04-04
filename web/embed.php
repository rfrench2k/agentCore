<?php
/**
 * AgentCore — Embeddable Skills Panel
 *
 * Include this in your existing PHP app to embed the AgentCore skills manager.
 *
 * Usage:
 *   require_once '/path/to/agentcore/web/embed.php';
 *   agentcore_render_skills_panel();
 */

function agentcore_render_skills_panel(): void
{
    $acWebRoot = dirname(__FILE__);
    $acBaseUrl = agentcore_detect_base_url();
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($acBaseUrl) ?>/assets/agentcore.css">

    <div class="ac-tabs">
        <button class="ac-tab active" data-tab="skills" onclick="switchTab('skills')">Skills</button>
        <button class="ac-tab" data-tab="schedules" onclick="switchTab('schedules')">Schedules</button>
        <button class="ac-tab" data-tab="runs" onclick="switchTab('runs')">Run History</button>
    </div>

    <div class="ac-panel active" id="panel-skills">
        <div id="skills-list"><div class="ac-empty">Loading...</div></div>
    </div>

    <div class="ac-panel" id="panel-schedules">
        <div id="schedules-list"><div class="ac-empty">Loading...</div></div>
    </div>

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
            </select>
        </div>
        <div id="runs-list"><div class="ac-empty">Loading...</div></div>
    </div>

    <div class="ac-modal-overlay" id="modal-overlay">
        <div class="ac-modal">
            <h3 id="modal-title"></h3>
            <div id="modal-body"></div>
        </div>
    </div>

    <script>
    const API = '<?= htmlspecialchars($acBaseUrl) ?>/skills-api.php';
    </script>
    <script src="<?= htmlspecialchars($acBaseUrl) ?>/assets/agentcore.js"></script>
    <?php
}

function agentcore_detect_base_url(): string
{
    // Try to determine the web-accessible URL for the agentcore/web/ directory
    $webDir = dirname(__FILE__);
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

    if ($docRoot && str_starts_with($webDir, $docRoot)) {
        return str_replace('\\', '/', substr($webDir, strlen($docRoot)));
    }

    // Fallback: assume agentcore is a sibling of the current app
    return '/agentcore/web';
}
