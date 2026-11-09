<?php
include '../dp.php';

// Handle form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = htmlspecialchars(trim($_POST['name']));
    $email = htmlspecialchars(trim($_POST['email']));
    $title = htmlspecialchars(trim($_POST['title']));

    // Prepare and bind
    $stmt = $conn->prepare("INSERT INTO team_members (name, email, title) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $email, $title);

    if ($stmt->execute()) {
        // Redirect to team_list.php after successful insertion
        header("Location: leader_team_list.php");
        exit();
    } else {
        echo "<p>Error: " . $stmt->error . "</p>";
    }

    $stmt->close();
}
$conn->close();
?>


