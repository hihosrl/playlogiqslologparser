<?php
// api_v6.php - Enhanced version with caching and minute/second granularity
header('Content-Type: application/json');

// Cache configuration
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_TTL_CURRENT_DAY', 300); // 5 minutes for current day
define('CACHE_ENABLED', true);

// Ensure cache directory exists
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// Get the action parameter
$action = $_REQUEST['action'] ?? '';

// Parse filter parameters
$fromDate = !empty($_REQUEST['from']) ? $_REQUEST['from'] : date('Y-m-d', strtotime('-1 day'));
$toDate   = !empty($_REQUEST['to'])   ? $_REQUEST['to']   : date('Y-m-d');
$qtlimit  = (isset($_REQUEST['qtlimit']) && $_REQUEST['qtlimit'] !== '') ? $_REQUEST['qtlimit'] : 1;

// Handle datetime - check if time is already included
if (strpos($fromDate, ':') !== false) {
    // Already has time component
    $fromDateTime = $fromDate;
} else {
    // Date only, add default time
    $fromDateTime = $fromDate . ' 00:00:00';
}

if (strpos($toDate, ':') !== false) {
    // Already has time component
    $toDateTime = $toDate;
} else {
    // Date only, add default time
    $toDateTime = $toDate . ' 23:59:59';
}

$bypassCache  = isset($_REQUEST['bypass_cache']) && $_REQUEST['bypass_cache'] == 1;

// Retrieve extra filtering parameters
$onlyRef   = (isset($_REQUEST['only_ref']) && $_REQUEST['only_ref'] == 1);
$refFilter = isset($_REQUEST['ref_filter']) ? trim($_REQUEST['ref_filter']) : '';

// Retrieve visible md5s via POST (if provided)
$visibleMd5s = [];
if (!$onlyRef && isset($_REQUEST['visible_md5s']) && trim($_REQUEST['visible_md5s']) !== '') {
    $visibleMd5s = explode(',', $_REQUEST['visible_md5s']);
}

/**
 * Generate cache key based on request parameters
 */
function getCacheKey($action, $params) {
    ksort($params);
    return md5($action . '_' . json_encode($params));
}

/**
 * Get cached data if available and valid
 */
function getCachedData($cacheKey, $fromDate, $toDate, $bypassCache) {
    if (!CACHE_ENABLED || $bypassCache) {
        return null;
    }
    
    $cacheFile = CACHE_DIR . '/' . $cacheKey . '.json';
    
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    $cacheTime = $cacheData['timestamp'] ?? 0;
    $currentTime = time();
    
    // Check if cache includes current day
    $today = date('Y-m-d');
    $includesCurrentDay = ($toDate >= $today);
    
    if ($includesCurrentDay) {
        // Current day data: use short TTL
        if (($currentTime - $cacheTime) > CACHE_TTL_CURRENT_DAY) {
            return null; // Cache expired
        }
    }
    // Historical data: cache is always valid (data doesn't change)
    
    return $cacheData;
}

/**
 * Save data to cache
 */
function setCachedData($cacheKey, $data) {
    if (!CACHE_ENABLED) {
        return;
    }
    
    $cacheFile = CACHE_DIR . '/' . $cacheKey . '.json';
    $cacheData = [
        'timestamp' => time(),
        'data' => $data
    ];
    
    file_put_contents($cacheFile, json_encode($cacheData));
}

/**
 * Get human-readable cache age
 */
function getCacheAge($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return $diff . 's ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    } else {
        return floor($diff / 86400) . 'd ago';
    }
}

// Connect to DB
try {
    $pdo = new PDO("mysql:host=localhost;dbname=plqaylogiq;charset=utf8mb4", "plq", "plq", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => "Database connection failed: " . $e->getMessage()]);
    exit;
}

switch($action) {

case 'updateQueryInfo':
    // This action doesn't use cache
    $qtypemd5 = $_POST['qtypemd5'] ?? '';
    $instance = $_POST['instance'] ?? '';
    $refVal   = $_POST['ref'] ?? null;
    $notesVal = $_POST['notes'] ?? null;
    try {
        $sqlCheck = "SELECT COUNT(*) FROM query_type_info WHERE qtypemd5 = :qmd5";
        $stCheck = $pdo->prepare($sqlCheck);
        $stCheck->execute([':qmd5' => $qtypemd5]);
        $exists = $stCheck->fetchColumn();
        if ($exists) {
            $sqlUpdate = "UPDATE query_type_info SET ref = :refVal, notes = :notesVal WHERE qtypemd5 = :qmd5";
            $st = $pdo->prepare($sqlUpdate);
            $st->execute([
                ':qmd5'     => $qtypemd5,
                ':refVal'   => ($refVal === '' ? null : $refVal),
                ':notesVal' => ($notesVal === '' ? null : $notesVal),
            ]);
        } else {
            $sqlFetch = "SELECT qtype, instance FROM slow_query_log WHERE qtypemd5 = :qmd5 ORDER BY log_time DESC LIMIT 1";
            $stFetch = $pdo->prepare($sqlFetch);
            $stFetch->execute([':qmd5' => $qtypemd5]);
            $row = $stFetch->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $sqlInsert = "INSERT INTO query_type_info (qtypemd5, instance, qtype, ref, notes)
                              VALUES (:qmd5, :inst, :qtype, :refVal, :notesVal)";
                $st = $pdo->prepare($sqlInsert);
                $st->execute([
                    ':qmd5'     => $qtypemd5,
                    ':inst'     => $row['instance'],
                    ':qtype'    => $row['qtype'],
                    ':refVal'   => ($refVal === '' ? null : $refVal),
                    ':notesVal' => ($notesVal === '' ? null : $notesVal),
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'qtypemd5 not found in slow_query_log']);
                exit;
            }
        }
        echo json_encode(['success' => true]);
    } catch (Exception $ex) {
        echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
    }
    exit;
    break;

case 'getTableData':
    // Generate cache key
    $cacheParams = [
        'from' => $fromDate,
        'to' => $toDate,
        'qtlimit' => $qtlimit
    ];
    $cacheKey = getCacheKey($action, $cacheParams);
    
    // Try to get from cache
    $cachedData = getCachedData($cacheKey, $fromDate, $toDate, $bypassCache);
    
    if ($cachedData !== null) {
        // Return cached data
        $response = $cachedData['data'];
        $response['cache_info'] = [
            'from_cache' => true,
            'cache_age' => getCacheAge($cachedData['timestamp'])
        ];
        echo json_encode($response);
        exit;
    }
    
    // Query for oldest/newest log_time in range
    $sqlRange = "SELECT MIN(log_time) AS oldest, MAX(log_time) AS newest
                 FROM slow_query_log
                 WHERE log_time BETWEEN :fromDate AND :toDate";
    $stmtRange = $pdo->prepare($sqlRange);
    $stmtRange->execute([':fromDate' => $fromDateTime, ':toDate' => $toDateTime]);
    $range = $stmtRange->fetch(PDO::FETCH_ASSOC);

    // Main table query
    $sqlMain = "
        SELECT
          slow_query_log.instance,
          slow_query_log.qtype,
          slow_query_log.qtypemd5,
          slow_query_log.query,
          COALESCE(qt.ref, 'none') AS ref,
          qt.notes,
          qt.id,
          COUNT(*) AS total_count,
          SUM(slow_query_log.query_time) AS sum_query_time,
          AVG(slow_query_log.query_time) AS avg_query_time,
          SUM(slow_query_log.lock_time) AS sum_lock_time,
          AVG(slow_query_log.lock_time) AS avg_lock_time,
          AVG(slow_query_log.rows_sent) AS avg_rows_sent,
          AVG(slow_query_log.rows_examined) AS avg_rows_examined
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE slow_query_log.log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
        GROUP BY slow_query_log.instance, slow_query_log.qtypemd5
        ORDER BY total_count DESC, avg_query_time DESC
    ";
    $stmtMain = $pdo->prepare($sqlMain);
    $stmtMain->execute([':fromDate' => $fromDateTime, ':toDate' => $toDateTime]);
    $rows = $stmtMain->fetchAll(PDO::FETCH_ASSOC);

    $globalNoRefMapping = [];
    foreach ($rows as $r) {
        if ($r['ref'] === 'none') {
            if (!isset($globalNoRefMapping[$r['qtypemd5']])) {
                $globalNoRefMapping[$r['qtypemd5']] = sprintf("%03d", count($globalNoRefMapping) + 1);
            }
        }
    }

    // Build the HTML table
    ob_start();
    ?>
    <table id="myTable">
      <thead>
        <tr>
          <th>Ref</th>
          <th>DB</th>
          <th>Query Md5</th>
          <th>Query Type</th>
          <th>Count</th>
          <th>Sum Query Time</th>
          <th>Avg Query Time</th>
          <th>Sum Lock Time</th>
          <th>Avg Lock Time</th>
          <th>Avg Rows Sent</th>
          <th>Avg Rows Examined</th>
        </tr>
      </thead>
      <tbody>
      <?php 
      foreach ($rows as $r):
          $fullQtype  = $r['qtype'];
          $shortQtype = preg_replace('/\n/',' ',$fullQtype);
          if ($r['ref'] === 'none') {
               if (!isset($globalNoRefMapping[$r['qtypemd5']])) {
                   $globalNoRefMapping[$r['qtypemd5']] = sprintf("%03d", count($globalNoRefMapping) + 1);
               }
               $refDisplay = "NOREF-" . $r['id'];
          } else {
               $refDisplay = $r['ref'];
          }
      ?>
        <tr data-qtypemd5="<?= htmlspecialchars($r['qtypemd5']) ?>">
          <td>
            <a href="#" 
               class="editRefLink"
               data-qtypemd5="<?= htmlspecialchars($r['qtypemd5']) ?>"
               data-instance="<?= htmlspecialchars($r['instance']) ?>"
               data-qtype="<?= htmlspecialchars($r['qtype']) ?>"
               data-ref="<?= htmlspecialchars($r['ref']) ?>"
               data-notes="<?= htmlspecialchars($r['notes']) ?>">
              <?= htmlspecialchars($refDisplay) ?>
            </a>
          </td>
          <td><?= htmlspecialchars($r['instance']) ?></td>
          <td class="center"><?= htmlspecialchars($r['qtypemd5']) ?></td>
          <td>
            <span class="sqltype" title="<?= htmlspecialchars($fullQtype) ?>" data-query="<?= htmlspecialchars($r['query']) ?>">
              <?= htmlspecialchars($shortQtype) ?>
            </span>
          </td>
          <td class="right"><?= (int)$r['total_count'] ?></td>
          <td class="right"><?= number_format($r['sum_query_time'], 6) ?></td>
          <td class="right"><?= number_format($r['avg_query_time'], 6) ?></td>
          <td class="right"><?= number_format($r['sum_lock_time'], 6) ?></td>
          <td class="right"><?= number_format($r['avg_lock_time'], 6) ?></td>
          <td class="right"><?= number_format($r['avg_rows_sent'], 2) ?></td>
          <td class="right"><?= number_format($r['avg_rows_examined'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    $html = ob_get_clean();

    $response = [
      'success'  => true,
      'html'     => $html,
      'range'    => $range,
      'qtlimit'  => $qtlimit,
      'cache_info' => [
          'from_cache' => false,
          'cache_age' => 'just now'
      ]
    ];
    
    // Cache the response
    setCachedData($cacheKey, $response);
    
    echo json_encode($response);
    exit;
    break;

    ////////// GRAPH GENERATION FUNCTION //////////
    
    case 'getDailyGraphData':
    case 'getDailyGraphDataTime':
    case 'getDailyGraphLock':
    case 'getHourlyGraphData':
    case 'getHourlyGraphDataTime':
    case 'getHourlyGraphLock':
    case 'getMinuteGraphData':
    case 'getMinuteGraphDataTime':
    case 'getMinuteGraphLock':
    case 'getSecondGraphData':
    case 'getSecondGraphDataTime':
    case 'getSecondGraphLock':
        
        // Determine granularity and metric from action
        $granularityMap = [
            'Daily' => 'daily',
            'Hourly' => 'hourly',
            'Minute' => 'minute',
            'Second' => 'second'
        ];
        
        $metricMap = [
            'DataTime' => 'time',  // Check this BEFORE 'Data' to match the more specific pattern first
            'Data' => 'count',
            'Lock' => 'lock'
        ];
        
        $granularity = null;
        $metric = null;
        
        foreach ($granularityMap as $key => $value) {
            if (strpos($action, $key) !== false) {
                $granularity = $value;
                break;
            }
        }
        
        foreach ($metricMap as $key => $value) {
            if (strpos($action, $key) !== false) {
                $metric = $value;
                break;
            }
        }
        
        // Generate cache key
        $cacheParams = [
            'action' => $action,
            'from' => $fromDate,
            'to' => $toDate,
            'qtlimit' => $qtlimit,
            'only_ref' => $onlyRef,
            'ref_filter' => $refFilter,
            'visible_md5s' => implode(',', $visibleMd5s)
        ];
        $cacheKey = getCacheKey($action, $cacheParams);
        
        // Try to get from cache
        $cachedData = getCachedData($cacheKey, $fromDate, $toDate, $bypassCache);
        
        if ($cachedData !== null) {
            $response = $cachedData['data'];
            $response['cache_info'] = [
                'from_cache' => true,
                'cache_age' => getCacheAge($cachedData['timestamp'])
            ];
            echo json_encode($response);
            exit;
        }
        
        // Build time labels based on granularity
        $labels = [];
        $dateFormat = '';
        $sqlDateFormat = '';
        
        switch ($granularity) {
            case 'daily':
                $dateFormat = 'Y-m-d';
                $sqlDateFormat = '%Y-%m-%d';
                $start = new DateTime($fromDate);
                $end = new DateTime($toDate);
                $end->modify('+1 day');
                while ($start < $end) {
                    $labels[] = $start->format($dateFormat);
                    $start->modify('+1 day');
                }
                break;
                
            case 'hourly':
                $dateFormat = 'Y-m-d H:00:00';
                $sqlDateFormat = '%Y-%m-%d %H:00:00';
                $start = new DateTime($fromDateTime);
                $end = new DateTime($toDateTime);
                $end->modify('+1 hour');
                while ($start < $end) {
                    $labels[] = $start->format($dateFormat);
                    $start->modify('+1 hour');
                }
                break;
                
            case 'minute':
                $dateFormat = 'Y-m-d H:i:00';
                $sqlDateFormat = '%Y-%m-%d %H:%i:00';
                $start = new DateTime($fromDateTime);
                $end = new DateTime($toDateTime);
                $end->modify('+1 minute');
                while ($start < $end) {
                    $labels[] = $start->format($dateFormat);
                    $start->modify('+1 minute');
                }
                break;
                
            case 'second':
                $dateFormat = 'Y-m-d H:i:s';
                $sqlDateFormat = '%Y-%m-%d %H:%i:%s';
                $start = new DateTime($fromDateTime);
                $end = new DateTime($toDateTime);
                $end->modify('+1 second');
                while ($start < $end) {
                    $labels[] = $start->format($dateFormat);
                    $start->modify('+1 second');
                }
                break;
        }
        
        // Build SQL based on metric
        $metricColumn = '';
        $metricAlias = '';
        
        switch ($metric) {
            case 'count':
                $metricColumn = 'COUNT(*)';
                $metricAlias = 'metric_value';
                break;
            case 'time':
                $metricColumn = 'SUM(slow_query_log.query_time)';
                $metricAlias = 'metric_value';
                break;
            case 'lock':
                $metricColumn = 'SUM(slow_query_log.lock_time)';
                $metricAlias = 'metric_value';
                break;
        }
        
        $sqlGraph = "
            SELECT 
              DATE_FORMAT(log_time, '$sqlDateFormat') AS time_label,
              slow_query_log.qtypemd5,
              slow_query_log.qtype,
              if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
              $metricColumn AS $metricAlias
            FROM slow_query_log
            LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
            WHERE log_time BETWEEN :fromDate AND :toDate
              AND slow_query_log.query_time >= $qtlimit
        ";
        
        if ($onlyRef) {
            $sqlGraph .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
        }
        
        if ($onlyRef && $refFilter !== '') {
            $sqlGraph .= " AND qt.ref LIKE :refFilter ";
        }
        
        if (!$onlyRef && !empty($visibleMd5s)) {
            $md5Placeholders = [];
            foreach ($visibleMd5s as $i => $md5) {
                $placeholder = ':md5_' . $i;
                $md5Placeholders[] = $placeholder;
            }
            $placeholders = implode(',', $md5Placeholders);
            $sqlGraph .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
        }
        
        $sqlGraph .= " GROUP BY time_label, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY time_label ASC ";
        
        $stmtGraph = $pdo->prepare($sqlGraph);
        
        $paramsArray = [
            ':fromDate' => $fromDateTime,
            ':toDate'   => $toDateTime
        ];
        if ($onlyRef && $refFilter !== '') {
            $paramsArray[':refFilter'] = '%' . $refFilter . '%';
        }
        if (!$onlyRef && !empty($visibleMd5s)) {
            foreach ($visibleMd5s as $i => $md5) {
                $paramsArray[':md5_' . $i] = trim($md5);
            }
        }
        
        $stmtGraph->execute($paramsArray);
        $graphRows = $stmtGraph->fetchAll(PDO::FETCH_ASSOC);
        
        $datasets = [];
        foreach ($graphRows as $row) {
            $ref = $row['ref'];
            if (!$ref && $onlyRef) continue;
            $key = $ref;
            if (!isset($datasets[$key])) {
                $datasets[$key] = [
                    'label' => $ref,
                    'fullQuery'   => $row['qtype'],
                    'data'        => array_fill(0, count($labels), 0)
                ];
            }
            $index = array_search($row['time_label'], $labels);
            if ($index !== false) {
                $datasets[$key]['data'][$index] += ($metric === 'count') ? (int)$row['metric_value'] : (float)$row['metric_value'];
            }
        }
        
        $response = [
            'success'   => true,
            'chartData' => ['labels' => $labels, 'datasets' => array_values($datasets)],
            'cache_info' => [
                'from_cache' => false,
                'cache_age' => 'just now'
            ]
        ];
        
        // Cache the response
        setCachedData($cacheKey, $response);
        
        echo json_encode($response);
        exit;
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
?>
