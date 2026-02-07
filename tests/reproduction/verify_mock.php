<?php

function sendRequest($url, $body, $contentLength = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 seconds timeout

    $headers = [];
    if ($contentLength !== null) {
        $headers[] = 'Content-Length: ' . $contentLength;
    }
    $headers[] = 'Connection: close'; // Ensure we don't keep alive
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => $error];
    }
    return ['code' => $httpCode];
}

$url = 'http://localhost:23000/loadSources';
$body = 'test_content';
$len = strlen($body);

// Test 1: Matching Content-Length
echo "Test 1: Matching Content-Length ($len)... ";
$res = sendRequest($url, $body, $len);
if (isset($res['code']) && $res['code'] === 200) {
    echo "PASS (Got 200)\n";
} else {
    echo "FAIL (Result: " . json_encode($res) . ")\n";
    exit(1);
}

// Test 2: Mismatching Content-Length
echo "Test 2: Mismatching Content-Length (" . ($len + 10) . ")... ";
$res = sendRequest($url, $body, $len + 10);

// We expect either a 400 (if server catches it fast) or a Timeout/EOF error (if server waits)
// cURL error 28 is Timeout.
// cURL error 18 is Partial File.
if ((isset($res['code']) && $res['code'] === 400) || isset($res['error'])) {
    echo "PASS (Got Expected Failure: " . json_encode($res) . ")\n";
} else {
    echo "FAIL (Got Unexpected Success: " . json_encode($res) . ")\n";
    exit(1);
}

echo "All mock server tests passed.\n";
