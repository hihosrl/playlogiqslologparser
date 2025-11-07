# Slow Query Analyzer - Detailed Functionality Report

## Executive Summary

**slowqueryanalyze5.php** is a comprehensive MySQL slow query log analysis tool that provides database administrators with detailed insights into query performance. The system consists of a PHP frontend, AJAX-based backend API, and interactive JavaScript components for real-time data visualization and analysis.

---

## System Architecture

### Components Overview

| Component | File | Purpose |
|-----------|------|---------|
| **Frontend** | `slowqueryanalyze5.php` | Main HTML interface and user controls |
| **Backend API** | `api.php` | RESTful API handling data retrieval and updates |
| **Client Logic** | `slowqueryanalyze_async.js` | AJAX operations and chart rendering |
| **Styling** | `slowqueryanalyze.css` | UI styling and layout |

### Database Schema

The system interacts with two main tables:

1. **`slow_query_log`** - Stores MySQL slow query log entries
   - `log_time` - Timestamp of query execution
   - `instance` - Database instance identifier
   - `qtype` - Normalized query pattern
   - `qtypemd5` - MD5 hash of query type
   - `query` - Full SQL query text
   - `query_time` - Execution time in seconds
   - `lock_time` - Lock wait time in seconds
   - `rows_sent` - Number of rows returned
   - `rows_examined` - Number of rows scanned

2. **`query_type_info`** - Metadata for query classification
   - `id` - Primary key
   - `qtypemd5` - MD5 hash linking to slow_query_log
   - `instance` - Database instance
   - `qtype` - Query pattern
   - `ref` - User-defined reference code (e.g., "BO08")
   - `notes` - Additional annotations

---

## Core Functionalities

### 1. Data Filtering & Search

#### Date Range Filtering
- **Default Range**: Yesterday to today
- **User Controls**: From/To date pickers
- **Implementation**: GET parameters `from` and `to`
- **Format**: `Y-m-d` (e.g., 2025-10-05)

#### Query Time Threshold
- **Parameter**: `qtlimit` (Query Time Limit)
- **Default**: 1 second
- **Purpose**: Filter queries by minimum execution time
- **Use Case**: Focus on problematic queries exceeding specific thresholds

#### Reference Filtering
- **Graph Ref Filter**: Text input for filtering by reference code
- **Wildcard Support**: Accepts patterns like "BO08" or "*"
- **Checkbox Control**: "Graph only ref set" - limits graphs to queries with assigned references

### 2. Interactive Data Table

#### Table Features (DataTables Integration)
- **Sorting**: Multi-column sorting capability
- **Search**: Real-time text search across all columns
- **Pagination**: Configurable page sizes (10, 25, 50, All)
- **State Persistence**: Saves user preferences (sorting, pagination) across sessions
- **Responsive Design**: Monospace font for technical data readability

#### Displayed Metrics

| Column | Description | Calculation |
|--------|-------------|-------------|
| **Ref** | Reference code or auto-generated ID | User-defined or "NOREF-{id}" |
| **DB** | Database instance name | From `slow_query_log.instance` |
| **Query Md5** | Unique query pattern identifier | MD5 hash of normalized query |
| **Query Type** | Normalized SQL pattern | Parameterized query structure |
| **Count** | Total occurrences | `COUNT(*)` grouped by qtypemd5 |
| **Sum Query Time** | Total execution time | `SUM(query_time)` in seconds |
| **Avg Query Time** | Average execution time | `AVG(query_time)` in seconds |
| **Sum Lock Time** | Total lock wait time | `SUM(lock_time)` in seconds |
| **Avg Lock Time** | Average lock wait time | `AVG(lock_time)` in seconds |
| **Avg Rows Sent** | Average rows returned | `AVG(rows_sent)` |
| **Avg Rows Examined** | Average rows scanned | `AVG(rows_examined)` |

#### Interactive Features

**Query Copy to Clipboard**
- **Trigger**: Click on any query text in the "Query Type" column
- **Mechanism**: 
  - Primary: Modern Clipboard API (`navigator.clipboard.writeText`)
  - Fallback: Legacy `document.execCommand("copy")` for older browsers
- **Feedback**: Toast notification confirming copy action
- **Data**: Copies full original SQL query (not normalized pattern)

**Edit Query Metadata**
- **Trigger**: Click on reference code in "Ref" column
- **Modal Fields**:
  - Query Type MD5 (read-only)
  - Query Type (read-only display)
  - Instance (hidden field)
  - Reference Code (editable text)
  - Notes (editable textarea)
- **Save Action**: AJAX POST to `api.php?action=updateQueryInfo`
- **Database Logic**:
  - Updates existing record if `qtypemd5` exists in `query_type_info`
  - Inserts new record if not found, fetching `qtype` and `instance` from `slow_query_log`

### 3. Data Visualization (Chart.js Integration)

#### Graph Types

The system provides **6 distinct graph types** across two temporal resolutions:

##### Daily Graphs (Aggregated by Date)

1. **Daily Count** (`getDailyGraphData`)
   - **Metric**: Number of query executions per day
   - **Y-Axis**: Query count
   - **Use Case**: Identify days with unusual query volume

2. **Daily Query Time** (`getDailyGraphDataTime`)
   - **Metric**: Total query execution time per day
   - **Y-Axis**: Cumulative seconds
   - **Use Case**: Detect days with performance degradation

3. **Daily Lock Time** (`getDailyGraphLock`)
   - **Metric**: Total lock wait time per day
   - **Y-Axis**: Cumulative lock seconds
   - **Use Case**: Identify concurrency issues

##### Hourly Graphs (Aggregated by Hour)

4. **Hourly Count** (`getHourlyGraphData`)
   - **Metric**: Query executions per hour
   - **Granularity**: Hourly buckets (e.g., "2025-10-06 12:00:00")
   - **Use Case**: Pinpoint specific hours with high activity

5. **Hourly Query Time** (`getHourlyGraphDataTime`)
   - **Metric**: Total execution time per hour
   - **Use Case**: Identify performance bottlenecks at specific times

6. **Hourly Lock Time** (`getHourlyGraphLock`)
   - **Metric**: Total lock time per hour
   - **Use Case**: Diagnose lock contention patterns

#### Graph Filtering Logic

**Reference-Based Filtering**
- **"Graph only ref set" Enabled**: Shows only queries with assigned reference codes
- **Ref Filter Input**: Further narrows to specific references (LIKE pattern matching)
- **Example**: Filter "BO08" shows only queries tagged with that reference

**Visible MD5 Filtering**
- **Mechanism**: Extracts MD5 hashes from currently visible table rows (after DataTable filtering)
- **Purpose**: Graph only the queries currently displayed in the table
- **Implementation**: JavaScript extracts column 2 (MD5) from filtered DataTable rows

#### Chart Rendering Features

**Interactive Legend**
- **Hover Tooltip**: Displays full SQL query when hovering over legend items
- **Positioning**: Follows mouse cursor with 10px offset
- **Styling**: Dark background with monospace font for readability

**Chart Configuration**
- **Type**: Line chart (Chart.js)
- **Responsive**: Auto-resizes to modal dimensions
- **Axes**:
  - X-Axis: Date/Hour labels
  - Y-Axis: Metric value (count, seconds)
- **Modal Display**: 80% viewport width/height, centered overlay

**Dataset Labeling**
- **With Reference**: Uses assigned reference code
- **Without Reference**: Auto-generates "NOREF-{id}" or "no ref [###]" labels
- **Consistency**: Same query type maintains consistent label across graphs

### 4. Real-Time Status Display

**Current Filter Status**
- **Location**: Fixed position (top-right corner)
- **Information Displayed**:
  - Oldest log entry date in current filter
  - Newest log entry date in current filter
  - Active query time threshold
- **Example**: "Showing from **2025-10-05** to **2025-10-06** using query time > **1 seconds**"
- **Empty State**: "No records found in this date range."

---

## API Endpoints (api.php)

### Endpoint: `getTableData`

**Method**: GET  
**Parameters**: `from`, `to`, `qtlimit`  
**Response**:
```json
{
  "success": true,
  "html": "<table>...</table>",
  "range": {
    "oldest": "2025-10-05 08:23:15",
    "newest": "2025-10-06 12:45:30"
  },
  "qtlimit": "1"
}
```

**SQL Query**:
- Joins `slow_query_log` with `query_type_info`
- Groups by `instance` and `qtypemd5`
- Calculates aggregates (COUNT, SUM, AVG)
- Orders by `total_count DESC`, `avg_query_time DESC`

### Endpoint: `updateQueryInfo`

**Method**: POST  
**Parameters**: `qtypemd5`, `instance`, `ref`, `notes`  
**Logic**:
1. Check if record exists in `query_type_info`
2. If exists: UPDATE `ref` and `notes`
3. If not: INSERT new record (fetch `qtype` from `slow_query_log`)

**Response**:
```json
{
  "success": true
}
```

### Graph Endpoints (6 endpoints)

All graph endpoints follow similar patterns:

**Common Parameters**:
- `from`, `to`, `qtlimit` - Date/time filtering
- `only_ref` - Boolean flag for reference filtering
- `ref_filter` - Reference pattern (LIKE matching)
- `visible_md5s` - Comma-separated MD5 list

**Common Response**:
```json
{
  "success": true,
  "chartData": {
    "labels": ["2025-10-05", "2025-10-06"],
    "datasets": [
      {
        "label": "BO08",
        "fullQuery": "SELECT * FROM users WHERE id = ?",
        "data": [45, 67]
      }
    ]
  }
}
```

**SQL Pattern**:
```sql
SELECT 
  DATE(log_time) AS log_date,  -- or DATE_FORMAT for hourly
  qtypemd5,
  qtype,
  COALESCE(ref, 'none') AS ref,
  COUNT(*) AS metric  -- or SUM(query_time), SUM(lock_time)
FROM slow_query_log
LEFT JOIN query_type_info USING (qtypemd5)
WHERE log_time BETWEEN :fromDate AND :toDate
  AND query_time >= :qtlimit
  [AND ref filtering conditions]
  [AND MD5 filtering conditions]
GROUP BY log_date, qtypemd5, qtype, ref
ORDER BY log_date ASC
```

---

## User Workflows

### Workflow 1: Identify Problematic Queries

1. **Set Date Range**: Select period of interest
2. **Adjust Threshold**: Set `qtlimit` to focus on slow queries (e.g., 5 seconds)
3. **Review Table**: Sort by "Sum Query Time" or "Count" to find top offenders
4. **Analyze Query**: Click query text to copy for EXPLAIN analysis
5. **Tag Query**: Click reference to add tracking code (e.g., "TICKET-1234")

### Workflow 2: Track Query Performance Over Time

1. **Filter Table**: Use DataTable search to find specific query pattern
2. **Generate Graph**: Click "Daily Query Time" to visualize trend
3. **Analyze Pattern**: Identify spikes or degradation
4. **Drill Down**: Switch to "Hourly Query Time" for granular analysis
5. **Cross-Reference**: Hover over legend to confirm query identity

### Workflow 3: Monitor Specific Query References

1. **Set Reference Filter**: Enter reference code (e.g., "BO08")
2. **Enable "Graph only ref set"**: Checkbox to limit to tagged queries
3. **Generate Graphs**: View only queries with that reference
4. **Compare Metrics**: Switch between Count, Query Time, and Lock Time graphs
5. **Document Findings**: Add notes to query metadata for team collaboration

---

## Technical Implementation Details

### AJAX Architecture

**Asynchronous Loading**:
- Table data loads via AJAX on page load and filter changes
- Prevents full page reloads for better UX
- Form submission intercepted with `e.preventDefault()`

**DataTable Integration**:
- Initialized after HTML injection: `$('#myTable').DataTable({...})`
- State persistence enabled: `stateSave: true`
- Custom page length options: `[10, 25, 50, -1]`

### Modal System

**Two Modal Types**:

1. **Edit Modal** (`#editModal`)
   - Form-based input for query metadata
   - Backdrop overlay prevents interaction with main page
   - Close handlers on button and backdrop click

2. **Chart Modal** (`#chartModal`)
   - Large canvas for Chart.js rendering
   - 80% viewport coverage
   - Destroys previous chart instance before rendering new one

### Security Considerations

**SQL Injection Prevention**:
- All queries use PDO prepared statements
- Named parameters (`:fromDate`, `:toDate`, etc.)
- Dynamic MD5 placeholders generated safely

**XSS Prevention**:
- All output escaped with `htmlspecialchars()`
- User input sanitized before display

**Database Credentials**:
- Hardcoded in `api.php` (line 47)
- **Recommendation**: Move to external config file

---

## Performance Optimizations

### Database Query Optimization

**Indexing Requirements** (recommended):
- `slow_query_log.log_time` - For date range filtering
- `slow_query_log.qtypemd5` - For grouping and joins
- `slow_query_log.query_time` - For threshold filtering
- `query_type_info.qtypemd5` - For join performance

**Query Aggregation**:
- Single query per table load (no N+1 issues)
- GROUP BY reduces result set size
- LEFT JOIN ensures all queries shown even without metadata

### Frontend Optimization

**Lazy Loading**:
- Table data loaded only when needed
- Charts generated on-demand (not pre-rendered)

**Chart Instance Management**:
- Previous chart destroyed before new render: `window.myChart.destroy()`
- Prevents memory leaks from multiple chart instances

---

## Limitations & Potential Improvements

### Current Limitations

1. **Hardcoded Database Credentials**: Security risk if code is exposed
2. **No User Authentication**: Anyone with access can view/edit all queries
3. **No Export Functionality**: Cannot export table data or charts
4. **Limited Error Handling**: Some edge cases may not display user-friendly errors
5. **No Query Comparison**: Cannot compare two queries side-by-side

### Suggested Enhancements

1. **Authentication System**: Add login/role-based access control
2. **Export Features**: CSV/Excel export for tables, PNG export for charts
3. **Query Recommendations**: Integrate with MySQL EXPLAIN for optimization suggestions
4. **Alerting System**: Email notifications when queries exceed thresholds
5. **Historical Comparison**: Compare current period vs. previous period
6. **Database Selector**: Support multiple database instances in UI
7. **Configuration File**: Externalize database credentials and settings
8. **API Rate Limiting**: Prevent abuse of graph generation endpoints
9. **Query Annotations**: Allow team members to comment on specific queries
10. **Performance Baselines**: Track query performance against established baselines

---

## Database Connection Details

**Connection String**:
```php
$pdo = new PDO(
    "mysql:host=localhost;dbname=plqaylogiq;charset=utf8mb4",
    "plq",
    "plq",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

**Database**: `plqaylogiq`  
**User**: `plq`  
**Password**: `plq`  
**Character Set**: UTF-8 (utf8mb4)

---

## UI/UX Features

### Visual Feedback

1. **Copy Notification**: Green toast message on successful clipboard copy
2. **Row Highlighting**: Cyan background on table row hover
3. **Modal Backdrop**: Semi-transparent overlay (50% black)
4. **Loading States**: Implicit via AJAX (could be enhanced with spinners)

### Responsive Design

- **Table Width**: 95% of container
- **Chart Modal**: 80% viewport width/height
- **Fixed Status**: Top-right corner, always visible
- **Monospace Fonts**: Technical data (queries, MD5 hashes) for readability

### Accessibility Considerations

- **Clickable Elements**: Clear cursor changes (pointer on `.sqltype`)
- **Tooltips**: Full query text on hover for truncated displays
- **Keyboard Navigation**: Standard form controls support tab navigation
- **Color Contrast**: Could be improved for WCAG compliance

---

## Code Quality Assessment

### Strengths

1. **Separation of Concerns**: Clear division between frontend, API, and styling
2. **Prepared Statements**: Consistent use of PDO for security
3. **Modular JavaScript**: Functions separated by responsibility
4. **Consistent Naming**: Descriptive variable and function names
5. **Error Handling**: Try-catch blocks in critical sections

### Areas for Improvement

1. **Code Duplication**: Graph endpoints share 90% of code (could be refactored)
2. **Magic Numbers**: Hardcoded values (e.g., column index 2 for MD5)
3. **Comments**: Limited inline documentation
4. **Configuration**: Database credentials should be externalized
5. **Validation**: Minimal input validation on user-provided data

---

## Conclusion

**slowqueryanalyze5.php** is a robust, feature-rich tool for MySQL slow query analysis. It successfully combines:

- **Comprehensive Data Aggregation**: Multiple metrics across time dimensions
- **Interactive Visualization**: Six graph types with flexible filtering
- **User-Friendly Interface**: DataTables integration with search/sort/pagination
- **Metadata Management**: Query tagging and annotation system
- **Real-Time Updates**: AJAX-based architecture for responsive UX

The system is production-ready for internal database administration teams, with opportunities for enhancement in security, export capabilities, and advanced analytics features.

---

**Report Generated**: 2025-10-06  
**Analyzed Files**:
- `slowqueryanalyze5.php` (98 lines)
- `api.php` (852 lines)
- `slowqueryanalyze_async.js` (285 lines)
- `slowqueryanalyze.css` (87 lines)

**Total Lines of Code**: 1,322
