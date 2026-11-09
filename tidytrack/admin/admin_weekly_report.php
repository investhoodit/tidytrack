<?php
session_start();
require '../dp.php'; // Database connection

// === Access Control ===
$userID = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;
if (!$userID || $role !== 'admin') {
    die("Unauthorized. Access restricted to Admin.");
}

// === Determine current week range (Mon → Sun) ===
$startOfWeek = date('Y-m-d', strtotime('monday this week'));
$endOfWeek   = date('Y-m-d', strtotime('sunday this week'));

// === Initialize Stats ===
$stats = [
    'projects_this_week' => 0,
    'projects_this_week_names' => [],
    'projects_ending' => 0,
    'tasks_created' => 0,
    'tasks_created_names' => [],
    'tasks_completed' => 0,
    'tasks_due' => 0,
    'priorities' => [],
    'statuses' => []
];

// === 1. New Projects This Week ===
$query = "SELECT project_id, project_name FROM projects_x WHERE start_date BETWEEN ? AND ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $startOfWeek, $endOfWeek);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['projects_this_week']++;
    $stats['projects_this_week_names'][] = $row['project_name'];
}
$stmt->close();

// === 2. Projects Ending This Week ===
$query = "SELECT COUNT(*) AS cnt FROM projects_x WHERE end_date BETWEEN ? AND ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $startOfWeek, $endOfWeek);
$stmt->execute();
$stmt->bind_result($endingCount);
$stmt->fetch();
$stats['projects_ending'] = (int)$endingCount;
$stmt->close();

// === 3. Tasks Created This Week ===
$query = "SELECT taskTitle FROM tasks WHERE created_at BETWEEN ? AND ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $startOfWeek, $endOfWeek);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['tasks_created']++;
    $stats['tasks_created_names'][] = $row['taskTitle'];
}
$stmt->close();

// === 4. Tasks Completed (no completed_at column) ===
$query = "SELECT COUNT(*) AS cnt FROM tasks WHERE status = 'Completed'";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $stats['tasks_completed'] = (int)$row['cnt'];
} else {
    $stats['tasks_completed'] = 0;
}

// === 5. Tasks Due This Week ===
$query = "SELECT COUNT(*) FROM tasks WHERE dueDate BETWEEN ? AND ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $startOfWeek, $endOfWeek);
$stmt->execute();
$stmt->bind_result($dueCount);
$stmt->fetch();
$stats['tasks_due'] = (int)$dueCount;
$stmt->close();

// === 6. Priorities Breakdown ===
$query = "SELECT priority, COUNT(*) AS cnt FROM tasks WHERE created_at BETWEEN ? AND ? GROUP BY priority";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $startOfWeek, $endOfWeek);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['priorities'][$row['priority']] = (int)$row['cnt'];
}
$stmt->close();

// === 7. Status Breakdown ===
$query = "SELECT status, COUNT(*) AS cnt FROM tasks WHERE created_at BETWEEN ? AND ? GROUP BY status";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $startOfWeek, $endOfWeek);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['statuses'][$row['status']] = (int)$row['cnt'];
}
$stmt->close();

// =====================================================
// === AUTO-SAVE THIS WEEK’S REPORT TO weekly_report ===
// =====================================================

$projectsNamesJSON = json_encode($stats['projects_this_week_names']);
$tasksNamesJSON     = json_encode($stats['tasks_created_names']);
$prioritiesJSON     = json_encode($stats['priorities']);
$statusesJSON       = json_encode($stats['statuses']);

$checkQuery = "SELECT id FROM weekly_reports WHERE week_start = ? AND week_end = ?";
$checkStmt = $conn->prepare($checkQuery);
$checkStmt->bind_param("ss", $startOfWeek, $endOfWeek);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows > 0) {
    // Update existing record
    $updateQuery = "
        UPDATE weekly_reports
        SET projects_this_week = ?, 
            projects_names = ?, 
            projects_ending = ?, 
            tasks_created = ?, 
            tasks_names = ?, 
            tasks_completed = ?, 
            tasks_due = ?, 
            priorities = ?, 
            statuses = ?, 
            created_at = NOW()
        WHERE week_start = ? AND week_end = ?
    ";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param(
        "isiiissssss",
        $stats['projects_this_week'],
        $projectsNamesJSON,
        $stats['projects_ending'],
        $stats['tasks_created'],
        $tasksNamesJSON,
        $stats['tasks_completed'],
        $stats['tasks_due'],
        $prioritiesJSON,
        $statusesJSON,
        $startOfWeek,
        $endOfWeek
    );
    $updateStmt->execute();
    $updateStmt->close();
} else {
    // Insert new record
    $insertQuery = "
        INSERT INTO weekly_reports
        (week_start, week_end, projects_this_week, projects_names, projects_ending, 
         tasks_created, tasks_names, tasks_completed, tasks_due, priorities, statuses)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param(
        "ssisiisssss",
        $startOfWeek,
        $endOfWeek,
        $stats['projects_this_week'],
        $projectsNamesJSON,
        $stats['projects_ending'],
        $stats['tasks_created'],
        $tasksNamesJSON,
        $stats['tasks_completed'],
        $stats['tasks_due'],
        $prioritiesJSON,
        $statusesJSON
    );
    $insertStmt->execute();
    $insertStmt->close();
}

$checkStmt->close();

// === FETCH REPORT HISTORY ===
$historyQuery = "SELECT * FROM weekly_reports ORDER BY week_start DESC";
$historyResult = $conn->query($historyQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Weekly Dashboard ‒ INVESTHOOD_TSM</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    :root {
        /* Light mode */
        --bg-light: #f4f7fa;
        --card-light: #ffffff;
        --text-light: #1d3557;
        --text-secondary-light: #2c3e50;
        --border-light: #ddd;
        --header-bg-light: #e9ecef;
        --card-bg-light: #fafafa;
        --shadow-light: rgba(0,0,0,0.08);
        --btn-bg-light: #2a9d8f;
        --btn-hover-light: #21867a;
        
        /* Dark mode */
        --bg-dark: #0f172a;
        --card-dark: #1e293b;
        --text-dark: #f1f5f9;
        --text-secondary-dark: #cbd5e1;
        --border-dark: #334155;
        --header-bg-dark: #2d3a4f;
        --card-bg-dark: #1e293b;
        --shadow-dark: rgba(0,0,0,0.3);
        --btn-bg-dark: #8b5cf6;
        --btn-hover-dark: #7c3aed;
        
        /* Default to light */
        --bg: var(--bg-light);
        --card: var(--card-light);
        --text: var(--text-light);
        --text-secondary: var(--text-secondary-light);
        --border: var(--border-light);
        --header-bg: var(--header-bg-light);
        --card-bg: var(--card-bg-light);
        --shadow: var(--shadow-light);
        --btn-bg: var(--btn-bg-light);
        --btn-hover: var(--btn-hover-light);
    }

    body.dark-mode {
        --bg: var(--bg-dark);
        --card: var(--card-dark);
        --text: var(--text-dark);
        --text-secondary: var(--text-secondary-dark);
        --border: var(--border-dark);
        --header-bg: var(--header-bg-dark);
        --card-bg: var(--card-bg-dark);
        --shadow: var(--shadow-dark);
        --btn-bg: var(--btn-bg-dark);
        --btn-hover: var(--btn-hover-dark);
    }

    body {
        font-family: 'Poppins', sans-serif;
        background: var(--bg);
        padding: 2rem;
        margin: 0;
        transition: background-color 0.3s ease, color 0.2s ease;
        color: var(--text);
    }

    .dashboard {
        background: var(--card);
        border-radius: 12px;
        padding: 2rem;
        max-width: 1200px;
        margin: 0 auto;
        box-shadow: 0 8px 20px var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 2rem;
        transition: background-color 0.3s ease;
    }

    .top-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }

    .logo-section {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .logo {
        height: 60px;
        border-radius: 8px;
    }

    .theme-toggle {
        background: var(--card-bg);
        border: 1px solid var(--border);
        color: var(--text);
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.2rem;
        transition: all 0.2s;
    }

    .theme-toggle:hover {
        background: var(--border);
        transform: scale(1.05);
    }

    .export-btn {
        background: var(--btn-bg);
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        font-weight: 500;
    }

    .export-btn:hover { 
        background: var(--btn-hover); 
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(139,92,246,0.3);
    }

    h1 { 
        color: var(--text); 
        margin: 0;
        font-size: 2rem;
    }

    h2 { 
        color: var(--text-secondary); 
        font-size: 1.2rem;
        border-bottom: 1px solid var(--border);
        padding-bottom: 5px;
        margin-bottom: 1rem;
    }

    .week-range {
        color: var(--text-secondary);
        font-size: 1.1rem;
        margin: 0.5rem 0 1.5rem;
    }

    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 2rem;
    }

    .card {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 1.5rem;
        box-shadow: 0 4px 12px var(--shadow);
        transition: background-color 0.3s ease;
        border: 1px solid var(--border);
    }

    ul.stats-list, ul.inner-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    ul.inner-list { 
        list-style: disc; 
        margin-left: 1.5rem; 
        margin-top: 8px;
        margin-bottom: 8px;
        color: var(--text-secondary);
    }

    .stats-list li { 
        padding: 8px 0; 
        font-size: 0.95rem;
        border-bottom: 1px solid var(--border);
    }

    .stats-list li:last-child {
        border-bottom: none;
    }

    .stats-list strong {
        color: var(--btn-bg);
        font-size: 1.1rem;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        color: var(--text);
    }

    table th, table td {
        padding: 12px;
        border: 1px solid var(--border);
        text-align: left;
    }

    table th { 
        background: var(--header-bg); 
        color: var(--text);
        font-weight: 600;
    }

    table td {
        color: var(--text-secondary);
    }

    table tr:hover {
        background: var(--border);
    }

    @media print {
        .export-btn, .theme-toggle { display: none; }
        .top-actions { justify-content: center; }
        body { background: white; }
        .dashboard { box-shadow: none; }
    }

    /* Chart container adjustments */
    canvas {
        max-height: 250px;
        width: 100% !important;
    }
</style>
</head>
<body>
<div class="dashboard">
    <div class="top-actions">
        <div class="logo-section">
            <img src="../images/INVESTHOOD.jpg" alt="Company Logo" class="logo">
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
                <i class="fas fa-moon"></i>
            </button>
        </div>
        <button class="export-btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print / Export
        </button>
    </div>

    <div>
        <h1>📊 Weekly Dashboard</h1>
        <p class="week-range"><i class="far fa-calendar-alt"></i> <?= htmlspecialchars($startOfWeek) ?> → <?= htmlspecialchars($endOfWeek) ?></p>
    </div>

    <div class="grid">
        <div class="card">
            <h2><i class="fas fa-folder"></i> Project Stats</h2>
            <ul class="stats-list">
                <li>New Projects: <strong><?= $stats['projects_this_week'] ?></strong></li>
                <?php if (!empty($stats['projects_this_week_names'])): ?>
                    <ul class="inner-list">
                        <?php foreach ($stats['projects_this_week_names'] as $projectName): ?>
                            <li>📌 <?= htmlspecialchars($projectName) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <li>Projects Ending: <strong><?= $stats['projects_ending'] ?></strong></li>
            </ul>
        </div>

        <div class="card"><canvas id="projectsChart"></canvas></div>

        <div class="card">
            <h2><i class="fas fa-tasks"></i> Task Stats</h2>
            <ul class="stats-list">
                <li>Tasks Created: <strong><?= $stats['tasks_created'] ?></strong></li>
                <?php if (!empty($stats['tasks_created_names'])): ?>
                    <ul class="inner-list">
                        <?php foreach ($stats['tasks_created_names'] as $taskName): ?>
                            <li>🗒️ <?= htmlspecialchars($taskName) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <li>Tasks Completed: <strong><?= $stats['tasks_completed'] ?></strong></li>
                <li>Tasks Due: <strong><?= $stats['tasks_due'] ?></strong></li>
            </ul>
        </div>

        <div class="card"><canvas id="tasksChart"></canvas></div>
        <div class="card"><h2><i class="fas fa-flag"></i> Priorities</h2><canvas id="priorityChart"></canvas></div>
        <div class="card"><h2><i class="fas fa-check-circle"></i> Statuses</h2><canvas id="statusChart"></canvas></div>
    </div>

    <div class="card">
        <h2><i class="fas fa-history"></i> Weekly Report History</h2>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Week Start</th>
                        <th>Week End</th>
                        <th>Projects</th>
                        <th>Tasks</th>
                        <th>Completed</th>
                        <th>Due</th>
                        <th>Generated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $historyResult->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['week_start']) ?></td>
                            <td><?= htmlspecialchars($row['week_end']) ?></td>
                            <td><?= htmlspecialchars($row['projects_this_week']) ?></td>
                            <td><?= htmlspecialchars($row['tasks_created']) ?></td>
                            <td><?= htmlspecialchars($row['tasks_completed']) ?></td>
                            <td><?= htmlspecialchars($row['tasks_due']) ?></td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Dark mode toggle functionality
(function() {
    const themeToggle = document.getElementById('themeToggle');
    const icon = themeToggle.querySelector('i');
    
    // Check for saved preference
    const savedDarkMode = localStorage.getItem('darkMode');
    
    function setDarkMode(isDark) {
        if (isDark) {
            document.body.classList.add('dark-mode');
            icon.className = 'fas fa-sun';
            localStorage.setItem('darkMode', 'enabled');
        } else {
            document.body.classList.remove('dark-mode');
            icon.className = 'fas fa-moon';
            localStorage.setItem('darkMode', 'disabled');
        }
    }
    
    // Apply saved preference
    if (savedDarkMode === 'enabled') {
        setDarkMode(true);
    } else if (savedDarkMode === 'disabled') {
        setDarkMode(false);
    } else {
        // Check system preference
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        setDarkMode(systemPrefersDark);
    }
    
    // Toggle on click
    themeToggle.addEventListener('click', () => {
        const isDark = document.body.classList.contains('dark-mode');
        setDarkMode(!isDark);
    });
    
    // Listen for system preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (!localStorage.getItem('darkMode')) {
            setDarkMode(e.matches);
        }
    });
})();

// Charts with dark mode support
const projectsThisWeek = <?= json_encode($stats['projects_this_week_names']) ?>;
const tasksThisWeek = <?= json_encode($stats['tasks_created_names']) ?>;
const priorityData = <?= json_encode($stats['priorities']) ?>;
const statusData = <?= json_encode($stats['statuses']) ?>;

// Helper function to get text color based on mode
function getTextColor() {
    return document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1d3557';
}

// Projects Chart
new Chart(document.getElementById('projectsChart'), {
    type: 'bar',
    data: {
        labels: projectsThisWeek,
        datasets: [{ 
            label: 'New Projects', 
            data: projectsThisWeek.map(() => 1), 
            backgroundColor: '#2a9d8f',
            borderColor: document.body.classList.contains('dark-mode') ? '#4ade80' : '#2a9d8f',
            borderWidth: 1
        }]
    },
    options: { 
        plugins: { 
            title: { 
                display: true, 
                text: 'Projects This Week',
                color: getTextColor()
            },
            legend: {
                labels: {
                    color: getTextColor()
                }
            }
        }, 
        scales: { 
            y: { 
                beginAtZero: true, 
                ticks: { 
                    stepSize: 1,
                    color: getTextColor()
                },
                grid: {
                    color: document.body.classList.contains('dark-mode') ? '#334155' : '#e2e8f0'
                }
            },
            x: {
                ticks: {
                    color: getTextColor()
                },
                grid: {
                    color: document.body.classList.contains('dark-mode') ? '#334155' : '#e2e8f0'
                }
            }
        }
    }
});

// Tasks Chart
new Chart(document.getElementById('tasksChart'), {
    type: 'bar',
    data: {
        labels: tasksThisWeek,
        datasets: [{ 
            label: 'Tasks', 
            data: tasksThisWeek.map(() => 1), 
            backgroundColor: '#457b9d',
            borderColor: document.body.classList.contains('dark-mode') ? '#60a5fa' : '#457b9d',
            borderWidth: 1
        }]
    },
    options: { 
        plugins: { 
            title: { 
                display: true, 
                text: 'Tasks Created This Week',
                color: getTextColor()
            },
            legend: {
                labels: {
                    color: getTextColor()
                }
            }
        }, 
        scales: { 
            y: { 
                beginAtZero: true, 
                ticks: { 
                    stepSize: 1,
                    color: getTextColor()
                },
                grid: {
                    color: document.body.classList.contains('dark-mode') ? '#334155' : '#e2e8f0'
                }
            },
            x: {
                ticks: {
                    color: getTextColor()
                },
                grid: {
                    color: document.body.classList.contains('dark-mode') ? '#334155' : '#e2e8f0'
                }
            }
        }
    }
});

// Priority Chart
new Chart(document.getElementById('priorityChart'), {
    type: 'doughnut',
    data: { 
        labels: Object.keys(priorityData), 
        datasets: [{ 
            data: Object.values(priorityData), 
            backgroundColor: ['#e76f51','#f4a261','#2a9d8f'],
            borderColor: document.body.classList.contains('dark-mode') ? '#1e293b' : '#ffffff',
            borderWidth: 2
        }]
    },
    options: { 
        plugins: { 
            title: { 
                display: true, 
                text: 'Task Priorities',
                color: getTextColor()
            },
            legend: {
                labels: {
                    color: getTextColor()
                }
            }
        }
    }
});

// Status Chart
new Chart(document.getElementById('statusChart'), {
    type: 'pie',
    data: { 
        labels: Object.keys(statusData), 
        datasets: [{ 
            data: Object.values(statusData), 
            backgroundColor: ['#264653','#e9c46a','#2a9d8f'],
            borderColor: document.body.classList.contains('dark-mode') ? '#1e293b' : '#ffffff',
            borderWidth: 2
        }]
    },
    options: { 
        plugins: { 
            title: { 
                display: true, 
                text: 'Task Statuses',
                color: getTextColor()
            },
            legend: {
                labels: {
                    color: getTextColor()
                }
            }
        }
    }
});

// Update chart colors when dark mode toggles
document.getElementById('themeToggle').addEventListener('click', function() {
    setTimeout(() => {
        // Refresh charts with new colors (in a real app, you'd want to update chart instances)
        location.reload(); // Simple solution: reload page to reinitialize charts with new colors
    }, 100);
});
</script>
</body>
</html>