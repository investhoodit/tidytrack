<?php
session_start();
include '../dp.php';

$userName = $_SESSION['name'] ?? null;
$role = $_SESSION['role'] ?? 'member';

if (!$userName || $role !== 'manager') {
  die("Unauthorized. Access restricted to managers.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>All Tasks - INVESTHOOD_TSM (Manager)</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <style>
    :root {
      --accent: #1d3557;
      --bg: #f8f9fa;
      --card-bg: #ffffff;
      --text-dark: #1d1d1d;
      --text-light: #666;
      --green: #2a9d8f;
      --red: #e76f51;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: 'Poppins', sans-serif;
      background: var(--bg);
      color: var(--text-dark);
      line-height: 1.5;
    }

    header {
      width: 100%;
      background: #fff;
      padding: 1rem 1.5rem;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 100;
    }

    header .logo {
      font-size: 1.8rem;
      font-weight: bold;
      color: var(--accent);
    }

    header nav ul {
      list-style: none;
      display: flex;
      align-items: center;
      gap: 1rem;
      margin: 0;
      padding: 0;
      font-size: 1rem;
    }

    main.dashboard-container {
      max-width: 900px;
      padding: 1rem 1rem 2rem;
      margin: 0 auto;
    }

    .task-item {
      background: var(--card-bg);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
      display: flex;
      flex-direction: column;
      gap: 0.8rem;
      transition: box-shadow 0.3s ease, transform 0.3s ease;
    }

    .task-item:hover {
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
      transform: translateY(-2px);
    }

    .task-title {
      font-size: 1.3rem;
      font-weight: 600;
      color: var(--accent);
    }

    small, .status-label {
      font-size: 1rem;
      color: var(--text-light);
    }

    .task-meta {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      margin-top: 0.8rem;
      gap: 0.5rem;
    }

    .status-label.status-completed {
      color: var(--green);
      font-weight: 600;
    }

    .status-label.status-pending {
      color: var(--red);
      font-weight: 600;
    }

    main h2 {
      font-size: 2rem;
      color: var(--accent);
      margin-bottom: 2rem;
      text-align: center;
    }

    .task-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    @media (max-width: 600px) {
      main.dashboard-container {
        padding-right: 1rem;
        padding-left: 1rem;
      }

      header {
        padding: 1rem;
      }

      header .logo {
        font-size: 1.5rem;
      }

      header nav ul {
        font-size: 0.9rem;
        gap: 0.8rem;
      }

      .task-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.4rem;
      }

      .task-title {
        font-size: 1.1rem;
      }

      small, .status-label {
        font-size: 0.9rem;
      }
    }

    @media (max-width: 900px) and (min-width: 601px) {
      main.dashboard-container {
        padding-right: 2rem;
        padding-left: 2rem;
      }
    }
  </style>
</head>
<body>

  <header>
    <div class="logo">INVESTHOOD_TSM</div>
    <nav>
      <ul>
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="tasks.php">Tasks</a></li>
        <li><a href="logout.php">Logout</a></li>
      </ul>
    </nav>
  </header>

  <!-- Main Content -->
  <main class="dashboard-container">
    <h2>All Assigned Tasks</h2>
    <ul class="task-list">
      <?php
      $stmt = $conn->prepare("
        SELECT
          tasks.task_id AS taskID,
          tasks.taskTitle,
          tasks.description,
          tasks.created_at,
          tasks.dueDate,
          tasks.status,
          tasks.priority,
          users.name AS assignee
        FROM tasks
        JOIN users ON tasks.assignTo = users.name
        WHERE tasks.assignTo != ?
        ORDER BY tasks.dueDate ASC
      ");
      $stmt->bind_param("s", $userName);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows > 0) {
          while ($row = $result->fetch_assoc()) { ?>
              <li class="task-item">
                  <div>
                      <span class="task-title"><?= htmlspecialchars($row['taskTitle']) ?></span><br>
                      <small><?= nl2br(htmlspecialchars($row['description'])) ?></small><br>
                      <small><em>Assigned to: <?= htmlspecialchars($row['assignee']) ?></em></small><br>
                      <small><em>Created on: <?= date("M d, Y H:i", strtotime($row['created_at'])) ?></em></small>
                  </div>
                  <div class="task-meta">
                      <span class="status-label">Priority: <?= htmlspecialchars($row['priority']) ?></span>
                      <span class="status-label">Due: <?= htmlspecialchars($row['dueDate']) ?></span>
                      <?php if ($row['status'] === 'completed'): ?>
                          <span class="status-label status-completed">✅ Completed</span>
                      <?php else: ?>
                          <span class="status-label status-pending">⏳ Pending</span>
                      <?php endif; ?>
                  </div>
              </li>
          <?php
          }
      } else {
          echo '<li class="task-item"><span>No tasks found.</span></li>';
      }
      ?>
    </ul>
  </main>

</body>
</html>
