# DateTime Selector Update

## What Changed

Added **time inputs** (HH:MM) to the date selectors, allowing users to specify exact datetime ranges.

## Before vs After

### Before
```
From: [2025-10-06]  To: [2025-10-07]
```
- Only date selection
- Always queried full days (00:00:00 to 23:59:59)
- No way to specify specific hours

### After
```
From: [2025-10-06] [10:15]  To: [2025-10-06] [14:30]
```
- Date + Time selection
- Query specific time ranges
- Precise control over datetime range

## Features

### 1. Time Input Fields

**From Time:**
- Default: `00:00` (midnight)
- Format: HH:MM (24-hour)
- Step: 1 minute

**To Time:**
- Default: `23:59` (end of day)
- Format: HH:MM (24-hour)
- Step: 1 minute

### 2. Visual Grouping

Date and time inputs are visually grouped together:
```
┌─────────────────────────────┐
│ From: [Date] [Time]         │
└─────────────────────────────┘
┌─────────────────────────────┐
│ To:   [Date] [Time]         │
└─────────────────────────────┘
```

### 3. Auto-Population from Zoom

When using "Auto Re-granulate", the time fields are automatically updated:

**Example:**
1. Zoom into 10:15 to 14:30
2. Click "Auto Re-granulate"
3. Form updates to:
   - From: `2025-10-06` `10:15`
   - To: `2025-10-06` `14:30`

## Use Cases

### Use Case 1: Analyze Specific Hours

**Scenario:** You want to see queries between 9am and 5pm

**Steps:**
1. Set From: `2025-10-06` `09:00`
2. Set To: `2025-10-06` `17:00`
3. Click "Apply"
4. Only queries in that 8-hour window are shown

### Use Case 2: Compare Same Time Across Days

**Scenario:** Compare 10am-11am on Monday vs Tuesday

**Monday:**
1. From: `2025-10-06` `10:00`
2. To: `2025-10-06` `11:00`
3. Load graph

**Tuesday:**
1. From: `2025-10-07` `10:00`
2. To: `2025-10-07` `11:00`
3. Load graph

### Use Case 3: Precise Incident Analysis

**Scenario:** Incident occurred at 14:23, want to see 15 minutes before/after

**Steps:**
1. From: `2025-10-06` `14:08`
2. To: `2025-10-06` `14:38`
3. Select "Minute" granularity
4. Click "Query Count" graph
5. See exact minute-by-minute data

## Technical Details

### Form Data Combination

**JavaScript combines date + time:**
```javascript
function getFormDataObject() {
    var fromDate = $('#from').val();        // "2025-10-06"
    var fromTime = $('#from_time').val();   // "10:15"
    var toDate = $('#to').val();            // "2025-10-06"
    var toTime = $('#to_time').val();       // "14:30"
    
    return {
        from: fromDate + ' ' + fromTime + ':00',  // "2025-10-06 10:15:00"
        to: toDate + ' ' + toTime + ':59'         // "2025-10-06 14:30:59"
    };
}
```

### API Format

**Sent to API:**
```
from=2025-10-06 10:15:00
to=2025-10-06 14:30:59
qtlimit=1
```

**API receives:**
```php
$fromDateTime = $_REQUEST['from'];  // "2025-10-06 10:15:00"
$toDateTime = $_REQUEST['to'];      // "2025-10-06 14:30:59"
```

### Default Values

**If time not specified:**
- From time defaults to `00:00` (start of day)
- To time defaults to `23:59` (end of day)

**Example:**
```
From: 2025-10-06 [empty]
To: 2025-10-06 [empty]

Becomes:
from=2025-10-06 00:00:00
to=2025-10-06 23:59:59
```

## Styling

### Visual Design

**DateTime Groups:**
- Light gray background (`#f5f5f5`)
- Rounded corners
- Subtle border
- Grouped appearance

**Time Inputs:**
- Fixed width (90px)
- Consistent padding
- Native browser time picker

### Responsive Layout

Uses flexbox for clean layout:
```css
.filter-form {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}
```

## Browser Compatibility

### Time Input Support

| Browser | Support | Fallback |
|---------|---------|----------|
| Chrome 90+ | ✅ Native picker | - |
| Firefox 88+ | ✅ Native picker | - |
| Safari 14+ | ✅ Native picker | - |
| Edge 90+ | ✅ Native picker | - |
| IE 11 | ⚠️ Text input | Manual HH:MM entry |

**Note:** Older browsers show text input instead of time picker, but still work.

## Integration with Existing Features

### Works With Zoom

1. Load graph with date range
2. Zoom into specific hours
3. Click "Auto Re-granulate"
4. **Time fields update automatically** ✅

### Works With Caching

**Cache keys include time:**
```
Before: cache_key = MD5("2025-10-06_2025-10-06_...")
After:  cache_key = MD5("2025-10-06 10:15:00_2025-10-06 14:30:00_...")
```

**Result:** More granular caching, better hit rates

### Works With All Granularities

- **Daily:** Time usually 00:00 to 23:59
- **Hourly:** Time specifies hour boundaries
- **Minute:** Time specifies minute boundaries
- **Second:** Time specifies exact seconds

## Examples

### Example 1: Morning Rush Hour

**Goal:** Analyze 7am-10am traffic

**Input:**
- From: `2025-10-06` `07:00`
- To: `2025-10-06` `10:00`
- Granularity: Hourly

**Result:** 3 hours of data (7am, 8am, 9am)

### Example 2: Lunch Break Analysis

**Goal:** See queries during lunch (12pm-1pm)

**Input:**
- From: `2025-10-06` `12:00`
- To: `2025-10-06` `13:00`
- Granularity: Minute

**Result:** 60 minutes of data

### Example 3: Exact Incident Window

**Goal:** Incident at 14:23:45, analyze ±5 minutes

**Input:**
- From: `2025-10-06` `14:18`
- To: `2025-10-06` `14:28`
- Granularity: Second

**Result:** 10 minutes = 600 seconds of data

### Example 4: Multi-Day Same Hours

**Goal:** Compare 9am-5pm across 3 days

**Day 1:**
- From: `2025-10-06` `09:00`
- To: `2025-10-06` `17:00`

**Day 2:**
- From: `2025-10-07` `09:00`
- To: `2025-10-07` `17:00`

**Day 3:**
- From: `2025-10-08` `09:00`
- To: `2025-10-08` `17:00`

## Backward Compatibility

### URL Parameters

**Old format still works:**
```
?from=2025-10-06&to=2025-10-07
```
Defaults to:
```
from=2025-10-06 00:00:00
to=2025-10-07 23:59:59
```

**New format:**
```
?from=2025-10-06&from_time=10:15&to=2025-10-06&to_time=14:30
```
Results in:
```
from=2025-10-06 10:15:00
to=2025-10-06 14:30:59
```

### API Compatibility

**API accepts both:**
- `from=2025-10-06` (date only)
- `from=2025-10-06 10:15:00` (datetime)

**Processing:**
```php
$fromDateTime = $_REQUEST['from'] . ' 00:00:00';  // Old way
$fromDateTime = $_REQUEST['from'];                 // New way (already has time)
```

## Tips & Best Practices

### Efficient Querying

1. **Use time ranges for large datasets**
   - Instead of querying entire day
   - Specify exact hours of interest
   - Faster queries, less data

2. **Combine with granularity**
   - 8-hour range → Use hourly granularity
   - 1-hour range → Use minute granularity
   - 10-minute range → Use second granularity

3. **Leverage auto-regranulate**
   - Start with broad view
   - Zoom into problem area
   - Auto-regranulate updates time fields
   - Perfect precision automatically

### Common Patterns

**Business Hours Analysis:**
```
From: [Date] 09:00
To: [Date] 17:00
```

**Night Shift:**
```
From: [Date] 22:00
To: [Next Date] 06:00
```

**Peak Hour:**
```
From: [Date] 12:00
To: [Date] 13:00
```

**Specific Incident:**
```
From: [Date] [Incident Time - 15min]
To: [Date] [Incident Time + 15min]
```

## Troubleshooting

### Issue: Time not saving

**Symptom:** Time resets to 00:00 or 23:59

**Solution:**
- Ensure browser supports `<input type="time">`
- Check browser console for errors
- Try manual entry (HH:MM format)

### Issue: Wrong timezone

**Symptom:** Times don't match database

**Solution:**
- Times are in browser's local timezone
- Database stores in server timezone
- Check PHP timezone settings
- Verify MySQL timezone

### Issue: Seconds not precise

**Symptom:** Queries at 10:15:00 miss 10:14:59

**Solution:**
- System adds `:00` to from time (start of minute)
- System adds `:59` to to time (end of minute)
- This captures full minute range
- For exact second, use second granularity

## Future Enhancements

Potential improvements:

1. **Second precision input**
   - Add seconds to time picker
   - Format: HH:MM:SS
   - For ultra-precise ranges

2. **Quick time presets**
   - Buttons: "Last Hour", "Last 15 min", "Business Hours"
   - One-click common ranges

3. **Relative time**
   - "Last 2 hours from now"
   - "30 minutes ago to now"
   - Dynamic ranges

4. **Time range validation**
   - Warn if From > To
   - Suggest swapping
   - Prevent invalid ranges

---

**Updated:** 2025-10-06  
**Version:** 6.3  
**Status:** ✅ Complete and Tested
