<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ASG Instance Monitor</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.3/css/jquery.dataTables.min.css"/>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        
        h1 {
            color: #333;
            border-bottom: 2px solid #2196F3;
            padding-bottom: 10px;
        }
        
        .filter-form {
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: inline-block;
            width: 120px;
            font-weight: bold;
            color: #555;
        }
        
        .form-group input[type="text"] {
            padding: 8px 12px;
            width: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-group input[type="text"]:focus {
            outline: none;
            border-color: #2196F3;
            box-shadow: 0 0 5px rgba(33, 150, 243, 0.3);
        }
        
        button[type="submit"] {
            background: #2196F3;
            color: white;
            padding: 10px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        
        button[type="submit"]:hover {
            background: #1976D2;
        }
        
        button[type="submit"]:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        #loadingOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #2196F3;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        #tableContainer {
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        #summaryBox {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        #summaryBox h3 {
            margin-top: 0;
            color: #1976D2;
        }
        
        #summaryBox .stat-row {
            display: inline-block;
            margin-right: 30px;
            margin-bottom: 5px;
        }
        
        #summaryBox .stat-label {
            font-weight: bold;
            color: #555;
        }
        
        #summaryBox .stat-value {
            color: #2196F3;
            font-weight: bold;
        }
        
        .error-box {
            background: #ffebee;
            border-left: 4px solid #f44336;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #c62828;
        }
        
        table.dataTable {
            font-size: 10px;
        }
        
        table.dataTable tbody td {
            padding: 3px 4px;
            white-space: nowrap;
        }
        
        table.dataTable thead th {
            background: #2196F3;
            color: white;
            font-weight: bold;
            padding: 4px 6px;
            font-size: 10px;
            white-space: nowrap;
        }
        
        .status-ok {
            color: #4CAF50;
            font-weight: bold;
        }
        
        .status-error {
            color: #f44336;
            font-weight: bold;
        }
        
        .high-load {
            background-color: #fff3cd !important;
        }
        
        .critical-load {
            background-color: #f8d7da !important;
        }
    </style>
</head>
<body>
    <h1>ASG Instance Monitor 📊</h1>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" style="display: none;">
        <div class="loading-content">
            <div class="spinner"></div>
            <div id="loadingMessage">Fetching instance data...</div>
        </div>
    </div>
    
    <div class="filter-form">
        <form id="monitorForm">
            <div class="form-group">
                <label for="asgName">ASG Name:</label>
                <input type="text" id="asgName" name="asgName" placeholder="e.g., asg-betmaker-pro" required>
            </div>
            <div class="form-group">
                <button type="submit" id="submitBtn">🔍 Monitor Instances</button>
            </div>
        </form>
        <div style="margin-top: 10px; font-size: 12px; color: #666;">
            <strong>Examples:</strong> asg-betmaker-pro, asg-betmaker-crons
        </div>
    </div>
    
    <div id="resultsContainer" style="display: none;">
        <div id="summaryBox"></div>
        <div id="tableContainer"></div>
    </div>

    <!-- Include libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.3/js/jquery.dataTables.min.js"></script>
    
    <script>
        $(document).ready(function() {
            let dataTable = null;
            
            $('#monitorForm').on('submit', function(e) {
                e.preventDefault();
                
                const asgName = $('#asgName').val().trim();
                if (!asgName) {
                    alert('Please enter an ASG name');
                    return;
                }
                
                // Show loading
                $('#loadingOverlay').show();
                $('#submitBtn').prop('disabled', true);
                $('#resultsContainer').hide();
                
                // Fetch data
                $.ajax({
                    url: 'asgMonitorAPI.php',
                    method: 'GET',
                    data: { asg: asgName },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            displayResults(response.data);
                        } else {
                            displayError(response.error || 'Unknown error occurred');
                        }
                    },
                    error: function(xhr, status, error) {
                        displayError('Failed to fetch data: ' + error);
                    },
                    complete: function() {
                        $('#loadingOverlay').hide();
                        $('#submitBtn').prop('disabled', false);
                    }
                });
            });
            
            function displayResults(data) {
                // Show summary
                let summaryHTML = '<h3>Summary for ASG: ' + data.asg_name + '</h3>';
                summaryHTML += '<div class="stat-row"><span class="stat-label">Total Instances:</span> <span class="stat-value">' + data.summary.total_instances + '</span></div>';
                summaryHTML += '<div class="stat-row"><span class="stat-label">Active Connections:</span> <span class="stat-value">' + data.summary.total_active_connections + '</span></div>';
                summaryHTML += '<div class="stat-row"><span class="stat-label">Busy Workers:</span> <span class="stat-value">' + data.summary.total_busy_workers + '</span></div>';
                summaryHTML += '<div class="stat-row"><span class="stat-label">Idle Workers:</span> <span class="stat-value">' + data.summary.total_idle_workers + '</span></div>';
                summaryHTML += '<div class="stat-row"><span class="stat-label">Total Req/sec:</span> <span class="stat-value">' + data.summary.total_req_per_sec.toFixed(2) + '</span></div>';
                summaryHTML += '<div style="margin-top: 10px; font-size: 11px; color: #666;">Last updated: ' + data.timestamp + '</div>';
                $('#summaryBox').html(summaryHTML);
                
                // Build table
                if (dataTable) {
                    dataTable.destroy();
                }
                
                let tableHTML = '<table id="instancesTable" class="display" style="width:100%"><thead><tr>';
                
                // Define column order and short names
                const columns = ['InstanceID', 'IP', 'OK', 'ActiveConnections', 'BusyWorkers', 'IdleWorkers', 
                               'CPULoad', 'ReqPerSec', 'BytesPerSec', 'BytesPerReq', 'TotalAccesses',
                               'ConnsTotal', 'ConnsAsyncKeepAlive', 'Score_Reading', 'Score_Writing', 
                               'Score_Keepalive', 'Score_Starting', 'Score_Closing', 'Score_Dead',
                               'Score_OpenSlot', 'Uptime', 'Error'];
                
                // Short names for display
                const shortNames = {
                    'InstanceID': 'InstID',
                    'ActiveConnections': 'ActConn',
                    'BusyWorkers': 'Busy',
                    'IdleWorkers': 'Idle',
                    'CPULoad': 'CPU',
                    'ReqPerSec': 'Req/s',
                    'BytesPerSec': 'B/s',
                    'BytesPerReq': 'B/Req',
                    'TotalAccesses': 'TotAcc',
                    'ConnsTotal': 'ConnT',
                    'ConnsAsyncKeepAlive': 'AsyncKA',
                    'Score_Reading': 'Read',
                    'Score_Writing': 'Write',
                    'Score_Keepalive': 'KA',
                    'Score_Starting': 'Start',
                    'Score_Closing': 'Close',
                    'Score_Dead': 'Dead',
                    'Score_OpenSlot': 'Open'
                };
                
                columns.forEach(col => {
                    const displayName = shortNames[col] || col;
                    tableHTML += '<th title="' + col + '">' + displayName + '</th>';
                });
                tableHTML += '</tr></thead><tbody>';
                
                // Add rows
                data.instances.forEach(instance => {
                    let rowClass = '';
                    if (instance.OK === 1) {
                        // Highlight high load
                        if (instance.BusyWorkers > 40) {
                            rowClass = 'critical-load';
                        } else if (instance.BusyWorkers > 30) {
                            rowClass = 'high-load';
                        }
                    }
                    
                    tableHTML += '<tr class="' + rowClass + '">';
                    columns.forEach(col => {
                        let val = instance[col] !== undefined && instance[col] !== null ? instance[col] : '';
                        
                        // Format specific columns
                        if (col === 'OK') {
                            val = val === 1 ? '<span class="status-ok">✓</span>' : '<span class="status-error">✗</span>';
                        } else if (col === 'CPULoad' && val !== '') {
                            val = parseFloat(val).toFixed(4);
                        } else if (['ReqPerSec', 'BytesPerSec', 'BytesPerReq'].includes(col) && val !== '') {
                            val = parseFloat(val).toFixed(2);
                        } else if (col === 'Uptime' && val !== '') {
                            val = formatUptime(val);
                        }
                        
                        tableHTML += '<td>' + val + '</td>';
                    });
                    tableHTML += '</tr>';
                });
                
                tableHTML += '</tbody></table>';
                $('#tableContainer').html(tableHTML);
                
                // Initialize DataTable
                dataTable = $('#instancesTable').DataTable({
                    pageLength: 100,
                    order: [[0, 'asc']], // Sort by InstanceID
                    lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "All"]],
                    dom: '<"top"lf>rt<"bottom"ip><"clear">',
                    scrollX: true,
                    scrollCollapse: true,
                    fixedHeader: false,
                    autoWidth: false,
                    columnDefs: [
                        { targets: '_all', className: 'dt-body-center dt-head-center' }
                    ]
                });
                
                // Force redraw to fix alignment
                setTimeout(function() {
                    dataTable.columns.adjust().draw();
                }, 100);
                
                $('#resultsContainer').show();
            }
            
            function displayError(errorMsg) {
                $('#tableContainer').html('<div class="error-box"><strong>Error:</strong> ' + errorMsg + '</div>');
                $('#resultsContainer').show();
            }
            
            function formatUptime(seconds) {
                const days = Math.floor(seconds / 86400);
                const hours = Math.floor((seconds % 86400) / 3600);
                const mins = Math.floor((seconds % 3600) / 60);
                
                let parts = [];
                if (days > 0) parts.push(days + 'd');
                if (hours > 0) parts.push(hours + 'h');
                if (mins > 0) parts.push(mins + 'm');
                
                return parts.length > 0 ? parts.join(' ') : seconds + 's';
            }
        });
    </script>
</body>
</html>
