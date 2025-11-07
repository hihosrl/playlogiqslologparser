<?php
// index.php
// Parse GET parameters for from/to and qtlimit
$fromDate = !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-1 day'));
$toDate   = !empty($_GET['to'])   ? $_GET['to']   : date('Y-m-d');
$qtlimit  = (isset($_GET['qtlimit']) && $_GET['qtlimit'] !== '') ? $_GET['qtlimit'] : 1;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Slow Query Stats</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.3/css/jquery.dataTables.min.css"/>
    <link rel="stylesheet" href="slowqueryanalyze.css"/>
    <style>
      /* Basic styling for modal & container */
      #chartModal { display: none; position: fixed; top: 10%; left: 10%; background: #fff; padding: 1em; border: 1px solid #ccc; z-index: 1000; }
      #modalBackdrop { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 900; }
    </style>
</head>
<body>
    <div id="currentStatus" style="position: absolute; top: 10px; right: 10px;"></div>
    <h1>Slow Query Stats</h1>

    <div class="graph-filter">
      <label for="graphRefFilter">Graph Ref Filter:</label>
      <input type="text" id="graphRefFilter" placeholder="Enter ref (e.g. BO08 or *)">
      <label>
        <input type="checkbox" id="useOnlyReferenced" checked>
        Graph only ref set
      </label>
      <!-- Daily graphs -->
      <button id="showDailyGraphBtn">Daily Count </button>
      <button id="showHourlyGraphBtn">Hourly Count </button>
      <button id="showDailyGraphTimeBtn">Daily Query Time</button>
      <button id="showHourlyGraphTimeBtn">Hourly Query Time</button>
      <button id="showDailyGraphLockBtn">Daily Lock Time</button>
      <button id="showHourlyGraphLockBtn">Hourly Lock Time</button>
      <!-- Hourly graphs -->
    </div>

    <form method="GET" id="filterForm" class="filter-form">
        <label for="from">From:</label>
        <input type="date" id="from" name="from" value="<?= htmlspecialchars($fromDate) ?>">
        <label for="to">To:</label>
        <input type="date" id="to" name="to" value="<?= htmlspecialchars($toDate) ?>">
        <label for="qtlimit">Query Time >= :</label>
        <input type="number" id="qtlimit" name="qtlimit" value="<?= htmlspecialchars($qtlimit) ?>">
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
      <button id="closeChartBtn">Close</button>
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
    <!-- Custom JavaScript -->
    <script src="slowqueryanalyze_async.js"></script>
    <script>
      // Pass filter parameters to JavaScript for AJAX calls
      var filterParams = {
          from: '<?= htmlspecialchars($fromDate) ?>',
          to: '<?= htmlspecialchars($toDate) ?>',
          qtlimit: '<?= htmlspecialchars($qtlimit) ?>'
      };
    </script>
</body>
</html>
