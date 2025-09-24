<?php
/**
 * Example: Run a saved Logs Insights query for "yesterday's" UTC range
 *          and save final results to a file named "YYYY-MM-DD.json".
 */

// Name of the profile you want to use (e.g., 'mfa').
$profile = 'mfa';

// Define "yesterday" in UTC
$startDateTime = new DateTime('yesterday 00:00:00', new DateTimeZone('UTC'));
$endDateTime   = new DateTime('yesterday 23:59:59', new DateTimeZone('UTC'));

// We'll use this for the output filename. e.g. "2025-01-27.json"
$outputFilename = $startDateTime->format('Y-m-d') . '.json';

// 1) Describe query definitions to find the target by name.
$command = "aws logs --profile " . escapeshellarg($profile) 
         . " describe-query-definitions --output json";

$output = shell_exec($command . " 2>&1");
if ($output === null) {
    echo "Error: Could not execute 'describe-query-definitions' or shell_exec is disabled.\n";
    exit(1);
}

$json = json_decode($output, true);
if ($json === null) {
    echo "Error: Output was not valid JSON. Raw output:\n$output\n";
    exit(1);
}

// Target name to search for
$targetName = 'slow queries analisys/slow query aggregations by day';
$queryToExecute = null;

if (isset($json['queryDefinitions'])) {
    foreach ($json['queryDefinitions'] as $qd) {
        if ($qd['name'] === $targetName) {
            $queryToExecute = $qd;
            break;
        }
    }
} 

if (!$queryToExecute) {
    echo "Error: Could not find saved query named '$targetName'.\n";
    exit(1);
}

// Extract query string and log group names
$queryString   = $queryToExecute['queryString'];
$logGroupNames = $queryToExecute['logGroupNames'];

// 2) Start the query for yesterday's UTC time range
$startTime = $startDateTime->getTimestamp(); // 00:00:00 yesterday
$endTime   = $endDateTime->getTimestamp();   // 23:59:59 yesterday

// Build the --log-group-names argument
$logGroupOption = " --log-group-names";
foreach ($logGroupNames as $lg) {
    $logGroupOption .= " " . escapeshellarg($lg);
}

$commandStartQuery = "aws logs --profile " . escapeshellarg($profile)
    . " start-query"
    . $logGroupOption
    . " --start-time " . intval($startTime)
    . " --end-time " . intval($endTime)
    . " --query-string " . escapeshellarg($queryString)
    . " --output json";

$startOutput = shell_exec($commandStartQuery . " 2>&1");
if ($startOutput === null) {
    echo "Error: Could not execute 'start-query' or shell_exec is disabled.\n";
    exit(1);
}

$startJson = json_decode($startOutput, true);
if (!is_array($startJson) || !isset($startJson['queryId'])) {
    echo "Failed to parse queryId from start-query response. Raw output:\n$startOutput\n";
    exit(1);
}

$queryId = $startJson['queryId'];
echo "Started query: $queryId\n";

// 3) Poll until the query finishes
while (true) {
    $commandGetResults = "aws logs --profile " . escapeshellarg($profile)
        . " get-query-results --query-id " . escapeshellarg($queryId)
        . " --output json";

    $resultsRaw = shell_exec($commandGetResults . " 2>&1");
    if ($resultsRaw === null) {
        echo "Error: Could not execute 'get-query-results'.\n";
        exit(1);
    }

    $resultsJson = json_decode($resultsRaw, true);
    if (!$resultsJson) {
        echo "Could not parse JSON from get-query-results:\n$resultsRaw\n";
        exit(1);
    }

    $status = $resultsJson['status'] ?? '(unknown)';
    echo "Query status: $status\n";

    if (in_array($status, ['Complete','Failed','Cancelled'])) {
        if ($status === 'Complete') {
            echo "Query is complete! Saving results to '$outputFilename'.\n";

            // 4) Save final JSON to file. 
            //    We'll store the entire object from get-query-results.
            file_put_contents($outputFilename, json_encode($resultsJson, JSON_PRETTY_PRINT));
            echo "Saved results to $outputFilename\n";
        } else {
            echo "Query ended with status: $status\n";
            echo "Raw output:\n$resultsRaw\n";
        }
        break;
    }
    
    sleep(2);
}

echo "Done.\n";
