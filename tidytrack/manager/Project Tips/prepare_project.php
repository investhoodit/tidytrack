<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow for local testing

$apiKey = getenv('TIDYTRACK_GEMINI_API_KEY') ?: '';

if (!$apiKey) {
    echo json_encode(["ok" => false, "error" => "Missing API key"]);
    exit;
}

// Read POST data
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(["ok" => false, "error" => "Invalid input"]);
    exit;
}

$project_name = trim($data['project_name'] ?? '');
$department = trim($data['department'] ?? '');
$company_name = trim($data['company_name'] ?? '');
$team_level = trim($data['team_level'] ?? '');

if (!$project_name || !$department) {
    echo json_encode(["ok" => false, "error" => "Project Name and Department required"]);
    exit;
}

$companyText = $company_name ? " for $company_name" : '';
$teamText = $team_level ? " with a $team_level team" : '';

$prompt = <<<EOD
Generate project preparation material for a project called "{$project_name}" in the {$department} department{$companyText}{$teamText}.
Return JSON with these keys:
- project_tips (5-8 short actionable tips)
- deliverables (5-10 expected deliverables)
- tasks (6-12 key tasks)
- risks (5-8 potential risks)
Only output valid JSON. No markdown or comments.
EOD;

// Prepare API request body
$body = [
    "contents" => [
        ["parts" => [["text" => $prompt]]]
    ]
];

// Call Gemini API
$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(["ok" => false, "error" => "Curl error: " . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

$json = json_decode($response, true);

if (isset($json['error'])) {
    echo json_encode(["ok" => false, "error" => $json['error']['message'] ?? 'API error']);
    exit;
}

$content = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');

// Remove code fences if present
$content = preg_replace('/^```[a-z]*\s*/mi', '', $content);
$content = preg_replace('/```$/m', '', $content);
$content = trim($content);

$payload = json_decode($content, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["ok" => false, "error" => "Invalid JSON from API: " . json_last_error_msg(), "raw" => $content]);
    exit;
}

$required = ['project_tips', 'deliverables', 'tasks', 'risks'];
foreach ($required as $key) {
    if (!isset($payload[$key]) || !is_array($payload[$key])) {
        echo json_encode(["ok" => false, "error" => "Missing or invalid key: $key", "raw" => $content]);
        exit;
    }
}

echo json_encode(["ok" => true, "data" => $payload]);
