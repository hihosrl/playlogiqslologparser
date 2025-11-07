<?php
// slowqueryanalyze6.php - Enhanced version with minute/second granularity and caching
// Parse GET parameters for from/to and qtlimit
$fromDate = !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-1 day'));
$toDate   = !empty($_GET['to'])   ? $_GET['to']   : date('Y-m-d');
$fromTime = !empty($_GET['from_time']) ? $_GET['from_time'] : '00:00';
$toTime   = !empty($_GET['to_time']) ? $_GET['to_time'] : '23:59';
$qtlimit  = (isset($_GET['qtlimit']) && $_GET['qtlimit'] !== '') ? $_GET['qtlimit'] : 1;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Slow Query Stats v6 (Enhanced)</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.3/css/jquery.dataTables.min.css"/>
    <link rel="stylesheet" href="slowqueryanalyze6.css"/>
    <style>
      /* Basic styling for modal & container */
      #chartModal { display: none; position: fixed; top: 10%; left: 10%; background: #fff; padding: 1em; border: 1px solid #ccc; z-index: 1000; }
      #modalBackdrop { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 900; }
    </style>
</head>
<body>
    <div id="currentStatus" style="position: absolute; top: 10px; right: 10px;"></div>
    <div id="cacheStatus" style="position: absolute; top: 35px; right: 10px; font-size: 11px; color: #666;"></div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" style="display: none;">
        <div class="loading-content">
            <div class="spinner"></div>
            <div id="loadingMessage">Loading data...</div>
            <button id="cancelLoadingBtn" class="cancel-btn">✖ Cancel</button>
        </div>
    </div>
    
    <h1>Slow Query Stats v6 <span style="font-size: 14px; color: #666;">(with Caching & Enhanced Granularity)</span></h1>

    <div class="graph-filter">
      <label for="graphRefFilter">Graph Ref Filter:</label>
      <input type="text" id="graphRefFilter" placeholder="Enter ref (e.g. BO08 or *)">
      <label>
        <input type="checkbox" id="useOnlyReferenced" checked>
        Graph only ref set
      </label>
      
      <div style="margin-top: 10px; padding: 10px; background: #f0f0f0; border: 1px solid #ccc;">
        <strong>Granularity:</strong>
        <label><input type="radio" name="granularity" value="daily" checked> Daily</label>
        <label><input type="radio" name="granularity" value="hourly"> Hourly</label>
        <label><input type="radio" name="granularity" value="minute"> By Minute</label>
        <label><input type="radio" name="granularity" value="second"> By Second</label>
        <span style="color: #d00; font-size: 11px; margin-left: 10px;">
          ⚠ Second granularity recommended only for short time ranges (&lt;1 day)
        </span>
      </div>

      <!-- Graph buttons -->
      <div style="margin-top: 10px;">
        <button id="showGraphCountBtn" class="graph-btn">📊 Query Count</button>
        <button id="showGraphTimeBtn" class="graph-btn">⏱️ Query Time</button>
        <button id="showGraphLockBtn" class="graph-btn">🔒 Lock Time</button>
      </div>
      
      <div style="margin-top: 5px; font-size: 11px; color: #666;">
        <label>
          <input type="checkbox" id="bypassCache">
          Bypass cache (force fresh query)
        </label>
      </div>
    </div>

    <form method="GET" id="filterForm" class="filter-form">
        <div class="datetime-group">
            <label for="from">From:</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($fromDate) ?>">
            <input type="time" id="from_time" name="from_time" value="<?= htmlspecialchars($fromTime) ?>" step="60">
        </div>
        <div class="datetime-group">
            <label for="to">To:</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($toDate) ?>">
            <input type="time" id="to_time" name="to_time" value="<?= htmlspecialchars($toTime) ?>" step="60">
        </div>
        <div class="qtlimit-group">
            <label for="qtlimit">Query Time >= :</label>
            <input type="number" id="qtlimit" name="qtlimit" value="<?= htmlspecialchars($qtlimit) ?>">
        </div>
        <button type="submit">Apply</button>
    </form>

    <!-- Container for table data loaded via AJAX -->
    <div id="tableContainer">
      <!-- The table will be injected here -->
    </div>

    <!-- Chart Modal -->
    <div id="modalBackdrop"></div>
    <div id="legendTooltip" style="position: absolute; pointer-events: none; background: rgba(0,0,0,0.8); color: #fff; padding: 5px 10px; border-radius: 3px; font-size: 12px; display: none;"></div>

    <div id="chartModal">
      <div style="margin-bottom: 10px;">
        <button id="closeChartBtn" style="float: right;">Close</button>
        <button id="resetZoomBtn" style="margin-right: 10px;">🔍 Reset Zoom</button>
        <button id="autoRegranulateBtn" style="margin-right: 10px;">🔄 Auto Re-granulate</button>
        <span id="zoomInfo" style="font-size: 11px; color: #2196F3; margin-left: 10px;"></span>
      </div>
      <div id="chartInfo" style="font-size: 11px; color: #666; margin-bottom: 10px; clear: both;"></div>
      <canvas id="chartCanvas"></canvas>
    </div>

    <!-- Modal for editing query info -->
    <div id="editModal">
      <h3>Edit Query Info</h3>
      <form id="editForm">
        <input type="text" readonly name="qtypemd5" id="qtypemd5Input">
        <div class="oldQueryType" id="qtypeInput"></div>
        <input type="hidden" name="instance" id="instanceInput">
        <p><label>Ref:</label> <input type="text" name="ref" id="refInput"></p>
        <p><label>Notes:</label> <textarea name="notes" id="notesInput"></textarea></p>
      </form>
      <div class="buttons">
        <button type="button" id="saveBtn">Save</button>
        <button type="button" id="closeBtn">Close</button>
      </div>
    </div>

    <!-- Include libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.3/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8"></script>
    <!-- Custom JavaScript -->
    <script src="slowqueryanalyze6.js"></script>
    <script>
      // Pass filter parameters to JavaScript for AJAX calls
      var filterParams = {
          from: '<?= htmlspecialchars($fromDate) ?>',
          to: '<?= htmlspecialchars($toDate) ?>',
          from_time: '<?= htmlspecialchars($fromTime) ?>',
          to_time: '<?= htmlspecialchars($toTime) ?>',
          qtlimit: '<?= htmlspecialchars($qtlimit) ?>'
      };
    </script>
</body>
</html>
