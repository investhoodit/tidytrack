<?php
$apiKey = getenv('TIDYTRACK_GEMINI_API_KEY') ?: '';

if ($apiKey === '') {
  http_response_code(500);
  exit('Gemini API configuration is missing.');
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=" . urlencode($apiKey);

$payload = json_encode([
  "contents" => [[
    "role" => "user",
    "parts" => [["text" => "Return JSON ONLY: [{\"title\":\"Test Task\",\"description\":\"Testing Gemini works\",\"priority\":\"normal\",\"due_in_days\":5}]"]]
  ]]
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_TIMEOUT => 25
]);
$response = curl_exec($ch);
if ($response === false) {
  echo "Curl error: " . curl_error($ch);
} else {
  echo "Response:<br><pre>";
  echo htmlspecialchars($response);
  echo "</pre>";
}
curl_close($ch);
