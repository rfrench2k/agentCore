// AgentCore — Skills Manager UI

const API = 'skills-api.php';

// ── Tab switching ──────────────────────────────────────────────────────────

function switchTab(tabName) {
    document.querySelectorAll('.ac-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
    document.querySelectorAll('.ac-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + tabName));

    if (tabName === 'skills') loadSkills();
    else if (tabName === 'schedules') loadSchedules();
    else if (tabName === 'runs') loadRuns();
}

// ── Skills Panel ───────────────────────────────────────────────────────────

async function loadSkills() {
    const res = await fetch(API + '?action=list_skills');
    const data = await res.json();
    const container = document.getElementById('skills-list');

    if (!data.ok || !data.skills.length) {
        container.innerHTML = '<div class="ac-empty">No skills found.</div>';
        return;
    }

    container.innerHTML = data.skills.map(s => {
        const sched = s.schedule;
        const lastRun = s.last_run;
        const statusDot = !lastRun ? 'ac-dot-gray' :
            lastRun.status === 'completed' ? 'ac-dot-green' :
            lastRun.status === 'running' ? 'ac-dot-yellow' : 'ac-dot-red';
        const schedBadge = sched
            ? (sched.enabled
                ? `<span class="ac-badge ac-badge-success">${sched.cron} | ${sched.model}</span>`
                : `<span class="ac-badge ac-badge-muted">disabled</span>`)
            : `<span class="ac-badge ac-badge-warning">no schedule</span>`;
        const nextRun = sched?.next_run_at ? new Date(sched.next_run_at).toLocaleString() : '';
        const lastRunInfo = lastRun
            ? `${lastRun.status} — ${lastRun.duration_seconds}s — ${new Date(lastRun.started_at).toLocaleString()}`
            : 'never run';

        return `
        <div class="ac-card">
            <div class="ac-card-header">
                <div class="ac-flex">
                    <span class="ac-dot ${statusDot}"></span>
                    <span class="ac-card-title">${s.name}</span>
                    ${schedBadge}
                    ${s.has_learnings ? '<span class="ac-badge ac-badge-info">has learnings</span>' : ''}
                </div>
                <div class="ac-flex">
                    <button class="ac-btn ac-btn-sm" onclick="editSkill('${s.name}')">Edit</button>
                    <button class="ac-btn ac-btn-sm ac-btn-primary" onclick="runSkill('${s.name}')">Run Now</button>
                </div>
            </div>
            <div class="ac-card-desc">${s.description || 'No description'}</div>
            <div class="ac-card-desc ac-mt-1" style="font-size:0.78rem;color:var(--text-muted)">
                Last: ${lastRunInfo}${nextRun ? ' | Next: ' + nextRun : ''}
            </div>
        </div>`;
    }).join('');
}

async function editSkill(name) {
    const res = await fetch(API + '?action=get_skill&name=' + encodeURIComponent(name));
    const data = await res.json();
    if (!data.ok) { alert('Failed to load skill'); return; }

    document.getElementById('modal-title').textContent = 'Edit: ' + name;
    document.getElementById('modal-body').innerHTML = `
        <div class="ac-mb-1"><strong>SKILL.md</strong></div>
        <textarea class="ac-textarea" id="edit-skill-md">${escHtml(data.skill_md)}</textarea>
        <div class="ac-mt-2 ac-mb-1"><strong>LEARNINGS.md</strong></div>
        <textarea class="ac-textarea" id="edit-learnings-md" style="min-height:150px">${escHtml(data.learnings_md)}</textarea>
        <div class="ac-mt-2 ac-flex">
            <button class="ac-btn ac-btn-primary" onclick="saveSkill('${name}')">Save</button>
            <button class="ac-btn" onclick="closeModal()">Cancel</button>
        </div>`;
    document.getElementById('modal-overlay').classList.add('active');
}

async function saveSkill(name) {
    const skillMd = document.getElementById('edit-skill-md').value;
    const learningsMd = document.getElementById('edit-learnings-md').value;

    await fetch(API, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=save_skill&name=${encodeURIComponent(name)}&content=${encodeURIComponent(skillMd)}`});
    await fetch(API, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=save_learnings&name=${encodeURIComponent(name)}&content=${encodeURIComponent(learningsMd)}`});

    closeModal();
    loadSkills();
}

async function runSkill(name) {
    if (!confirm('Run ' + name + ' now?')) return;

    const btn = event.target;
    btn.textContent = 'Running...';
    btn.disabled = true;

    const res = await fetch(API, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=run&name=${encodeURIComponent(name)}`});
    const data = await res.json();

    btn.textContent = 'Run Now';
    btn.disabled = false;

    const status = data.success ? 'Completed' : 'Failed';
    alert(`${status} (${data.duration}s)\n\n${data.output || data.error || 'No output'}`);
    loadSkills();
}

// ── Schedules Panel ────────────────────────────────────────────────────────

async function loadSchedules() {
    const res = await fetch(API + '?action=list_schedules');
    const data = await res.json();
    const container = document.getElementById('schedules-list');

    if (!data.ok || !data.schedules.length) {
        container.innerHTML = '<div class="ac-empty">No schedules configured.</div>';
        return;
    }

    let html = `<table class="ac-table">
        <thead><tr>
            <th>Skill</th><th>Schedule</th><th>Model</th><th>Turns</th><th>Budget</th>
            <th>Status</th><th>Next Run</th><th>Actions</th>
        </tr></thead><tbody>`;

    data.schedules.forEach(s => {
        const statusBadge = s.enabled
            ? '<span class="ac-badge ac-badge-success">active</span>'
            : '<span class="ac-badge ac-badge-muted">disabled</span>';
        const nextRun = s.next_run_at ? new Date(s.next_run_at).toLocaleString() : 'n/a';
        const toggleLabel = s.enabled ? 'Disable' : 'Enable';
        const failures = s.consecutive_failures > 0
            ? ` <span class="ac-badge ac-badge-error">${s.consecutive_failures} fails</span>` : '';

        html += `<tr>
            <td><strong>${s.skill_name}</strong>${failures}</td>
            <td class="ac-mono">${s.cron_expression}</td>
            <td>${s.model}</td>
            <td>${s.max_turns}</td>
            <td>$${parseFloat(s.max_budget_usd).toFixed(2)}</td>
            <td>${statusBadge}</td>
            <td style="font-size:0.78rem">${nextRun}</td>
            <td>
                <button class="ac-btn ac-btn-sm" onclick="toggleSchedule('${s.skill_name}', ${!s.enabled})">${toggleLabel}</button>
                <button class="ac-btn ac-btn-sm" onclick="editSchedule('${s.skill_name}')">Edit</button>
            </td>
        </tr>`;
    });

    html += '</tbody></table>';
    container.innerHTML = html;
}

async function toggleSchedule(name, enable) {
    await fetch(API, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle_schedule&skill_name=${encodeURIComponent(name)}&enabled=${enable ? 1 : 0}`});
    loadSchedules();
}

function editSchedule(name) {
    // Fetch current values
    fetch(API + '?action=list_schedules').then(r => r.json()).then(data => {
        const s = data.schedules.find(x => x.skill_name === name);
        if (!s) return;

        document.getElementById('modal-title').textContent = 'Edit Schedule: ' + name;
        document.getElementById('modal-body').innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <label>Cron Expression<br><input class="ac-input" id="sched-cron" value="${s.cron_expression}" style="width:100%"></label>
                <label>Model<br><select class="ac-select" id="sched-model" style="width:100%">
                    <option value="haiku" ${s.model==='haiku'?'selected':''}>haiku</option>
                    <option value="sonnet" ${s.model==='sonnet'?'selected':''}>sonnet</option>
                    <option value="opus" ${s.model==='opus'?'selected':''}>opus</option>
                </select></label>
                <label>Max Turns<br><input class="ac-input" type="number" id="sched-turns" value="${s.max_turns}" style="width:100%"></label>
                <label>Max Budget ($)<br><input class="ac-input" type="number" step="0.01" id="sched-budget" value="${s.max_budget_usd}" style="width:100%"></label>
                <label>Effort<br><select class="ac-select" id="sched-effort" style="width:100%">
                    <option value="low" ${s.effort==='low'?'selected':''}>low</option>
                    <option value="medium" ${s.effort==='medium'?'selected':''}>medium</option>
                    <option value="high" ${s.effort==='high'?'selected':''}>high</option>
                </select></label>
            </div>
            <div class="ac-mt-2 ac-flex">
                <button class="ac-btn ac-btn-primary" onclick="saveSchedule('${name}')">Save</button>
                <button class="ac-btn" onclick="closeModal()">Cancel</button>
                <button class="ac-btn ac-btn-danger" onclick="deleteSchedule('${name}')" style="margin-left:auto">Delete</button>
            </div>`;
        document.getElementById('modal-overlay').classList.add('active');
    });
}

async function saveSchedule(name) {
    const body = new URLSearchParams({
        action: 'save_schedule',
        skill_name: name,
        cron_expression: document.getElementById('sched-cron').value,
        model: document.getElementById('sched-model').value,
        max_turns: document.getElementById('sched-turns').value,
        max_budget_usd: document.getElementById('sched-budget').value,
        effort: document.getElementById('sched-effort').value,
        enabled: '1',
    });
    await fetch(API, { method: 'POST', body });
    closeModal();
    loadSchedules();
}

async function deleteSchedule(name) {
    if (!confirm('Delete schedule for ' + name + '?')) return;
    await fetch(API, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_schedule&skill_name=${encodeURIComponent(name)}`});
    closeModal();
    loadSchedules();
}

// ── Runs Panel ─────────────────────────────────────────────────────────────

let runsOffset = 0;
const runsLimit = 25;

async function loadRuns(offset) {
    if (offset !== undefined) runsOffset = offset;
    const skill = document.getElementById('filter-skill')?.value || '';
    const status = document.getElementById('filter-status')?.value || '';

    let url = `${API}?action=list_runs&limit=${runsLimit}&offset=${runsOffset}`;
    if (skill) url += '&skill=' + encodeURIComponent(skill);
    if (status) url += '&status=' + encodeURIComponent(status);

    const res = await fetch(url);
    const data = await res.json();
    const container = document.getElementById('runs-list');

    if (!data.ok || !data.runs.length) {
        container.innerHTML = '<div class="ac-empty">No runs found.</div>';
        return;
    }

    let html = `<table class="ac-table">
        <thead><tr>
            <th>ID</th><th>Skill</th><th>Trigger</th><th>Model</th><th>Status</th>
            <th>Duration</th><th>Cost</th><th>Started</th>
        </tr></thead><tbody>`;

    data.runs.forEach(r => {
        const statusClass = r.status === 'completed' ? 'ac-badge-success' :
            r.status === 'running' ? 'ac-badge-warning' : 'ac-badge-error';
        const cost = r.budget_used ? '$' + parseFloat(r.budget_used).toFixed(4) : '';
        const started = new Date(r.started_at).toLocaleString();

        html += `<tr onclick="viewRun(${r.id})" style="cursor:pointer">
            <td>#${r.id}</td>
            <td><strong>${r.skill_name}</strong></td>
            <td>${r.trigger_type}${r.trigger_by ? ' (' + r.trigger_by + ')' : ''}</td>
            <td>${r.model_used || ''}</td>
            <td><span class="ac-badge ${statusClass}">${r.status}</span></td>
            <td>${r.duration_seconds !== null ? r.duration_seconds + 's' : '...'}</td>
            <td>${cost}</td>
            <td style="font-size:0.78rem">${started}</td>
        </tr>`;
    });

    html += '</tbody></table>';

    // Pagination
    const totalPages = Math.ceil(data.total / runsLimit);
    const currentPage = Math.floor(runsOffset / runsLimit) + 1;
    html += `<div class="ac-flex-between ac-mt-2" style="font-size:0.8rem;color:var(--text-muted)">
        <span>${data.total} total runs</span>
        <div class="ac-flex">
            <button class="ac-btn ac-btn-sm" ${runsOffset === 0 ? 'disabled' : ''} onclick="loadRuns(${runsOffset - runsLimit})">Prev</button>
            <span>Page ${currentPage} / ${totalPages}</span>
            <button class="ac-btn ac-btn-sm" ${currentPage >= totalPages ? 'disabled' : ''} onclick="loadRuns(${runsOffset + runsLimit})">Next</button>
        </div>
    </div>`;

    container.innerHTML = html;
}

async function viewRun(id) {
    const res = await fetch(API + '?action=get_run&id=' + id);
    const data = await res.json();
    if (!data.ok) return;

    const r = data.run;
    document.getElementById('modal-title').textContent = 'Run #' + r.id + ' — ' + r.skill_name;
    document.getElementById('modal-body').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:1rem;font-size:0.85rem">
            <div><strong>Status:</strong> ${r.status}</div>
            <div><strong>Model:</strong> ${r.model_used || 'n/a'}</div>
            <div><strong>Trigger:</strong> ${r.trigger_type} ${r.trigger_by ? '(' + r.trigger_by + ')' : ''}</div>
            <div><strong>Duration:</strong> ${r.duration_seconds}s</div>
            <div><strong>Started:</strong> ${new Date(r.started_at).toLocaleString()}</div>
            <div><strong>Cost:</strong> ${r.budget_used ? '$' + parseFloat(r.budget_used).toFixed(4) : 'n/a'}</div>
        </div>
        ${r.error_message ? '<div class="ac-card" style="border-color:var(--error);margin-bottom:1rem"><strong>Error:</strong><br>' + escHtml(r.error_message) + '</div>' : ''}
        <div class="ac-mb-1"><strong>Output:</strong></div>
        <pre style="background:var(--bg-body);padding:1rem;border-radius:6px;font-size:0.8rem;max-height:400px;overflow:auto;white-space:pre-wrap">${escHtml(r.output_full || r.output_summary || 'No output')}</pre>
        <div class="ac-mt-2"><button class="ac-btn" onclick="closeModal()">Close</button></div>`;
    document.getElementById('modal-overlay').classList.add('active');
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function closeModal() {
    document.getElementById('modal-overlay').classList.remove('active');
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

// Close modal on overlay click
document.addEventListener('click', e => {
    if (e.target.id === 'modal-overlay') closeModal();
});

// Load initial tab
document.addEventListener('DOMContentLoaded', () => switchTab('skills'));
