$(document).ready(function(){
    // Helper function to show a notification message
function showNotification(message) {
    var notif = $("<div class='copy-notif'>" + message + "</div>");
    // Basic styling for the notification (you can adjust as needed)
    notif.css({
        "position": "fixed",
        "bottom": "20px",
        "right": "20px",
        "background": "#4CAF50",
        "color": "#fff",
        "padding": "10px 15px",
        "border-radius": "5px",
        "box-shadow": "0 2px 4px rgba(0,0,0,0.3)",
        "z-index": 10000,
        "font-size": "14px",
        "opacity": 0.95
    });
    $("body").append(notif);
    // Fade out after 2 seconds and then remove the element
    notif.delay(2000).fadeOut(500, function() {
        $(this).remove();
    });
}

    $(document).on('click', '.editRefLink', function(e) {
    e.preventDefault();
    var $link = $(this);
    var qtypemd5 = $link.data('qtypemd5');
    var instance = $link.data('instance');
    var qtype    = $link.data('qtype');
    var ref      = $link.data('ref');
    var notes    = $link.data('notes');

    // Populate modal fields
    $('#qtypemd5Input').val(qtypemd5);
    $('#instanceInput').val(instance);
    $('#qtypeInput').text(qtype);  // Display query type (read-only)
    $('#refInput').val(ref);
    $('#notesInput').val(notes);

    // Show modal and backdrop
    $('#editModal').show();
    $('#modalBackdrop').show();
});

// When the user clicks the "Save" button, send an AJAX POST to update the query info.
$('#saveBtn').on('click', function() {
    var formData = $('#editForm').serialize();
    $.ajax({
        url: 'api.php?action=updateQueryInfo',
        type: 'POST',
        dataType: 'json',
        data: formData,
        success: function(response) {
            if(response.success){
                // Hide the modal on success.
                $('#editModal').hide();
                $('#modalBackdrop').hide();
                // Optionally, refresh table data to reflect updated ref/notes.
                loadTableData();
            } else {
                alert('Error updating query info: ' + response.error);
            }
        },
        error: function(xhr, status, error) {
            alert('AJAX error: ' + error);
        }
    });
});

// Close the modal when the user clicks the "Close" button.
$('#closeBtn').on('click', function() {
    $('#editModal').hide();
    $('#modalBackdrop').hide();
});
    // Load table data on page load
    $(document).on('click', '.sqltype', function(){
    var queryText = $(this).data('query');

    // Fallback copy function using a temporary textarea
    function fallbackCopyText(text) {
        var tempInput = $("<textarea>");
        $("body").append(tempInput);
        tempInput.val(text).select();
        document.execCommand("copy");
        tempInput.remove();
        showNotification("Query copied to clipboard!");
        console.log("Query copied to clipboard using fallback!");
    }
    
    if(navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(queryText)
            .then(function(){
                showNotification("Query copied to clipboard!");
                console.log("Query copied to clipboard!");
            })
            .catch(function(err){
                console.error("Failed to copy query using Clipboard API: ", err);
                fallbackCopyText(queryText);
            });
    } else {
        fallbackCopyText(queryText);
    }
});
    loadTableData();

    // Bind button clicks for daily graphs
    $('#showDailyGraphBtn').on('click', function(){
        loadGraphData('getDailyGraphData');
    });
    $('#showDailyGraphAllBtn').on('click', function(){
        loadGraphData('getDailyGraphDataAll');
    });
    $('#showDailyGraphTimeBtn').on('click', function(){
        loadGraphData('getDailyGraphDataTime');
    });
    $('#showDailyGraphTimeAllBtn').on('click', function(){
        loadGraphData('getDailyGraphDataTimeAll');
    });
    $('#showDailyGraphLockBtn').on('click', function(){
        loadGraphData('getDailyGraphLock');
    });

    // Bind button clicks for hourly graphs
    $('#showHourlyGraphBtn').on('click', function(){
        loadGraphData('getHourlyGraphData');
    });
    $('#showHourlyGraphAllBtn').on('click', function(){
        loadGraphData('getHourlyGraphDataAll');
    });
    $('#showHourlyGraphTimeBtn').on('click', function(){
        loadGraphData('getHourlyGraphDataTime');
    });
    $('#showHourlyGraphTimeAllBtn').on('click', function(){
        loadGraphData('getHourlyGraphDataTimeAll');
    });
    $('#showHourlyGraphLockBtn').on('click', function(){
        loadGraphData('getHourlyGraphLock');
    });

    // Bind close button for chart modal
    $('#closeChartBtn').on('click', function(){
        $('#chartModal').hide();
        $('#modalBackdrop').hide();
    });

    // Bind filter form submission to reload table data
    $('#filterForm').on('submit', function(e){
        e.preventDefault();
        loadTableData();
    });
});

function loadTableData(){
    var params = $('#filterForm').serialize();
    $.getJSON('api.php?action=getTableData&' + params, function(response){
        if(response.success){
            $('#tableContainer').html(response.html);
            $('#myTable').DataTable({
              ordering: true,
              searching: true,
              pageLength: -1,
              lengthMenu: [[10, 25, 50, -1],[10, 25, 50, "All"]],
              stateSave: true
            });

            // Update the status display with the lowest/highest dates and qtlimit.
            if(response.range.oldest !== null && response.range.newest !== null){
                $('#currentStatus').html(
                  "Showing from <strong>" + response.range.oldest + "</strong> to <strong>" + response.range.newest + "</strong> using query time &gt; <strong>" + response.qtlimit + " seconds</strong>"
                );
            } else {
                $('#currentStatus').html("No records found in this date range.");
            }
        } else {
            alert('Error loading table data: ' + response.error);
        }
    });
}

function loadGraphData(action){
    // Serialize the filter form parameters (from, to, qtlimit)
    window.table = $('#myTable').DataTable();
    var formData = $('#filterForm').serializeArray();
    var dataToSend = {};
    $.each(formData, function(i, field){
        dataToSend[field.name] = field.value;
    });

    // Get additional filtering values:
    dataToSend.only_ref = $('#useOnlyReferenced').is(':checked') ? 1 : 0;
    dataToSend.ref_filter = $('#graphRefFilter').val().trim();

    // Extract visible md5 values from the DataTable.
    // (Assumes your DataTable variable is "table" and that md5 is in column index 2.)
    var visibleMd5s = [];
    if(window.table){
        
        var visibleData = window.table.rows({ filter: 'applied' }).data();
        visibleData.each(function(row){
            // Adjust the index as needed. Here we assume md5 is in the third column.
            visibleMd5s.push(row[2]);
        });
    }
    // Join them into a comma-separated string.
    dataToSend.visible_md5s = visibleMd5s.join(',');

    // Include the action parameter
    dataToSend.action = action;

    $.ajax({
        url: 'api.php',
        type: 'POST',
        dataType: 'json',
        data: dataToSend,
        success: function(response){
            if(response.success){
                // Chart.js expects a 'label' property; transform if needed.
                var chartData = response.chartData;
                chartData.datasets.forEach(function(dataset) {
                    if(dataset.legendLabel){
                        dataset.label = dataset.legendLabel;
                        delete dataset.legendLabel;
                    }
                });
                renderChart(chartData);
            } else {
                alert('Error loading chart data: ' + response.error);
            }
        }
    });
}

function renderChart(chartData){
    var ctx = document.getElementById('chartCanvas').getContext('2d');
    if(window.myChart) { window.myChart.destroy(); }
    window.myChart = new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    // Custom onHover callback for legend items:
                    onHover: function(e, legendItem, legend) {
    // Try to get the native event properties.
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
                        // Hide the tooltip when the mouse leaves a legend item.
                        var tooltipEl = document.getElementById('legendTooltip');
                        tooltipEl.style.display = 'none';
                    }
                }
            },
            scales: {
                x: {
                    display: true,
                    title: { display: true, text: 'Date / Hour' }
                },
                y: {
                    display: true,
                    title: { display: true, text: 'Value' }
                }
            }
        }
    });
    $('#chartModal').show();
    $('#modalBackdrop').show();
}
