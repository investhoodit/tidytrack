<?php
include '../dp.php';

$sql = "SELECT id, name, email, title FROM team_members";
$result = $conn->query($sql);
?>
<style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: Arial, sans-serif;
      padding: 20px;
      background-color: #f9f9f9;
    }

    .team-section {
      max-width: 800px;
      margin: 0 auto;
      text-align: center;
    }

    .team-heading {
      font-size: 28px;
      font-weight: bold;
      margin-bottom: 20px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    th, td {
      padding: 10px;
      border: 1px solid #ccc;
      text-align: left;
    }

    th {
      background-color: #eee;
    }

    #openModalBtn {
      display: inline-block;
      margin-top: 30px;
      padding: 10px 20px;
      background-color: #007BFF;
      color: white;
      text-decoration: none;
      border-radius: 5px;
    }

    #openModalBtn:hover {
      background-color: #0056b3;
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0; top: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.4);
    }

    .modal-content {
      background: #fff;
      margin: 10% auto;
      padding: 20px;
      width: 90%;
      max-width: 400px;
      border-radius: 8px;
      position: relative;
    }

    .close {
      position: absolute;
      top: 10px;
      right: 15px;
      font-size: 24px;
      cursor: pointer;
      color: #888;
    }

    .close:hover {
      color: #000;
    }

    form {
      display: flex;
      flex-direction: column;
    }

    label {
      margin-top: 10px;
      text-align: left;
    }

    input, select, button {
      margin-top: 5px;
      padding: 8px;
      font-size: 16px;
    }

    button[type="submit"] {
      margin-top: 15px;
      background-color: #0056b3;
      color: white;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }

    button[type="submit"]:hover {
      background-color: #0056b3;
    }
  </style>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Team List</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">  
</head>
<body>
  <div class="team-section">
    <h1 class="team-heading">Team Members</h1>
    <table>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Title</th>
      </tr>
      <?php if ($result && $result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
          <tr>
            <td><?php echo htmlspecialchars($row["id"]); ?></td>
            <td><?php echo htmlspecialchars($row["name"]); ?></td>
            <td><?php echo htmlspecialchars($row["email"]); ?></td>
            <td><?php echo htmlspecialchars($row["title"]); ?></td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="4">No members found.</td></tr>
      <?php endif; ?>
    </table>

    <a href="#" id="openModalBtn">Add New Member</a>
  </div>

  <!-- Modal -->
  <div id="memberModal" class="modal">
    <div class="modal-content">
      <span class="close" id="closeModalBtn">&times;</span>
      <h2>Add a New Member</h2>
      <form action="add_member.php" method="post">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" required>

        <label for="email">Email:</label>
        <input type="email" id="email" name="email" required>

        <label for="title">Title:</label>
        <select id="title" name="title" required>
          <option value="">-- Select title --</option>
          <option value="editor">Editor</option>
          <option value="viewer">Viewer</option>
        </select>

        <button type="submit">Add Member</button>
      </form>
    </div>
  </div>
  <script>
    document.getElementById('openModalBtn').onclick = function () {
      document.getElementById('memberModal').style.display = 'block';
    };

    document.getElementById('closeModalBtn').onclick = function () {
      document.getElementById('memberModal').style.display = 'none';
    };

    window.onclick = function (event) {
      if (event.target === document.getElementById('memberModal')) {
        document.getElementById('memberModal').style.display = 'none';
      }
    };
  </script>
</body>
</html>

<?php $conn->close(); ?>
