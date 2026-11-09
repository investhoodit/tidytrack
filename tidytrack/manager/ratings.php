<?php
session_start();
require_once __DIR__ . '/../dp.php';

// Load employees
$employees = $conn->query("SELECT user_id AS id, name FROM users");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Investhood - Manager Dashboard</title>
<style>
/* Light mode (default) */
body { 
    font-family: Arial, sans-serif; 
    margin:20px; 
    background:#f9f9f9; 
    color:#1d3557; 
    transition: background 0.3s, color 0.3s;
}
.container { 
    max-width: 900px; 
    margin:auto; 
    background:#fff; 
    padding:20px; 
    border-radius:8px; 
    box-shadow:0 4px 10px rgba(0,0,0,0.1);
    transition: background 0.3s, color 0.3s;
}
h2 { 
    margin-bottom:20px; 
    text-align:center; 
    color:#1d3557; 
}
label { font-weight:bold; }
select, textarea { 
    width:100%; 
    padding:8px; 
    margin:8px 0 15px 0; 
    border:1px solid #ccc; 
    border-radius:5px; 
    background: #fff;
    color: #1d3557;
    transition: background 0.3s, color 0.3s, border 0.3s;
}
table { 
    width:100%; 
    border-collapse:collapse; 
    margin-bottom:20px; 
}
th, td { 
    border:1px solid #ddd; 
    padding:10px; 
    text-align:center; 
}
th { background:#1d3557; color:#fff; }
tr:nth-child(even){ background:#f4f7fb; }
tr:hover { background:#ffe5e5; } 
button { 
    padding:10px 20px; 
    margin:5px; 
    cursor:pointer; 
    border:none; 
    border-radius:5px; 
    font-weight:bold;
}
#exportBtn { 
    background:#1d3557; 
    color:#fff;
}
#exportBtn:hover { 
    background:#0b2239; 
}
.back-btn {
    display: inline-block;
    margin-bottom: 20px;
    padding: 8px 12px;
    background: #1d3557;
    color: #fff;
    text-decoration: none;
    border-radius: 5px;
    font-weight: bold;
    transition: background 0.3s;
}
.back-btn:hover {
    background: #0b2239;
}

/* Dark mode styles */
body.dark-mode { 
    background:#1d1f21; 
    color:#f0f0f0; 
}
body.dark-mode .container {
    background:#2b2e33;
    color:#f0f0f0;
    box-shadow: 0 4px 10px rgba(0,0,0,0.5);
}
body.dark-mode h2 {
    color: #7ec8e3; /* Light blue heading */
}
body.dark-mode select, 
body.dark-mode textarea {
    background:#3a3d42;
    color:#f0f0f0;
    border:1px solid #555;
}
body.dark-mode table th { 
    background:#44475a; 
    color:#f8f8f2; 
}
body.dark-mode tr:nth-child(even){ background:#3a3d42; }
body.dark-mode tr:hover{ background:#5a5d61; }
body.dark-mode .back-btn, body.dark-mode #exportBtn {
    background:#44475a;
    color:#f8f8f2;
}
body.dark-mode .back-btn:hover, body.dark-mode #exportBtn:hover {
    background:#2b2e33;
}

/* Dark mode toggle button */
#darkModeToggle {
    position: fixed;
    top: 20px;
    right: 20px;
    background:#1d3557;
    color:#fff;
    border:none;
    padding:10px 15px;
    border-radius:5px;
    cursor:pointer;
    font-weight:bold;
    transition: background 0.3s;
}
#darkModeToggle:hover {
    background:#0b2239;
}
</style>
</head>
<body>
<!-- Dark Mode Toggle Button -->
<button id="darkModeToggle">Toggle Dark Mode</button>

<div class="container">
    <!-- Back to Dashboard Button -->
    <a href="manager_index.php" class="back-btn" title="Back to Dashboard">&#8592; Back to Dashboard</a>

    <h2>Performance Ratings</h2>

    <form id="ratingForm" action="export_ratings_pdf.php" method="post" target="_blank">
        <label for="employee">Select Employee:</label>
        <select name="employee_id" id="employee" required>
            <option value="">-- Select Employee --</option>
            <?php if ($employees && $employees->num_rows > 0): ?>
                <?php while($row = $employees->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($row['id']) ?>"><?= htmlspecialchars($row['name']) ?></option>
                <?php endwhile; ?>
            <?php else: ?>
                <option disabled>No employees found</option>
            <?php endif; ?>
        </select>

        <table>
            <tr>
                <th>Criteria</th>
                <th>0</th><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th>
            </tr>
            <?php
            $criteria = [
                "Demonstrates teamwork and collaboration effectively",
                "Communicates clearly and professionally",
                "Shows strong technical skills in assigned tasks",
                "Handles problems and finds solutions efficiently",
                "Exhibits leadership and initiative when needed"
            ];
            foreach ($criteria as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c) ?></td>
                <?php for($i=0;$i<=5;$i++): ?>
                    <td><input type="radio" name="rating[<?= htmlspecialchars($c) ?>]" value="<?= $i ?>"></td>
                <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
        </table>

        <label for="feedback">Feedback / Comments:</label><br>
        <textarea name="feedback" id="feedback" rows="4"></textarea><br><br>

        <button type="submit" id="exportBtn">Export to PDF</button>
    </form>
</div>

<script>
// Dark mode toggle
const toggleBtn = document.getElementById('darkModeToggle');
toggleBtn.addEventListener('click', () => {
    document.body.classList.toggle('dark-mode');
    // Save preference
    if(document.body.classList.contains('dark-mode')){
        localStorage.setItem('darkMode','enabled');
    } else {
        localStorage.setItem('darkMode','disabled');
    }
});

// Load dark mode preference on page load
if(localStorage.getItem('darkMode') === 'enabled'){
    document.body.classList.add('dark-mode');
}
</script>

</body>
</html>
