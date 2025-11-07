// Global variables for request management
window.currentTableRequest = null;
window.currentGraphRequest = null;

// Helper function to show a notification message (global scope)
function showNotification(message, type = 'success') {
    var bgColor = type === 'success' ? '#4CAF50' : (type === 'error' ? '#f44336' : '#2196F3');
    var notif = $("<div class='copy-notif'>" + message + "</div>");
    notif.css({
        "position": "fixed",
        "bottom": "20px",
        "right": "20px",
        "background": bgColor,
        "color": "#fff",
        "padding": "10px 15px",
        "border-radius": "5px",
        "box-shadow": "0 2px 4px rgba(0,0,0,0.3)",
        "z-index": 10000,
        "font-size": "14px",
        "opacity": 0.95
    });
    $("body").append(notif);
    notif.delay(2000).fadeOut(500, function() {
        $(this).remove();
    });
}

function showLoading(message) {
    $('#loadingMessage').text(message);
    $('#loadingOverlay').fadeIn(200);
}

function hideLoading() {
    $('#loadingOverlay').fadeOut(200);
}

$(document).ready(function(){

    $(document).on('click', '.editRefLink', function(e) {
        e.preventDefault();
        var $link = $(this);
        var qtypemd5 = $link.data('qtypemd5');
        var instance = $link.data('instance');
        var qtype    = $link.data('qtype');
        var ref      = $link.data('ref');
        var notes    = $link.data('notes');

        $('#qtypemd5Input').val(qtypemd5);
        $('#instanceInput').val(instance);
        $('#qtypeInput').text(qtype);
        $('#refInput').val(ref);
        $('#notesInput').val(notes);

        $('#editModal').show();
        $('#modalBackdrop').show();
    });

    $('#saveBtn').on('click', function() {
        var formData = $('#editForm').serialize();
        $.ajax({
            url: 'api_v6.php?action=updateQueryInfo',
            type: 'POST',
            dataType: 'json',
            data: formData,
            success: function(response) {
                if(response.success){
                    $('#editModal').hide();
                    $('#modalBackdrop').hide();
                    loadTableData();
                    showNotification('Query info updated successfully!');
                } else {
                    showNotification('Error: ' + response.error, 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotification('AJAX error: ' + error, 'error');
            }
        });
    });

    $('#closeBtn').on('click', function() {
        $('#editModal').hide();
        $('#modalBackdrop').hide();
    });

    $(document).on('click', '.sqltype', function(){
        var queryText = $(this).data('query');

        function fallbackCopyText(text) {
            var tempInput = $("<textarea>");
            $("body").append(tempInput);
            tempInput.val(text).select();
            document.execCommand("copy");
            tempInput.remove();
            showNotification("Query copied to clipboard!");
        }
        
        if(navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(queryText)
                .then(function(){
                    showNotification("Query copied to clipboard!");
                })
                .catch(function(err){
                    fallbackCopyText(queryText);
                });
        } else {
            fallbackCopyText(queryText);
        }
    });

    loadTableData();

    // Unified graph button handlers
    $('#showGraphCountBtn').on('click', function(){
        var granularity = $('input[name="granularity"]:checked').val();
        var action = getGraphAction(granularity, 'count');
        loadGraphData(action, granularity, 'count');
    });

    $('#showGraphTimeBtn').on('click', function(){
        var granularity = $('input[name="granularity"]:checked').val();
        var action = getGraphAction(granularity, 'time');
        loadGraphData(action, granularity, 'time');
    });

    $('#showGraphLockBtn').on('click', function(){
        var granularity = $('input[name="granularity"]:checked').val();
        var action = getGraphAction(granularity, 'lock');
        loadGraphData(action, granularity, 'lock');
    });

    $('#closeChartBtn').on('click', function(){
        $('#chartModal').hide();
        $('#modalBackdrop').hide();
    });

    $('#resetZoomBtn').on('click', function(){
        if(window.myChart) {
            window.myChart.resetZoom();
            $('#zoomInfo').html('');
        }
    });

    $('#autoRegranulateBtn').on('click', function(){
        autoRegranulate();
    });

    $('#filterForm').on('submit', function(e){
        e.preventDefault();
        loadTableData();
    });

    $('#cancelLoadingBtn').on('click', function(){
        // Cancel ongoing requests
        if(window.currentTableRequest) {
            window.currentTableRequest.abort();
            window.currentTableRequest = null;
        }
        if(window.currentGraphRequest) {
            window.currentGraphRequest.abort();
            window.currentGraphRequest = null;
        }
        hideLoading();
        showNotification('Operation cancelled', 'info');
    });
});

function getFormParamsWithDateTime() {
    var fromDate = $('#from').val();
    var fromTime = $('#from_time').val() || '00:00';
    var toDate = $('#to').val();
    var toTime = $('#to_time').val() || '23:59';
    var qtlimit = $('#qtlimit').val();
    
    // Combine date and time
    var from = fromDate + ' ' + fromTime + ':00';
    var to = toDate + ' ' + toTime + ':59';
    
    return 'from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&qtlimit=' + encodeURIComponent(qtlimit);
}

function getFormDataObject() {
    var fromDate = $('#from').val();
    var fromTime = $('#from_time').val() || '00:00';
    var toDate = $('#to').val();
    var toTime = $('#to_time').val() || '23:59';
    var qtlimit = $('#qtlimit').val();
    
    // Combine date and time
    return {
        from: fromDate + ' ' + fromTime + ':00',
        to: toDate + ' ' + toTime + ':59',
        qtlimit: qtlimit
    };
}

function getGraphAction(granularity, metric) {
    // Map granularity and metric to action name
    var actionMap = {
        'daily': {
            'count': 'getDailyGraphData',
            'time': 'getDailyGraphDataTime',
            'lock': 'getDailyGraphLock'
        },
        'hourly': {
            'count': 'getHourlyGraphData',
            'time': 'getHourlyGraphDataTime',
            'lock': 'getHourlyGraphLock'
        },
        'minute': {
            'count': 'getMinuteGraphData',
            'time': 'getMinuteGraphDataTime',
            'lock': 'getMinuteGraphLock'
        },
        'second': {
            'count': 'getSecondGraphData',
            'time': 'getSecondGraphDataTime',
            'lock': 'getSecondGraphLock'
        }
    };
    return actionMap[granularity][metric];
}

function loadTableData(){
    // Cancel any existing table request
    if(window.currentTableRequest) {
        window.currentTableRequest.abort();
    }
    
    var params = getFormParamsWithDateTime();
    var bypassCache = $('#bypassCache').is(':checked') ? '&bypass_cache=1' : '';
    
    showLoading('Loading table data...');
    
    window.currentTableRequest = $.ajax({
        url: 'api_v6.php?action=getTableData&' + params + bypassCache,
        type: 'GET',
        dataType: 'json',
        success: function(response){
            hideLoading();
            window.currentTableRequest = null;
            
            if(response.success){
                $('#tableContainer').html(response.html);
                $('#myTable').DataTable({
                    ordering: true,
                    searching: true,
                    pageLength: -1,
                    lengthMenu: [[10, 25, 50, -1],[10, 25, 50, "All"]],
                    stateSave: true
                });

                if(response.range.oldest !== null && response.range.newest !== null){
                    $('#currentStatus').html(
                        "Showing from <strong>" + response.range.oldest + "</strong> to <strong>" + response.range.newest + "</strong> using query time &gt; <strong>" + response.qtlimit + " seconds</strong>"
                    );
                } else {
                    $('#currentStatus').html("No records found in this date range.");
                }
                
                // Show cache status
                if(response.cache_info) {
                    var cacheMsg = response.cache_info.from_cache ? 
                        '✓ Loaded from cache (' + response.cache_info.cache_age + ')' : 
                        '⟳ Fresh query (cached for future)';
                    $('#cacheStatus').html(cacheMsg);
                }
            } else {
                showNotification('Error loading table data: ' + response.error, 'error');
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            window.currentTableRequest = null;
            
            if(status !== 'abort') {
                showNotification('Error loading table data: ' + error, 'error');
            }
        }
    });
}

function loadGraphData(action, granularity, metric, dateTimeOverride){
    // Cancel any existing graph request
    if(window.currentGraphRequest) {
        window.currentGraphRequest.abort();
    }
    
    window.table = $('#myTable').DataTable();
    
    // Get form data with combined datetime
    var dataToSend = getFormDataObject();

    // Override date range if provided (for zoomed regranulation)
    if (dateTimeOverride) {
        dataToSend.from = dateTimeOverride.from;
        dataToSend.to = dateTimeOverride.to;
    }

    dataToSend.only_ref = $('#useOnlyReferenced').is(':checked') ? 1 : 0;
    dataToSend.ref_filter = $('#graphRefFilter').val().trim();
    dataToSend.bypass_cache = $('#bypassCache').is(':checked') ? 1 : 0;

    var visibleMd5s = [];
    if(window.table){
        var visibleData = window.table.rows({ filter: 'applied' }).data();
        visibleData.each(function(row){
            visibleMd5s.push(row[2]);
        });
    }
    dataToSend.visible_md5s = visibleMd5s.join(',');
    dataToSend.action = action;

    // Validate second granularity for large date ranges
    if(granularity === 'second') {
        var fromDate = new Date(dataToSend.from);
        var toDate = new Date(dataToSend.to);
        var timeDiff = (toDate - fromDate) / 1000; // seconds
        var daysDiff = timeDiff / 86400;
        
        // Only warn if more than 1 day AND not coming from auto-regranulate
        if(daysDiff > 1 && !dateTimeOverride) {
            if(!confirm('Warning: Second-level granularity for ' + Math.round(daysDiff) + ' days will generate ' + 
                       Math.round(timeDiff) + ' data points. This may be very slow.\n\nContinue anyway?')) {
                return;
            }
        }
    }

    // Show loading with specific message
    var metricName = metric === 'count' ? 'Query Count' : (metric === 'time' ? 'Query Time' : 'Lock Time');
    showLoading('Loading ' + granularity + ' ' + metricName + ' graph...');

    window.currentGraphRequest = $.ajax({
        url: 'api_v6.php',
        type: 'POST',
        dataType: 'json',
        data: dataToSend,
        success: function(response){
            hideLoading();
            window.currentGraphRequest = null;
            
            if(response.success){
                var chartData = response.chartData;
                chartData.datasets.forEach(function(dataset) {
                    if(dataset.legendLabel){
                        dataset.label = dataset.legendLabel;
                        delete dataset.legendLabel;
                    }
                });
                
                // Prepare chart info
                var chartInfo = {
                    granularity: granularity,
                    metric: metric,
                    dataPoints: chartData.labels.length,
                    datasets: chartData.datasets.length,
                    fromCache: response.cache_info ? response.cache_info.from_cache : false,
                    cacheAge: response.cache_info ? response.cache_info.cache_age : null,
                    dateTimeOverride: dateTimeOverride || null
                };
                
                renderChart(chartData, chartInfo);
                showNotification('Graph loaded successfully!');
            } else {
                showNotification('Error loading chart data: ' + response.error, 'error');
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            window.currentGraphRequest = null;
            
            if(status !== 'abort') {
                showNotification('AJAX error: ' + error, 'error');
            }
        }
    });
}

function renderChart(chartData, chartInfo){
    var ctx = document.getElementById('chartCanvas').getContext('2d');
    if(window.myChart) { window.myChart.destroy(); }
    
    // Display chart info
    var metricNames = {
        'count': 'Query Count',
        'time': 'Query Time (seconds)',
        'lock': 'Lock Time (seconds)'
    };
    
    var infoHtml = '<strong>Granularity:</strong> ' + chartInfo.granularity.toUpperCase() + ' | ' +
                   '<strong>Metric:</strong> ' + metricNames[chartInfo.metric] + ' | ' +
                   '<strong>Data Points:</strong> ' + chartInfo.dataPoints + ' | ' +
                   '<strong>Query Types:</strong> ' + chartInfo.datasets;
    
    // Show precise range if using datetime override
    if(chartInfo.dateTimeOverride) {
        infoHtml += ' | <span style="color: #ff9800;">🎯 Precise Range: ' + 
                    chartInfo.dateTimeOverride.from + ' to ' + chartInfo.dateTimeOverride.to + '</span>';
    }
    
    if(chartInfo.fromCache) {
        infoHtml += ' | <span style="color: #4CAF50;">✓ From Cache (' + chartInfo.cacheAge + ')</span>';
    } else {
        infoHtml += ' | <span style="color: #2196F3;">⟳ Fresh Query</span>';
    }
    
    $('#chartInfo').html(infoHtml);
    
    // Store chart info globally for regranulation
    window.currentChartInfo = chartInfo;
    window.currentChartLabels = chartData.labels;
    
    window.myChart = new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    onHover: function(e, legendItem, legend) {
                        var evt = e.native || e;
                        var x = evt.clientX || evt.x;
                        var y = evt.clientY || evt.y;
                        
                        var chart = legend.chart;
                        var datasetIndex = legendItem.datasetIndex;
                        var dataset = chart.data.datasets[datasetIndex];
                        
                        var tooltipEl = document.getElementById('legendTooltip');
                        tooltipEl.innerHTML = dataset.fullQuery || '';
                        tooltipEl.style.display = 'block';
                        tooltipEl.style.left = (x + 10) + 'px';
                        tooltipEl.style.top = (y + 10) + 'px';
                    },
                    onLeave: function(e, legendItem, legend) {
                        var tooltipEl = document.getElementById('legendTooltip');
                        tooltipEl.style.display = 'none';
                    }
                },
                zoom: {
                    zoom: {
                        wheel: {
                            enabled: true,
                            speed: 0.1
                        },
                        pinch: {
                            enabled: true
                        },
                        mode: 'x',
                        onZoomComplete: function({chart}) {
                            updateZoomInfo(chart);
                        }
                    },
                    pan: {
                        enabled: true,
                        mode: 'x',
                        onPanComplete: function({chart}) {
                            updateZoomInfo(chart);
                        }
                    },
                    limits: {
                        x: {min: 'original', max: 'original'}
                    }
                }
            },
            scales: {
                x: {
                    display: true,
                    title: { 
                        display: true, 
                        text: chartInfo.granularity.charAt(0).toUpperCase() + chartInfo.granularity.slice(1) + ' Timeline'
                    }
                },
                y: {
                    display: true,
                    title: { 
                        display: true, 
                        text: metricNames[chartInfo.metric]
                    }
                }
            }
        }
    });
    $('#chartModal').show();
    $('#modalBackdrop').show();
}

function updateZoomInfo(chart) {
    var xScale = chart.scales.x;
    var minIndex = Math.floor(xScale.min);
    var maxIndex = Math.ceil(xScale.max);
    
    if (minIndex < 0) minIndex = 0;
    if (maxIndex >= window.currentChartLabels.length) maxIndex = window.currentChartLabels.length - 1;
    
    var visibleLabels = maxIndex - minIndex + 1;
    var startLabel = window.currentChartLabels[minIndex];
    var endLabel = window.currentChartLabels[maxIndex];
    
    // Calculate time range in minutes
    var timeRange = calculateTimeRange(startLabel, endLabel, window.currentChartInfo.granularity);
    
    var zoomText = 'Zoomed: ' + startLabel + ' to ' + endLabel + ' (' + visibleLabels + ' points, ' + timeRange.text + ')';
    
    // Suggest better granularity
    var suggestion = suggestGranularity(timeRange.minutes, window.currentChartInfo.granularity);
    if (suggestion) {
        zoomText += ' <strong style="color: #ff9800;">→ Suggest: ' + suggestion + '</strong>';
    }
    
    $('#zoomInfo').html(zoomText);
}

function calculateTimeRange(startLabel, endLabel, granularity) {
    var start = new Date(startLabel);
    var end = new Date(endLabel);
    var diffMs = end - start;
    var diffMinutes = Math.round(diffMs / 60000);
    
    var text = '';
    if (diffMinutes < 60) {
        text = diffMinutes + ' minutes';
    } else if (diffMinutes < 1440) {
        text = Math.round(diffMinutes / 60) + ' hours';
    } else {
        text = Math.round(diffMinutes / 1440) + ' days';
    }
    
    return {
        minutes: diffMinutes,
        text: text
    };
}

function suggestGranularity(minutes, currentGranularity) {
    // Suggest better granularity based on visible time range
    if (minutes <= 30 && currentGranularity !== 'second') {
        return 'SECOND granularity';
    } else if (minutes <= 180 && currentGranularity !== 'minute') {
        return 'MINUTE granularity';
    } else if (minutes <= 4320 && currentGranularity !== 'hourly') {
        return 'HOURLY granularity';
    } else if (minutes > 4320 && currentGranularity !== 'daily') {
        return 'DAILY granularity';
    }
    return null;
}

function autoRegranulate() {
    if (!window.myChart || !window.currentChartInfo) {
        showNotification('No chart loaded', 'error');
        return;
    }
    
    var xScale = window.myChart.scales.x;
    var minIndex = Math.floor(xScale.min);
    var maxIndex = Math.ceil(xScale.max);
    
    if (minIndex < 0) minIndex = 0;
    if (maxIndex >= window.currentChartLabels.length) maxIndex = window.currentChartLabels.length - 1;
    
    var startLabel = window.currentChartLabels[minIndex];
    var endLabel = window.currentChartLabels[maxIndex];
    
    var timeRange = calculateTimeRange(startLabel, endLabel, window.currentChartInfo.granularity);
    
    // Determine optimal granularity
    var newGranularity;
    if (timeRange.minutes <= 30) {
        newGranularity = 'second';
    } else if (timeRange.minutes <= 180) {
        newGranularity = 'minute';
    } else if (timeRange.minutes <= 4320) {
        newGranularity = 'hourly';
    } else {
        newGranularity = 'daily';
    }
    
    if (newGranularity === window.currentChartInfo.granularity) {
        showNotification('Already at optimal granularity (' + newGranularity + ')', 'info');
        return;
    }
    
    // Parse dates from labels - keep full datetime precision
    var fromDate = new Date(startLabel);
    var toDate = new Date(endLabel);
    
    // Format dates for form fields
    var fromDateOnly = fromDate.toISOString().split('T')[0];
    var toDateOnly = toDate.toISOString().split('T')[0];
    var fromTimeOnly = ('0' + fromDate.getHours()).slice(-2) + ':' + ('0' + fromDate.getMinutes()).slice(-2);
    var toTimeOnly = ('0' + toDate.getHours()).slice(-2) + ':' + ('0' + toDate.getMinutes()).slice(-2);
    
    // Update form fields (for display/next queries)
    $('#from').val(fromDateOnly);
    $('#to').val(toDateOnly);
    $('#from_time').val(fromTimeOnly);
    $('#to_time').val(toTimeOnly);
    
    // Update granularity radio
    $('input[name="granularity"][value="' + newGranularity + '"]').prop('checked', true);
    
    // Close current chart
    $('#chartModal').hide();
    $('#modalBackdrop').hide();
    
    // Prepare datetime override with FULL precision (not just date)
    var dateTimeOverride = {
        from: formatDateTimeForAPI(fromDate, startLabel),
        to: formatDateTimeForAPI(toDate, endLabel)
    };
    
    // Show notification with precise range
    showNotification('Re-loading ' + timeRange.text + ' with ' + newGranularity.toUpperCase() + ' granularity...', 'info');
    
    var action = getGraphAction(newGranularity, window.currentChartInfo.metric);
    loadGraphData(action, newGranularity, window.currentChartInfo.metric, dateTimeOverride);
}

function formatDateTimeForAPI(dateObj, originalLabel) {
    // If original label has time component, preserve it
    // Otherwise use the date object
    if (originalLabel.includes(':')) {
        // Has time - use original label format
        return originalLabel.substring(0, 19); // Y-m-d H:i:s format
    } else {
        // Date only - return just the date
        return dateObj.toISOString().split('T')[0];
    }
}
