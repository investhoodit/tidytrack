<?php
session_start();
require_once __DIR__.'/../dp.php';
require_once __DIR__.'/../track.php';

@include __DIR__ . '/../profile.php';

/* --- AUTH GUARD --- */
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'manager') { http_response_code(403); echo "Forbidden"; exit; }
log_activity($userId, "Viewed Project Performance");

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* --- Filters --- */
$search  = trim($_GET['q'] ?? '');
$dueFrom = $_GET['due_from'] ?? '';
$dueTo   = $_GET['due_to']   ?? '';

$whereP = []; $params = []; $types = '';
if ($search !== '') { $whereP[] = "(p.project_name LIKE CONCAT('%',?,'%'))"; $params[] = $search; $types .= 's'; }
if ($dueFrom !== '') { $whereP[] = "p.end_date >= ?"; $params[] = $dueFrom; $types .= 's'; }
if ($dueTo   !== '') { $whereP[] = "p.end_date <= ?"; $params[] = $dueTo;   $types .= 's'; }
$sqlWhereP = $whereP ? ('WHERE '.implode(' AND ', $whereP)) : '';

/* --- Per-project task rollups --- */
$sql = "
  SELECT
    p.project_id, p.project_name, p.start_date, p.end_date,
    COUNT(t.task_id) AS total_tasks,
    SUM(CASE WHEN t.status='Completed'   THEN 1 ELSE 0 END) AS done,
    SUM(CASE WHEN t.status='In progress' THEN 1 ELSE 0 END) AS inprog,
    SUM(CASE WHEN t.status='In review'   THEN 1 ELSE 0 END) AS inrev,
    SUM(CASE WHEN t.status='On hold'     THEN 1 ELSE 0 END) AS onhold,
    SUM(CASE WHEN t.dueDate < CURDATE() AND t.status <> 'Completed' THEN 1 ELSE 0 END) AS overdue,
    IFNULL(GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', '),'—') AS team
  FROM projects_x p
  LEFT JOIN tasks t ON t.project_id = p.project_id
  LEFT JOIN users u ON (t.assignTo = u.name OR t.assignTo = u.user_id)
  $sqlWhereP
  GROUP BY p.project_id, p.project_name, p.start_date, p.end_date
  ORDER BY p.end_date ASC, p.project_name ASC
";
$st = $conn->prepare($sql);
if ($types) { $st->bind_param($types, ...$params); }
$st->execute();
$res = $st->get_result();

$rows = [];
while($r = $res->fetch_assoc()){
  $r['total_tasks'] = (int)$r['total_tasks'];
  $r['done']  = (int)$r['done'];  $r['inprog'] = (int)$r['inprog'];
  $r['inrev'] = (int)$r['inrev']; $r['onhold'] = (int)$r['onhold'];
  $r['overdue'] = (int)$r['overdue'];
  $r['pct'] = $r['total_tasks'] > 0 ? round(($r['done'] / $r['total_tasks']) * 100, 1) : 0;
  $rows[] = $r;
}
$st->close();

/* --- Assignee Workload Breakdown --- */
$assMap = [];
if ($rs = $conn->query("SELECT t.project_id, IFNULL(u.name, CAST(t.assignTo AS CHAR)) AS who, COUNT(*) AS cnt FROM tasks t LEFT JOIN users u ON (t.assignTo = u.name OR t.assignTo = u.user_id) GROUP BY t.project_id, who")) {
  $tmp = [];
  while($a = $rs->fetch_assoc()){
    $pid = (int)$a['project_id']; if(!$pid) continue;
    $tmp[$pid][] = [($a['who'] ?: 'Unassigned'), (int)$a['cnt']];
  }
  foreach($tmp as $pid => $pairs){
    usort($pairs, fn($A,$B)=> $B[1] <=> $A[1]);
    $pairs = array_slice($pairs, 0, 5);
    $assMap[$pid] = implode(', ', array_map(fn($p)=> $p[0].' ('.$p[1].')', $pairs));
  }
}

/* --- KPIs --- */
$totalProjects     = count($rows);
$overallTotalTasks = array_sum(array_column($rows,'total_tasks'));
$overallDone       = array_sum(array_column($rows,'done'));
$overallPct        = $overallTotalTasks > 0 ? round(($overallDone / $overallTotalTasks) * 100, 1) : 0;
$overallOverdue    = array_sum(array_column($rows,'overdue'));
?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Project Performance — Manager Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
  /* =============================================
     SKIP LINK
  ============================================= */
  .skip-link {
    position: absolute; top: -100px; left: 0;
    background: #000; color: #fff; padding: 12px 20px;
    z-index: 100000; font-weight: 700; font-size: 16px;
    border-radius: 0 0 8px 0; text-decoration: none; transition: top .2s;
  }
  .skip-link:focus { top: 0; outline: 3px solid #fbbf24; outline-offset: 2px; }

  /* =============================================
     SCREEN READER ONLY
  ============================================= */
  .sr-only {
    position: absolute; width: 1px; height: 1px;
    padding: 0; margin: -1px; overflow: hidden;
    clip: rect(0,0,0,0); white-space: nowrap; border-width: 0;
  }

  /* =============================================
     FOCUS INDICATOR
  ============================================= */
  *:focus-visible {
    outline: 3px solid #f59e0b !important;
    outline-offset: 3px !important;
    border-radius: 4px;
  }

  /* =============================================
     CSS VARIABLES + THEMES
  ============================================= */
  :root {
    --ink:#1d3557; --muted:#6b7280; --line:#e5e7eb;
    --card:#fff; --bg:#f6f8fb; --accent:#1d3557;
    --red:#b91c1c; --success:#16a34a;
    --shadow:0 2px 10px rgba(0,0,0,.07);
    --radius:12px; --radius-sm:8px;
    --transition:all .2s ease;
  }
  /* Dark mode */
  body.dark-mode {
    --bg:#1a1f2e; --card:#242b3d; --ink:#e5e7eb;
    --muted:#9ca3af; --line:#334155; --accent:#60a5fa;
  }
  /* High Contrast */
  body.high-contrast {
    --bg:#000 !important; --card:#111 !important; --ink:#fff !important;
    --muted:#ddd !important; --line:#fff !important; --accent:#ffff00 !important;
    --red:#ff6666 !important; --success:#00ff88 !important;
  }
  body.high-contrast .badge, body.high-contrast input,
  body.high-contrast button, body.high-contrast select { border: 2px solid #fff !important; }
  body.high-contrast .bar > div { background: #ffff00 !important; }

  /* Large text */
  body.large-text { font-size: 118%; }
  body.large-text .big { font-size: 1.6rem !important; }
  body.large-text th, body.large-text td { font-size: 1rem !important; }

  /* Reduce motion */
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
  body.reduce-motion *, body.reduce-motion *::before, body.reduce-motion *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }

  /* =============================================
     BASE
  ============================================= */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Poppins, system-ui; background: var(--bg); color: var(--ink); }
  .wrap { max-width: 1200px; margin: 20px auto; padding: 0 16px; }

  /* =============================================
     TOP BAR
  ============================================= */
  .topbar {
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
  }
  .back-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 14px; background: var(--accent); color: #fff;
    text-decoration: none; border-radius: var(--radius-sm);
    font-weight: 600; transition: var(--transition);
  }
  .back-btn:hover { opacity: .88; }

  /* =============================================
     KPI CARDS
  ============================================= */
  .kpis { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin: 8px 0 16px; }
  @media (max-width:900px) { .kpis { grid-template-columns: repeat(2,1fr); } }
  @media (max-width:500px) { .kpis { grid-template-columns: 1fr; } }
  .card {
    background: var(--card); border: 1px solid var(--line);
    border-radius: var(--radius); padding: 14px; box-shadow: var(--shadow);
  }
  .tiny { font-size: .78rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
  .big { font-weight: 700; font-size: 1.35rem; color: var(--ink); }
  .bar { height: 10px; background: var(--line); border-radius: 6px; overflow: hidden; margin-top: 6px; }
  .bar > div { height: 10px; background: var(--accent); border-radius: 6px; transition: width .4s ease; }
  .overdue { color: var(--red) !important; }

  /* =============================================
     FILTERS
  ============================================= */
  .filters { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; align-items: center; }
  .filters label { font-size: .85rem; font-weight: 600; color: var(--muted); }
  .filters input[type="text"], .filters input[type="date"] {
    padding: 8px 12px; border: 1px solid var(--line); border-radius: var(--radius-sm);
    background: var(--card); color: var(--ink); font-family: inherit; font-size: .9rem;
  }
  .filters input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(96,165,250,.2); }

  /* =============================================
     TABLE
  ============================================= */
  .table-wrapper { overflow-x: auto; border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 24px; }
  table {
    width: 100%; border-collapse: collapse; background: var(--card);
    border: 1px solid var(--line); min-width: 900px;
  }
  table caption {
    caption-side: top; text-align: left; padding: 10px 12px;
    font-weight: 700; font-size: .9rem; color: var(--ink);
    background: var(--card); border-bottom: 1px solid var(--line);
  }
  th, td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: left; font-size: .9rem; }
  th { color: var(--muted); text-transform: uppercase; font-size: .78rem; font-weight: 600; background: var(--bg); }
  tbody tr:hover { background: rgba(96,165,250,.07); }
  tbody tr:focus-within { outline: 2px solid var(--accent); outline-offset: -2px; }

  /* =============================================
     BUTTONS / BADGES
  ============================================= */
  .badge {
    padding: 6px 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--line); cursor: pointer;
    background: var(--card); color: var(--ink); font-family: inherit;
    font-size: .85rem; transition: var(--transition); font-weight: 600;
  }
  .badge:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
  .badge.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .badge.primary:hover { opacity: .88; }
  .badge-reset { color: var(--muted); text-decoration: none; }
  .badge-reset:hover { color: var(--ink); }

  /* =============================================
     ACCESSIBILITY TOOLBAR (right side, fixed)
  ============================================= */
  #a11y-toolbar {
    position: fixed; top: 50%; right: 0; transform: translateY(-50%);
    z-index: 99998; display: flex; flex-direction: column; gap: 4px;
    background: var(--card); border: 2px solid var(--line); border-right: none;
    border-radius: 12px 0 0 12px; padding: 10px 6px; box-shadow: var(--shadow);
  }
  .a11y-btn {
    width: 44px; height: 44px; border-radius: 8px; border: 2px solid var(--line);
    background: var(--card); color: var(--ink); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; transition: var(--transition); position: relative;
  }
  .a11y-btn:hover, .a11y-btn.active {
    background: var(--accent); color: #000; border-color: var(--accent); transform: scale(1.1);
  }
  .a11y-btn .a11y-tooltip {
    position: absolute; right: calc(100% + 10px); top: 50%; transform: translateY(-50%);
    background: #1e293b; color: #fff; padding: 6px 12px; border-radius: 6px;
    font-size: 13px; font-weight: 600; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .2s; z-index: 99999;
  }
  .a11y-btn:hover .a11y-tooltip,
  .a11y-btn:focus-visible .a11y-tooltip { opacity: 1; }

  /* =============================================
     KEYBOARD SHORTCUTS OVERLAY
  ============================================= */
  #kbd-help-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); justify-content: center;
    align-items: center; z-index: 100000;
  }
  #kbd-help-box {
    background: var(--card); padding: 28px; width: 520px;
    max-width: 94%; border-radius: var(--radius); box-shadow: 0 20px 50px rgba(0,0,0,.4);
    max-height: 85vh; overflow-y: auto;
  }
  #kbd-help-box h2 {
    margin: 0 0 18px; font-size: 18px; font-weight: 700;
    color: var(--ink); display: flex; align-items: center; gap: 10px;
  }
  #kbd-help-box table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: unset; }
  #kbd-help-box th, #kbd-help-box td { font-size: 13px; }
  #kbd-help-box tr:nth-child(even) td { background: rgba(96,165,250,.05); }
  kbd {
    background: var(--bg); border: 1px solid var(--line);
    border-radius: 4px; padding: 2px 7px; font-size: .82rem;
    font-family: monospace; color: var(--ink);
  }

  /* =============================================
     VOICE ASSISTANT PANEL
  ============================================= */
  #voice-panel {
    position: fixed; bottom: 24px; right: 24px; z-index: 99998; width: 360px;
    background: var(--card); border: 2px solid var(--accent); border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.3); overflow: hidden;
    display: none; flex-direction: column; font-size: 14px;
  }
  #voice-panel.open { display: flex; animation: slideUp .3s ease; }
  @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  #voice-panel-header {
    padding: 14px 18px; background: var(--accent); color: #000;
    display: flex; align-items: center; justify-content: space-between;
    font-weight: 800; font-size: 15px;
  }
  #voice-transcript {
    padding: 16px; min-height: 60px; max-height: 120px; overflow-y: auto;
    color: var(--ink); background: var(--bg); font-size: 14px; border-bottom: 1px solid var(--line);
  }
  #voice-response {
    padding: 14px 16px; min-height: 50px; max-height: 100px;
    overflow-y: auto; color: var(--ink); font-size: 13px; line-height: 1.5;
  }
  #voice-controls {
    padding: 14px 16px; display: flex; gap: 10px; align-items: center;
    border-top: 1px solid var(--line); background: var(--bg);
  }
  #voice-mic-btn {
    width: 52px; height: 52px; border-radius: 50%; border: none;
    background: var(--accent); color: #000; font-size: 22px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: var(--transition); flex-shrink: 0;
  }
  #voice-mic-btn.listening { background: var(--red); color: #fff; animation: pulse-mic 1s infinite; }
  @keyframes pulse-mic { 0%,100%{box-shadow:0 0 0 0 rgba(185,28,28,.4)} 50%{box-shadow:0 0 0 12px rgba(185,28,28,0)} }
  #voice-status { font-size: 12px; color: var(--muted); font-weight: 600; flex: 1; }
  #voice-commands-hint { padding: 10px 16px; background: rgba(96,165,250,.08); font-size: 12px; color: var(--muted); line-height: 1.6; border-top: 1px solid var(--line); }

  /* =============================================
     MODAL (project details)
  ============================================= */
  #projModal {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.6); z-index: 9999;
    align-items: center; justify-content: center;
  }
  #projModal.open { display: flex; animation: fadeIn .25s ease; }
  @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
  #projModalInner {
    background: var(--card); width: 90%; max-width: 920px;
    border-radius: var(--radius); padding: 22px;
    max-height: 85vh; overflow: auto; box-shadow: 0 20px 50px rgba(0,0,0,.4);
  }
  #projModalHeader {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
  }
  #projTitle { font-size: 1.1rem; font-weight: 700; color: var(--ink); }

  /* =============================================
     TOAST
  ============================================= */
  .toast {
    position: fixed; top: 20px; right: 80px;
    background: var(--card); color: var(--ink);
    padding: 12px 20px; border-radius: var(--radius-sm);
    box-shadow: var(--shadow); z-index: 99997; font-weight: 600; font-size: .9rem;
    animation: toastIn .3s ease; border-left: 4px solid var(--accent); max-width: 320px;
  }
  @keyframes toastIn  { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }
  @keyframes toastOut { from { transform: none; opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
  </style>
</head>

<body>

<!-- =============================================
     SKIP LINKS
============================================= -->
<a href="#main-content" class="skip-link">Skip to main content</a>
<a href="#filter-search" class="skip-link" style="left:220px">Skip to search/filter</a>

<!-- =============================================
     ARIA LIVE REGIONS
============================================= -->
<div id="live-region" aria-live="polite" aria-atomic="true" role="status"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>
<div id="live-region-urgent" aria-live="assertive" aria-atomic="true" role="alert"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>

<!-- =============================================
     ACCESSIBILITY TOOLBAR
============================================= -->
<nav id="a11y-toolbar" aria-label="Accessibility options">

  <button class="a11y-btn" id="btn-high-contrast" onclick="toggleHighContrast()"
          aria-pressed="false" aria-label="Toggle high contrast mode">
    <i class="fas fa-adjust" aria-hidden="true"></i>
    <span class="a11y-tooltip">High Contrast</span>
  </button>

  <button class="a11y-btn" id="btn-large-text" onclick="toggleLargeText()"
          aria-pressed="false" aria-label="Toggle large text mode">
    <i class="fas fa-text-height" aria-hidden="true"></i>
    <span class="a11y-tooltip">Large Text</span>
  </button>

  <button class="a11y-btn" id="btn-reduce-motion" onclick="toggleReduceMotion()"
          aria-pressed="false" aria-label="Reduce animations">
    <i class="fas fa-wind" aria-hidden="true"></i>
    <span class="a11y-tooltip">Reduce Motion</span>
  </button>

  <button class="a11y-btn" id="btn-dark-mode" onclick="toggleDarkMode()"
          aria-pressed="false" aria-label="Toggle dark mode">
    <i class="fas fa-moon" aria-hidden="true"></i>
    <span class="a11y-tooltip">Dark Mode</span>
  </button>

  <button class="a11y-btn" id="btn-voice" onclick="toggleVoicePanel()"
          aria-expanded="false" aria-controls="voice-panel"
          aria-label="Open voice assistant">
    <i class="fas fa-microphone" aria-hidden="true"></i>
    <span class="a11y-tooltip">Voice Assistant</span>
  </button>

  <button class="a11y-btn" id="btn-read-page" onclick="readPageSummary()"
          aria-label="Read page summary aloud">
    <i class="fas fa-volume-up" aria-hidden="true"></i>
    <span class="a11y-tooltip">Read Summary</span>
  </button>

  <button class="a11y-btn" id="btn-stop-speech" onclick="stopSpeech()"
          aria-label="Stop speaking" style="display:none;">
    <i class="fas fa-stop" aria-hidden="true"></i>
    <span class="a11y-tooltip">Stop Reading</span>
  </button>

  <button class="a11y-btn" id="btn-kbd-help" onclick="toggleKbdHelp()"
          aria-expanded="false" aria-controls="kbd-help-overlay"
          aria-label="Keyboard shortcuts help">
    <i class="fas fa-keyboard" aria-hidden="true"></i>
    <span class="a11y-tooltip">Keyboard Help</span>
  </button>

</nav>

<!-- =============================================
     VOICE ASSISTANT PANEL
============================================= -->
<div id="voice-panel" role="dialog" aria-label="Voice assistant" aria-modal="false">
  <div id="voice-panel-header">
    <span><i class="fas fa-microphone" aria-hidden="true"></i> &nbsp;Voice Assistant</span>
    <button onclick="toggleVoicePanel()" aria-label="Close voice assistant"
            style="background:none;border:none;color:#000;cursor:pointer;font-size:20px;line-height:1;">
      &times;
    </button>
  </div>
  <div id="voice-transcript" role="log" aria-live="polite" aria-label="Your speech">
    <em style="color:var(--muted);">Your speech will appear here…</em>
  </div>
  <div id="voice-response" aria-live="polite" aria-label="Assistant response">
    <em style="color:var(--muted);">Assistant response will appear here…</em>
  </div>
  <div id="voice-controls">
    <button id="voice-mic-btn" onclick="toggleListening()"
            aria-label="Start listening" aria-pressed="false">
      <i class="fas fa-microphone" aria-hidden="true"></i>
    </button>
    <div id="voice-status">Click the mic to speak</div>
  </div>
  <div id="voice-commands-hint">
    <strong>Try saying:</strong><br>
    "Read summary" • "How many projects" • "How many overdue" •
    "Overall progress" • "Go back to dashboard" • "Toggle dark mode"
  </div>
</div>

<!-- =============================================
     KEYBOARD SHORTCUTS OVERLAY
============================================= -->
<div id="kbd-help-overlay"
     role="dialog" aria-modal="true" aria-labelledby="kbdHelpTitle">
  <div id="kbd-help-box">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;">
      <h2 id="kbdHelpTitle">
        <i class="fas fa-keyboard" aria-hidden="true"></i> Keyboard Shortcuts
      </h2>
      <button onclick="toggleKbdHelp()" aria-label="Close keyboard shortcuts help"
              style="background:none;border:none;font-size:22px;color:var(--muted);
                     cursor:pointer;width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
        &times;
      </button>
    </div>
    <table aria-label="Keyboard shortcuts list">
      <caption class="sr-only">Complete list of keyboard shortcuts for the Project Performance page.</caption>
      <thead>
        <tr>
          <th scope="col" style="text-align:left;padding:10px;background:rgba(96,165,250,.1);border:1px solid var(--line);">Key</th>
          <th scope="col" style="text-align:left;padding:10px;background:rgba(96,165,250,.1);border:1px solid var(--line);">Action</th>
        </tr>
      </thead>
      <tbody>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>?</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Show / hide this guide</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>/</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Focus the project search box</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Escape</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Close any open dialog or stop speech</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Alt + R</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Read the page summary aloud</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Alt + V</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Open or close the voice assistant</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Alt + C</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Toggle high contrast mode</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Alt + T</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Toggle large text mode</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Alt + D</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Toggle dark mode</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Tab / Shift+Tab</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Navigate between focusable elements</td></tr>
        <tr><td style="padding:9px 10px;border:1px solid var(--line);"><kbd>Enter / Space</kbd></td><td style="padding:9px 10px;border:1px solid var(--line);">Activate buttons</td></tr>
      </tbody>
    </table>
    <div style="margin-top:18px;padding:14px;background:rgba(96,165,250,.08);border-radius:8px;font-size:13px;color:var(--muted);line-height:1.6;">
      <strong style="color:var(--ink);">Voice commands</strong> — open the mic and try:<br>
      <em>"Read summary"</em> &bull; <em>"How many overdue"</em> &bull; <em>"Overall progress"</em> &bull; <em>"Go back"</em>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:18px;">
      <button onclick="toggleKbdHelp()"
              style="padding:10px 24px;background:var(--accent);color:#fff;border:none;
                     border-radius:var(--radius-sm);font-weight:700;cursor:pointer;font-family:inherit;"
              aria-label="Close keyboard shortcuts help">
        Close
      </button>
    </div>
  </div>
</div>

<!-- =============================================
     MAIN CONTENT
============================================= -->
<main class="wrap" id="main-content" role="main">

  <!-- TOP BAR -->
  <header class="topbar" role="banner">
    <a href="manager_index.php" class="back-btn" aria-label="Back to Manager Dashboard">
      <i class="fas fa-arrow-left" aria-hidden="true"></i>
      Back to Dashboard
    </a>
  </header>

  <h1 style="font-size:1.5rem;font-weight:700;margin-bottom:14px;" id="page-heading">
    Projects Performance
  </h1>

  <!-- =============================================
       KPI CARDS
  ============================================= -->
  <section aria-label="Key performance indicators" aria-describedby="kpi-desc">
    <p id="kpi-desc" class="sr-only">
      Four summary statistics: total projects, tasks completed, overall progress percentage, and number of overdue tasks.
    </p>
    <div class="kpis" role="list">

      <article class="card" role="listitem"
               aria-label="Total projects: <?= $totalProjects ?>">
        <div class="tiny" aria-hidden="true">Projects</div>
        <div class="big" aria-label="<?= $totalProjects ?> projects"><?= $totalProjects ?></div>
      </article>

      <article class="card" role="listitem"
               aria-label="Tasks completed: <?= $overallDone ?> out of <?= $overallTotalTasks ?>">
        <div class="tiny" aria-hidden="true">Tasks Completed</div>
        <div class="big"><?= $overallDone ?>/<?= $overallTotalTasks ?></div>
      </article>

      <article class="card" role="listitem"
               aria-label="Overall progress: <?= $overallPct ?> percent">
        <div class="tiny" aria-hidden="true">Overall Progress</div>
        <div class="big"><?= $overallPct ?>%</div>
        <div class="bar" role="progressbar"
             aria-valuenow="<?= $overallPct ?>"
             aria-valuemin="0" aria-valuemax="100"
             aria-label="Overall completion: <?= $overallPct ?>%">
          <div style="width:<?= $overallPct ?>%"></div>
        </div>
      </article>

      <article class="card" role="listitem"
               aria-label="Overdue tasks: <?= $overallOverdue ?>">
        <div class="tiny" aria-hidden="true">Overdue</div>
        <div class="big overdue"
             aria-label="<?= $overallOverdue ?> overdue task<?= $overallOverdue !== 1 ? 's' : '' ?>">
          <?= $overallOverdue ?>
        </div>
      </article>

    </div>
  </section>

  <!-- =============================================
       FILTERS
  ============================================= -->
  <section aria-label="Filter projects" style="margin-bottom:16px;">
    <form class="filters" method="get" role="search" aria-label="Filter projects by name and date">

      <div style="display:flex;flex-direction:column;gap:4px;">
        <label for="filter-search" style="font-size:.78rem;font-weight:600;color:var(--muted);">Search project</label>
        <input type="text" id="filter-search" name="q"
               placeholder="Type project name…"
               value="<?= esc($search) ?>"
               aria-label="Search project by name"
               autocomplete="off"
               style="padding:8px 12px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--card);color:var(--ink);font-family:inherit;">
      </div>

      <div style="display:flex;flex-direction:column;gap:4px;">
        <label for="due_from" style="font-size:.78rem;font-weight:600;color:var(--muted);">Due date from</label>
        <input type="date" id="due_from" name="due_from"
               value="<?= esc($dueFrom) ?>"
               aria-label="Filter: due date from"
               style="padding:8px 12px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--card);color:var(--ink);font-family:inherit;">
      </div>

      <div style="display:flex;flex-direction:column;gap:4px;">
        <label for="due_to" style="font-size:.78rem;font-weight:600;color:var(--muted);">Due date to</label>
        <input type="date" id="due_to" name="due_to"
               value="<?= esc($dueTo) ?>"
               aria-label="Filter: due date to"
               style="padding:8px 12px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--card);color:var(--ink);font-family:inherit;">
      </div>

      <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px;">
        <button type="submit" class="badge primary"
                aria-label="Apply filters">
          <i class="fas fa-filter" aria-hidden="true"></i> Apply
        </button>
        <a href="manager_project_performance.php" class="badge badge-reset"
           aria-label="Reset all filters">
          <i class="fas fa-times" aria-hidden="true"></i> Reset
        </a>
      </div>

    </form>
    <?php if($search || $dueFrom || $dueTo): ?>
    <p role="status" aria-live="polite" style="font-size:.85rem;color:var(--muted);margin-top:-4px;">
      Showing <?= count($rows) ?> project<?= count($rows) !== 1 ? 's' : '' ?>
      <?= $search ? ' matching "'.esc($search).'"' : '' ?>
      <?= ($dueFrom || $dueTo) ? ' with due date'.($dueFrom ? ' from '.esc($dueFrom) : '').($dueTo ? ' to '.esc($dueTo) : '') : '' ?>.
    </p>
    <?php endif; ?>
  </section>

  <!-- =============================================
       PROJECTS TABLE
  ============================================= -->
  <section aria-label="Project performance table" aria-describedby="table-desc">
    <p id="table-desc" class="sr-only">
      Table showing performance data for <?= $totalProjects ?> project<?= $totalProjects !== 1 ? 's' : '' ?>.
      Columns: Project name and team, completion progress bar and percentage, tasks done out of total,
      in-progress count, in-review count, on-hold count, overdue count, top assignees,
      start date, due date, and a view action button.
    </p>
    <div class="table-wrapper" role="region" aria-label="Projects performance data">
      <table aria-label="Project performance — <?= $totalProjects ?> project<?= $totalProjects !== 1 ? 's' : '' ?>">
        <caption>
          Project Performance Report — <?= $totalProjects ?> project<?= $totalProjects !== 1 ? 's' : '' ?> found
          <?php if($search || $dueFrom || $dueTo): ?>
            (filtered)
          <?php endif; ?>
        </caption>
        <thead>
          <tr role="row">
            <th scope="col">Project</th>
            <th scope="col">Progress</th>
            <th scope="col">Done</th>
            <th scope="col">In Progress</th>
            <th scope="col">In Review</th>
            <th scope="col">On Hold</th>
            <th scope="col">Overdue</th>
            <th scope="col">Top Assignees</th>
            <th scope="col">Start</th>
            <th scope="col">Due</th>
            <th scope="col">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if(!$rows): ?>
          <tr>
            <td colspan="11" style="text-align:center;padding:30px;color:var(--muted);" role="status">
              <i class="fas fa-folder-open" aria-hidden="true" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4;"></i>
              No projects found<?= ($search || $dueFrom || $dueTo) ? ' matching your filters' : '' ?>.
            </td>
          </tr>
          <?php else: foreach($rows as $r): $pid = $r['project_id']; ?>
          <tr role="row"
              aria-label="Project <?= esc($r['project_name']) ?>: <?= $r['pct'] ?>% complete, <?= $r['overdue'] ?> overdue task<?= $r['overdue'] !== 1 ? 's' : '' ?>">
            <td role="cell">
              <strong><?= esc($r['project_name']) ?></strong><br>
              <span class="tiny" style="color:var(--muted);font-size:.78rem;"
                    aria-label="Team: <?= esc($r['team']) ?>">
                <?= esc($r['team']) ?>
              </span>
            </td>
            <td role="cell">
              <div class="bar" role="progressbar"
                   aria-valuenow="<?= $r['pct'] ?>"
                   aria-valuemin="0" aria-valuemax="100"
                   aria-label="<?= $r['pct'] ?>% complete"
                   style="margin-bottom:4px;">
                <div style="width:<?= $r['pct'] ?>%"></div>
              </div>
              <span style="font-size:.82rem;font-weight:600;"><?= $r['pct'] ?>%</span>
            </td>
            <td role="cell" aria-label="<?= $r['done'] ?> of <?= $r['total_tasks'] ?> tasks done">
              <?= $r['done'] ?>/<?= $r['total_tasks'] ?>
            </td>
            <td role="cell" aria-label="<?= $r['inprog'] ?> in progress"><?= $r['inprog'] ?></td>
            <td role="cell" aria-label="<?= $r['inrev'] ?> in review"><?= $r['inrev'] ?></td>
            <td role="cell" aria-label="<?= $r['onhold'] ?> on hold"><?= $r['onhold'] ?></td>
            <td role="cell" class="<?= $r['overdue'] ? 'overdue' : '' ?>"
                aria-label="<?= $r['overdue'] ?> overdue"><?= $r['overdue'] ?></td>
            <td role="cell" style="font-size:.82rem;"
                aria-label="Top assignees: <?= esc($assMap[$pid] ?? 'None') ?>">
              <?= esc($assMap[$pid] ?? '—') ?>
            </td>
            <td role="cell"><?= esc($r['start_date'] ?: '—') ?></td>
            <td role="cell"><?= esc($r['end_date'] ?: '—') ?></td>
            <td role="cell">
              <button class="badge btn-view-project" data-project-id="<?= $pid ?>"
                      aria-label="View detailed overview for project: <?= esc($r['project_name']) ?>">
                <i class="fas fa-eye" aria-hidden="true"></i> View
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>

<!-- =============================================
     PROJECT DETAIL MODAL
============================================= -->
<div id="projModal" role="dialog" aria-modal="true" aria-labelledby="projTitle">
  <div id="projModalInner">
    <div id="projModalHeader">
      <h2 id="projTitle">Project Overview</h2>
      <button onclick="closeProjModal()" class="badge"
              aria-label="Close project overview dialog">
        <i class="fas fa-times" aria-hidden="true"></i> Close
      </button>
    </div>
    <div id="projContent" aria-live="polite" role="region" aria-label="Project details">
      <em>Loading…</em>
    </div>
  </div>
</div>

<!-- =============================================
     JAVASCRIPT
============================================= -->
<script>
// Dashboard data for TTS/voice
const PAGE_DATA = {
  totalProjects:  <?= (int)$totalProjects ?>,
  totalTasks:     <?= (int)$overallTotalTasks ?>,
  doneTasks:      <?= (int)$overallDone ?>,
  overallPct:     <?= (float)$overallPct ?>,
  overallOverdue: <?= (int)$overallOverdue ?>
};

// =============================================
// LIVE REGION
// =============================================
function announce(msg, urgent = false) {
  const el = document.getElementById(urgent ? 'live-region-urgent' : 'live-region');
  el.textContent = '';
  requestAnimationFrame(() => { el.textContent = msg; });
}

// =============================================
// TEXT-TO-SPEECH
// =============================================
let currentUtterance = null;

function speak(text, onEnd) {
  if (!window.speechSynthesis) return;
  stopSpeech();
  currentUtterance = new SpeechSynthesisUtterance(text);
  currentUtterance.rate = 0.95; currentUtterance.pitch = 1.0; currentUtterance.lang = 'en-ZA';
  currentUtterance.onstart = () => {
    document.getElementById('btn-stop-speech').style.display = 'flex';
    document.getElementById('btn-read-page').classList.add('active');
    document.getElementById('btn-read-page').setAttribute('aria-label', 'Currently reading — click to stop');
  };
  currentUtterance.onend = currentUtterance.onerror = () => {
    document.getElementById('btn-stop-speech').style.display = 'none';
    document.getElementById('btn-read-page').classList.remove('active');
    document.getElementById('btn-read-page').setAttribute('aria-label', 'Read page summary aloud');
    if (onEnd) onEnd();
  };
  window.speechSynthesis.speak(currentUtterance);
}

function stopSpeech() {
  if (window.speechSynthesis) {
    window.speechSynthesis.cancel();
    document.getElementById('btn-stop-speech').style.display = 'none';
    document.getElementById('btn-read-page').classList.remove('active');
  }
}

function readPageSummary() {
  const d = PAGE_DATA;
  const overdueMsg = d.overallOverdue > 0
    ? `There are ${d.overallOverdue} overdue tasks that need attention.`
    : 'There are no overdue tasks.';
  const summary =
    `Project Performance page. ` +
    `You have ${d.totalProjects} project${d.totalProjects !== 1 ? 's' : ''} total. ` +
    `${d.doneTasks} of ${d.totalTasks} tasks are completed, giving an overall progress of ${d.overallPct} percent. ` +
    overdueMsg +
    ` Press Alt R to hear this again.`;
  speak(summary);
  announce(summary);
}

// =============================================
// VOICE ASSISTANT
// =============================================
let recognition = null, isListening = false;

function setupRecognition() {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) return null;
  const r = new SR();
  r.lang = 'en-ZA'; r.continuous = false; r.interimResults = true;
  r.onresult = (event) => {
    let interim = '', final = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const t = event.results[i][0].transcript;
      event.results[i].isFinal ? (final += t) : (interim += t);
    }
    document.getElementById('voice-transcript').textContent = final || interim || '…';
    if (final) handleVoiceCommand(final.toLowerCase().trim());
  };
  r.onerror = (e) => {
    const msgs = {'no-speech':'No speech detected.','not-allowed':'Microphone access denied. Please allow microphone in browser settings.','audio-capture':'No microphone found.','network':'Network error.'};
    const msg = msgs[e.error] || `Error: ${e.error}`;
    document.getElementById('voice-status').textContent = msg;
    setListening(false); speak(msg);
  };
  r.onend = () => setListening(false);
  return r;
}

function setListening(state) {
  isListening = state;
  const btn = document.getElementById('voice-mic-btn');
  btn.classList.toggle('listening', state);
  btn.setAttribute('aria-pressed', state ? 'true' : 'false');
  btn.setAttribute('aria-label', state ? 'Stop listening' : 'Start listening');
  btn.querySelector('i').className = state ? 'fas fa-stop' : 'fas fa-microphone';
  document.getElementById('voice-status').textContent = state ? 'Listening… speak now' : 'Click the mic to speak';
}

function toggleListening() {
  if (!recognition) recognition = setupRecognition();
  if (!recognition) {
    const msg = 'Speech recognition is not supported in this browser. Try Chrome or Edge.';
    document.getElementById('voice-response').textContent = msg; speak(msg); return;
  }
  if (isListening) { recognition.stop(); }
  else { document.getElementById('voice-transcript').textContent = '…'; document.getElementById('voice-response').textContent = ''; try{recognition.start();setListening(true);}catch(e){} }
}

function handleVoiceCommand(cmd) {
  let response = '';

  if (cmd.includes('stop') || cmd.includes('quiet')) {
    stopSpeech(); response = 'Stopped.';
  } else if (cmd.includes('summary') || cmd.includes('read') || cmd.includes('overview')) {
    readPageSummary(); response = 'Reading page summary now.';
  } else if (cmd.includes('how many project') || cmd.includes('total project')) {
    response = `There are ${PAGE_DATA.totalProjects} project${PAGE_DATA.totalProjects !== 1 ? 's' : ''} total.`;
  } else if (cmd.includes('overdue') || cmd.includes('late')) {
    response = PAGE_DATA.overallOverdue > 0
      ? `There are ${PAGE_DATA.overallOverdue} overdue tasks.`
      : 'There are no overdue tasks.';
  } else if (cmd.includes('progress') || cmd.includes('percent') || cmd.includes('complete')) {
    response = `Overall progress is ${PAGE_DATA.overallPct} percent. ${PAGE_DATA.doneTasks} of ${PAGE_DATA.totalTasks} tasks are done.`;
  } else if (cmd.includes('dark') || cmd.includes('dark mode')) {
    toggleDarkMode(); response = 'Dark mode toggled.';
  } else if (cmd.includes('contrast')) {
    toggleHighContrast(); response = 'High contrast toggled.';
  } else if (cmd.includes('large text') || cmd.includes('bigger')) {
    toggleLargeText(); response = 'Large text toggled.';
  } else if (cmd.includes('back') || cmd.includes('dashboard') || cmd.includes('go back')) {
    response = 'Navigating back to dashboard.';
    setTimeout(() => window.location.href = 'manager_index.php', 1000);
  } else if (cmd.includes('search') || cmd.includes('find')) {
    const q = cmd.replace(/(search|find)(\s+for)?/i,'').trim();
    if (q) { document.getElementById('filter-search').value = q; document.getElementById('filter-search').focus(); response = `Type detected: ${q}. Press Enter to apply filter.`; }
    else { document.getElementById('filter-search').focus(); response = 'Search box focused. Type a project name.'; }
  } else if (cmd.includes('keyboard') || cmd.includes('shortcuts')) {
    toggleKbdHelp(); response = 'Opening keyboard shortcuts guide.';
  } else if (cmd.includes('help') || cmd.includes('commands')) {
    response = 'You can say: Read summary, How many projects, How many overdue, Overall progress, Search for a project, Toggle dark mode, or Go back to dashboard.';
  } else {
    response = `I heard: "${cmd}". Say "help" for a list of commands.`;
  }

  document.getElementById('voice-response').textContent = response;
  announce(response, true);
  if (response && !response.startsWith('Reading')) speak(response);
}

function toggleVoicePanel() {
  const panel = document.getElementById('voice-panel');
  const btn   = document.getElementById('btn-voice');
  const isOpen = panel.classList.toggle('open');
  btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  btn.classList.toggle('active', isOpen);
  if (isOpen) {
    if (!window.SpeechRecognition && !window.webkitSpeechRecognition) document.getElementById('voice-response').textContent = 'Speech recognition is not supported in this browser. Text-to-speech is still available.';
    document.getElementById('voice-mic-btn').focus();
    announce('Voice assistant opened.');
  } else {
    if (isListening && recognition) recognition.stop();
    announce('Voice assistant closed.');
  }
}

// =============================================
// ACCESSIBILITY TOGGLES
// =============================================
function toggleHighContrast() {
  const on = document.body.classList.toggle('high-contrast');
  const btn = document.getElementById('btn-high-contrast');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('active', on);
  localStorage.setItem('a11y-high-contrast', on);
  announce(on ? 'High contrast enabled.' : 'High contrast disabled.');
}
function toggleLargeText() {
  const on = document.body.classList.toggle('large-text');
  const btn = document.getElementById('btn-large-text');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('active', on);
  localStorage.setItem('a11y-large-text', on);
  announce(on ? 'Large text enabled.' : 'Large text disabled.');
}
function toggleReduceMotion() {
  const on = document.body.classList.toggle('reduce-motion');
  const btn = document.getElementById('btn-reduce-motion');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('active', on);
  localStorage.setItem('a11y-reduce-motion', on);
  announce(on ? 'Reduced motion enabled.' : 'Reduced motion disabled.');
}
function toggleDarkMode() {
  // Works alongside original dark-mode toggle class
  const on = document.body.classList.toggle('dark-mode');
  const btn = document.getElementById('btn-dark-mode');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('active', on);
  btn.querySelector('i').className = on ? 'fas fa-sun' : 'fas fa-moon';
  localStorage.setItem('darkMode', on ? 'enabled' : 'disabled');
  localStorage.setItem('a11y-dark', on);
  announce(on ? 'Dark mode enabled.' : 'Dark mode disabled.');
}

// Restore preferences on load
(function restorePrefs() {
  if (localStorage.getItem('darkMode') === 'enabled' || localStorage.getItem('a11y-dark') === 'true') {
    document.body.classList.add('dark-mode');
    const b = document.getElementById('btn-dark-mode');
    if (b) { b.classList.add('active'); b.setAttribute('aria-pressed','true'); b.querySelector('i').className='fas fa-sun'; }
  }
  if (localStorage.getItem('a11y-high-contrast') === 'true') {
    document.body.classList.add('high-contrast');
    const b = document.getElementById('btn-high-contrast');
    if (b) { b.classList.add('active'); b.setAttribute('aria-pressed','true'); }
  }
  if (localStorage.getItem('a11y-large-text') === 'true') {
    document.body.classList.add('large-text');
    const b = document.getElementById('btn-large-text');
    if (b) { b.classList.add('active'); b.setAttribute('aria-pressed','true'); }
  }
  if (localStorage.getItem('a11y-reduce-motion') === 'true') {
    document.body.classList.add('reduce-motion');
    const b = document.getElementById('btn-reduce-motion');
    if (b) { b.classList.add('active'); b.setAttribute('aria-pressed','true'); }
  }
  if (window.matchMedia('(prefers-contrast: more)').matches     && !localStorage.getItem('a11y-high-contrast')) toggleHighContrast();
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches && !localStorage.getItem('a11y-reduce-motion')) toggleReduceMotion();
  if (window.matchMedia('(prefers-color-scheme: dark)').matches && !localStorage.getItem('darkMode')) toggleDarkMode();
})();

// =============================================
// KEYBOARD HELP
// =============================================
function toggleKbdHelp() {
  const overlay = document.getElementById('kbd-help-overlay');
  const btn     = document.getElementById('btn-kbd-help');
  const isOpen  = overlay.style.display === 'flex';
  if (isOpen) {
    overlay.style.display = 'none';
    btn.setAttribute('aria-expanded', 'false'); btn.classList.remove('active');
    document.body.style.overflow = 'auto';
    announce('Keyboard shortcuts guide closed.'); btn.focus();
  } else {
    overlay.style.display = 'flex';
    btn.setAttribute('aria-expanded', 'true'); btn.classList.add('active');
    document.body.style.overflow = 'hidden';
    announce('Keyboard shortcuts guide opened. Press Escape to close.');
    setTimeout(() => overlay.querySelector('button')?.focus(), 50);
  }
}
document.getElementById('kbd-help-overlay').addEventListener('click', function(e) { if (e.target === this) toggleKbdHelp(); });

// =============================================
// GLOBAL KEYBOARD SHORTCUTS
// =============================================
document.addEventListener('keydown', (e) => {
  const inInput = ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName);

  if (e.key === '?' && !inInput) { toggleKbdHelp(); return; }
  if (e.key === '/' && !inInput) { e.preventDefault(); document.getElementById('filter-search').focus(); return; }

  if (e.key === 'Escape') {
    stopSpeech();
    if (document.getElementById('kbd-help-overlay').style.display === 'flex') { toggleKbdHelp(); return; }
    if (document.getElementById('projModal').classList.contains('open')) { closeProjModal(); return; }
    return;
  }

  if (e.altKey) {
    switch (e.key.toLowerCase()) {
      case 'r': e.preventDefault(); readPageSummary(); break;
      case 'v': e.preventDefault(); toggleVoicePanel(); break;
      case 'c': e.preventDefault(); toggleHighContrast(); break;
      case 't': e.preventDefault(); toggleLargeText();    break;
      case 'd': e.preventDefault(); toggleDarkMode();     break;
    }
  }
});

// Focus trap for kbd-help and project modal
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Tab') return;
  const kbdOverlay = document.getElementById('kbd-help-overlay');
  if (kbdOverlay.style.display === 'flex') {
    const focusable = kbdOverlay.querySelectorAll('button:not([disabled]),[href],[tabindex]:not([tabindex="-1"])');
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    return;
  }
  const projModal = document.getElementById('projModal');
  if (projModal.classList.contains('open')) {
    const focusable = projModal.querySelectorAll('button:not([disabled]),[href],input:not([disabled]),[tabindex]:not([tabindex="-1"])');
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
});

// =============================================
// PROJECT DETAIL MODAL
// =============================================
function viewProject(projectId) {
  const modal   = document.getElementById('projModal');
  const content = document.getElementById('projContent');
  const title   = document.getElementById('projTitle');

  modal.classList.add('open');
  modal.style.display = 'flex';
  content.innerHTML = '<em aria-live="polite">Loading project details…</em>';
  title.textContent = 'Project Overview';
  document.body.style.overflow = 'hidden';
  announce('Loading project details…');

  // Move focus to close button once loaded
  setTimeout(() => {
    const closeBtn = modal.querySelector('button[aria-label*="Close"]');
    if (closeBtn) closeBtn.focus();
  }, 100);

  fetch('manager_project_insights.php?project_id=' + encodeURIComponent(projectId))
    .then(r => r.text())
    .then(html => {
      content.innerHTML = html;
      const h = content.querySelector('[data-proj-title]');
      if (h) title.textContent = h.getAttribute('data-proj-title');
      announce('Project details loaded: ' + title.textContent);
    })
    .catch(() => {
      content.innerHTML = '<span style="color:var(--red);" role="alert">Failed to load project details. Please try again.</span>';
      announce('Failed to load project details.', true);
    });

  // Click backdrop to close
  modal.addEventListener('click', (e) => { if (e.target === modal) closeProjModal(); }, { once: true });
}

function closeProjModal() {
  const modal = document.getElementById('projModal');
  modal.classList.remove('open');
  modal.style.display = 'none';
  document.getElementById('projContent').innerHTML = '';
  document.body.style.overflow = 'auto';
  announce('Project details dialog closed.');
  // Return focus to the button that opened it
  document.querySelector('.btn-view-project[data-last-focused]')?.focus();
}

// =============================================
// TOAST NOTIFICATIONS
// =============================================
function showToast(message, type = 'info') {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const colors = { success: '#16a34a', error: '#dc2626', info: '#1d4ed8', warning: '#d97706' };
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
  toast.style.borderLeftColor = colors[type] || colors.info;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => { toast.style.animation = 'toastOut .3s ease'; setTimeout(() => toast.remove(), 300); }, 3500);
}

// =============================================
// INIT
// =============================================
document.addEventListener('DOMContentLoaded', () => {
  // Wire up View buttons — track which was last focused for focus-return
  document.querySelectorAll('.btn-view-project').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.btn-view-project').forEach(b => b.removeAttribute('data-last-focused'));
      btn.setAttribute('data-last-focused', 'true');
      viewProject(btn.dataset.projectId);
    });
  });

  // Announce page load
  setTimeout(() => {
    announce(`Project Performance page loaded. ${PAGE_DATA.totalProjects} projects. ${PAGE_DATA.overallPct}% overall progress. Press Alt R to hear a full summary, or ? for keyboard shortcuts.`);
    showToast('Press ? for keyboard shortcuts or Alt+R to hear a page summary.', 'info');
  }, 800);
});
</script>

</body>
</html>