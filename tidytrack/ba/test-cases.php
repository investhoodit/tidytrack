
<?php
session_start();
require_once '../dp.php';

/* =====================================================
   BA ACCESS CONTROL
===================================================== */

if (
    empty($_SESSION['ba_authenticated']) ||
    empty($_SESSION['ba_user_id'])
) {
    header('Location: index.php');
    exit;
}

$currentUserId = (int) $_SESSION['ba_user_id'];

$authStmt = $conn->prepare("
    SELECT user_id, name
    FROM users
    WHERE user_id = ?
      AND LOWER(TRIM(department)) = 'ba team'
    LIMIT 1
");

$authStmt->bind_param('i', $currentUserId);
$authStmt->execute();

$currentUser = $authStmt->get_result()->fetch_assoc();

$authStmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: index.php');
    exit;
}

/* =====================================================
   HELPERS
===================================================== */

function tcEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tcRedirect($message, $type = 'success')
{
    $_SESSION['tc_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: test-cases.php');
    exit;
}

function tcValidChoice($value, $allowed)
{
    return in_array($value, $allowed, true);
}

/* =====================================================
   CSRF TOKEN
===================================================== */

if (empty($_SESSION['tc_csrf'])) {
    $_SESSION['tc_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tc_csrf'];

/* =====================================================
   LOAD PROJECTS
===================================================== */

$projects = [];

$projectResult = $conn->query("
    SELECT project_id, project_name
    FROM projects_x
    ORDER BY project_name ASC
");

if ($projectResult) {
    while ($row = $projectResult->fetch_assoc()) {
        $projects[] = $row;
    }
}

/* =====================================================
   LOAD TEST CASE FOR EDITING
===================================================== */

$editTest = null;

$editId = filter_input(
    INPUT_GET,
    'edit',
    FILTER_VALIDATE_INT
);

if ($editId) {
    $stmt = $conn->prepare("
        SELECT *
        FROM ba_test_cases
        WHERE test_case_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $editId);
    $stmt->execute();

    $editTest = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

/* =====================================================
   HANDLE FORM SUBMISSIONS
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['tc_csrf']) ||
        !hash_equals($_SESSION['tc_csrf'], $postedToken)
    ) {
        tcRedirect(
            'Your session expired. Please try again.',
            'error'
        );
    }

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------
       DELETE TEST CASE
    ------------------------------------------------- */

    if ($action === 'delete') {

        $testCaseId = (int) ($_POST['test_case_id'] ?? 0);

        if ($testCaseId < 1) {
            tcRedirect('Invalid test case.', 'error');
        }

        $stmt = $conn->prepare("
            DELETE FROM ba_test_cases
            WHERE test_case_id = ?
        ");

        $stmt->bind_param('i', $testCaseId);
        $stmt->execute();

        $deleted = $stmt->affected_rows > 0;

        $stmt->close();

        tcRedirect(
            $deleted
                ? 'Test case deleted successfully.'
                : 'Test case was not found.',
            $deleted ? 'success' : 'error'
        );
    }

    /* -------------------------------------------------
       CREATE OR UPDATE TEST CASE
    ------------------------------------------------- */

    if ($action === 'save') {

        $testCaseId = (int) ($_POST['test_case_id'] ?? 0);

        $projectId = (int) ($_POST['project_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $testSteps = trim($_POST['test_steps'] ?? '');
        $expectedResult = trim($_POST['expected_result'] ?? '');
        $actualResult = trim($_POST['actual_result'] ?? '');
        $priority = $_POST['priority'] ?? 'Medium';
        $status = $_POST['status'] ?? 'Draft';

        $allowedPriorities = [
            'Low',
            'Medium',
            'High',
            'Critical'
        ];

        $allowedStatuses = [
            'Draft',
            'Ready',
            'Passed',
            'Failed',
            'Blocked'
        ];

        if (
            $projectId < 1 ||
            $title === '' ||
            strlen($title) > 255 ||
            !tcValidChoice($priority, $allowedPriorities) ||
            !tcValidChoice($status, $allowedStatuses)
        ) {
            tcRedirect(
                'Please provide a valid project, title, priority and status.',
                'error'
            );
        }

        /*
         * Confirm that the project exists.
         */

        $projectCheck = $conn->prepare("
            SELECT project_id
            FROM projects_x
            WHERE project_id = ?
            LIMIT 1
        ");

        $projectCheck->bind_param('i', $projectId);
        $projectCheck->execute();

        $projectExists = $projectCheck
            ->get_result()
            ->num_rows > 0;

        $projectCheck->close();

        if (!$projectExists) {
            tcRedirect(
                'The selected project could not be found.',
                'error'
            );
        }

        /*
         * Execution information is updated when the test
         * is marked Passed, Failed or Blocked.
         */

        $isExecuted = in_array(
            $status,
            ['Passed', 'Failed', 'Blocked'],
            true
        );

        $executedBy = $isExecuted ? $currentUserId : null;

        if ($testCaseId > 0) {

            $stmt = $conn->prepare("
                UPDATE ba_test_cases
                SET
                    project_id = ?,
                    title = ?,
                    description = ?,
                    test_steps = ?,
                    expected_result = ?,
                    actual_result = ?,
                    priority = ?,
                    status = ?,
                    executed_by = ?,
                    executed_at = CASE
                        WHEN ? IN ('Passed', 'Failed', 'Blocked')
                            THEN NOW()
                        ELSE NULL
                    END
                WHERE test_case_id = ?
            ");

            $stmt->bind_param(
                'isssssssisi',
                $projectId,
                $title,
                $description,
                $testSteps,
                $expectedResult,
                $actualResult,
                $priority,
                $status,
                $executedBy,
                $status,
                $testCaseId
            );

            $success = $stmt->execute();

            $stmt->close();

        } else {

            $stmt = $conn->prepare("
                INSERT INTO ba_test_cases (
                    project_id,
                    title,
                    description,
                    test_steps,
                    expected_result,
                    actual_result,
                    priority,
                    status,
                    created_by,
                    executed_by,
                    executed_at
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    CASE
                        WHEN ? IN ('Passed', 'Failed', 'Blocked')
                            THEN NOW()
                        ELSE NULL
                    END
                )
            ");

            $stmt->bind_param(
                'isssssssiss',
                $projectId,
                $title,
                $description,
                $testSteps,
                $expectedResult,
                $actualResult,
                $priority,
                $status,
                $currentUserId,
                $executedBy,
                $status
            );

            $success = $stmt->execute();

            $stmt->close();
        }

        tcRedirect(
            $success
                ? (
                    $testCaseId > 0
                        ? 'Test case updated successfully.'
                        : 'Test case created successfully.'
                )
                : 'Could not save the test case.',
            $success ? 'success' : 'error'
        );
    }
}

/* =====================================================
   LOAD TEST CASES
===================================================== */

$testCases = [];

$result = $conn->query("
    SELECT
        tc.*,
        p.project_name,
        creator.name AS creator_name,
        executor.name AS executor_name
    FROM ba_test_cases tc
    INNER JOIN projects_x p
        ON p.project_id = tc.project_id
    LEFT JOIN users creator
        ON creator.user_id = tc.created_by
    LEFT JOIN users executor
        ON executor.user_id = tc.executed_by
    ORDER BY tc.updated_at DESC, tc.test_case_id DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $testCases[] = $row;
    }
}

/* =====================================================
   STATISTICS
===================================================== */

$totalTests = count($testCases);
$passedTests = 0;
$failedTests = 0;
$pendingTests = 0;
$blockedTests = 0;

foreach ($testCases as $test) {
    switch ($test['status']) {
        case 'Passed':
            $passedTests++;
            break;

        case 'Failed':
            $failedTests++;
            break;

        case 'Blocked':
            $blockedTests++;
            break;

        case 'Draft':
        case 'Ready':
            $pendingTests++;
            break;
    }
}

$passRate = $totalTests > 0
    ? round(($passedTests / $totalTests) * 100)
    : 0;

$flash = $_SESSION['tc_flash'] ?? null;
unset($_SESSION['tc_flash']);

$formTest = $editTest ?: [
    'test_case_id' => 0,
    'project_id' => '',
    'title' => '',
    'description' => '',
    'test_steps' => '',
    'expected_result' => '',
    'actual_result' => '',
    'priority' => 'Medium',
    'status' => 'Draft'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Test Cases | TidyTrack BA Portal</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>
:root {
    --primary: #0d9488;
    --primary-dark: #0f766e;
    --background: #f8fafc;
    --text: #172b3a;
    --muted: #64748b;
    --border: #e2e8f0;
    --white: #ffffff;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
}

.topbar {
    background: white;
    border-bottom: 1px solid var(--border);
    min-height: 76px;
    padding: 14px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 19px;
    font-weight: 700;
}

.brand img {
    width: 40px;
    height: 40px;
    object-fit: contain;
}

.brand small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 500;
}

.back-button {
    text-decoration: none;
    color: var(--text);
    border: 1px solid var(--border);
    padding: 10px 15px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
}

.page {
    max-width: 1250px;
    margin: auto;
    padding: 35px 6%;
}

.breadcrumb {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 20px;
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
}

.heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

h1 {
    font-size: 28px;
    margin: 0 0 8px;
}

h2 {
    font-size: 18px;
    margin: 0;
}

p {
    color: var(--muted);
}

.heading p {
    margin: 0;
    font-size: 14px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.stat, .card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.stat {
    padding: 20px;
}

.stat span {
    color: var(--muted);
    font-size: 13px;
}

.stat strong {
    display: block;
    font-size: 27px;
    margin-top: 10px;
}

.card {
    padding: 24px;
    margin-bottom: 24px;
}

.card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.card-heading p {
    font-size: 13px;
    margin: 6px 0 0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.field {
    margin-bottom: 18px;
}

.field label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}

input[type="text"],
input[type="search"],
select,
textarea {
    width: 100%;
    border: 1px solid var(--border);
    background: white;
    padding: 12px;
    border-radius: 8px;
    font: inherit;
    font-size: 14px;
    color: var(--text);
}

textarea {
    min-height: 105px;
    resize: vertical;
    line-height: 1.6;
}

input:focus, select:focus, textarea:focus {
    outline: 2px solid #99f6e4;
    border-color: var(--primary);
}

.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 0;
    border-radius: 8px;
    padding: 11px 15px;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.primary {
    background: var(--primary);
    color: white;
}

.primary:hover {
    background: var(--primary-dark);
}

.secondary {
    background: white;
    color: var(--text);
    border: 1px solid var(--border);
}

.danger {
    background: #fee2e2;
    color: #991b1b;
}

.button-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.flash {
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-size: 13px;
}

.flash.success {
    background: #dcfce7;
    color: #166534;
}

.flash.error {
    background: #fee2e2;
    color: #991b1b;
}

.filter-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

th, td {
    padding: 14px 12px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
}

th {
    background: #f8fafc;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

td strong {
    display: block;
    margin-bottom: 5px;
}

td small {
    color: var(--muted);
}

.badge {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.status-Draft {
    color: #475569;
    background: #f1f5f9;
}

.status-Ready {
    color: #1d4ed8;
    background: #dbeafe;
}

.status-Passed {
    color: #166534;
    background: #dcfce7;
}

.status-Failed {
    color: #991b1b;
    background: #fee2e2;
}

.status-Blocked {
    color: #92400e;
    background: #fef3c7;
}

.priority-Low {
    color: #166534;
    background: #dcfce7;
}

.priority-Medium {
    color: #1d4ed8;
    background: #dbeafe;
}

.priority-High {
    color: #92400e;
    background: #fef3c7;
}

.priority-Critical {
    color: #991b1b;
    background: #fee2e2;
}

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.actions .button {
    padding: 8px 10px;
}

.empty {
    text-align: center;
    padding: 30px 15px;
    color: var(--muted);
}

.progress-track {
    height: 7px;
    background: #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    margin-top: 12px;
}

.progress-fill {
    height: 100%;
    background: var(--primary);
    border-radius: 20px;
}

footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
}

@media (max-width: 900px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filter-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .page {
        padding: 25px 4%;
    }

    .topbar {
        padding: 14px 4%;
    }

    .stats, .form-grid {
        grid-template-columns: 1fr;
    }

    .card {
        padding: 17px;
    }

    h1 {
        font-size: 24px;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        <img src="assets/images/logo.png"
             alt="TidyTrack"
             onerror="this.style.display='none'">

        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </div>

    <a href="dashboard.php" class="back-button">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Dashboard
    </a>
</header>

<main class="page">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        &nbsp; / &nbsp; Test Cases
    </div>

    <div class="heading">
        <div>
            <h1>Test Case Management</h1>
            <p>Create test cases, record results and track software quality.</p>
        </div>

        <a href="#testForm" class="button primary">
            <i class="fa-solid fa-plus"></i>
            New Test Case
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= tcEscape($flash['type']) ?>">
            <?= tcEscape($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <span>Total Test Cases</span>
            <strong><?= $totalTests ?></strong>
        </div>

        <div class="stat">
            <span>Passed</span>
            <strong><?= $passedTests ?></strong>
        </div>

        <div class="stat">
            <span>Failed</span>
            <strong><?= $failedTests ?></strong>
        </div>

        <div class="stat">
            <span>Not Yet Executed</span>
            <strong><?= $pendingTests + $blockedTests ?></strong>
        </div>
    </section>

    <section class="card">

        <div class="card-heading">
            <div>
                <h2>Test Pass Rate</h2>
                <p>
                    <?= $passedTests ?> passed out of
                    <?= $totalTests ?> total test cases.
                </p>
            </div>

            <strong style="font-size:24px;color:var(--primary);">
                <?= $passRate ?>%
            </strong>
        </div>

        <div class="progress-track">
            <div class="progress-fill"
                 style="width:<?= $passRate ?>%;"></div>
        </div>
    </section>

    <!-- CREATE / EDIT FORM -->

    <section class="card" id="testForm">

        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-clipboard-check"></i>
                    <?= $editTest ? 'Edit Test Case' : 'Create Test Case' ?>
                </h2>

                <p>
                    Record what needs to be tested and the expected outcome.
                </p>
            </div>

            <?php if ($editTest): ?>
                <a href="test-cases.php" class="button secondary">
                    Cancel Editing
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($projects)): ?>

            <div class="empty">
                No projects were found in the system.
                Create a project before adding a test case.
            </div>

        <?php else: ?>

        <form method="POST" id="testCaseForm">

            <input type="hidden" name="csrf_token"
                   value="<?= tcEscape($csrfToken) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="test_case_id"
                   value="<?= (int) $formTest['test_case_id'] ?>">

            <div class="field">
                <label for="project_id">Project *</label>

                <select name="project_id" id="project_id" required>
                    <option value="">Select project</option>

                    <?php foreach ($projects as $project): ?>
                        <option
                            value="<?= (int) $project['project_id'] ?>"
                            <?= (string) $formTest['project_id'] ===
                                (string) $project['project_id']
                                ? 'selected'
                                : '' ?>>

                            <?= tcEscape($project['project_name']) ?>

                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="title">Test Case Title *</label>

                <input type="text"
                       name="title"
                       id="title"
                       maxlength="255"
                       required
                       value="<?= tcEscape($formTest['title']) ?>"
                       placeholder="e.g. Verify user login with valid credentials">
            </div>

            <div class="field">
                <label for="description">Description</label>

                <textarea name="description"
                          id="description"
                          placeholder="Explain what this test should verify..."><?= tcEscape($formTest['description']) ?></textarea>
            </div>

            <div class="field">
                <label for="test_steps">Test Steps</label>

                <textarea name="test_steps"
                          id="test_steps"
                          placeholder="1. Open the login page&#10;2. Enter valid credentials&#10;3. Click Login"><?= tcEscape($formTest['test_steps']) ?></textarea>
            </div>

            <div class="field">
                <label for="expected_result">Expected Result</label>

                <textarea name="expected_result"
                          id="expected_result"
                          placeholder="Describe what should happen..."><?= tcEscape($formTest['expected_result']) ?></textarea>
            </div>

            <div class="field">
                <label for="actual_result">Actual Result</label>

                <textarea name="actual_result"
                          id="actual_result"
                          placeholder="Record what happened when the test was run..."><?= tcEscape($formTest['actual_result']) ?></textarea>
            </div>

            <div class="form-grid">

                <div class="field">
                    <label for="priority">Priority</label>

                    <select name="priority" id="priority">
                        <?php foreach (
                            ['Low', 'Medium', 'High', 'Critical']
                            as $priorityOption
                        ): ?>
                            <option
                                value="<?= tcEscape($priorityOption) ?>"
                                <?= $formTest['priority'] === $priorityOption
                                    ? 'selected'
                                    : '' ?>>

                                <?= tcEscape($priorityOption) ?>

                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="status">Test Status</label>

                    <select name="status" id="status">
                        <?php foreach (
                            ['Draft', 'Ready', 'Passed', 'Failed', 'Blocked']
                            as $statusOption
                        ): ?>
                            <option
                                value="<?= tcEscape($statusOption) ?>"
                                <?= $formTest['status'] === $statusOption
                                    ? 'selected'
                                    : '' ?>>

                                <?= tcEscape($statusOption) ?>

                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <div class="button-row">
                <button type="submit" class="button primary">
                    <i class="fa-solid fa-floppy-disk"></i>

                    <?= $editTest ? 'Save Changes' : 'Create Test Case' ?>
                </button>

                <button type="reset" class="button secondary">
                    Clear Form
                </button>
            </div>

        </form>

        <?php endif; ?>

    </section>

    <!-- TEST CASE HISTORY -->

    <section class="card" id="testHistory">

        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-list-check"></i>
                    Test Case History
                </h2>

                <p>Review test cases created by the BA Team.</p>
            </div>
        </div>

        <div class="filter-row">

            <input type="search"
                   id="testSearch"
                   placeholder="Search title, project or description...">

            <select id="projectFilter">
                <option value="">All projects</option>

                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['project_id'] ?>">
                        <?= tcEscape($project['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="statusFilter">
                <option value="">All statuses</option>
                <option value="Draft">Draft</option>
                <option value="Ready">Ready</option>
                <option value="Passed">Passed</option>
                <option value="Failed">Failed</option>
                <option value="Blocked">Blocked</option>
            </select>

        </div>

        <div class="table-wrap">
            <table id="testTable">

                <thead>
                    <tr>
                        <th>Test Case</th>
                        <th>Project</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Last Executed</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($testCases)): ?>

                    <tr>
                        <td colspan="7" class="empty">
                            No test cases have been created yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($testCases as $test): ?>

                        <tr
                            data-search="<?= tcEscape(strtolower(
                                $test['title'] . ' '
                                . $test['project_name'] . ' '
                                . ($test['description'] ?? '')
                            )) ?>"
                            data-project="<?= (int) $test['project_id'] ?>"
                            data-status="<?= tcEscape($test['status']) ?>">

                            <td>
                                <strong>
                                    <?= tcEscape($test['title']) ?>
                                </strong>

                                <small>
                                    TC-<?= (int) $test['test_case_id'] ?>
                                </small>
                            </td>

                            <td>
                                <?= tcEscape($test['project_name']) ?>
                            </td>

                            <td>
                                <span class="badge priority-<?= tcEscape($test['priority']) ?>">
                                    <?= tcEscape($test['priority']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge status-<?= tcEscape($test['status']) ?>">
                                    <?= tcEscape($test['status']) ?>
                                </span>
                            </td>

                            <td>
                                <?= tcEscape($test['creator_name'] ?? 'Unknown') ?>
                            </td>

                            <td>
                                <?php if ($test['executed_at']): ?>

                                    <?= tcEscape(
                                        date(
                                            'd M Y H:i',
                                            strtotime($test['executed_at'])
                                        )
                                    ) ?>

                                    <br>

                                    <small>
                                        <?= tcEscape(
                                            $test['executor_name'] ?? 'Unknown'
                                        ) ?>
                                    </small>

                                <?php else: ?>

                                    <small>Not executed</small>

                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="actions">

                                    <button
                                        type="button"
                                        class="button secondary view-test"
                                        data-title="<?= tcEscape($test['title']) ?>"
                                        data-description="<?= tcEscape($test['description']) ?>"
                                        data-steps="<?= tcEscape($test['test_steps']) ?>"
                                        data-expected="<?= tcEscape($test['expected_result']) ?>"
                                        data-actual="<?= tcEscape($test['actual_result']) ?>">

                                        <i class="fa-solid fa-eye"></i>
                                        View

                                    </button>

                                    <a href="test-cases.php?edit=<?= (int) $test['test_case_id'] ?>#testForm"
                                       class="button secondary">

                                        <i class="fa-solid fa-pen"></i>
                                        Edit

                                    </a>

                                    <form method="POST"
                                          onsubmit="return confirm('Delete this test case permanently?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= tcEscape($csrfToken) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="delete">

                                        <input type="hidden"
                                               name="test_case_id"
                                               value="<?= (int) $test['test_case_id'] ?>">

                                        <button type="submit"
                                                class="button danger">

                                            <i class="fa-solid fa-trash"></i>
                                            Delete

                                        </button>

                                    </form>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

</main>

<footer>
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>

<!-- TEST CASE DETAILS MODAL -->

<div id="testModal"
     style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.55);
        z-index:1000;
        padding:20px;
        overflow-y:auto;
     ">

    <div style="
        background:white;
        max-width:750px;
        margin:40px auto;
        border-radius:14px;
        padding:25px;
    ">

        <div class="card-heading">
            <h2 id="modalTitle">Test Case Details</h2>

            <button type="button"
                    id="closeModal"
                    class="button secondary">
                <i class="fa-solid fa-xmark"></i>
                Close
            </button>
        </div>

        <div id="modalContent"></div>

    </div>
</div>

<script>
const testSearch = document.getElementById('testSearch');
const projectFilter = document.getElementById('projectFilter');
const statusFilter = document.getElementById('statusFilter');

function filterTests() {
    const search = testSearch.value.toLowerCase().trim();
    const project = projectFilter.value;
    const status = statusFilter.value;

    document.querySelectorAll('#testTable tbody tr[data-search]').forEach(row => {
        const matchesSearch = row.dataset.search.includes(search);
        const matchesProject = !project || row.dataset.project === project;
        const matchesStatus = !status || row.dataset.status === status;

        row.hidden = !(
            matchesSearch &&
            matchesProject &&
            matchesStatus
        );
    });
}

testSearch.addEventListener('input', filterTests);
projectFilter.addEventListener('change', filterTests);
statusFilter.addEventListener('change', filterTests);

/* View test case details safely */

const modal = document.getElementById('testModal');
const modalTitle = document.getElementById('modalTitle');
const modalContent = document.getElementById('modalContent');

document.querySelectorAll('.view-test').forEach(button => {
    button.addEventListener('click', () => {
        modalTitle.textContent = button.dataset.title;
        modalContent.replaceChildren();

        const fields = [
            ['Description', button.dataset.description],
            ['Test Steps', button.dataset.steps],
            ['Expected Result', button.dataset.expected],
            ['Actual Result', button.dataset.actual]
        ];

        fields.forEach(([label, value]) => {
            const section = document.createElement('div');
            section.style.marginBottom = '20px';

            const heading = document.createElement('h3');
            heading.textContent = label;
            heading.style.fontSize = '14px';

            const text = document.createElement('p');
            text.textContent = value || 'Not provided.';
            text.style.whiteSpace = 'pre-wrap';
            text.style.lineHeight = '1.7';
            text.style.overflowWrap = 'anywhere';

            section.append(heading, text);
            modalContent.appendChild(section);
        });

        modal.style.display = 'block';
    });
});

document.getElementById('closeModal').addEventListener('click', () => {
    modal.style.display = 'none';
});

modal.addEventListener('click', event => {
    if (event.target === modal) {
        modal.style.display = 'none';
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        modal.style.display = 'none';
    }
});
</script>

</body>
</html>
