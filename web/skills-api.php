<?php
/**
 * AgentCore — Skills API
 *
 * JSON API for the skills manager web UI.
 * All endpoints return JSON responses.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../src/AgentCore.php';
$core = AgentCore::init(__DIR__ . '/../config/config.php');

// Access control — see web/auth.php for modes. Forces an early exit on failure.
// The Accept header is set so auth.php returns a JSON error body for this endpoint.
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require_once __DIR__ . '/auth.php';

require_once __DIR__ . '/../src/SkillRunner.php';
require_once __DIR__ . '/../src/CronExpression.php';
require_once __DIR__ . '/../src/Scheduler.php';
require_once __DIR__ . '/../src/MemoryManager.php';

$db = $core->db();
$runner = new SkillRunner($core);
$scheduler = new Scheduler($core);
$memory = new MemoryManager($core);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Skill names are used to construct file paths (skills/<name>/SKILL.md). Anything that
// isn't a strict slug could traverse out of skillsDir() — guard at the API boundary so
// a future endpoint added below doesn't have to remember to validate. Lowercase-only
// to match the capture-routing validator in MemoryManager::captureToTarget — keeps
// names portable across case-sensitive (Linux) and case-insensitive (Windows, default
// macOS) filesystems.
$validSkillName = static function ($name): bool {
    return is_string($name) && $name !== '' && (bool)preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name);
};

try {
    switch ($action) {
        case 'list_skills':
            $skills = $runner->listSkills();
            // Enrich with schedule info
            $schedules = $db->query("SELECT * FROM skill_schedules")->fetchAll();
            $schedMap = array_column($schedules, null, 'skill_name');

            foreach ($skills as &$s) {
                $sched = $schedMap[$s['name']] ?? null;
                $s['schedule'] = $sched ? [
                    'id'              => $sched['id'],
                    'cron'            => $sched['cron_expression'],
                    'model'           => $sched['model'],
                    'max_turns'       => $sched['max_turns'],
                    'max_budget_usd'  => $sched['max_budget_usd'],
                    'enabled'         => (bool)$sched['enabled'],
                    'next_run_at'     => $sched['next_run_at'],
                    'last_run_at'     => $sched['last_run_at'],
                    'consecutive_failures' => $sched['consecutive_failures'],
                ] : null;

                // Last run info
                $stmt = $db->prepare("SELECT status, started_at, duration_seconds FROM skill_runs WHERE skill_name = ? ORDER BY started_at DESC LIMIT 1");
                $stmt->execute([$s['name']]);
                $s['last_run'] = $stmt->fetch() ?: null;
            }
            echo json_encode(['ok' => true, 'skills' => $skills]);
            break;

        case 'get_skill':
            $name = $_GET['name'] ?? '';
            if (!$validSkillName($name)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid skill name']);
                break;
            }
            $skillDir = $core->skillsDir() . '/' . $name;
            $skillFile = $skillDir . '/SKILL.md';
            $learningsFile = $skillDir . '/LEARNINGS.md';

            if (!file_exists($skillFile)) {
                echo json_encode(['ok' => false, 'error' => 'Skill not found']);
                break;
            }

            echo json_encode([
                'ok' => true,
                'name' => $name,
                'skill_md' => file_get_contents($skillFile),
                'learnings_md' => file_exists($learningsFile) ? file_get_contents($learningsFile) : '',
            ]);
            break;

        case 'save_skill':
            $name = $_POST['name'] ?? '';
            $content = $_POST['content'] ?? '';
            if (!$validSkillName($name)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid skill name']);
                break;
            }
            $skillFile = $core->skillsDir() . '/' . $name . '/SKILL.md';

            if (!file_exists(dirname($skillFile))) {
                echo json_encode(['ok' => false, 'error' => 'Skill not found']);
                break;
            }

            file_put_contents($skillFile, $content, LOCK_EX);
            echo json_encode(['ok' => true]);
            break;

        case 'save_learnings':
            $name = $_POST['name'] ?? '';
            $content = $_POST['content'] ?? '';
            if (!$validSkillName($name)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid skill name']);
                break;
            }
            $skillDir = $core->skillsDir() . '/' . $name;
            if (!is_dir($skillDir)) {
                echo json_encode(['ok' => false, 'error' => 'Skill not found']);
                break;
            }
            $file = $skillDir . '/LEARNINGS.md';

            file_put_contents($file, $content, LOCK_EX);
            echo json_encode(['ok' => true]);
            break;

        case 'run':
            $name = $_POST['name'] ?? '';
            $args = $_POST['arguments'] ?? '';

            if (!$validSkillName($name)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid skill name']);
                break;
            }

            $result = $scheduler->runNow($name, 'web', 'web-ui', $args);
            echo json_encode([
                'ok' => true,
                'success' => $result->success,
                'duration' => $result->duration,
                'output' => $result->summary(1000),
                'error' => $result->error,
            ]);
            break;

        case 'list_schedules':
            $schedules = $scheduler->listSchedules();
            foreach ($schedules as &$s) {
                $cron = new CronExpression($s['cron_expression']);
                $s['description'] = $cron->describe();
            }
            echo json_encode(['ok' => true, 'schedules' => $schedules]);
            break;

        case 'save_schedule':
            $name     = $_POST['skill_name'] ?? '';
            $cron     = $_POST['cron_expression'] ?? '';
            $model    = $_POST['model'] ?? 'sonnet';
            $turns    = (int)($_POST['max_turns'] ?? 10);
            $budget   = (float)($_POST['max_budget_usd'] ?? 2.00);
            $effort   = $_POST['effort'] ?? 'high';
            $enabled  = ($_POST['enabled'] ?? '1') === '1';

            // Allow exec:<id> form for direct shell schedules; otherwise require strict slug.
            $isExec = is_string($name) && str_starts_with($name, 'exec:');
            $execIdValid = $isExec && $validSkillName(substr($name, 5));
            if (!$cron || !($validSkillName($name) || $execIdValid)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid name or missing cron']);
                break;
            }

            $scheduler->upsertSchedule($name, $cron, $model, $turns, $budget, $effort, $enabled);
            echo json_encode(['ok' => true]);
            break;

        case 'toggle_schedule':
            $name = $_POST['skill_name'] ?? '';
            $enabled = ($_POST['enabled'] ?? '1') === '1';
            $isExec = is_string($name) && str_starts_with($name, 'exec:') && $validSkillName(substr($name, 5));
            if (!($validSkillName($name) || $isExec)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid skill name']);
                break;
            }

            $stmt = $db->prepare("UPDATE skill_schedules SET enabled = ? WHERE skill_name = ?");
            $stmt->execute([$enabled ? 1 : 0, $name]);
            echo json_encode(['ok' => true, 'rows' => $stmt->rowCount()]);
            break;

        case 'delete_schedule':
            $name = $_POST['skill_name'] ?? '';
            $isExec = is_string($name) && str_starts_with($name, 'exec:') && $validSkillName(substr($name, 5));
            if (!($validSkillName($name) || $isExec)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid skill name']);
                break;
            }
            $stmt = $db->prepare("DELETE FROM skill_schedules WHERE skill_name = ?");
            $stmt->execute([$name]);
            echo json_encode(['ok' => true]);
            break;

        case 'list_runs':
            $skill = $_GET['skill'] ?? '';
            $status = $_GET['status'] ?? '';
            $limit = min(max((int)($_GET['limit'] ?? 50), 1), 200);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            $where = [];
            $params = [];
            if ($skill) { $where[] = 'skill_name = ?'; $params[] = $skill; }
            if ($status) { $where[] = 'status = ?'; $params[] = $status; }

            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $countStmt = $db->prepare("SELECT COUNT(*) FROM skill_runs {$whereClause}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $params[] = $limit;
            $params[] = $offset;
            $stmt = $db->prepare("
                SELECT id, skill_name, trigger_type, trigger_by, model_used, status,
                       started_at, completed_at, duration_seconds, output_summary, error_message, budget_used
                FROM skill_runs {$whereClause}
                ORDER BY started_at DESC LIMIT ? OFFSET ?
            ");
            $stmt->execute($params);

            echo json_encode(['ok' => true, 'runs' => $stmt->fetchAll(), 'total' => $total]);
            break;

        case 'get_run':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM skill_runs WHERE id = ?");
            $stmt->execute([$id]);
            $run = $stmt->fetch();
            echo json_encode(['ok' => (bool)$run, 'run' => $run]);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
