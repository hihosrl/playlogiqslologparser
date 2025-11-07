<?php
/**
 * collect_mod_status_inline.php
 * - Hard-coded IP list
 * - Concurrent fetch of /server-status?auto
 * - Pretty console output + optional --csv/--json files
 *
 * Usage:
 *   php collect_mod_status_inline.php --csv=out.csv --json=out.json
 */

ini_set('memory_limit', '512M');

$opts = getopt("", ["csv::","json::"]);
$csvPath  = $opts['csv']  ?? null;
$jsonPath = $opts['json'] ?? null;

$ips = ['172.31.56.185', '172.31.60.91', '172.31.52.128', '172.31.50.112', '172.31.58.8', '172.31.63.104', '172.31.54.210', '172.31.54.224', '172.31.55.135', '172.31.62.146', '172.31.57.161', '172.31.59.235', '172.31.61.74', '172.31.62.148', '172.31.56.132', '172.31.55.121', '172.31.52.239', '172.31.51.40', '172.31.55.64', '172.31.51.150', '172.31.48.8', '172.31.52.185', '172.31.52.207', '172.31.61.22', '172.31.51.130', '172.31.59.22', '172.31.55.177', '172.31.54.31', '172.31.63.136', '172.31.57.216', '172.31.49.149', '172.31.51.57', '172.31.62.73', '172.31.56.78', '172.31.48.227', '172.31.63.152', '172.31.62.71', '172.31.51.159', '172.31.64.72', '172.31.77.187', '172.31.65.185', '172.31.69.178', '172.31.74.111', '172.31.76.216', '172.31.75.7', '172.31.65.42', '172.31.78.205', '172.31.76.68', '172.31.64.143', '172.31.64.190'];

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

$raw = fetch_all($ips, $scheme, $port, $path, $timeout, $concurrency);

$rows = [];
foreach ($raw as $r) {
  if (!$r['OK']) {
    $rows[] = [
      'IP' => $r['IP'],
      'URL' => $r['URL'],
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
    'IP' => $r['IP'],
    'URL' => $r['URL'],
    'OK' => 1,
    'Error' => null,
    'Uptime' => $d['Uptime'] ?? null,
    'BusyWorkers' => $busy,
    'IdleWorkers' => $idle,
    'ReqPerSec' => $d['ReqPerSec'] ?? null,
    'BytesPerSec' => $d['BytesPerSec'] ?? null,
    'BytesPerReq' => $d['BytesPerReq'] ?? null,
    'ActiveConnections' => $active,
    'Score_Reading' => $d['Score_Reading'] ?? 0,
    'Score_Writing' => $d['Score_Writing'] ?? 0,
    'Score_Keepalive' => $keep,
    'Score_OpenSlot' => $d['Score_OpenSlot'] ?? 0,
  ]);
}

/* ---------- console output ---------- */
function print_table($rows) {
  if (!$rows) return;
  $priority = ['IP','OK','ActiveConnections','BusyWorkers','IdleWorkers','ReqPerSec','BytesPerSec','BytesPerReq','Score_Reading','Score_Writing','Score_Keepalive','Score_OpenSlot','Uptime','Error','URL'];
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

print_table($rows);

/* ---------- CSV ---------- */
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
    fwrite(STDOUT, "CSV written: $csvPath\n");
  }
}

/* ---------- JSON ---------- */
if ($jsonPath) {
  $ok = @file_put_contents($jsonPath, json_encode($rows, JSON_PRETTY_PRINT));
  if ($ok === false) {
    fwrite(STDERR, "Cannot write JSON to $jsonPath\n");
  } else {
    fwrite(STDOUT, "JSON written: $jsonPath\n");
  }
}
