<?php
session_start();
require '../dp.php';

$userId   = $_SESSION['userID'] ?? null;
$userName = $_SESSION['name'] ?? 'Manager';
$role     = $_SESSION['role'] ?? 'manager';

// Only retrieve users whose role is "member"
$users = $conn->query("
    SELECT user_id, name
    FROM users
    WHERE role = 'member'
    ORDER BY name ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>View Monthly Timesheets</title>

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Poppins&display=swap"
    rel="stylesheet"
  >

  <style>
    :root {
      --accent: #1d3557;
      --bg: #eef2f5;
    }

    * {
      box-sizing: border-box;
    }

    body {
      font-family: 'Poppins', sans-serif;
      margin: 0;
      background: var(--bg);
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    .dashboard-container {
      flex: 1;
      padding: 2rem;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .form-card {
      background: rgba(255, 255, 255, 0.6);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
      width: 100%;
      max-width: 600px;
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .form-card h2 {
      font-size: 1.6rem;
      margin-bottom: 1.5rem;
      color: var(--accent);
      text-align: center;
    }

    .form-group {
      margin-bottom: 1.2rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 600;
      color: #333;
    }

    .form-group select {
      width: 100%;
      padding: 0.7rem;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 1rem;
      font-family: 'Poppins', sans-serif;
      background: #fff;
    }

    .btn {
      background: #6c757d;
      color: #fff;
      padding: 0.75rem 1.5rem;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition:
        background 0.3s ease,
        opacity 0.3s ease;
      text-decoration: none;
      display: inline-block;
      text-align: center;
    }

    .btn:hover {
      background: #5a6268;
    }

    .btn.disabled {
      pointer-events: none;
      opacity: 0.6;
    }

    .button-group {
      display: flex;
      justify-content: center;
      margin-top: 1rem;
    }

    .empty-message {
      padding: 1rem;
      border-radius: 6px;
      background: #fff3cd;
      color: #856404;
      text-align: center;
    }

    @media (max-width: 768px) {
      .dashboard-container {
        padding: 1rem;
      }

      .form-card {
        padding: 1.5rem;
      }

      .form-card h2 {
        font-size: 1.4rem;
      }

      .form-group select {
        font-size: 0.95rem;
      }

      .btn {
        width: 100%;
        padding: 0.75rem;
      }
    }

    @media (max-width: 480px) {
      .form-card {
        padding: 1rem;
      }
    }
  </style>
</head>

<body>

<div class="dashboard-container">
  <div class="form-card">

    <?php if ($role === 'manager'): ?>

      <h2>View Member Timesheet</h2>

      <?php if ($users && $users->num_rows > 0): ?>

        <div class="form-group">
          <label for="user_id">Member</label>

          <select id="user_id" name="user_id" required>
            <option value="">-- Select Member --</option>

            <?php while ($u = $users->fetch_assoc()): ?>
              <option value="<?= (int) $u['user_id'] ?>">
                <?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="button-group">
          <a
            id="viewTimesheetBtn"
            href="#"
            class="btn disabled"
            aria-disabled="true"
          >
            <i class="fas fa-calendar-alt"></i>
            View Member Timesheet
          </a>
        </div>

      <?php else: ?>

        <div class="empty-message">
          No members were found.
        </div>

      <?php endif; ?>

    <?php else: ?>

      <h2>My Timesheet</h2>

      <div class="button-group">
        <a
          href="timesheet_list.php?user_id=<?= (int) $userId ?>"
          class="btn"
        >
          <i class="fas fa-calendar-alt"></i>
          View My Timesheet
        </a>
      </div>

    <?php endif; ?>

  </div>
</div>

<?php if ($role === 'manager' && $users && $users->num_rows > 0): ?>
<script>
  const userSelect = document.getElementById('user_id');
  const viewButton = document.getElementById('viewTimesheetBtn');

  userSelect.addEventListener('change', function () {
    const userId = this.value;

    if (userId) {
      viewButton.href =
        'timesheet_list.php?user_id=' + encodeURIComponent(userId);

      viewButton.classList.remove('disabled');
      viewButton.setAttribute('aria-disabled', 'false');
    } else {
      viewButton.href = '#';
      viewButton.classList.add('disabled');
      viewButton.setAttribute('aria-disabled', 'true');
    }
  });
</script>
<?php endif; ?>

</body>
</html>