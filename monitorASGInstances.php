#!/usr/bin/env php
<?php
/**
 * Monitor ASG Instances - Apache mod_status metrics
 * Automatically fetches IPs from Auto Scaling Group and monitors them
 * 
 * Usage:
 *   php monitorASGInstances.php <ASG_NAME> [--csv=out.csv] [--json=out.json]
 *   php monitorASGInstances.php asg-betmaker-pro --csv=status.csv
 */

ini_set('memory_limit', '512M');

// ============================================================
// CONFIGURATION
// ============================================================
$awsProfile  = 'mfa';
$port        = 8001;
$path        = '/server-status?auto';
$scheme      = 'http';
$timeout     = 3;
$concurrency = 8;

$scoreMap = [
  '_' => 'OpenSlot','S' => 'Starting','R' => 'Reading','W' => 'Writing',
  'K' => 'Keepalive','D' => 'DNSLookup','C' => 'Closing','L' => 'Logging',
  'G' => 'GracefulFin','I' => 'IdleCleanup','.' => 'Dead'
];

// ============================================================
// PARSE ARGUMENTS
// ============================================================
if ($argc < 2) {
    echo "Usage: php monitorASGInstances.php <ASG_NAME> [--csv=file.csv] [--json=file.json]\n";
    echo "Example: php monitorASGInstances.php asg-betmaker-pro --csv=status.csv\n";
    exit(1);
}

// Parse arguments manually (getopt has issues with positional args)
$asgName = null;
$csvPath = null;
$jsonPath = null;

for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--csv=(.+)$/', $argv[$i], $m)) {
        $csvPath = $m[1];
    } elseif (preg_match('/^--json=(.+)$/', $argv[$i], $m)) {
        $jsonPath = $m[1];
    } elseif (!str_starts_with($argv[$i], '--')) {
        $asgName = $argv[$i];
    }
}

if (!$asgName) {
    echo "Error: ASG name is required\n";
    echo "Usage: php monitorASGInstances.php <ASG_NAME> [--csv=file.csv] [--json=file.json]\n";
    exit(1);
}

// Determine if we should suppress console output (JSON-only mode)
$quietMode = ($jsonPath && !$csvPath);

// Debug (removed after testing)
// fwrite(STDERR, "DEBUG: jsonPath='$jsonPath', csvPath='$csvPath', quietMode=" . ($quietMode ? 'true' : 'false') . "\n");

// ============================================================
// FETCH IPs FROM AUTO SCALING GROUP
// ============================================================
if (!$quietMode) {
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Fetching instances from ASG: $asgName\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
}

$cmd_asg = "aws autoscaling describe-auto-scaling-groups --auto-scaling-group-names " . 
           escapeshellarg($asgName) . " --profile $awsProfile 2>&1";
$output_asg = shell_exec($cmd_asg);

// Check for AWS errors
if (preg_match('/ExpiredTokenException|Unable to locate credentials|token included in the request is invalid/i', $output_asg)) {
    echo "ERROR: AWS authentication failed.\n";
    echo "Output: $output_asg\n";
    exit(1);
}

$asg_data = json_decode($output_asg, true);

if (!isset($asg_data['AutoScalingGroups']) || empty($asg_data['AutoScalingGroups'])) {
    echo "ERROR: Auto Scaling Group '$asgName' not found.\n";
    exit(1);
}

$instances = $asg_data['AutoScalingGroups'][0]['Instances'] ?? [];

if (empty($instances)) {
    echo "No instances found in ASG '$asgName'.\n";
    exit(0);
}

// Extract instance IDs
$instance_ids = array_map(function($instance) {
    return $instance['InstanceId'];
}, $instances);

if (!$quietMode) echo "Found " . count($instance_ids) . " instance(s)\n";

// Get private IPs for these instances
$instance_ids_string = implode(" ", $instance_ids);
$cmd_ec2 = "aws ec2 describe-instances --instance-ids $instance_ids_string --profile $awsProfile " .
           "--query 'Reservations[*].Instances[*].[InstanceId,PrivateIpAddress,State.Name]' --output json 2>&1";
$output_ec2 = shell_exec($cmd_ec2);

$ec2_data = json_decode($output_ec2, true);
if (!is_array($ec2_data)) {
    echo "ERROR: Failed to parse EC2 instance data.\n";
    exit(1);
}

// Extract running instances' IPs
$ips = [];
$ipToInstanceId = [];
foreach ($ec2_data as $reservation) {
    foreach ($reservation as $instance) {
        $instance_id = $instance[0];
        $private_ip = $instance[1];
        $state = $instance[2];
        
        if ($state === 'running') {
            $ips[] = $private_ip;
            $ipToInstanceId[$private_ip] = $instance_id;
        }
    }
}

if (empty($ips)) {
    echo "No running instances found.\n";
    exit(0);
}

if (!$quietMode) echo "Monitoring " . count($ips) . " running instance(s)\n\n";

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function build_url($scheme, $ip, $port, $path) {
  $host = str_contains($ip, ':') ? "[$ip]" : $ip; // IPv6 safe
  $p = ($path === "" || $path[0] !== "/") ? "/$path" : $path;
  return sprintf('%s://%s:%d%s', $scheme, $host, $port, $p);
}

function parse_mod_status($text, $scoreMap) {
  $data = [];
  foreach (explode("\n", $text) as $line) {
    $line = trim($line);
    if ($line === "" || strpos($line, ":") === false) continue;
    [$k,$v] = array_map('trim', explode(":", $line, 2));
    $data[$k] = $v;
  }
  // normalize numeric fields
  foreach ([
    "Total Accesses","Total kBytes","Uptime","BusyWorkers","IdleWorkers",
    "ConnsTotal","ConnsAsyncWriting","ConnsAsyncKeepAlive","ConnsAsyncClosing"
  ] as $k) if (isset($data[$k]) && is_numeric($data[$k])) $data[$k] = (int)$data[$k];
  foreach (["CPULoad","ReqPerSec","BytesPerSec","BytesPerReq"] as $k)
    if (isset($data[$k]) && is_numeric($data[$k])) $data[$k] = (float)$data[$k];

  // scoreboard expansion
  $score = $data['Scoreboard'] ?? '';
  foreach ($scoreMap as $ch => $label) {
    $data["Score_$label"] = substr_count($score, $ch);
  }
  return $data;
}

function fetch_all($ips, $scheme, $port, $path, $timeout, $concurrency) {
  $mh = curl_multi_init();
  $pending = $ips;
  $handles = [];
  $results = [];

  $create = function($ip) use ($scheme,$port,$path,$timeout) {
    $ch = curl_init();
    $url = build_url($scheme,$ip,$port,$path);
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_USERAGENT => 'modstatus-php',
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
    ]);
    return [$ch, $url];
  };

  while (count($handles) < $concurrency && $pending) {
    $ip = array_shift($pending);
    [$ch,$url] = $create($ip);
    $handles[(int)$ch] = ['ch'=>$ch,'ip'=>$ip,'url'=>$url];
    curl_multi_add_handle($mh, $ch);
  }

  do {
    curl_multi_exec($mh, $running);
    while ($info = curl_multi_info_read($mh)) {
      $ch  = $info['handle'];
      $key = (int)$ch;
      $ip  = $handles[$key]['ip'];
      $url = $handles[$key]['url'];

      $body = curl_multi_getcontent($ch);
      $err  = curl_error($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $ok   = !$err && $code >= 200 && $code < 300;

      $results[] = [
        'IP' => $ip,
        'URL' => $url,
        'OK' => $ok ? 1 : 0,
        'Error' => $ok ? null : ($err ?: "HTTP $code"),
        'Body' => $ok ? $body : null
      ];

      curl_multi_remove_handle($mh,$ch);
      curl_close($ch);
      unset($handles[$key]);

      if ($pending) {
        $ipN = array_shift($pending);
        [$chN,$urlN] = $create($ipN);
        $handles[(int)$chN] = ['ch'=>$chN,'ip'=>$ipN,'url'=>$urlN];
        curl_multi_add_handle($mh,$chN);
      }
    }
    if ($running) curl_multi_select($mh, 0.2);
  } while ($running || $handles);

  curl_multi_close($mh);
  return $results;
}

function print_table($rows) {
  if (!$rows) return;
  $priority = ['InstanceID','IP','OK','ActiveConnections','BusyWorkers','IdleWorkers','CPULoad','ReqPerSec','BytesPerSec','BytesPerReq','TotalAccesses','ConnsTotal','ConnsAsyncKeepAlive','Score_Reading','Score_Writing','Score_Keepalive','Score_Starting','Score_Closing','Score_Dead','Score_OpenSlot','Uptime','Error'];
  $cols = [];
  foreach ($rows as $r) $cols = array_unique(array_merge($cols, array_keys($r)));
  usort($cols, function($a,$b) use ($priority){
    $pa = array_search($a,$priority); $pa = $pa===false?999:$pa;
    $pb = array_search($b,$priority); $pb = $pb===false?999:$pb;
    return $pa === $pb ? strcmp($a,$b) : ($pa <=> $pb);
  });

  $w = [];
  foreach ($cols as $c) $w[$c] = max(strlen($c), 3);
  foreach ($rows as $r) foreach ($cols as $c) $w[$c] = max($w[$c], strlen((string)($r[$c] ?? "")));

  foreach ($cols as $c) echo str_pad($c, $w[$c] + 2);
  echo PHP_EOL;
  foreach ($cols as $c) echo str_pad(str_repeat('-', $w[$c]), $w[$c] + 2);
  echo PHP_EOL;

  foreach ($rows as $r) {
    foreach ($cols as $c) echo str_pad((string)($r[$c] ?? ''), $w[$c] + 2);
    echo PHP_EOL;
  }
}

// ============================================================
// FETCH MOD_STATUS FROM ALL IPs
// ============================================================
if (!$quietMode) echo "Fetching mod_status from instances...\n\n";
$raw = fetch_all($ips, $scheme, $port, $path, $timeout, $concurrency);

// ============================================================
// PROCESS RESULTS
// ============================================================
$rows = [];
foreach ($raw as $r) {
  $instanceId = $ipToInstanceId[$r['IP']] ?? 'unknown';
  
  if (!$r['OK']) {
    $rows[] = [
      'InstanceID' => $instanceId,
      'IP' => $r['IP'],
      'OK' => 0,
      'Error' => $r['Error']
    ];
    continue;
  }
  
  $d = parse_mod_status($r['Body'], $scoreMap);

  $busy = (int)($d['BusyWorkers'] ?? 0);
  $keep = (int)($d['Score_Keepalive'] ?? 0);
  $idle = (int)($d['IdleWorkers'] ?? 0);
  $active = $busy + $keep; // approximate active TCP connections

  $rows[] = array_merge([
    'InstanceID' => $instanceId,
    'IP' => $r['IP'],
    'OK' => 1,
    'Error' => null,
    'Uptime' => $d['Uptime'] ?? null,
    'CPULoad' => $d['CPULoad'] ?? null,
    'TotalAccesses' => $d['Total Accesses'] ?? null,
    'BusyWorkers' => $busy,
    'IdleWorkers' => $idle,
    'ActiveConnections' => $active,
    'ReqPerSec' => $d['ReqPerSec'] ?? null,
    'BytesPerSec' => $d['BytesPerSec'] ?? null,
    'BytesPerReq' => $d['BytesPerReq'] ?? null,
    'ConnsTotal' => $d['ConnsTotal'] ?? null,
    'ConnsAsyncKeepAlive' => $d['ConnsAsyncKeepAlive'] ?? null,
    'Score_Reading' => $d['Score_Reading'] ?? 0,
    'Score_Writing' => $d['Score_Writing'] ?? 0,
    'Score_Keepalive' => $keep,
    'Score_Starting' => $d['Score_Starting'] ?? 0,
    'Score_Closing' => $d['Score_Closing'] ?? 0,
    'Score_Dead' => $d['Score_Dead'] ?? 0,
    'Score_OpenSlot' => $d['Score_OpenSlot'] ?? 0,
  ]);
}

// ============================================================
// OUTPUT
// ============================================================
// Statistics (calculate first)
$totalActive = array_sum(array_column(array_filter($rows, fn($r) => $r['OK']), 'ActiveConnections'));
$totalBusy = array_sum(array_column(array_filter($rows, fn($r) => $r['OK']), 'BusyWorkers'));
$totalIdle = array_sum(array_column(array_filter($rows, fn($r) => $r['OK']), 'IdleWorkers'));
$avgReqPerSec = array_sum(array_column(array_filter($rows, fn($r) => $r['OK']), 'ReqPerSec'));

// Only show console output if not in quiet mode
if (!$quietMode) {
    print_table($rows);
    
    echo "\n╔════════════════════════════════════════════════════════════╗\n";
    echo "║  SUMMARY FOR ASG: $asgName\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║  Total Active Connections: $totalActive\n";
    echo "║  Total Busy Workers: $totalBusy\n";
    echo "║  Total Idle Workers: $totalIdle\n";
    echo "║  Total Requests/sec: " . number_format($avgReqPerSec, 2) . "\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
}

// ============================================================
// CSV OUTPUT
// ============================================================
if ($csvPath) {
  $allCols = [];
  foreach ($rows as $r) $allCols = array_unique(array_merge($allCols, array_keys($r)));
  $fp = @fopen($csvPath, 'w');
  if ($fp === false) {
    fwrite(STDERR, "Cannot write CSV to $csvPath\n");
  } else {
    fputcsv($fp, $allCols);
    foreach ($rows as $r) {
      $line = [];
      foreach ($allCols as $c) $line[] = $r[$c] ?? '';
      fputcsv($fp, $line);
    }
    fclose($fp);
    if (!$quietMode) echo "CSV written: $csvPath\n";
  }
}

// ============================================================
// JSON OUTPUT
// ============================================================
if ($jsonPath) {
  $output = [
    'asg_name' => $asgName,
    'timestamp' => date('c'),
    'summary' => [
      'total_instances' => count($ips),
      'total_active_connections' => $totalActive,
      'total_busy_workers' => $totalBusy,
      'total_idle_workers' => $totalIdle,
      'total_req_per_sec' => $avgReqPerSec,
    ],
    'instances' => $rows
  ];
  
  $ok = @file_put_contents($jsonPath, json_encode($output, JSON_PRETTY_PRINT));
  if ($ok === false) {
    fwrite(STDERR, "Cannot write JSON to $jsonPath\n");
  } else {
    // Make file readable by web server
    chmod($jsonPath, 0644);
    if (!$quietMode) fwrite(STDOUT, "JSON written: $jsonPath\n");
  }
}

?>
