<?php
// chatbot_api.php - AI + DB-Aware Assistant
session_start();
header('Content-Type: application/json');
include '../dp.php'; 

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';
if (!$userId) {
    echo json_encode(['reply' => 'Unauthorized. Please log in first.']);
    exit;
}

// Get message
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!isset($input['message']) || empty(trim($input['message']))) {
    echo json_encode(['reply' => 'Please send a valid message.']);
    exit;
}

$message = strtolower(trim($input['message']));

// --------------------
// DB-Aware Shortcuts
// --------------------
$response = []; // 👈 structured JSON response

if (strpos($message, "my projects") !== false || strpos($message, "show projects") !== false) {
    $stmt = $conn->prepare("
        SELECT p.project_name, p.deliverable, p.start_date, p.end_date, u.name AS leader
        FROM projects_x p
        JOIN users u ON u.user_id = p.teamLeadder_id
        JOIN tasks t ON t.project_id = p.project_id
        WHERE t.assignTo = ?
        GROUP BY p.project_id
    ");
    $stmt->bind_param("s", $userName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows) {
        $projects = [];
        while ($row = $result->fetch_assoc()) {
            $projects[] = [
                "name" => $row['project_name'],
                "description" => $row['deliverable'],
                "start_date" => $row['start_date'],
                "due_date" => $row['end_date'],
                "leader" => $row['leader']
            ];
        }
        $response = ["projects" => $projects];
    } else {
        $response = ["reply" => "You don’t have any assigned projects yet."];
    }
}

elseif (strpos($message, "my tasks") !== false || strpos($message, "show tasks") !== false) {
    $stmt = $conn->prepare("
        SELECT task_id, taskTitle, description, created_at, priority, dueDate, status
    FROM tasks
    WHERE assignTo = ?
    ORDER BY dueDate ASC
    ");
    $stmt->bind_param("s", $userName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows) {
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $tasks[] = [
                "title" => $row['taskTitle'],
                "description" => $row['description'],
                "priority" => $row['priority'],
                "due_date" => $row['dueDate'],
                "status" => $row['status']
            ];
        }
        $response = ["tasks" => $tasks];
    } else {
        $response = ["reply" => "You don’t have any tasks assigned."];
    }
}

elseif (strpos($message, "leader") !== false) {
    $stmt = $conn->prepare("
       SELECT DISTINCT u.name AS leader_name
        FROM projects_x p
        JOIN users u ON p.teamLeadder_id = u.user_id
        JOIN tasks t ON t.project_id = p.project_id
        WHERE t.assignTo = ?
    ");
    $stmt->bind_param("s", $userName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $response = ["leader" => $row['leader_name']];
    } else {
        $response = ["reply" => "I couldn’t find your leader yet."];
    }
}

// --------------------
// If no DB shortcut → Ask API
// --------------------
if (empty($response)) {
    $openai_api_key = getenv('TIDYTRACK_OPENAI_API_KEY') ?: '';
    if ($openai_api_key === '') {
        http_response_code(503);
        echo json_encode(["reply" => "The AI service is not configured."]);
        exit;
    }

    $openai_url = "https://api.openai.com/v1/chat/completions";

    $headers = [
        "Authorization: Bearer " . $openai_api_key,
        "Content-Type: application/json"
    ];

    $data = json_encode([
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "system", "content" => "You are a helpful assistant that supports users with tasks and projects from the INVESTHOOD_TSM system."],
            ["role" => "user", "content" => $message]
        ],
        "temperature" => 0.7
    ]);

    $ch = curl_init($openai_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $responseAI = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $responseDecoded = json_decode($responseAI, true);

    if ($httpcode !== 200) {
        $reply = "Sorry, the AI service is currently unavailable.";
        if (isset($responseDecoded['error']['code'])) {
            $errorCode = $responseDecoded['error']['code'];
            if ($errorCode === "insufficient_quota") {
                $reply = "Your OpenAI quota has reached its limit. Please check your plan and billing details.";
            } elseif ($errorCode === "invalid_api_key") {
                $reply = "Invalid API key. Please check your OpenAI API key.";
            } else {
                $reply = "OpenAI Error: " . $responseDecoded['error']['message'];
            }
        } elseif (!empty($error)) {
            $reply = "Connection error: $error";
        }
        $response = ["reply" => $reply];
    } else {
        $reply = $responseDecoded['choices'][0]['message']['content'] ?? "No valid reply from AI.";
        $response = ["reply" => $reply];
    }
}

// --------------------
// Final Reply (always JSON structured)
// --------------------
echo json_encode($response);
