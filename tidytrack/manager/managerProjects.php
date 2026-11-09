<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';
require_once __DIR__ . '/teams_schema.php';

$userName    = $userName ?? ($_SESSION['name'] ?? 'Manager');
$userID      = (int)($_SESSION['user_id'] ?? 0);
$currentRole = strtolower(trim($currentRole ?? ($_SESSION['role'] ?? 'manager')));

if (!$userID || $currentRole !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

tt_ensure_team_tables($conn);

/* ===============================
   LOOKUPS
================================ */
function mp_leaders(mysqli $conn): array {
    $out = [];
    $q = $conn->query("SELECT user_id, name FROM users WHERE LOWER(role)='leader' ORDER BY name ASC");
    while ($r = $q->fetch_assoc()) $out[] = ['id' => (int)$r['user_id'], 'name' => $r['name']];
    return $out;
}
function mp_users(mysqli $conn): array {
    $out = [];
    $q = $conn->query("SELECT user_id, name, role FROM users WHERE name IS NOT NULL AND name <> '' ORDER BY name ASC");
    while ($r = $q->fetch_assoc()) $out[] = ['id' => (int)$r['user_id'], 'name' => $r['name'], 'role' => ucfirst((string)$r['role'])];
    return $out;
}
function mp_projects(mysqli $conn): array {
    $out = [];
    $q = $conn->query("
        SELECT project_id, project_name, teamLeadder_id, created_by, start_date, end_date
        FROM projects_x
        ORDER BY (end_date >= CURDATE()) DESC, project_name ASC
    ");
    while ($r = $q->fetch_assoc()) {
        $out[] = [
            'id'        => (int)$r['project_id'],
            'name'      => $r['project_name'],
            'leader'    => (int)$r['teamLeadder_id'],
            'mine'      => (int)$r['created_by'] === $GLOBALS['userID'],
            'end'       => $r['end_date'],
            'ended'     => !empty($r['end_date']) && strtotime($r['end_date']) < strtotime(date('Y-m-d')),
        ];
    }
    return $out;
}

function int_list($v): array {
    if (!is_array($v)) $v = ($v === null || $v === '') ? [] : [$v];
    return array_values(array_filter(array_unique(array_map('intval', $v)), fn($x) => $x > 0));
}

function replace_project_teams(mysqli $conn, int $projectId, array $teamIds): void {
    $del = $conn->prepare("DELETE FROM project_teams WHERE project_id = ?");
    $del->bind_param('i', $projectId);
    $del->execute();
    $del->close();
    if (!$teamIds) return;
    $ins = $conn->prepare("INSERT IGNORE INTO project_teams (project_id, team_id) VALUES (?, ?)");
    foreach ($teamIds as $tid) { $ins->bind_param('ii', $projectId, $tid); $ins->execute(); }
    $ins->close();
}

/* ===============================
   AJAX ACTIONS
================================ */
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? (isset($_POST['is_unified']) ? 'create_project' : '');
    $reply  = function(string $status, string $message, array $extra = []) use ($conn) {
        echo json_encode(array_merge([
            'status'   => $status,
            'message'  => $message,
            'teams'    => tt_fetch_teams($conn),
            'projects' => mp_projects($conn),
        ], $extra));
        exit;
    };

    try {
        switch ($action) {

        /* ---------- Create main project or sub-project ---------- */
        case 'create_project': {
            $type         = $_POST['type'] ?? 'main';
            $project_name = trim($_POST['project_name'] ?? '');
            $deliverable  = trim($_POST['deliverable'] ?? '');
            $start_date   = $_POST['start_date'] ?? '';
            $end_date     = $_POST['end_date'] ?? '';

            if (!$project_name || !$deliverable || !$start_date || !$end_date) $reply('error', 'Please fill all required fields.');
            if (strtotime($start_date) > strtotime($end_date)) $reply('error', 'Start date cannot be after end date.');

            if ($type === 'sub') {
                $main_project = (int)($_POST['parent_project'] ?? 0);
                if (!$main_project) $reply('error', 'Please select the parent project.');

                // Sub-projects inherit the parent's primary leader (sub_projects has a single leader column)
                $stmt = $conn->prepare("SELECT teamLeadder_id FROM projects_x WHERE project_id = ? AND created_by = ?");
                $stmt->bind_param('ii', $main_project, $userID);
                $stmt->execute();
                $parent = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$parent) $reply('error', 'Parent project not found.');
                $team_leader_id = (int)$parent['teamLeadder_id'];

                $stmt = $conn->prepare("
                    INSERT INTO sub_projects (project_id, sub_project_name, deliverable, start_date, end_date, teamLeadder_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('issssii', $main_project, $project_name, $deliverable, $start_date, $end_date, $team_leader_id, $userID);
                $ok = $stmt->execute();
                $stmt->close();
                $ok ? $reply('success', 'Sub-project added.') : $reply('error', 'Failed to add sub-project.');
            }

            $leader_ids = int_list($_POST['leader_ids'] ?? []);
            $team_ids   = int_list($_POST['team_ids'] ?? []);
            if (!$leader_ids) $reply('error', 'Please select at least one team leader.');

            $primary_leader = $leader_ids[0];
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                INSERT INTO projects_x (teamLeadder_id, project_name, deliverable, start_date, end_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('issssi', $primary_leader, $project_name, $deliverable, $start_date, $end_date, $userID);
            if (!$stmt->execute()) throw new Exception('Failed to add project.');
            $new_project_id = (int)$stmt->insert_id;
            $stmt->close();

            $stmtPL = $conn->prepare("INSERT IGNORE INTO project_leader (project_id, teamLeadder_id) VALUES (?, ?)");
            foreach ($leader_ids as $lid) {
                $stmtPL->bind_param('ii', $new_project_id, $lid);
                if (!$stmtPL->execute()) throw new Exception('Failed to link team leaders.');
            }
            $stmtPL->close();

            replace_project_teams($conn, $new_project_id, $team_ids);
            $conn->commit();

            $reply('success', 'Project created' . ($team_ids ? ' and linked to ' . count($team_ids) . ' team(s).' : '.'),
                   ['project' => ['id' => $new_project_id, 'name' => $project_name]]);
        }

        /* ---------- Set which teams work on a project ---------- */
        case 'save_project_teams': {
            $pid = (int)($_POST['project_id'] ?? 0);
            if (!$pid) $reply('error', 'Missing project.');
            $conn->begin_transaction();
            replace_project_teams($conn, $pid, int_list($_POST['team_ids'] ?? []));
            $conn->commit();
            $reply('success', 'Project teams updated.');
        }

        /* ---------- Create / edit a team (members + projects) ---------- */
        case 'save_team': {
            $tid   = (int)($_POST['team_id'] ?? 0);
            $name  = trim($_POST['team_name'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $members  = int_list($_POST['member_ids'] ?? []);
            $projects = int_list($_POST['project_ids'] ?? []);
            if ($name === '') $reply('error', 'Team name is required.');

            $stmt = $conn->prepare("SELECT team_id FROM teams WHERE team_name = ? AND team_id <> ?");
            $stmt->bind_param('si', $name, $tid);
            $stmt->execute();
            $dupe = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($dupe) $reply('error', 'A team with that name already exists.');

            $conn->begin_transaction();
            if ($tid) {
                $stmt = $conn->prepare("UPDATE teams SET team_name = ?, description = ? WHERE team_id = ?");
                $stmt->bind_param('ssi', $name, $desc, $tid);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO teams (team_name, description, created_by) VALUES (?, ?, ?)");
                $stmt->bind_param('ssi', $name, $desc, $userID);
                $stmt->execute();
                $tid = (int)$stmt->insert_id;
                $stmt->close();
            }

            foreach (['team_members' => ['user_id', $members], 'project_teams' => ['project_id', $projects]] as $table => [$col, $ids]) {
                $del = $conn->prepare("DELETE FROM $table WHERE team_id = ?");
                $del->bind_param('i', $tid);
                $del->execute();
                $del->close();
                $ins = $conn->prepare("INSERT IGNORE INTO $table (team_id, $col) VALUES (?, ?)");
                foreach ($ids as $id) { $ins->bind_param('ii', $tid, $id); $ins->execute(); }
                $ins->close();
            }
            $conn->commit();
            $reply('success', 'Team saved.', ['team_id' => $tid]);
        }

        case 'delete_team': {
            $tid = (int)($_POST['team_id'] ?? 0);
            if (!$tid) $reply('error', 'Missing team.');
            $conn->begin_transaction();
            foreach (['project_teams', 'team_members', 'teams'] as $table) {
                $del = $conn->prepare("DELETE FROM $table WHERE team_id = ?");
                $del->bind_param('i', $tid);
                $del->execute();
                $del->close();
            }
            $conn->commit();
            $reply('success', 'Team deleted.');
        }

        default:
            $reply('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('managerProjects.php: ' . $e->getMessage());
        $reply('error', 'Something went wrong: ' . $e->getMessage());
    }
}

/* ===============================
   PAGE DATA
================================ */
$leaders  = mp_leaders($conn);
$users    = mp_users($conn);
$projects = mp_projects($conn);
$teams    = tt_fetch_teams($conn);
$J = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
?>
<title>Projects &amp; Teams</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
/* Scoped under .mp so nothing leaks into manager_index.php */
.mp{
  --bg:#f4f6f4; --card:#fff; --soft:#f1f4f2; --border:#e6ebe7; --text:#111a14; --muted:#7b867f;
  --g900:#0f3d2a; --g700:#1b6b45; --g500:#2f9e6b; --g100:#e3f4ea;
  --amber:#c27c0e; --amber-bg:#fff4dc; --red:#c92a2a; --red-bg:#ffe8e8;
  --r:22px; --r-sm:12px; --shadow:0 1px 2px rgba(16,40,26,.04),0 8px 24px rgba(16,40,26,.05);
  color:var(--text); font-family:inherit; line-height:1.45;
}
body.dark .mp{ --bg:#0f172a; --card:#1e293b; --soft:#273449; --border:#334155; --text:#f1f5f9; --muted:#94a3b8; --g100:rgba(47,158,107,.18); --amber-bg:rgba(245,159,0,.15); --red-bg:rgba(240,62,62,.15); }
.mp *,.mp *::before,.mp *::after{box-sizing:border-box}
.mp h1,.mp h2,.mp h3,.mp p{margin:0}

.mp-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.mp-head h1{font-size:clamp(26px,3vw,36px);font-weight:600;letter-spacing:-.03em}
.mp-head p{color:var(--muted);font-size:14px;margin-top:4px}

.mp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:999px;font:inherit;font-size:14px;font-weight:500;cursor:pointer;border:1.5px solid transparent;transition:all .2s;text-decoration:none;white-space:nowrap}
.mp-btn-primary{background:linear-gradient(135deg,var(--g700),var(--g900));color:#fff}
.mp-btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(15,61,42,.25)}
.mp-btn-primary:disabled{opacity:.6;cursor:wait;transform:none}
.mp-btn-ghost{background:transparent;border-color:var(--g700);color:var(--g700)}
.mp-btn-ghost:hover{background:var(--g100)}
.mp-btn-sm{height:32px;padding:0 13px;font-size:12.5px}
.mp-btn-danger{background:transparent;border-color:transparent;color:var(--red)}
.mp-btn-danger:hover{background:var(--red-bg)}
body.dark .mp .mp-btn-ghost{color:#7fd1a8;border-color:#7fd1a8}

.mp-grid{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,6fr);gap:16px;margin-bottom:16px}
.mp-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:22px;box-shadow:var(--shadow);min-width:0}
.mp-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.mp-card-head h2{font-size:17px;font-weight:600}
.mp-sub{font-size:12.5px;color:var(--muted)}

/* Form */
.mp-seg{display:inline-flex;background:var(--soft);border-radius:999px;padding:4px;margin-bottom:6px}
.mp-seg button{border:none;background:transparent;font:inherit;font-size:13px;padding:7px 16px;border-radius:999px;cursor:pointer;color:var(--muted)}
.mp-seg button.active{background:var(--card);color:var(--text);font-weight:500;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.mp-field{margin-top:14px}
.mp-field label,.mp-label{display:block;font-size:12.5px;font-weight:500;color:var(--muted);margin-bottom:6px}
.mp-field input,.mp-field select,.mp-field textarea{width:100%;height:44px;border-radius:var(--r-sm);border:1px solid var(--border);background:var(--soft);color:var(--text);padding:0 14px;font:inherit;font-size:14px;outline:none;transition:border-color .2s,box-shadow .2s}
.mp-field textarea{height:auto;min-height:70px;padding:10px 14px;resize:vertical}
.mp-field input:focus,.mp-field select:focus,.mp-field textarea:focus{border-color:var(--g500);box-shadow:0 0 0 3px var(--g100);background:var(--card)}
.mp-field input[readonly]{color:var(--muted)}
.mp-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mp-hint{font-size:12px;color:var(--muted);margin-top:6px}

/* Multi-select */
.ms{position:relative}
.ms-trigger{width:100%;min-height:44px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1px solid var(--border);border-radius:var(--r-sm);background:var(--soft);padding:6px 36px 6px 10px;cursor:pointer;text-align:left;font:inherit;font-size:14px;color:var(--text);position:relative}
.ms-trigger::after{content:"\f078";font-family:"Font Awesome 6 Free";font-weight:900;font-size:11px;position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted)}
.ms.open .ms-trigger{border-color:var(--g500);box-shadow:0 0 0 3px var(--g100)}
.ms-placeholder{color:var(--muted);padding-left:4px}
.ms-chip{display:inline-flex;align-items:center;gap:6px;background:var(--g100);color:var(--g700);border-radius:999px;padding:3px 6px 3px 10px;font-size:12.5px}
body.dark .mp .ms-chip{color:#7fd1a8}
.ms-chip b{font-weight:500}
.ms-chip span{cursor:pointer;width:18px;height:18px;border-radius:50%;display:grid;place-items:center;font-size:11px}
.ms-chip span:hover{background:rgba(0,0,0,.08)}
.ms-panel{display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);box-shadow:0 18px 40px rgba(0,0,0,.12);z-index:50;padding:8px}
.ms.open .ms-panel{display:block}
.ms-search{width:100%;height:36px;border:1px solid var(--border);border-radius:10px;padding:0 10px;font:inherit;font-size:13px;background:var(--soft);color:var(--text);outline:none;margin-bottom:6px}
.ms-items{max-height:220px;overflow:auto}
.ms-item{display:flex;gap:10px;align-items:center;padding:8px;border-radius:9px;cursor:pointer;font-size:13.5px}
.ms-item:hover{background:var(--soft)}
.ms-item input{width:16px;height:16px;accent-color:var(--g700)}
.ms-item small{margin-left:auto;color:var(--muted);font-size:11.5px}
.ms-empty{padding:10px;font-size:13px;color:var(--muted)}

/* Teams list */
.mp-teams{display:grid;gap:10px}
.mp-team{border:1px solid var(--border);border-radius:16px;padding:14px 16px;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:14px;align-items:start}
.mp-team-badge{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-weight:600;font-size:15px}
.mp-team h3{font-size:15px;font-weight:600}
.mp-team p{font-size:12.5px;color:var(--muted);margin-top:2px}
.mp-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.mp-chip{font-size:11.5px;padding:3px 9px;border-radius:999px;background:var(--soft);color:var(--text);border:1px solid var(--border)}
.mp-chip.muted{color:var(--muted);border-style:dashed}
.mp-team-actions{display:flex;gap:4px}
.mp-empty{color:var(--muted);text-align:center;padding:28px 10px;font-size:13.5px}

/* Team editor (inline) */
.mp-editor{border:1.5px dashed var(--g500);border-radius:16px;padding:16px;margin-bottom:12px;background:var(--g100)}
.mp-editor .mp-field input,.mp-editor .mp-field textarea,.mp-editor .ms-trigger{background:var(--card)}

/* Matrix */
.mp-matrix-wrap{overflow:auto;border:1px solid var(--border);border-radius:16px}
.mp-matrix{border-collapse:separate;border-spacing:0;width:100%;font-size:13px}
.mp-matrix th,.mp-matrix td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:center;white-space:nowrap}
.mp-matrix thead th{background:var(--soft);font-weight:500;color:var(--muted);font-size:12px;position:sticky;top:0;z-index:1}
.mp-matrix th:first-child,.mp-matrix td:first-child{text-align:left;position:sticky;left:0;background:var(--card);z-index:2;min-width:220px}
.mp-matrix thead th:first-child{background:var(--soft);z-index:3}
.mp-matrix tbody tr:hover td{background:var(--soft)}
.mp-matrix td:first-child small{display:block;color:var(--muted);font-size:11.5px}
.mp-check{width:30px;height:30px;border-radius:9px;border:1.5px solid var(--border);background:var(--card);cursor:pointer;display:inline-grid;place-items:center;color:transparent;transition:all .15s}
.mp-check:hover{border-color:var(--g500)}
.mp-check.on{background:var(--g700);border-color:var(--g700);color:#fff}
.mp-check:disabled{opacity:.5;cursor:wait}
.mp-tag{font-size:10.5px;padding:2px 7px;border-radius:999px;margin-left:6px}
.mp-tag.active{background:var(--g100);color:var(--g700)}
.mp-tag.ended{background:var(--soft);color:var(--muted)}

.mp-toast{position:fixed;top:20px;right:20px;z-index:10000;background:var(--card);color:var(--text);padding:12px 18px;border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,.18);border-left:4px solid var(--g500);font-size:13.5px;max-width:360px}
.mp-toast.error{border-left-color:var(--red)}

@media (max-width:1100px){.mp-grid{grid-template-columns:1fr}}
@media (max-width:560px){.mp-row{grid-template-columns:1fr}.mp-team{grid-template-columns:auto minmax(0,1fr)}.mp-team-actions{grid-column:1/-1}}
</style>

<div class="mp" id="mpRoot">
  <div class="mp-head">
    <div>
      <h1>Projects &amp; Teams</h1>
      <p>Create projects, then choose which teams work on them. A team can work on several projects.</p>
    </div>
    <a href="Project_viewing.php" data-page="Project_viewing.php" class="mp-btn mp-btn-primary"><i class="fas fa-table-columns"></i> Open Project Board</a>
  </div>

  <div class="mp-grid">
    <!-- ===== New project ===== -->
    <section class="mp-card">
      <div class="mp-card-head"><h2>New project</h2><span class="mp-sub">Created by <?= esc($userName) ?></span></div>

      <form id="mpProjectForm" autocomplete="off">
        <input type="hidden" name="action" value="create_project">
        <input type="hidden" name="type" id="mpType" value="main">
        <div class="mp-seg" role="tablist">
          <button type="button" class="active" data-type="main">Main project</button>
          <button type="button" data-type="sub">Sub-project</button>
        </div>

        <div class="mp-field" id="mpParentField" hidden>
          <label for="mpParent">Parent project</label>
          <select name="parent_project" id="mpParent"></select>
          <div class="mp-hint">The sub-project inherits the parent's primary leader and teams.</div>
        </div>

        <div class="mp-field">
          <label for="mpName">Project name</label>
          <input type="text" id="mpName" name="project_name" required placeholder="e.g. Skills Hub Revamp">
        </div>
        <div class="mp-field">
          <label for="mpDeliverable">Deliverable</label>
          <input type="text" id="mpDeliverable" name="deliverable" required placeholder="What will be delivered?">
        </div>
        <div class="mp-row">
          <div class="mp-field"><label for="mpStart">Start date</label><input type="date" id="mpStart" name="start_date" required></div>
          <div class="mp-field"><label for="mpEnd">End date</label><input type="date" id="mpEnd" name="end_date" required></div>
        </div>

        <div id="mpMainOnly">
          <div class="mp-field">
            <span class="mp-label">Team leaders</span>
            <div class="ms" id="msLeaders"></div>
            <div class="mp-hint">The first leader you pick becomes the primary leader.</div>
          </div>
          <div class="mp-field">
            <span class="mp-label">Teams working on this project</span>
            <div class="ms" id="msProjectTeams"></div>
            <div class="mp-hint">Optional. You can change this later in the assignments table below.</div>
          </div>
        </div>

        <button type="submit" class="mp-btn mp-btn-primary" style="width:100%;margin-top:20px" id="mpSubmit"><i class="fas fa-plus"></i> Create project</button>
      </form>
    </section>

    <!-- ===== Teams ===== -->
    <section class="mp-card">
      <div class="mp-card-head">
        <div><h2>Teams</h2><div class="mp-sub" id="mpTeamCount"></div></div>
        <button type="button" class="mp-btn mp-btn-ghost mp-btn-sm" id="mpNewTeam"><i class="fas fa-plus"></i> New team</button>
      </div>

      <form class="mp-editor" id="mpTeamForm" hidden autocomplete="off">
        <input type="hidden" name="action" value="save_team">
        <input type="hidden" name="team_id" value="">
        <div class="mp-row">
          <div class="mp-field" style="margin-top:0"><label>Team name</label><input type="text" name="team_name" required placeholder="e.g. Mainframe"></div>
          <div class="mp-field" style="margin-top:0"><label>Responsibilities</label><input type="text" name="description" placeholder="e.g. Academy, Zogala, School Management"></div>
        </div>
        <div class="mp-field"><span class="mp-label">Members</span><div class="ms" id="msMembers"></div></div>
        <div class="mp-field"><span class="mp-label">Projects this team works on</span><div class="ms" id="msTeamProjects"></div></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
          <button type="button" class="mp-btn mp-btn-ghost mp-btn-sm" id="mpTeamCancel">Cancel</button>
          <button type="submit" class="mp-btn mp-btn-primary mp-btn-sm"><i class="fas fa-check"></i> Save team</button>
        </div>
      </form>

      <div class="mp-teams" id="mpTeams"></div>
    </section>
  </div>

  <!-- ===== Assignment matrix ===== -->
  <section class="mp-card">
    <div class="mp-card-head">
      <div><h2>Project ↔ team assignments</h2><div class="mp-sub">Tick a box to put a team on a project. Changes save immediately.</div></div>
      <div class="ms-search-wrap"><input type="search" class="ms-search" id="mpMatrixSearch" placeholder="Filter projects…" style="width:220px;margin:0"></div>
    </div>
    <div id="mpMatrix"></div>
  </section>
</div>

<script>
/* IIFE: manager_index.php re-runs this script each time the page is opened, so avoid top-level const/let. */
(function(){
  const root = document.getElementById('mpRoot');
  if (!root) return;
  const ENDPOINT = <?= json_encode(basename(__FILE__), $J) ?>;
  const state = {
    leaders:  <?= json_encode($leaders, $J) ?>,
    users:    <?= json_encode($users, $J) ?>,
    projects: <?= json_encode($projects, $J) ?>,
    teams:    <?= json_encode($teams, $J) ?>,
  };

  const $ = (sel, el = root) => el.querySelector(sel);
  const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
  const hue = (s) => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
  const initials = (s) => String(s).trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase() || '?';

  function toast(msg, isError){
    root.querySelectorAll('.mp-toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = 'mp-toast' + (isError ? ' error' : '');
    t.textContent = msg;
    root.appendChild(t);
    setTimeout(() => t.remove(), 3200);
  }

  async function post(data){
    const fd = data instanceof FormData ? data : Object.entries(data).reduce((f, [k, v]) => {
      (Array.isArray(v) ? v : [v]).forEach(x => f.append(Array.isArray(v) ? k + '[]' : k, x));
      return f;
    }, new FormData());
    const res = await fetch(ENDPOINT, { method:'POST', body:fd, credentials:'include', headers:{ 'X-Requested-With':'XMLHttpRequest' } });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch (e) { console.error(text); throw new Error('Server returned an invalid response.'); }
    if (json.teams) state.teams = json.teams;
    if (json.projects) state.projects = json.projects;
    if (json.status !== 'success') throw new Error(json.message || 'Request failed.');
    return json;
  }

  /* ---------- Multi-select with chips ---------- */
  function multiSelect(el, { name, placeholder, getOptions }){
    let selected = [];
    el.innerHTML = `
      <button type="button" class="ms-trigger"></button>
      <div class="ms-panel">
        <input type="text" class="ms-search" placeholder="Search…">
        <div class="ms-items"></div>
      </div>
      <div class="ms-hidden"></div>`;
    const trigger = $('.ms-trigger', el), items = $('.ms-items', el), search = $('.ms-search', el), hidden = $('.ms-hidden', el);

    function renderTrigger(){
      const opts = getOptions();
      trigger.innerHTML = selected.length
        ? selected.map(id => { const o = opts.find(x => x.id === id); return o ? `<span class="ms-chip"><b>${esc(o.name)}</b><span data-remove="${id}" title="Remove">&times;</span></span>` : ''; }).join('')
        : `<span class="ms-placeholder">${esc(placeholder)}</span>`;
      hidden.innerHTML = selected.map(id => `<input type="hidden" name="${name}" value="${id}">`).join('');
    }
    function renderItems(){
      const q = search.value.toLowerCase().trim();
      const opts = getOptions().filter(o => !q || o.name.toLowerCase().includes(q));
      items.innerHTML = opts.length
        ? opts.map(o => `<label class="ms-item"><input type="checkbox" value="${o.id}" ${selected.includes(o.id) ? 'checked' : ''}><span>${esc(o.name)}</span>${o.meta ? `<small>${esc(o.meta)}</small>` : ''}</label>`).join('')
        : '<div class="ms-empty">Nothing to choose yet.</div>';
    }
    trigger.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-remove]');
      if (rm) { selected = selected.filter(x => x !== Number(rm.dataset.remove)); renderTrigger(); renderItems(); return; }
      el.classList.toggle('open');
      if (el.classList.contains('open')) { renderItems(); search.focus(); }
    });
    items.addEventListener('change', (e) => {
      const id = Number(e.target.value);
      selected = e.target.checked ? [...selected, id] : selected.filter(x => x !== id);
      renderTrigger();
    });
    search.addEventListener('input', renderItems);
    document.addEventListener('click', (e) => { if (!el.contains(e.target)) el.classList.remove('open'); });

    renderTrigger();
    return {
      get: () => [...selected],
      set: (ids) => { selected = [...ids]; renderTrigger(); renderItems(); },
      refresh: () => { renderTrigger(); renderItems(); },
    };
  }

  const teamOpts    = () => state.teams.map(t => ({ id: t.team_id, name: t.team_name, meta: t.description || '' }));
  const projectOpts = () => state.projects.map(p => ({ id: p.id, name: p.name, meta: p.ended ? 'ended' : '' }));

  const msLeaders      = multiSelect($('#msLeaders'),      { name:'leader_ids[]',  placeholder:'Select team leaders', getOptions: () => state.leaders.map(l => ({ id:l.id, name:l.name })) });
  const msProjectTeams = multiSelect($('#msProjectTeams'), { name:'team_ids[]',    placeholder:'Select teams',        getOptions: teamOpts });
  const msMembers      = multiSelect($('#msMembers'),      { name:'member_ids[]',  placeholder:'Select members',      getOptions: () => state.users.map(u => ({ id:u.id, name:u.name, meta:u.role })) });
  const msTeamProjects = multiSelect($('#msTeamProjects'), { name:'project_ids[]', placeholder:'Select projects',     getOptions: projectOpts });

  /* ---------- Project form ---------- */
  const form = $('#mpProjectForm');
  function renderParents(){
    const sel = $('#mpParent'), cur = sel.value;
    sel.innerHTML = '<option value="">Select a main project</option>' +
      state.projects.filter(p => p.mine).map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
    sel.value = cur;
  }
  root.querySelectorAll('.mp-seg button').forEach(btn => btn.addEventListener('click', () => {
    root.querySelectorAll('.mp-seg button').forEach(b => b.classList.toggle('active', b === btn));
    const sub = btn.dataset.type === 'sub';
    $('#mpType').value = btn.dataset.type;
    $('#mpParentField').hidden = !sub;
    $('#mpMainOnly').hidden = sub;
    $('#mpParent').required = sub;
    $('#mpSubmit').innerHTML = `<i class="fas fa-plus"></i> Create ${sub ? 'sub-project' : 'project'}`;
  }));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#mpSubmit');
    btn.disabled = true;
    try {
      const res = await post(new FormData(form));
      toast(res.message);
      form.reset();
      $('#mpType').value = root.querySelector('.mp-seg button.active').dataset.type;
      msLeaders.set([]); msProjectTeams.set([]);
      renderAll();
    } catch (err) { toast(err.message, true); }
    finally { btn.disabled = false; }
  });

  /* ---------- Teams list + editor ---------- */
  const teamForm = $('#mpTeamForm');
  function openEditor(team){
    teamForm.hidden = false;
    teamForm.team_id.value = team ? team.team_id : '';
    teamForm.team_name.value = team ? team.team_name : '';
    teamForm.description.value = team ? (team.description || '') : '';
    msMembers.set(team ? team.member_ids : []);
    msTeamProjects.set(team ? team.project_ids : []);
    teamForm.team_name.focus();
    teamForm.scrollIntoView({ behavior:'smooth', block:'nearest' });
  }
  $('#mpNewTeam').addEventListener('click', () => openEditor(null));
  $('#mpTeamCancel').addEventListener('click', () => { teamForm.hidden = true; });
  teamForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await post(new FormData(teamForm));
      toast(res.message);
      teamForm.hidden = true;
      renderAll();
    } catch (err) { toast(err.message, true); }
  });

  function renderTeams(){
    const box = $('#mpTeams');
    $('#mpTeamCount').textContent = `${state.teams.length} team${state.teams.length === 1 ? '' : 's'}`;
    if (!state.teams.length) { box.innerHTML = '<div class="mp-empty"><i class="fas fa-people-group" style="font-size:26px;opacity:.4;display:block;margin-bottom:8px"></i>No teams yet. Create one to start assigning teams to projects.</div>'; return; }
    const pname = (id) => state.projects.find(p => p.id === id)?.name;
    box.innerHTML = state.teams.map(t => {
      const h = hue(t.team_name);
      const projs = t.project_ids.map(pname).filter(Boolean);
      return `
        <div class="mp-team">
          <span class="mp-team-badge" style="background:hsl(${h},65%,90%);color:hsl(${h},45%,28%)">${esc(initials(t.team_name))}</span>
          <div style="min-width:0">
            <h3>${esc(t.team_name)}</h3>
            <p>${esc(t.description || 'No responsibilities set')} · ${t.members.length} member${t.members.length === 1 ? '' : 's'}</p>
            <div class="mp-chips">${projs.length ? projs.map(n => `<span class="mp-chip">${esc(n)}</span>`).join('') : '<span class="mp-chip muted">Not on any project</span>'}</div>
          </div>
          <div class="mp-team-actions">
            <button type="button" class="mp-btn mp-btn-ghost mp-btn-sm" data-edit="${t.team_id}"><i class="fas fa-pen"></i> Edit</button>
            <button type="button" class="mp-btn mp-btn-danger mp-btn-sm" data-delete="${t.team_id}" title="Delete team"><i class="fas fa-trash"></i></button>
          </div>
        </div>`;
    }).join('');
  }
  $('#mpTeams').addEventListener('click', async (e) => {
    const edit = e.target.closest('[data-edit]'), del = e.target.closest('[data-delete]');
    if (edit) openEditor(state.teams.find(t => t.team_id === Number(edit.dataset.edit)));
    if (del) {
      const t = state.teams.find(x => x.team_id === Number(del.dataset.delete));
      if (!t || !confirm(`Delete team "${t.team_name}"? Its project links and member list will be removed. Tasks are not deleted.`)) return;
      try { toast((await post({ action:'delete_team', team_id:t.team_id })).message); renderAll(); }
      catch (err) { toast(err.message, true); }
    }
  });

  /* ---------- Assignment matrix ---------- */
  function renderMatrix(){
    const box = $('#mpMatrix');
    if (!state.teams.length || !state.projects.length) {
      box.innerHTML = `<div class="mp-empty">${!state.projects.length ? 'No projects yet.' : 'Create a team first, then tick which projects it works on.'}</div>`;
      return;
    }
    const q = $('#mpMatrixSearch').value.toLowerCase().trim();
    const rows = state.projects.filter(p => !q || p.name.toLowerCase().includes(q));
    const has = (t, pid) => t.project_ids.includes(pid);
    box.innerHTML = `
      <div class="mp-matrix-wrap">
        <table class="mp-matrix">
          <thead><tr><th>Project</th>${state.teams.map(t => `<th title="${esc(t.description || '')}">${esc(t.team_name)}</th>`).join('')}</tr></thead>
          <tbody>${rows.map(p => `
            <tr>
              <td>${esc(p.name)}<span class="mp-tag ${p.ended ? 'ended' : 'active'}">${p.ended ? 'Ended' : 'Running'}</span>
                <small>${state.teams.filter(t => has(t, p.id)).length} team(s)</small></td>
              ${state.teams.map(t => `<td><button type="button" class="mp-check ${has(t, p.id) ? 'on' : ''}" data-p="${p.id}" data-t="${t.team_id}"
                  aria-pressed="${has(t, p.id)}" title="${esc(t.team_name)} on ${esc(p.name)}"><i class="fas fa-check"></i></button></td>`).join('')}
            </tr>`).join('') || `<tr><td colspan="${state.teams.length + 1}" class="mp-empty">No projects match.</td></tr>`}
          </tbody>
        </table>
      </div>`;
  }
  $('#mpMatrix').addEventListener('click', async (e) => {
    const cell = e.target.closest('.mp-check');
    if (!cell) return;
    const pid = Number(cell.dataset.p), tid = Number(cell.dataset.t);
    const current = state.teams.filter(t => t.project_ids.includes(pid)).map(t => t.team_id);
    const next = current.includes(tid) ? current.filter(x => x !== tid) : [...current, tid];
    cell.disabled = true;
    try { await post({ action:'save_project_teams', project_id:pid, team_ids:next }); renderTeams(); renderMatrix(); }
    catch (err) { toast(err.message, true); cell.disabled = false; }
  });
  $('#mpMatrixSearch').addEventListener('input', renderMatrix);

  function renderAll(){
    renderParents(); renderTeams(); renderMatrix();
    [msProjectTeams, msTeamProjects].forEach(m => m.refresh());
  }
  renderAll();
})();
</script>
