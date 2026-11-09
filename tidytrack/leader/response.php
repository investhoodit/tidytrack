<?php

require "vendor/autoload.php";

use GeminiApi\Client;
use GeminiAPI\Resource\Parts\TextPart;

$data = json_decode(file_get_contents("php://input"));

$text = $data->text;

$apiKey = getenv('TIDYTRACK_GEMINI_API_KEY') ?: '';
if ($apiKey === '') {
    error_log('TidyTrack Gemini configuration is incomplete.');
    http_response_code(500);
    exit('AI service is not configured.');
}

$client = new client($apiKey);
$response = $client->geminiPro()->generateContent(
    new TextPart($text),
);

echo $respose->text();




?>