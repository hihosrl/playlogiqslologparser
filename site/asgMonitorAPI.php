<?php
/**
 * ASG Monitor API
 * Backend endpoint that calls the monitoring script and returns JSON
 */

header('Content-Type: application/json');

// Get ASG name from request
$asgName = isset($_GET['asg']) ? trim($_GET['asg']) : '';

if (empty($asgName)) {
    echo json_encode([
        'success' => false,
        'error' => 'ASG name is required'
    ]);
    exit;
}

// Validate ASG name (basic sanitization)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $asgName)) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid ASG name format'
    ]);
    exit;
}

// Path to the monitoring script - try multiple locations
$possiblePaths = [
    dirname(__FILE__) . '/monitorASGInstances.php',  // Same directory (symlink)
    '/var/www/plqslowmonitor/monitorASGInstances.php',  // Direct path
    '/home/bytoz/.aws/monitorASGInstances.php',  // Original location
    dirname(__FILE__) . '/../../monitorASGInstances.php',
    dirname(__FILE__) . '/../monitorASGInstances.php',
];

$scriptPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $scriptPath = $path;
        break;
    }
}

if (!$scriptPath) {
    echo json_encode([
        'success' => false,
        'error' => 'Monitoring script not found. Checked paths: ' . implode(', ', $possiblePaths)
    ]);
    exit;
}

// Create a unique filename in /tmp but let bytoz user create it
$jsonFile = '/tmp/asg_monitor_' . uniqid() . '_' . getmypid() . '.json';

// Execute the monitoring script with JSON output
// Run as bytoz user to access AWS credentials (bytoz will create the file)
$command = sprintf(
    'sudo -u bytoz php %s %s --json=%s 2>&1',
    escapeshellarg($scriptPath),
    escapeshellarg($asgName),
    escapeshellarg($jsonFile)
);

$output = shell_exec($command);

// Check if JSON file was created and has content
if (!file_exists($jsonFile) || filesize($jsonFile) == 0) {
    // Parse error from output
    $errorMsg = 'Failed to generate monitoring data';
    if (preg_match('/ERROR: (.+)/', $output, $matches)) {
        $errorMsg = $matches[1];
    } elseif (preg_match('/ExpiredTokenException|Unable to locate credentials/', $output)) {
        $errorMsg = 'AWS credentials expired or invalid';
    } elseif (preg_match('/Fatal error/', $output)) {
        $errorMsg = 'PHP Fatal error occurred';
    }
    
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'debug_output' => $output,
        'script_path' => $scriptPath,
        'command' => $command,
        'file_exists' => file_exists($jsonFile),
        'file_size' => file_exists($jsonFile) ? filesize($jsonFile) : 0
    ]);
    exit;
}

// Read JSON data
$jsonData = @file_get_contents($jsonFile);

if ($jsonData === false) {
    echo json_encode([
        'success' => false,
        'error' => 'Cannot read JSON file (permission issue)',
        'script_path' => $scriptPath,
        'json_file' => $jsonFile,
        'file_perms' => file_exists($jsonFile) ? substr(sprintf('%o', fileperms($jsonFile)), -4) : 'N/A'
    ]);
    exit;
}

// Clean up temp file
@unlink($jsonFile);

// Try to decode
$data = json_decode($jsonData, true);

if ($data === null) {
    // Show more of the raw output for debugging
    echo json_encode([
        'success' => false,
        'error' => 'Failed to parse monitoring data. JSON decode error: ' . json_last_error_msg(),
        'raw_json' => $jsonData,  // Show full JSON for debugging
        'json_length' => strlen($jsonData),
        'script_path' => $scriptPath,
        'command' => $command
    ]);
    exit;
}

// Return success response
echo json_encode([
    'success' => true,
    'data' => $data
]);
?>
