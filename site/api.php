<?php
// api.php
header('Content-Type: application/json');

// Get the action parameter
$action = $_REQUEST['action'] ?? '';

// Parse filter parameters
$fromDate = !empty($_REQUEST['from']) ? $_REQUEST['from'] : date('Y-m-d', strtotime('-1 day'));
$toDate   = !empty($_REQUEST['to'])   ? $_REQUEST['to']   : date('Y-m-d');
$qtlimit  = (isset($_REQUEST['qtlimit']) && $_REQUEST['qtlimit'] !== '') ? $_REQUEST['qtlimit'] : 1;
$fromDateTime = $fromDate . ' 00:00:00';
$toDateTime   = $toDate   . ' 23:59:59';
// Retrieve extra filtering parameters:
    $onlyRef   = (isset($_REQUEST['only_ref']) && $_REQUEST['only_ref'] == 1);
    $refFilter = isset($_REQUEST['ref_filter']) ? trim($_REQUEST['ref_filter']) : '';

    // Retrieve visible md5s via POST (if provided)
    $visibleMd5s = [];
    if (!$onlyRef && isset($_REQUEST['visible_md5s']) && trim($_REQUEST['visible_md5s']) !== '') {
        // Expecting a comma-separated string.
        $visibleMd5s = explode(',', $_REQUEST['visible_md5s']);
    }


// Build arrays for dates and hours (for graph labels)
$days = [];
$start = new DateTime($fromDate);
$end = new DateTime($toDate);
$end->modify('+1 day');
while ($start < $end) {
    $days[] = $start->format('Y-m-d');
    $start->modify('+1 day');
}

$hours = [];
$startHour = new DateTime($fromDateTime);
$endHour = new DateTime($toDateTime);
$endHour->modify('+1 hour');
while ($startHour < $endHour) {
    $hours[] = $startHour->format('Y-m-d H:00:00');
    $startHour->modify('+1 hour');
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
    // Retrieve POST parameters
    $qtypemd5 = $_POST['qtypemd5'] ?? '';
    $instance = $_POST['instance'] ?? '';
    $refVal   = $_POST['ref'] ?? null;
    $notesVal = $_POST['notes'] ?? null;
    try {
        // Check if a record for this query type already exists
        $sqlCheck = "SELECT COUNT(*) FROM query_type_info WHERE qtypemd5 = :qmd5";
        $stCheck = $pdo->prepare($sqlCheck);
        $stCheck->execute([':qmd5' => $qtypemd5]);
        $exists = $stCheck->fetchColumn();
        if ($exists) {
            // Update existing record
            $sqlUpdate = "UPDATE query_type_info SET ref = :refVal, notes = :notesVal WHERE qtypemd5 = :qmd5";
            $st = $pdo->prepare($sqlUpdate);
            $st->execute([
                ':qmd5'     => $qtypemd5,
                ':refVal'   => ($refVal === '' ? null : $refVal),
                ':notesVal' => ($notesVal === '' ? null : $notesVal),
            ]);
        } else {
            // Fetch qtype and instance from the slow_query_log table as a fallback
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
    // Query for oldest/newest log_time in range
    $sqlRange = "SELECT MIN(log_time) AS oldest, MAX(log_time) AS newest
                 FROM slow_query_log
                 WHERE log_time BETWEEN :fromDate AND :toDate";
    $stmtRange = $pdo->prepare($sqlRange);
    $stmtRange->execute([':fromDate' => $fromDateTime, ':toDate' => $toDateTime]);
    $range = $stmtRange->fetch(PDO::FETCH_ASSOC);

    // Main table query (similar to your original code)
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

    // Build a mapping for "no ref" labels
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

    // Return table HTML along with range and qtlimit info
    echo json_encode([
      'success'  => true,
      'html'     => $html,
      'range'    => $range,
      'qtlimit'  => $qtlimit
    ]);
    exit;
    break;

    ////////// DAILY GRAPH ENDPOINTS //////////

    case 'getDailyGraphData':
    // Daily graph: referenced only (count)
    $sqlGraph = "
        SELECT 
          DATE(log_time) AS log_date,
          slow_query_log.qtypemd5,
          slow_query_log.qtype,
          if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
          COUNT(*) AS daily_count
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
    ";
    // If the checkbox is checked, include only rows with a ref value.
    if ($onlyRef) {
        $sqlGraph .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
    }
    
    // If a ref filter value is provided, further limit results by that value.
    if ($onlyRef && $refFilter !== '') {
        $sqlGraph .= " AND qt.ref LIKE :refFilter ";
    }
    
    // If not using only_ref and a list of visible md5s is provided, restrict to those.
    if (!$onlyRef && !empty($visibleMd5s)) {
        // Generate named placeholders for each MD5 value.
        $md5Placeholders = [];
        foreach ($visibleMd5s as $i => $md5) {
            $placeholder = ':md5_' . $i;
            $md5Placeholders[] = $placeholder;
        }
        $placeholders = implode(',', $md5Placeholders);
        $sqlGraph .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
    }
    
    $sqlGraph .= " GROUP BY log_date, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY log_date ASC ";
    
    $stmtGraph = $pdo->prepare($sqlGraph);
    
    // Build parameters array using named parameters only.
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
                'data'        => array_fill(0, count($days), 0)
            ];
        }
        $index = array_search($row['log_date'], $days);
        if ($index !== false) {
            $datasets[$key]['data'][$index] += (int)$row['daily_count'];
        }
    }
    echo json_encode([
    'success'   => true,
    'sql'       => $sqlGraph,
    'params'    => $paramsArray,
    'chartData' => ['labels' => $days, 'datasets' => array_values($datasets)]
]);
    exit;
    break;

    
    case 'getDailyGraphDataTime':
    // Daily graph: SUM(query_time) for queries (only referenced if onlyRef is true)
    $sqlGraphTime = "
        SELECT 
          DATE(log_time) AS log_date,
          slow_query_log.qtypemd5,
          slow_query_log.qtype,
          if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
          SUM(slow_query_log.query_time) AS total_query_time
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
    ";
    // If only-ref mode is enabled, include only rows with a ref value.
    if ($onlyRef) {
        $sqlGraphTime .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
    }
    
    // If a ref filter is provided (and only-ref mode is enabled), further filter by that value.
    if ($onlyRef && $refFilter !== '') {
        $sqlGraphTime .= " AND qt.ref LIKE :refFilter ";
    }
    
    // If not in only-ref mode and a list of visible MD5s is provided, restrict to those.
    if (!$onlyRef && !empty($visibleMd5s)) {
        // Generate named placeholders for each MD5 value.
        $md5Placeholders = [];
        foreach ($visibleMd5s as $i => $md5) {
            $placeholder = ':md5_' . $i;
            $md5Placeholders[] = $placeholder;
        }
        $placeholders = implode(',', $md5Placeholders);
        $sqlGraphTime .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
    }
    
    $sqlGraphTime .= " GROUP BY log_date, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY log_date ASC ";
    
    $stmtGraphTime = $pdo->prepare($sqlGraphTime);
    
    // Build parameters array using only named parameters.
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
    
    $stmtGraphTime->execute($paramsArray);
    $graphRowsTime = $stmtGraphTime->fetchAll(PDO::FETCH_ASSOC);
    
    // Build datasets using the same "no ref" mapping logic as in getDailyGraphDataAll.
    $datasetsTime = [];
    $globalNoRefMapping = [];
    foreach ($graphRowsTime as $row) {
        $ref = $row['ref'];
        if (!$ref || strtolower($ref) === 'none') {
            $qmd5 = $row['qtypemd5'];
            if (isset($globalNoRefMapping[$qmd5])) {
                $mapping = $globalNoRefMapping[$qmd5];
            } else {
                $globalNoRefMapping[$qmd5] = sprintf("%03d", count($globalNoRefMapping) + 1);
                $mapping = $globalNoRefMapping[$qmd5];
            }
            $key = "no_ref_" . $mapping;
            $legendLabel = "no ref [" . $mapping . "]";
        } else {
            $key = $ref;
            $legendLabel = $ref;
        }
        if (!isset($datasetsTime[$key])) {
            $datasetsTime[$key] = [
                'label' => $legendLabel,
                'fullQuery'   => $row['qtype'],
                'data'        => array_fill(0, count($days), 0)
            ];
        }
        $index = array_search($row['log_date'], $days);
        if ($index !== false) {
            // Add the sum of query_time for that day.
            $datasetsTime[$key]['data'][$index] += (float)$row['total_query_time'];
        }
    }
    echo json_encode([
        'success' => true, 
        'chartData' => [
            'labels' => $days, 
            'datasets' => array_values($datasetsTime)
        ]
    ]);
    exit;
    break;

    
    case 'getDailyGraphLock':
    // Daily graph: SUM(lock_time)
    $sqlGraphLock = "
        SELECT 
          DATE(log_time) AS log_date,
          slow_query_log.qtypemd5,
          slow_query_log.qtype,
          if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
          SUM(slow_query_log.lock_time) AS total_lock_time
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
    ";
    
    // If only-ref mode is enabled, include only rows with a ref value.
    if ($onlyRef) {
        $sqlGraphLock .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
    }
    
    // If a ref filter value is provided (and only-ref mode is enabled), further limit results by that value.
    if ($onlyRef && $refFilter !== '') {
        $sqlGraphLock .= " AND qt.ref LIKE :refFilter ";
    }
    
    // If not in only-ref mode and a list of visible MD5s is provided, restrict to those.
    if (!$onlyRef && !empty($visibleMd5s)) {
        // Generate named placeholders for each MD5 value.
        $md5Placeholders = [];
        foreach ($visibleMd5s as $i => $md5) {
            $placeholder = ':md5_' . $i;
            $md5Placeholders[] = $placeholder;
        }
        $placeholders = implode(',', $md5Placeholders);
        $sqlGraphLock .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
    }
    
    $sqlGraphLock .= " GROUP BY log_date, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY log_date ASC ";
    
    $stmtGraphLock = $pdo->prepare($sqlGraphLock);
    
    // Build parameters array using only named parameters.
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
    
    $stmtGraphLock->execute($paramsArray);
    $graphRowsLock = $stmtGraphLock->fetchAll(PDO::FETCH_ASSOC);
    
    // Build datasets using the same "no ref" mapping logic as before.
    $datasetsLock = [];
    $globalNoRefMapping = [];
    foreach ($graphRowsLock as $row) {
        $ref = $row['ref'];
        if (!$ref || strtolower($ref) === 'none') {
            $qmd5 = $row['qtypemd5'];
            if (isset($globalNoRefMapping[$qmd5])) {
                $mapping = $globalNoRefMapping[$qmd5];
            } else {
                $globalNoRefMapping[$qmd5] = sprintf("%03d", count($globalNoRefMapping) + 1);
                $mapping = $globalNoRefMapping[$qmd5];
            }
            $key = "no_ref_" . $mapping;
            $legendLabel = "no ref [" . $mapping . "]";
        } else {
            $key = $ref;
            $legendLabel = $ref;
        }
        if (!isset($datasetsLock[$key])) {
            $datasetsLock[$key] = [
                'label'     => $legendLabel,
                'fullQuery' => $row['qtype'],
                'data'      => array_fill(0, count($days), 0)
            ];
        }
        $index = array_search($row['log_date'], $days);
        if ($index !== false) {
            $datasetsLock[$key]['data'][$index] += (float)$row['total_lock_time'];
        }
    }
    
    echo json_encode([
        'success'   => true,
        'chartData' => [
            'labels'   => $days,
            'datasets' => array_values($datasetsLock)
        ]
    ]);
    exit;
    break;


    ////////// HOURLY GRAPH ENDPOINTS //////////

    case 'getHourlyGraphData':
    // Build the hours array inside the function
    $hours = [];
    $startHour = new DateTime($fromDateTime);
    $endHour = new DateTime($toDateTime);
    // Include the last hour in the range.
    $endHour->modify('+1 hour');
    while ($startHour < $endHour) {
        $hours[] = $startHour->format('Y-m-d H:00:00');
        $startHour->modify('+1 hour');
    }
    
    // Hourly graph: referenced only (count)
    $sqlGraphHourly = "
        SELECT 
          DATE_FORMAT(log_time, '%Y-%m-%d %H:00:00') AS log_hour,
          slow_query_log.qtypemd5,
          slow_query_log.qtype,
          if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
          COUNT(*) AS hourly_count
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
    ";
    
    // If only-ref mode is enabled, include only rows with a ref value.
    if ($onlyRef) {
        $sqlGraphHourly .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
    }
    
    // If a ref filter is provided (and only-ref mode is enabled), further filter by that value.
    if ($onlyRef && $refFilter !== '') {
        $sqlGraphHourly .= " AND qt.ref LIKE :refFilter ";
    }
    
    // If not in only-ref mode and a list of visible MD5s is provided, restrict to those.
    if (!$onlyRef && !empty($visibleMd5s)) {
        // Generate named placeholders for each MD5 value.
        $md5Placeholders = [];
        foreach ($visibleMd5s as $i => $md5) {
            $placeholder = ':md5_' . $i;
            $md5Placeholders[] = $placeholder;
        }
        $placeholders = implode(',', $md5Placeholders);
        $sqlGraphHourly .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
    }
    
    $sqlGraphHourly .= " GROUP BY log_hour, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY log_hour ASC ";
    
    $stmtGraphHourly = $pdo->prepare($sqlGraphHourly);
    
    // Build parameters array using named parameters only.
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
    
    $stmtGraphHourly->execute($paramsArray);
    $graphRowsHourly = $stmtGraphHourly->fetchAll(PDO::FETCH_ASSOC);
    
    // Build datasets, using the same "no ref" mapping logic as in your daily endpoints.
    $datasetsHourly = [];
    $globalNoRefMapping = [];
    foreach ($graphRowsHourly as $row) {
        $ref = $row['ref'];
        if (!$ref || strtolower($ref) === 'none') {
            $qmd5 = $row['qtypemd5'];
            if (isset($globalNoRefMapping[$qmd5])) {
                $mapping = $globalNoRefMapping[$qmd5];
            } else {
                $globalNoRefMapping[$qmd5] = sprintf("%03d", count($globalNoRefMapping) + 1);
                $mapping = $globalNoRefMapping[$qmd5];
            }
            $key = "no_ref_" . $mapping;
            $legendLabel = "no ref [" . $mapping . "]";
        } else {
            $key = $ref;
            $legendLabel = $ref;
        }
        if (!isset($datasetsHourly[$key])) {
            $datasetsHourly[$key] = [
                'label'     => $legendLabel,
                'fullQuery' => $row['qtype'],
                'data'      => array_fill(0, count($hours), 0)
            ];
        }
        $index = array_search($row['log_hour'], $hours);
        if ($index !== false) {
            $datasetsHourly[$key]['data'][$index] += (int)$row['hourly_count'];
        }
    }
    echo json_encode([
        'success'   => true,
        'chartData' => [
            'labels'   => $hours,
            'datasets' => array_values($datasetsHourly)
        ]
    ]);
    exit;
    break;


    

    case 'getHourlyGraphDataTime':
    // Build the hours array locally
    $hours = [];
    $startHour = new DateTime($fromDateTime);
    $endHour = new DateTime($toDateTime);
    $endHour->modify('+1 hour'); // include final hour
    while ($startHour < $endHour) {
        $hours[] = $startHour->format('Y-m-d H:00:00');
        $startHour->modify('+1 hour');
    }
    
    // Hourly graph: SUM(query_time) for queries
    $sqlGraphHourlyTime = "
        SELECT 
          DATE_FORMAT(log_time, '%Y-%m-%d %H:00:00') AS log_hour,
          slow_query_log.qtypemd5,
          slow_query_log.qtype,
          if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
          SUM(slow_query_log.query_time) AS total_query_time
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
    ";
    
    // Apply filtering conditions:
    if ($onlyRef) {
        $sqlGraphHourlyTime .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
    }
    if ($onlyRef && $refFilter !== '') {
        $sqlGraphHourlyTime .= " AND qt.ref LIKE :refFilter ";
    }
    if (!$onlyRef && !empty($visibleMd5s)) {
        $md5Placeholders = [];
        foreach ($visibleMd5s as $i => $md5) {
            $placeholder = ':md5_' . $i;
            $md5Placeholders[] = $placeholder;
        }
        $placeholders = implode(',', $md5Placeholders);
        $sqlGraphHourlyTime .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
    }
    
    $sqlGraphHourlyTime .= " GROUP BY log_hour, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY log_hour ASC ";
    
    $stmtGraphHourlyTime = $pdo->prepare($sqlGraphHourlyTime);
    
    // Build parameters array using only named parameters.
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
    
    $stmtGraphHourlyTime->execute($paramsArray);
    $graphRowsHourlyTime = $stmtGraphHourlyTime->fetchAll(PDO::FETCH_ASSOC);
    
    // Build datasets using the same "no ref" mapping logic.
    $datasetsHourlyTime = [];
    $globalNoRefMapping = [];
    foreach ($graphRowsHourlyTime as $row) {
        $ref = $row['ref'];
        if (!$ref || strtolower($ref) === 'none') {
            $qmd5 = $row['qtypemd5'];
            if (isset($globalNoRefMapping[$qmd5])) {
                $mapping = $globalNoRefMapping[$qmd5];
            } else {
                $globalNoRefMapping[$qmd5] = sprintf("%03d", count($globalNoRefMapping) + 1);
                $mapping = $globalNoRefMapping[$qmd5];
            }
            $key = "no_ref_" . $mapping;
            $legendLabel = "no ref [" . $mapping . "]";
        } else {
            $key = $ref;
            $legendLabel = $ref;
        }
        if (!isset($datasetsHourlyTime[$key])) {
            $datasetsHourlyTime[$key] = [
                'label'     => $legendLabel,
                'fullQuery' => $row['qtype'],
                'data'      => array_fill(0, count($hours), 0)
            ];
        }
        $index = array_search($row['log_hour'], $hours);
        if ($index !== false) {
            $datasetsHourlyTime[$key]['data'][$index] += (float)$row['total_query_time'];
        }
    }
    
    echo json_encode([
        'success'   => true,
        'chartData' => [
            'labels'   => $hours,
            'datasets' => array_values($datasetsHourlyTime)
        ]
    ]);
    exit;
    break;


    case 'getHourlyGraphLock':
    // Build the hours array locally from fromDateTime to toDateTime.
    $hours = [];
    $startHour = new DateTime($fromDateTime);
    $endHour = new DateTime($toDateTime);
    $endHour->modify('+1 hour'); // include the final hour
    while ($startHour < $endHour) {
        $hours[] = $startHour->format('Y-m-d H:00:00');
        $startHour->modify('+1 hour');
    }
    
    // Hourly graph: SUM(lock_time)
    $sqlGraphHourlyLock = "
        SELECT 
          DATE_FORMAT(log_time, '%Y-%m-%d %H:00:00') AS log_hour,
          slow_query_log.qtypemd5,
          slow_query_log.qtype,
          if (qt.ref is not null,qt.ref,concat('NOREF-',qt.id)) as ref,
          SUM(slow_query_log.lock_time) AS total_lock_time
        FROM slow_query_log
        LEFT JOIN query_type_info qt ON slow_query_log.qtypemd5 = qt.qtypemd5
        WHERE log_time BETWEEN :fromDate AND :toDate
          AND slow_query_log.query_time >= $qtlimit
    ";
    
    // If only-ref mode is enabled, include only rows with a ref value.
    if ($onlyRef) {
        $sqlGraphHourlyLock .= " AND COALESCE(qt.ref, 'none') <> 'none' ";
    }
    
    // If a ref filter value is provided (and only-ref mode is enabled), further limit results by that value.
    if ($onlyRef && $refFilter !== '') {
        $sqlGraphHourlyLock .= " AND qt.ref LIKE :refFilter ";
    }
    
    // If not in only-ref mode and a list of visible MD5s is provided, restrict to those.
    if (!$onlyRef && !empty($visibleMd5s)) {
        // Generate named placeholders for each MD5 value.
        $md5Placeholders = [];
        foreach ($visibleMd5s as $i => $md5) {
            $placeholder = ':md5_' . $i;
            $md5Placeholders[] = $placeholder;
        }
        $placeholders = implode(',', $md5Placeholders);
        $sqlGraphHourlyLock .= " AND slow_query_log.qtypemd5 IN ($placeholders) ";
    }
    
    $sqlGraphHourlyLock .= " GROUP BY log_hour, slow_query_log.qtypemd5, slow_query_log.qtype, ref ORDER BY log_hour ASC ";
    
    $stmtGraphHourlyLock = $pdo->prepare($sqlGraphHourlyLock);
    
    // Build parameters array using named parameters.
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
    
    $stmtGraphHourlyLock->execute($paramsArray);
    $graphRowsHourlyLock = $stmtGraphHourlyLock->fetchAll(PDO::FETCH_ASSOC);
    
    // Build datasets with the same "no ref" mapping logic.
    $datasetsHourlyLock = [];
    $globalNoRefMapping = [];
    foreach ($graphRowsHourlyLock as $row) {
        $ref = $row['ref'];
        if (!$ref || strtolower($ref) === 'none') {
            $qmd5 = $row['qtypemd5'];
            if (isset($globalNoRefMapping[$qmd5])) {
                $mapping = $globalNoRefMapping[$qmd5];
            } else {
                $globalNoRefMapping[$qmd5] = sprintf("%03d", count($globalNoRefMapping) + 1);
                $mapping = $globalNoRefMapping[$qmd5];
            }
            $key = "no_ref_" . $mapping;
            $legendLabel = "no ref [" . $mapping . "]";
        } else {
            $key = $ref;
            $legendLabel = $ref;
        }
        if (!isset($datasetsHourlyLock[$key])) {
            $datasetsHourlyLock[$key] = [
                'label'     => $legendLabel,
                'fullQuery' => $row['qtype'],
                'data'      => array_fill(0, count($hours), 0)
            ];
        }
        $index = array_search($row['log_hour'], $hours);
        if ($index !== false) {
            $datasetsHourlyLock[$key]['data'][$index] += (float)$row['total_lock_time'];
        }
    }
    
    echo json_encode([
        'success'   => true,
        'chartData' => [
            'labels'   => $hours,
            'datasets' => array_values($datasetsHourlyLock)
        ]
    ]);
    exit;
    break;


    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
?>
