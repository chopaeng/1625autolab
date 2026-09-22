/**
 * ========================================================================================
 * 1625 AUTOLAB - GOOGLE SHEETS BIDIRECTIONAL LIVE SYNC SCRIPT
 * ========================================================================================
 * Version: 2.5.0 (Added Admin Notes column support)
 *
 * This Google Apps Script powers real-time two-way synchronization between your Google
 * Spreadsheet ('Sales' sheet) and Apollo:
 *
 *  1. [Apollo -> Google Sheets]
 *     When a customer books or an admin updates an inquiry on the website (e.g. Status change),
 *     Apollo sends a webhook POST to this script. This script matches the existing row via
 *     Inquiry ID, Reference Number, Phone, or Plate, and updates the row in-place without duplicating.
 *
 *  2. [Google Sheets -> Apollo]
 *     When staff/admin edits any cell on the data rows (Status, Date, Time, Name, Admin Notes, etc.),
 *     the installable onEdit trigger instantly sends the updated row back to Apollo.
 *
 *  3. [One-Click Bulk Sync]
 *     Use the custom menu "🏎️ 1625 AutoLab" in Google Sheets to push all rows, pull all rows,
 *     or install the automatic trigger.
 * ========================================================================================
 */

// ----------------------------------------------------------------------------------------
// CONFIGURATION
// ----------------------------------------------------------------------------------------
var CONFIG = {
  // Your website's inbound webhook URL
  DEFAULT_API_URL: 'https://api.1625autolab.com/api/integrations/google-sheets/inbound',
  // Your Webhook Secret Key
  DEFAULT_SECRET: '3213e76c579444693434',
  // Target Sheet Name
  SHEET_NAME: 'Sales',
  // Default Header Row: Row 1 (Headers on Row 1, Data rows start at Row 2)
  HEADER_ROW: 1,
};

// Standard Column definitions matching your 'Sales' sheet order:
// NOTE: Column order MUST match the actual Google Sheet header row exactly.
// If you reorder columns in the sheet, update this array to match.
var COLUMNS = [
  'Timestamp',           // Col 1  (A)
  'Reference Number',    // Col 2  (B)
  'Inquiry ID',          // Col 3  (C)
  'Full Name',           // Col 4  (D)
  'Email address',       // Col 5  (E)
  'Address',             // Col 6  (F)
  'Contact Number',      // Col 7  (G)
  'Facebook Name',       // Col 8  (H)
  'Car Make',            // Col 9  (I)
  'Car Model',           // Col 10 (J)
  'Year Model',          // Col 11 (K)
  'Service Name',        // Col 12 (L)
  'Product to Purchase', // Col 13 (M)
  'Service Type',        // Col 14 (N)
  'Plate Number',        // Col 15 (O)
  'Appointment Date',    // Col 16 (P)
  'Appointment Time',    // Col 17 (Q)
  'Status',              // Col 18 (R)
  'Admin Notes',         // Col 19 (S)
  'Last Updated',        // Col 20 (T)
];

// Default fallback column number for Sync Status (Column AU = 47).
// The script dynamically locates the 'Sync' column from the sheet's header row,
// so if your Sync column is AU (47), AS (45), or anywhere else, it automatically maps to it.
var SYNC_STATUS_COL = 47; // Default fallback: Column AU

/**
 * Returns true if a cell value contains a sync status string (e.g. '✅ Synced (Apollo)', 'Synced (00:39:26)')
 * rather than an actual user note.
 */
function isSyncStatusValue(val) {
  var s = String(val || '').trim().toLowerCase();
  if (!s) return false;
  return s.indexOf('synced') !== -1 || s.indexOf('apollo') !== -1 || s.indexOf('error:') !== -1 || s.indexOf('✅') !== -1 || s.indexOf('❌') !== -1;
}

/**
 * Dynamically resolves the Sync Status column index.
 * 1. Checks colMap['Sync Status'] or any header containing 'sync'
 * 2. Scans the sheet's header row for 'sync'
 * 3. Falls back to SYNC_STATUS_COL (47 / Column AU)
 */
function getSyncStatusCol(sheet, colMap) {
  if (colMap) {
    if (colMap['Sync Status']) return colMap['Sync Status'];
    if (colMap['sync']) return colMap['sync'];
    for (var key in colMap) {
      if (key.toLowerCase().indexOf('sync') !== -1 && key.toLowerCase().indexOf('note') === -1) {
        return colMap[key];
      }
    }
  }
  if (sheet) {
    try {
      var headerRow = getHeaderRow(sheet);
      var lastCol = sheet.getLastColumn();
      if (lastCol > 0) {
        var headers = sheet.getRange(headerRow, 1, 1, lastCol).getValues()[0];
        for (var i = 0; i < headers.length; i++) {
          var h = String(headers[i] || '').toLowerCase();
          if (h.indexOf('sync') !== -1 && h.indexOf('note') === -1) {
            return i + 1;
          }
        }
      }
    } catch (e) {}
  }
  return SYNC_STATUS_COL;
}

// ----------------------------------------------------------------------------------------
// SCRIPT PROPERTIES (Storage for Website URL & Secret Key)
// ----------------------------------------------------------------------------------------
function getProperty(key, fallback) {
  var props = PropertiesService.getScriptProperties();
  var val = props.getProperty(key);
  return val ? val : fallback;
}

function setProperty(key, val) {
  PropertiesService.getScriptProperties().setProperty(key, val);
}

function getApiUrl() {
  return getProperty('APOLLO_API_URL', CONFIG.DEFAULT_API_URL);
}

function getWebhookSecret() {
  return getProperty('APOLLO_SECRET', CONFIG.DEFAULT_SECRET);
}

function initialSetup() {
  var activeSpreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  PropertiesService.getScriptProperties().setProperty('key', activeSpreadsheet.getId());
}

// ----------------------------------------------------------------------------------------
// NORMALIZATION HELPERS
// ----------------------------------------------------------------------------------------
function cleanDigits(val) {
  var d = String(val || '').replace(/\D/g, '');
  return d.length >= 10 ? d.slice(-10) : d;
}

function cleanAlphanum(val) {
  return String(val || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

// ----------------------------------------------------------------------------------------
// WEBHOOK INBOUND: Handles HTTP POST from Apollo (Apollo -> Sheets)
// ----------------------------------------------------------------------------------------
function doPost(e) {
  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(30000); // 30 s — bulk payloads can take longer to process
  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({
      success: false,
      error: 'Lock timeout. Another sync is currently in progress.'
    })).setMimeType(ContentService.MimeType.JSON);
  }

  try {
    var rawPostData = e && e.postData ? e.postData.contents : '';
    var data = {};

    if (rawPostData) {
      try {
        data = JSON.parse(rawPostData);
      } catch (parseErr) {
        data = (e && e.parameter) ? e.parameter : {};
      }
    } else if (e && e.parameter) {
      data = e.parameter;
    }

    // Handle bulk sync payload { action: 'sync_all', rows: [...] }
    if (data.action === 'sync_all' && Array.isArray(data.rows)) {
      var syncResult = bulkSyncRowsFromApollo(data.rows);
      return ContentService.createTextOutput(JSON.stringify(syncResult))
        .setMimeType(ContentService.MimeType.JSON);
    }

    // Single inquiry sync
    var result = upsertInquiryRow(data, true);
    return ContentService.createTextOutput(JSON.stringify(result))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({
      success: false,
      error: error.toString()
    })).setMimeType(ContentService.MimeType.JSON);
  } finally {
    lock.releaseLock();
  }
}

// ----------------------------------------------------------------------------------------
// WEBHOOK READ: Handles HTTP GET from Apollo (Apollo pulling all Sheet rows)
// ----------------------------------------------------------------------------------------
function doGet(e) {
  var action = (e && e.parameter && e.parameter.action) ? e.parameter.action : 'get_all';

  if (action === 'ping') {
    return ContentService.createTextOutput(JSON.stringify({
      status: 'ok',
      timestamp: new Date().toISOString(),
      scriptName: '1625 AutoLab Live Sync v2.5.0'
    })).setMimeType(ContentService.MimeType.JSON);
  }

  if (action === 'get_all') {
    try {
      var sheet = getOrCreateTargetSheet();
      var rows = getAllInquiryObjects(sheet);
      return ContentService.createTextOutput(JSON.stringify({
        success: true,
        count: rows.length,
        data: rows,
        timestamp: new Date().toISOString()
      })).setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService.createTextOutput(JSON.stringify({
        success: false,
        error: error.toString()
      })).setMimeType(ContentService.MimeType.JSON);
    }
  }

  return ContentService.createTextOutput(JSON.stringify({
    success: true,
    message: '1625 AutoLab Google Sheets Sync Webhook is active.'
  })).setMimeType(ContentService.MimeType.JSON);
}

// ----------------------------------------------------------------------------------------
// REAL-TIME EDIT TRIGGER: Handles user edits in Google Sheets (Sheets -> Apollo)
// ----------------------------------------------------------------------------------------
function installedOnEdit(e) {
  if (!e || !e.range) return;

  var sheet = e.range.getSheet();
  var sheetName = sheet.getName();
  var targetSheet = getOrCreateTargetSheet();

  if (sheetName !== targetSheet.getName()) {
    return; // Edit was on another sheet tab
  }

  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;

  var row = e.range.getRow();
  if (row < startDataRow) {
    return; // Headers or top title rows were edited - ignore
  }

  // Prevent sync loops if the update was triggered programmatically by doPost or by the sort.
  var cache = CacheService.getScriptCache();
  if (cache.get('SUPPRESS_ALL_ONEDITS') === 'true') {
    return;
  }
  if (cache.get('SUPPRESS_ON_EDIT_' + row) === 'true') {
    return;
  }

  // Sync this single edited row to Apollo
  syncSingleRowToApollo(sheet, row);
}

/**
 * Normalizes and sends a single row from Google Sheets to Apollo API
 */
function syncSingleRowToApollo(sheet, rowNumber) {
  var apiUrl = getApiUrl();
  var secret = getWebhookSecret();

  if (!apiUrl) {
    showToast('⚠️ Website Webhook URL is not set. Go to "🏎️ 1625 AutoLab" menu > "⚙️ Configure".', 'Setup Needed', 8);
    return { success: false, error: 'API URL not configured' };
  }

  var rowData = getRowDataObject(sheet, rowNumber);

  // If row has no full name and no contact and no reference number, skip syncing
  if (!rowData.fullName && !rowData.contactNumber && !rowData.referenceNumber) {
    return { success: false, error: 'Row is empty' };
  }

  try {
    var body = Object.assign({
      source: 'google_sheets',
      rowNumber: rowNumber,
      timestamp: new Date().toISOString()
    }, rowData);
    var payload = JSON.stringify(body);

    var options = {
      method: 'post',
      contentType: 'application/json',
      payload: payload,
      muteHttpExceptions: true,
      headers: {
        'X-Sheets-Secret': secret,
        'Accept': 'application/json'
      }
    };

    var response = UrlFetchApp.fetch(apiUrl, options);
    var responseCode = response.getResponseCode();
    var responseBody = response.getContentText();
    var result = {};

    try {
      result = JSON.parse(responseBody);
    } catch (parseErr) {
      result = { raw: responseBody };
    }

    var colMap = getColumnMap(sheet);
    var syncCol = getSyncStatusCol(sheet, colMap);
    var nowFormatted = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm:ss');

    if (responseCode >= 200 && responseCode < 300 && result.success !== false) {
      // Update Sync Status and Last Updated in the sheet
      sheet.getRange(rowNumber, syncCol).setValue('✅ Synced (' + Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'HH:mm:ss') + ')');
      if (colMap['Last Updated']) {
        sheet.getRange(rowNumber, colMap['Last Updated']).setValue(nowFormatted);
      }
      // If Apollo generated or returned Reference Number or ID, write it to sheet so future edits match instantly
      var retInquiry = result.inquiry || {};
      var retRef = retInquiry.referenceNumber || retInquiry.reference_number || result.referenceNumber || '';
      var retId = retInquiry.id || retInquiry.inquiryId || result.inquiryId || '';

      if (retRef && colMap['Reference Number']) {
        sheet.getRange(rowNumber, colMap['Reference Number']).setValue(retRef);
      }
      if (retId && colMap['Inquiry ID']) {
        sheet.getRange(rowNumber, colMap['Inquiry ID']).setValue(retId);
      }

      return { success: true, response: result };
    } else {
      var errMsg = result.error || ('HTTP ' + responseCode);
      sheet.getRange(rowNumber, syncCol).setValue('❌ Error: ' + errMsg);
      return { success: false, error: errMsg };
    }
  } catch (err) {
    var syncColCatch = (typeof getSyncStatusCol === 'function') ? getSyncStatusCol(sheet, getColumnMap(sheet)) : SYNC_STATUS_COL;
    sheet.getRange(rowNumber, syncColCatch).setValue('❌ ' + err.message);
    return { success: false, error: err.message };
  }
}

// ----------------------------------------------------------------------------------------
// ROW UPSERT LOGIC (Apollo -> Sheets) - Multi-Tier Matching to Prevent Duplication
// ----------------------------------------------------------------------------------------
function upsertInquiryRow(inquiry, isFromApi) {
  var sheet = getOrCreateTargetSheet();
  var colMap = getColumnMap(sheet);

  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;

  var inqId = String(inquiry.id || inquiry.inquiryId || inquiry.inquiry_id || inquiry['Inquiry ID'] || '').trim().toLowerCase();

  var lastRow = sheet.getLastRow();
  var targetRow = -1;

  // Inquiry ID is the only match key — no fuzzy phone/name/date fallback.
  // Each inquiry is its own unique record; matching by anything other than
  // its ID would merge separate bookings from the same customer.
  if (inqId && inqId.length >= 6 && lastRow >= startDataRow) {
    var numRows = lastRow - headerRow;
    var dataRange = sheet.getRange(startDataRow, 1, numRows, sheet.getLastColumn());
    var values = dataRange.getValues();
    var idColIdx = colMap['Inquiry ID'] ? colMap['Inquiry ID'] - 1 : 2;

    for (var i = 0; i < values.length; i++) {
      var rowId = String(values[i][idColIdx] || '').toLowerCase().trim();
      if (rowId === inqId) {
        targetRow = startDataRow + i;
        break;
      }
    }
  }

  var isNewRow = (targetRow === -1);
  if (isNewRow) {
    // Insert at the top (right below headers), pushing existing data down
    sheet.insertRowBefore(startDataRow);
    targetRow = startDataRow;
  }

  // Set suppression flag in cache to prevent onEdit trigger echo loop
  if (isFromApi) {
    CacheService.getScriptCache().put('SUPPRESS_ON_EDIT_' + targetRow, 'true', 30);
  }

  var syncCol = getSyncStatusCol(sheet, colMap);
  var numCols = Math.max(sheet.getLastColumn(), COLUMNS.length, syncCol);
  var existingRowData = isNewRow ? null : sheet.getRange(targetRow, 1, 1, numCols).getValues()[0];
  var rowArray = buildRowArray(inquiry, colMap, numCols, existingRowData);

  // Suppress ALL onEdit callbacks while we write + sort so no row bounces back to Apollo
  var cache = CacheService.getScriptCache();
  if (isFromApi) {
    cache.put('SUPPRESS_ALL_ONEDITS', 'true', 30);
    cache.put('SUPPRESS_ON_EDIT_' + targetRow, 'true', 30);
  }

  try {
    // Write values to target row (preserving any table validations/dropdowns)
    sheet.getRange(targetRow, 1, 1, rowArray.length).setValues([rowArray]);

    // Apply row formatting
    var valStatus = String(inquiry.status || inquiry.Status || 'pending').toLowerCase().trim();
    applyRowStyles(sheet, targetRow, valStatus);

    // Re-sort the sheet so the latest appointment date is always at the top
    sortByAppointmentDate(sheet);
  } finally {
    if (isFromApi) {
      cache.remove('SUPPRESS_ALL_ONEDITS');
    }
  }

  var valRef = String(inquiry.referenceNumber || inquiry.reference_number || inquiry['Reference Number'] || '').trim();
  var valId = inqId;

  return {
    success: true,
    action: isNewRow ? 'created' : 'updated',
    row: targetRow,
    rowNumber: targetRow,
    ref: valRef,
    referenceNumber: valRef,
    inquiryId: valId
  };
}

/**
 * Builds a 1D row array mapped precisely to column indices defined in colMap.
 */
function buildRowArray(inquiry, colMap, totalCols, existingRowValues) {
  var now = new Date();
  var valCreated = inquiry.timestamp || inquiry['Timestamp'] || inquiry.createdAt || inquiry.created_at || Utilities.formatDate(now, Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm:ss');
  var valRef = String(inquiry.referenceNumber || inquiry.reference_number || inquiry['Reference Number'] || '').trim();
  var valId = String(inquiry.id || inquiry.inquiryId || inquiry.inquiry_id || inquiry['Inquiry ID'] || '').trim();
  var valName = String(inquiry.fullName || inquiry.full_name || inquiry['Full Name'] || inquiry.customerName || '').trim();
  var valEmail = String(inquiry.emailAddress || inquiry.email_address || inquiry['Email address'] || inquiry['Email Address'] || '').trim();
  var valAddr = String(inquiry.address || inquiry.Address || '').trim();
  var valPhone = String(inquiry.contactNumber || inquiry.contact_number || inquiry['Contact Number'] || inquiry.phone || '').trim();
  var valFb = String(inquiry.facebookName || inquiry.facebook_name || inquiry['Facebook Name'] || '').trim();
  var valMake = String(inquiry.make || inquiry.carMake || inquiry['Car Make'] || '').trim();
  var valModel = String(inquiry.model || inquiry.carModel || inquiry['Car Model'] || '').trim();
  var valYear = String(inquiry.yearModel || inquiry.year_model || inquiry['Year Model'] || inquiry.year || '').trim();
  var rawSt = String(inquiry.serviceType || inquiry.service_type || inquiry['Service Type'] || inquiry['Service Location'] || inquiry.serviceLocation || '').toLowerCase().trim();
  var valServiceType = (rawSt === 'home_service' || rawSt === 'home service') ? 'Home Service' : 'Shop Visit';
  var valService = String(inquiry.serviceName || inquiry.service_name || inquiry['Service Name'] || inquiry.service || '').trim();
  var valProduct = String(inquiry.productToPurchase || inquiry.product_to_purchase || inquiry['Product to Purchase'] || '').trim();
  var valPlate = String(inquiry.plateNumber || inquiry.plate_number || inquiry['Plate Number'] || '').trim();
  var valDate = String(inquiry.appointmentDate || inquiry.appointment_date || inquiry['Appointment Date'] || '').trim();
  var valTime = String(inquiry.appointmentTime || inquiry.appointment_time || inquiry['Appointment Time'] || '').trim();
  var valStatus = String(inquiry.status || inquiry.Status || 'pending').toLowerCase().trim();
  var valNotes = String(inquiry.adminNotes || inquiry.admin_notes || inquiry['Admin Notes'] || inquiry.internalNotes || inquiry.internal_notes || inquiry['Internal Notes'] || inquiry.notes || '').trim();
  if (isSyncStatusValue(valNotes)) {
    valNotes = '';
  }
  var valUpdated = inquiry.lastUpdated || inquiry['Last Updated'] || Utilities.formatDate(now, Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm:ss');
  var valSync = '✅ Synced (Apollo)';

  var syncCol = (typeof getSyncStatusCol === 'function') ? getSyncStatusCol(null, colMap) : SYNC_STATUS_COL;
  var numCols = Math.max(totalCols || 0, COLUMNS.length, syncCol);
  var row = existingRowValues ? existingRowValues.slice(0, numCols) : new Array(numCols).fill('');
  while (row.length < numCols) {
    row.push('');
  }

  function setField(colName, fallbackIdx, value) {
    var colIdx = (colMap && (colMap[colName] || colMap[colName.toLowerCase()])) ? (colMap[colName] || colMap[colName.toLowerCase()]) : null;
    if (!colIdx) {
      // Only use fallbackIdx if that column is not already claimed by another header
      var isOccupied = false;
      if (colMap && fallbackIdx) {
        for (var h in colMap) {
          if (colMap[h] === fallbackIdx && h.toLowerCase() !== colName.toLowerCase()) {
            isOccupied = true;
            break;
          }
        }
      }
      if (!isOccupied) {
        colIdx = fallbackIdx;
      }
    }
    if (colIdx && colIdx <= row.length) {
      if (colName === 'Admin Notes' || colName === 'Notes') {
        var existingCellVal = existingRowValues ? String(existingRowValues[colIdx - 1] || '').trim() : '';
        // If the existing cell has a misplaced sync status (e.g. 'Synced (Apollo)'), NEVER keep it!
        if (isSyncStatusValue(existingCellVal)) {
          row[colIdx - 1] = value;
          return;
        }
        // If incoming note is empty and existing cell has genuine note text, preserve it
        if (value === '' && existingCellVal !== '') {
          return;
        }
      }
      row[colIdx - 1] = value;
    }
  }

  // Fallback column indices MUST match the COLUMNS array order above.
  setField('Timestamp', 1, valCreated);
  setField('Reference Number', 2, valRef);
  setField('Inquiry ID', 3, valId);
  setField('Full Name', 4, valName);
  setField('Email address', 5, valEmail);
  setField('Address', 6, valAddr);
  setField('Contact Number', 7, valPhone);
  setField('Facebook Name', 8, valFb);
  setField('Car Make', 9, valMake);
  setField('Car Model', 10, valModel);
  setField('Year Model', 11, valYear);
  setField('Service Name', 12, valService);        // col 12 in actual sheet
  setField('Product to Purchase', 13, valProduct); // col 13 in actual sheet
  setField('Service Type', 14, valServiceType);    // col 14 in actual sheet
  setField('Plate Number', 15, valPlate);
  setField('Appointment Date', 16, valDate);
  setField('Appointment Time', 17, valTime);
  setField('Status', 18, valStatus);
  setField('Admin Notes', 19, valNotes);
  setField('Last Updated', 20, valUpdated);

  // Set Sync Status at the resolved sync column (e.g. Column AU / 47)
  if (syncCol && syncCol <= row.length) {
    row[syncCol - 1] = valSync;
  }

  return row;
}

/**
 * Bulk sync an array of inquiries from Apollo into Google Sheets in sub-2-second in-memory batches.
 */
function bulkSyncRowsFromApollo(rows) {
  if (!rows || !Array.isArray(rows) || rows.length === 0) {
    return { success: true, processedCount: 0, updated: 0, created: 0 };
  }

  var sheet = getOrCreateTargetSheet();
  var colMap = getColumnMap(sheet);
  var syncCol = getSyncStatusCol(sheet, colMap);
  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;
  var lastRow = sheet.getLastRow();
  var numCols = Math.max(sheet.getLastColumn(), COLUMNS.length, syncCol);

  var refColIdx    = colMap['Reference Number']  ? colMap['Reference Number']  - 1 : 1;
  var idColIdx     = colMap['Inquiry ID']         ? colMap['Inquiry ID']         - 1 : 2;
  var nameColIdx   = colMap['Full Name']          ? colMap['Full Name']          - 1 : 3;
  var emailColIdx  = colMap['Email address']      ? colMap['Email address']      - 1 : 4;
  var phoneColIdx  = colMap['Contact Number']     ? colMap['Contact Number']     - 1 : 6;
  var plateColIdx  = colMap['Plate Number']       ? colMap['Plate Number']       - 1 : 14;
  var dateColIdx   = colMap['Appointment Date']   ? colMap['Appointment Date']   - 1 : 15;
  var statusColIdx = colMap['Status']             ? colMap['Status']             - 1 : 17;

  var numExistingRows = lastRow >= startDataRow ? (lastRow - headerRow) : 0;
  var existingValues  = numExistingRows > 0
    ? sheet.getRange(startDataRow, 1, numExistingRows, numCols).getValues()
    : [];

  // ── Pre-build Inquiry ID index for O(1) matching ─────────────────────────
  // Inquiry ID is the sole match key — fuzzy phone/name/date/email matching
  // is intentionally removed to prevent separate bookings from the same
  // customer being merged into a single row.
  var idxById = {};  // normalized inquiry ID → array index

  for (var ei = 0; ei < existingValues.length; ei++) {
    var eId = String(existingValues[ei][idColIdx] || '').toLowerCase().trim();
    if (eId && eId.length >= 6) { idxById[eId] = ei; }
  }
  // ──────────────────────────────────────────────────────────────────────────

  var updatedCount = 0;
  var createdCount = 0;
  var newRows = [];

  for (var i = 0; i < rows.length; i++) {
    var inquiry = rows[i];
    if (!inquiry) continue;

    var inqId = String(inquiry.id || inquiry.inquiryId || inquiry.inquiry_id || inquiry['Inquiry ID'] || '').trim().toLowerCase();

    var matchedIdx = -1;

    // Inquiry ID only — no fuzzy fallback.
    if (inqId && inqId.length >= 6 && idxById[inqId] !== undefined) {
      matchedIdx = idxById[inqId];
    }

    if (matchedIdx !== -1) {
      existingValues[matchedIdx] = buildRowArray(inquiry, colMap, numCols, existingValues[matchedIdx]);
      updatedCount++;
    } else {
      newRows.push(buildRowArray(inquiry, colMap, numCols, null));
      createdCount++;
    }
  }

  // Combine: new rows prepended to top (under header), followed by updated existing rows
  var combinedValues = newRows.concat(existingValues);
  if (combinedValues.length > 0) {
    // Suppress ALL onEdit callbacks for the entire bulk write + sort block.
    // Without this, sort() and setValues() physically move rows and the installable
    // onEdit fires for every displaced row, sending them all back to Apollo as edits
    // which Apollo wrongly interprets as reschedules or status changes.
    var cache = CacheService.getScriptCache();
    cache.put('SUPPRESS_ALL_ONEDITS', 'true', 60);

    try {
      sheet.getRange(startDataRow, 1, combinedValues.length, numCols).setValues(combinedValues);

      // Batch apply status cell formatting in one single call
      var statusBg     = [];
      var statusColors = [];
      for (var k = 0; k < combinedValues.length; k++) {
        var st = String(combinedValues[k][statusColIdx] || '').toLowerCase().trim();
        if (st === 'confirmed') {
          statusBg.push(['#dcfce7']); statusColors.push(['#166534']);
        } else if (st === 'in_progress' || st === 'in progress') {
          statusBg.push(['#fef3c7']); statusColors.push(['#92400e']);
        } else if (st === 'completed') {
          statusBg.push(['#e0e7ff']); statusColors.push(['#3730a3']);
        } else if (st === 'cancelled') {
          statusBg.push(['#fee2e2']); statusColors.push(['#991b1b']);
        } else {
          statusBg.push(['#fef9c3']); statusColors.push(['#854d0e']);
        }
      }
      var statusRange = sheet.getRange(startDataRow, statusColIdx + 1, combinedValues.length, 1);
      statusRange.setBackgrounds(statusBg).setFontColors(statusColors).setFontWeight('bold');

      // Re-sort so the latest appointment date is always at the top
      sortByAppointmentDate(sheet);
    } finally {
      cache.remove('SUPPRESS_ALL_ONEDITS');
    }
  }

  return {
    success: true,
    processedCount: rows.length,
    updated: updatedCount,
    created: createdCount
  };
}

// ----------------------------------------------------------------------------------------
// DATA EXTRACTION (Reading Sheet Rows -> Apollo Inquiries)
// ----------------------------------------------------------------------------------------
function getRowDataObject(sheet, rowNumber) {
  var colMap = getColumnMap(sheet);
  var numCols = sheet.getLastColumn();
  if (numCols < 1) return {};

  var rowValues = sheet.getRange(rowNumber, 1, 1, numCols).getValues()[0];

  function getVal(colName, fallbackColIdx) {
    var colIdx = colMap ? (colMap[colName] || colMap[colName.toLowerCase()]) : null;
    if (!colIdx) {
      var isOccupied = false;
      if (colMap && fallbackColIdx) {
        for (var h in colMap) {
          if (colMap[h] === fallbackColIdx && h.toLowerCase() !== colName.toLowerCase()) {
            isOccupied = true;
            break;
          }
        }
      }
      if (!isOccupied) {
        colIdx = fallbackColIdx;
      }
    }
    if (colIdx && colIdx <= rowValues.length) {
      var v = rowValues[colIdx - 1];
      if (v instanceof Date) {
        return Utilities.formatDate(v, Session.getScriptTimeZone(), 'yyyy-MM-dd');
      }
      return (v === null || v === undefined) ? '' : String(v).trim();
    }
    return '';
  }

  var notesVal = getVal('Admin Notes', 19) || getVal('Internal Notes', 19) || getVal('Notes', 19);
  if (isSyncStatusValue(notesVal)) {
    notesVal = '';
  }

  return {
    timestamp: getVal('Timestamp', 1),
    referenceNumber: getVal('Reference Number', 2),
    id: getVal('Inquiry ID', 3),
    inquiryId: getVal('Inquiry ID', 3),
    fullName: getVal('Full Name', 4),
    emailAddress: getVal('Email address', 5) || getVal('Email Address', 5),
    address: getVal('Address', 6),
    contactNumber: getVal('Contact Number', 7),
    facebookName: getVal('Facebook Name', 8),
    make: getVal('Car Make', 9) || getVal('Make', 9),
    model: getVal('Car Model', 10) || getVal('Model', 10),
    yearModel: getVal('Year Model', 11) || getVal('Year', 11),
    serviceName: getVal('Service Name', 12) || getVal('Service', 12),
    productToPurchase: getVal('Product to Purchase', 13) || getVal('Product', 13),
    serviceType: getVal('Service Type', 14) || getVal('Service Location', 14),
    plateNumber: getVal('Plate Number', 15) || getVal('Plate', 15),
    appointmentDate: getVal('Appointment Date', 16),
    appointmentTime: getVal('Appointment Time', 17),
    status: getVal('Status', 18),
    adminNotes: notesVal,
    internalNotes: notesVal,
    lastUpdated: getVal('Last Updated', 20)
  };
}

function getAllInquiryObjects(sheet) {
  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;
  var lastRow = sheet.getLastRow();
  if (lastRow < startDataRow) return [];

  var numCols = sheet.getLastColumn();
  if (numCols < 1) return [];

  var colMap = getColumnMap(sheet);
  var numRows = lastRow - headerRow;
  var allValues = sheet.getRange(startDataRow, 1, numRows, numCols).getValues();

  function getValFromRow(rowValues, colName, fallbackColIdx) {
    var colIdx = colMap ? (colMap[colName] || colMap[colName.toLowerCase()]) : null;
    if (!colIdx) {
      var isOccupied = false;
      if (colMap && fallbackColIdx) {
        for (var h in colMap) {
          if (colMap[h] === fallbackColIdx && h.toLowerCase() !== colName.toLowerCase()) {
            isOccupied = true;
            break;
          }
        }
      }
      if (!isOccupied) {
        colIdx = fallbackColIdx;
      }
    }
    if (colIdx && colIdx <= rowValues.length) {
      var v = rowValues[colIdx - 1];
      if (v instanceof Date) {
        return Utilities.formatDate(v, Session.getScriptTimeZone(), 'yyyy-MM-dd');
      }
      return (v === null || v === undefined) ? '' : String(v).trim();
    }
    return '';
  }

  var result = [];
  for (var i = 0; i < allValues.length; i++) {
    var rowValues = allValues[i];
    var notesVal = getValFromRow(rowValues, 'Admin Notes', 19) || getValFromRow(rowValues, 'Internal Notes', 19) || getValFromRow(rowValues, 'Notes', 19);
    if (isSyncStatusValue(notesVal)) {
      notesVal = '';
    }
    var obj = {
      timestamp: getValFromRow(rowValues, 'Timestamp', 1),
      referenceNumber: getValFromRow(rowValues, 'Reference Number', 2),
      id: getValFromRow(rowValues, 'Inquiry ID', 3),
      inquiryId: getValFromRow(rowValues, 'Inquiry ID', 3),
      fullName: getValFromRow(rowValues, 'Full Name', 4),
      emailAddress: getValFromRow(rowValues, 'Email address', 5) || getValFromRow(rowValues, 'Email Address', 5),
      address: getValFromRow(rowValues, 'Address', 6),
      contactNumber: getValFromRow(rowValues, 'Contact Number', 7),
      facebookName: getValFromRow(rowValues, 'Facebook Name', 8),
      make: getValFromRow(rowValues, 'Car Make', 9) || getValFromRow(rowValues, 'Make', 9),
      model: getValFromRow(rowValues, 'Car Model', 10) || getValFromRow(rowValues, 'Model', 10),
      yearModel: getValFromRow(rowValues, 'Year Model', 11) || getValFromRow(rowValues, 'Year', 11),
      serviceName: getValFromRow(rowValues, 'Service Name', 12) || getValFromRow(rowValues, 'Service', 12),
      productToPurchase: getValFromRow(rowValues, 'Product to Purchase', 13) || getValFromRow(rowValues, 'Product', 13),
      serviceType: getValFromRow(rowValues, 'Service Type', 14) || getValFromRow(rowValues, 'Service Location', 14),
      plateNumber: getValFromRow(rowValues, 'Plate Number', 15) || getValFromRow(rowValues, 'Plate', 15),
      appointmentDate: getValFromRow(rowValues, 'Appointment Date', 16),
      appointmentTime: getValFromRow(rowValues, 'Appointment Time', 17),
      status: getValFromRow(rowValues, 'Status', 18),
      adminNotes: notesVal,
      internalNotes: notesVal,
      lastUpdated: getValFromRow(rowValues, 'Last Updated', 20)
    };

    if (obj.fullName || obj.contactNumber || obj.referenceNumber || obj.inquiryId) {
      result.push(obj);
    }
  }

  return result;
}

// ----------------------------------------------------------------------------------------
// SHEET STRUCTURE & FORMATTING HELPERS
// ----------------------------------------------------------------------------------------
function getOrCreateTargetSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) {
    sheet = ss.getActiveSheet();
  }
  return sheet;
}

/**
 * Dynamically detects the header row by scanning rows 1 to 15 for recognizable column names.
 * Falls back to CONFIG.HEADER_ROW (default 1).
 */
function getHeaderRow(sheet) {
  var configured = CONFIG.HEADER_ROW || 1;
  var maxRows = Math.min(sheet.getLastRow(), 15);
  if (maxRows < 1) return configured;

  var keyHeaders = ['reference', 'ref', 'inquiry', 'full name', 'name', 'status', 'contact', 'timestamp'];

  // Check configured row first
  if (configured <= maxRows) {
    var confValues = sheet.getRange(configured, 1, 1, sheet.getLastColumn()).getValues()[0];
    var confMatches = 0;
    for (var k = 0; k < confValues.length; k++) {
      var hText = String(confValues[k] || '').toLowerCase().trim();
      for (var j = 0; j < keyHeaders.length; j++) {
        if (hText.indexOf(keyHeaders[j]) !== -1) {
          confMatches++;
          break;
        }
      }
    }
    if (confMatches >= 2) return configured;
  }

  // Scan rows 1 through maxRows
  for (var r = 1; r <= maxRows; r++) {
    var rowValues = sheet.getRange(r, 1, 1, sheet.getLastColumn()).getValues()[0];
    var matches = 0;
    for (var c = 0; c < rowValues.length; c++) {
      var cellText = String(rowValues[c] || '').toLowerCase().trim();
      for (var m = 0; m < keyHeaders.length; m++) {
        if (cellText.indexOf(keyHeaders[m]) !== -1) {
          matches++;
          break;
        }
      }
    }
    if (matches >= 2) {
      return r;
    }
  }

  return configured;
}

/**
 * Builds a column index map that is case-insensitive and alias-aware.
 */
function getColumnMap(sheet) {
  var headerRow = getHeaderRow(sheet);
  var lastCol = sheet.getLastColumn();
  var map = {};
  if (lastCol < 1) return map;

  var headers = sheet.getRange(headerRow, 1, 1, lastCol).getValues()[0];
  for (var i = 0; i < headers.length; i++) {
    var rawH = String(headers[i] || '').trim();
    if (!rawH) continue;
    var normH = rawH.toLowerCase();
    var colNum = i + 1;

    map[rawH] = colNum;
    map[normH] = colNum;

    // Aliases
    if (normH.indexOf('reference') !== -1 || normH.indexOf('ref') !== -1) {
      map['Reference Number'] = colNum;
    }
    if (normH.indexOf('inquiry id') !== -1 || normH === 'id' || normH === 'inquiry_id') {
      map['Inquiry ID'] = colNum;
    }
    if (normH === 'name' || normH.indexOf('full name') !== -1 || normH.indexOf('customer') !== -1) {
      map['Full Name'] = colNum;
    }
    if (normH.indexOf('email') !== -1) {
      map['Email address'] = colNum;
      map['Email Address'] = colNum;
    }
    if (normH.indexOf('contact') !== -1 || normH.indexOf('phone') !== -1 || normH.indexOf('mobile') !== -1) {
      map['Contact Number'] = colNum;
    }
    if (normH.indexOf('facebook') !== -1 || normH === 'fb' || normH === 'fb name') {
      map['Facebook Name'] = colNum;
    }
    if (normH.indexOf('make') !== -1) {
      map['Car Make'] = colNum;
    }
    if (normH.indexOf('model') !== -1 && normH.indexOf('year') === -1) {
      map['Car Model'] = colNum;
    }
    if (normH.indexOf('year') !== -1) {
      map['Year Model'] = colNum;
    }
    if (normH.indexOf('service type') !== -1 || normH.indexOf('service location') !== -1) {
      map['Service Type'] = colNum;
    }
    if (normH.indexOf('service name') !== -1 || normH === 'service') {
      map['Service Name'] = colNum;
    }
    if (normH.indexOf('product') !== -1) {
      map['Product to Purchase'] = colNum;
    }
    if (normH.indexOf('plate') !== -1) {
      map['Plate Number'] = colNum;
    }
    if (normH.indexOf('appointment date') !== -1 || normH === 'date' || normH.indexOf('booking date') !== -1) {
      map['Appointment Date'] = colNum;
    }
    if (normH.indexOf('appointment time') !== -1 || normH === 'time' || normH.indexOf('booking time') !== -1) {
      map['Appointment Time'] = colNum;
    }
    if (normH === 'status' || normH.indexOf('status') !== -1) {
      map['Status'] = colNum;
    }
    if (normH.indexOf('admin note') !== -1 || normH.indexOf('internal note') !== -1 || normH === 'notes' || normH === 'note' || normH === 'admin notes' || normH === 'internal notes' || normH === 'workspace notes') {
      map['Admin Notes'] = colNum;
      map['Internal Notes'] = colNum;
      map['Notes'] = colNum;
      map['notes'] = colNum;
    }
    if (normH.indexOf('sync') !== -1 && normH.indexOf('note') === -1) {
      map['Sync Status'] = colNum;
      map['sync'] = colNum;
    }
    if (normH.indexOf('updated') !== -1) {
      map['Last Updated'] = colNum;
    }
  }
  return map;
}

function setupSheetHeaders(sheet) {
  if (!sheet) {
    sheet = getOrCreateTargetSheet();
  }

  var headerRow = getHeaderRow(sheet);
  sheet.getRange(headerRow, 1, 1, COLUMNS.length).setValues([COLUMNS]);

  // Header Styling (1625 AutoLab Dark / Orange Theme)
  var headerRange = sheet.getRange(headerRow, 1, 1, COLUMNS.length);
  headerRange.setBackground('#18181b');
  headerRange.setFontColor('#f97316');
  headerRange.setFontWeight('bold');
  headerRange.setFontFamily('Consolas');
  headerRange.setFontSize(10);
  headerRange.setHorizontalAlignment('center');
  headerRange.setVerticalAlignment('middle');
  sheet.setRowHeight(headerRow, 36);

  // Set Admin Notes column width for comfortable reading/editing
  var colMap = getColumnMap(sheet);
  var adminNotesColIdx = colMap['Admin Notes'] || (COLUMNS.indexOf('Admin Notes') + 1);
  if (adminNotesColIdx > 0) {
    sheet.setColumnWidth(adminNotesColIdx, 220);
  }

  // Service Type column dropdown validation
  var serviceTypeColIdx = colMap['Service Type'] || (COLUMNS.indexOf('Service Type') + 1);
  if (serviceTypeColIdx > 0) {
    var serviceTypeRule = SpreadsheetApp.newDataValidation()
      .requireValueInList(['Shop Visit', 'Home Service'], true)
      .setAllowInvalid(true)
      .build();
    sheet.getRange(headerRow + 1, serviceTypeColIdx, 500, 1).setDataValidation(serviceTypeRule);
  }

  // Status column dropdown validation
  var statusColIdx = colMap['Status'] || (COLUMNS.indexOf('Status') + 1);
  if (statusColIdx > 0) {
    var statusRule = SpreadsheetApp.newDataValidation()
      .requireValueInList(['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'], true)
      .setAllowInvalid(true)
      .build();
    sheet.getRange(headerRow + 1, statusColIdx, 500, 1).setDataValidation(statusRule);
  }

  // Write 'Sync Status' header at the resolved sync column (Column AU / 47)
  var syncCol = getSyncStatusCol(sheet, colMap);
  sheet.getRange(headerRow, syncCol).setValue('Sync Status')
    .setBackground('#18181b').setFontColor('#f97316').setFontWeight('bold')
    .setFontFamily('Consolas').setFontSize(10)
    .setHorizontalAlignment('center').setVerticalAlignment('middle');
  sheet.getRange(headerRow + 1, syncCol, 500, 1).clearDataValidations();
}

function applyRowStyles(sheet, rowNumber, status) {
  var range = sheet.getRange(rowNumber, 1, 1, COLUMNS.length);
  range.setFontFamily('Inter');
  range.setFontSize(10);
  range.setVerticalAlignment('middle');

  var colMap = getColumnMap(sheet);
  var statusCol = colMap['Status'] || 18;
  if (statusCol) {
    var statusCell = sheet.getRange(rowNumber, statusCol);
    var st = String(status).toLowerCase();
    if (st === 'confirmed') {
      statusCell.setBackground('#dcfce7').setFontColor('#166534').setFontWeight('bold');
    } else if (st === 'in_progress') {
      statusCell.setBackground('#fef3c7').setFontColor('#92400e').setFontWeight('bold');
    } else if (st === 'completed') {
      statusCell.setBackground('#e0e7ff').setFontColor('#3730a3').setFontWeight('bold');
    } else if (st === 'cancelled') {
      statusCell.setBackground('#fee2e2').setFontColor('#991b1b').setFontWeight('bold');
    } else {
      statusCell.setBackground('#fef9c3').setFontColor('#854d0e').setFontWeight('bold');
    }
  }
}

// ----------------------------------------------------------------------------------------
// CUSTOM MENU & USER ACTIONS
// ----------------------------------------------------------------------------------------
function onOpen() {
  var ui = SpreadsheetApp.getUi();
  ui.createMenu('🏎️ 1625 AutoLab')
    .addItem('⚡ Sync Selected Row to Website', 'menuSyncSelectedRow')
    .addItem('🔄 Sync ALL Rows to Website', 'menuSyncAllRows')
    .addSeparator()
    .addItem('📥 Pull All Inquiries from Website', 'menuPullAllFromWebsite')
    .addSeparator()
    .addItem('🧹 Clean Notes Column (Fix Misplaced Sync Status)', 'menuCleanNotesColumn')
    .addItem('🧹 Remove Duplicate Rows from Sheet', 'menuRemoveDuplicates')
    .addItem('🛠️ Enable Real-Time Auto-Sync (Install Trigger)', 'installEditTrigger')
    .addItem('📋 Format Headers & Columns', 'menuFormatHeaders')
    .addItem('⚙️ Configure Website URL & Secret', 'menuConfigureSettings')
    .addToUi();
}

function menuSyncSelectedRow() {
  var sheet = SpreadsheetApp.getActiveSheet();
  var row = sheet.getActiveCell().getRow();
  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;

  if (row < startDataRow) {
    SpreadsheetApp.getUi().alert('Please select a data row (Row ' + startDataRow + ' or below) to sync.');
    return;
  }

  showToast('Syncing row ' + row + ' to Apollo website...', 'Syncing', 4);
  var res = syncSingleRowToApollo(sheet, row);
  if (res.success) {
    SpreadsheetApp.getUi().alert('✅ Success! Row ' + row + ' has been synchronized to the website.');
  } else {
    SpreadsheetApp.getUi().alert('❌ Sync Failed:\n' + res.error);
  }
}

function menuSyncAllRows() {
  var ui = SpreadsheetApp.getUi();
  var response = ui.alert('Confirm Sync All', 'Do you want to send all rows from this spreadsheet to the website?', ui.ButtonSet.YES_NO);
  if (response !== ui.Button.YES) return;

  var sheet = getOrCreateTargetSheet();
  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;
  var lastRow = sheet.getLastRow();

  if (lastRow < startDataRow) {
    ui.alert('No inquiry rows found starting at Row ' + startDataRow + '.');
    return;
  }

  var successCount = 0;
  var failCount = 0;

  for (var r = startDataRow; r <= lastRow; r++) {
    var res = syncSingleRowToApollo(sheet, r);
    if (res.success) {
      successCount++;
    } else {
      failCount++;
    }
    Utilities.sleep(150); // Avoid rate limits
  }

  ui.alert('Sync Completed!\n\n✅ Successfully Synced: ' + successCount + '\n❌ Failed: ' + failCount);
}

function menuPullAllFromWebsite() {
  var ui = SpreadsheetApp.getUi();
  var apiUrl = getApiUrl();
  var secret = getWebhookSecret();

  if (!apiUrl) {
    ui.alert('⚠️ Website URL is not configured. Please use "🏎️ 1625 AutoLab" > "⚙️ Configure".');
    return;
  }

  var pullUrl = apiUrl.replace(/\/inbound$/, '/all-inquiries');

  showToast('Fetching latest inquiries from website...', 'Pulling Data', 5);

  try {
    var options = {
      method: 'get',
      headers: {
        'X-Sheets-Secret': secret,
        'Accept': 'application/json'
      },
      muteHttpExceptions: true
    };

    var response = UrlFetchApp.fetch(pullUrl, options);
    var data = JSON.parse(response.getContentText());

    if (data.inquiries && Array.isArray(data.inquiries)) {
      bulkSyncRowsFromApollo(data.inquiries);
      ui.alert('✅ Success! Pulled and updated ' + data.inquiries.length + ' inquiries from website into Google Sheets.');
    } else {
      ui.alert('⚠️ Received response:\n' + response.getContentText().substring(0, 300));
    }
  } catch (e) {
    ui.alert('❌ Error pulling from website:\n' + e.message);
  }
}

function menuFormatHeaders() {
  setupSheetHeaders(getOrCreateTargetSheet());
  SpreadsheetApp.getUi().alert('✅ Sheet headers and status validations have been formatted.');
}

function menuConfigureSettings() {
  var ui = SpreadsheetApp.getUi();
  var currentUrl = getApiUrl();
  var currentSecret = getWebhookSecret();

  var urlPrompt = ui.prompt(
    'Step 1: Website Inbound Webhook URL',
    'Enter your Apollo Inbound Webhook URL (from Admin Settings > Google Sheets):\n\nCurrent: ' + (currentUrl || '(not set)'),
    ui.ButtonSet.OK_CANCEL
  );

  if (urlPrompt.getSelectedButton() !== ui.Button.OK) return;
  var newUrl = urlPrompt.getResponseText().trim();
  if (newUrl) {
    setProperty('APOLLO_API_URL', newUrl);
  }

  var secretPrompt = ui.prompt(
    'Step 2: Webhook Secret Key',
    'Enter your Webhook Secret Key (from Admin Settings > Google Sheets):\n\nCurrent: ' + (currentSecret || '(not set)'),
    ui.ButtonSet.OK_CANCEL
  );

  if (secretPrompt.getSelectedButton() !== ui.Button.OK) return;
  var newSecret = secretPrompt.getResponseText().trim();
  if (newSecret) {
    setProperty('APOLLO_SECRET', newSecret);
  }

  ui.alert('✅ Settings saved successfully!\n\nURL: ' + (newUrl || currentUrl) + '\nSecret: ' + (newSecret ? '********' : '(not set)'));
}

/**
 * Installs the installable onEdit trigger so edits in Google Sheets can make UrlFetchApp HTTP requests
 */
function installEditTrigger() {
  var triggers = ScriptApp.getProjectTriggers();
  for (var i = 0; i < triggers.length; i++) {
    if (triggers[i].getHandlerFunction() === 'installedOnEdit') {
      SpreadsheetApp.getUi().alert('ℹ️ Auto-Sync Trigger is ALREADY installed and active!\n\nAny cell you edit on data rows will automatically sync to your website.');
      return;
    }
  }

  ScriptApp.newTrigger('installedOnEdit')
    .forSpreadsheet(SpreadsheetApp.getActiveSpreadsheet())
    .onEdit()
    .create();

  SpreadsheetApp.getUi().alert('🎉 Auto-Sync on Edit is now ENABLED!\n\nWhenever you or your staff change any cell on data rows, it will automatically update on the website.');
}

/**
 * One-click cleanup utility for the Notes column.
 * Removes any misplaced 'Synced (Apollo)' or '✅ Synced (...)' values from the Notes column,
 * and moves them to the actual Sync Status column (Column AU / 47) if empty.
 */
function menuCleanNotesColumn() {
  var ui = SpreadsheetApp.getUi();
  var sheet = getOrCreateTargetSheet();
  var colMap = getColumnMap(sheet);
  var notesCol = colMap['Notes'] || colMap['Admin Notes'] || colMap['Internal Notes'];
  var syncCol = getSyncStatusCol(sheet, colMap);

  if (!notesCol) {
    ui.alert('Could not locate the "Notes" column in the sheet.');
    return;
  }

  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;
  var lastRow = sheet.getLastRow();

  if (lastRow < startDataRow) {
    ui.alert('No data rows found to clean.');
    return;
  }

  var numRows = lastRow - headerRow;
  var notesRange = sheet.getRange(startDataRow, notesCol, numRows, 1);
  var notesValues = notesRange.getValues();
  var cleanedCount = 0;

  for (var i = 0; i < notesValues.length; i++) {
    var val = String(notesValues[i][0] || '').trim();
    if (isSyncStatusValue(val)) {
      notesValues[i][0] = '';
      cleanedCount++;
      // If the actual sync column is blank, move the sync status there
      if (syncCol && syncCol !== notesCol) {
        var currentSync = String(sheet.getRange(startDataRow + i, syncCol).getValue() || '').trim();
        if (!currentSync) {
          sheet.getRange(startDataRow + i, syncCol).setValue(val);
        }
      }
    }
  }

  if (cleanedCount > 0) {
    notesRange.setValues(notesValues);
    ui.alert('✅ Done! Removed misplaced sync status from ' + cleanedCount + ' row(s) in the Notes column (Column ' + notesCol + ') and preserved the Sync column (Column ' + syncCol + ').');
  } else {
    ui.alert('✅ The Notes column is already clean! No misplaced sync statuses found.');
  }
}

/**
 * Safely finds and removes duplicate rows in the sheet (keeps the first occurrence)
 */
function menuRemoveDuplicates() {
  var ui = SpreadsheetApp.getUi();
  var sheet = getOrCreateTargetSheet();
  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;
  var lastRow = sheet.getLastRow();

  if (lastRow < startDataRow) {
    ui.alert('No data rows found to check.');
    return;
  }

  var colMap = getColumnMap(sheet);
  var idColIdx = colMap['Inquiry ID'] ? colMap['Inquiry ID'] - 1 : 2;
  var refColIdx = colMap['Reference Number'] ? colMap['Reference Number'] - 1 : 1;
  var phoneColIdx = colMap['Contact Number'] ? colMap['Contact Number'] - 1 : 6;
  var dateColIdx = colMap['Appointment Date'] ? colMap['Appointment Date'] - 1 : 15;

  var numRows = lastRow - headerRow;
  var values = sheet.getRange(startDataRow, 1, numRows, sheet.getLastColumn()).getValues();

  var seenIds = {};
  var seenRefs = {};
  var seenPhoneDates = {};
  var duplicateRows = []; // store 1-based row numbers

  for (var i = 0; i < values.length; i++) {
    var currentRowNum = startDataRow + i;
    var rowId = String(values[i][idColIdx] || '').toLowerCase().trim();
    var rowRef = cleanAlphanum(values[i][refColIdx] || '');
    var rowPhone = cleanDigits(values[i][phoneColIdx] || '');
    var rowDate = String(values[i][dateColIdx] || '').trim();

    var isDup = false;

    // Check ID
    if (rowId && rowId.length >= 6) {
      if (seenIds[rowId]) {
        isDup = true;
      } else {
        seenIds[rowId] = currentRowNum;
      }
    }

    // Check Reference Number
    if (!isDup && rowRef && rowRef.length >= 6) {
      if (seenRefs[rowRef]) {
        isDup = true;
      } else {
        seenRefs[rowRef] = currentRowNum;
      }
    }

    // Check Phone + Date
    if (!isDup && rowPhone && rowPhone.length >= 7 && rowDate) {
      var key = rowPhone + '_' + rowDate;
      if (seenPhoneDates[key]) {
        isDup = true;
      } else {
        seenPhoneDates[key] = currentRowNum;
      }
    }

    if (isDup) {
      duplicateRows.push(currentRowNum);
    }
  }

  if (duplicateRows.length === 0) {
    ui.alert('✅ No duplicate rows found! Your sheet is clean.');
    return;
  }

  var confirm = ui.alert(
    'Duplicate Rows Detected',
    'Found ' + duplicateRows.length + ' duplicate row(s) (Row ' + duplicateRows.join(', Row ') + ').\n\nDo you want to permanently delete these duplicate rows?',
    ui.ButtonSet.YES_NO
  );

  if (confirm !== ui.Button.YES) return;

  // Delete from bottom to top to preserve row indexing
  for (var d = duplicateRows.length - 1; d >= 0; d--) {
    sheet.deleteRow(duplicateRows[d]);
  }

  ui.alert('✅ Successfully removed ' + duplicateRows.length + ' duplicate row(s)!');
}

function showToast(msg, title, timeoutSec) {
  SpreadsheetApp.getActiveSpreadsheet().toast(msg, title || '1625 AutoLab', timeoutSec || 4);
}

/**
 * Sorts all data rows by Appointment Date descending (latest first).
 * Sets SUPPRESS_ALL_ONEDITS while the sort is in progress so the installable
 * onEdit trigger does not fire for rows that physically change position.
 */
function sortByAppointmentDate(sheet) {
  var headerRow = getHeaderRow(sheet);
  var startDataRow = headerRow + 1;
  var lastRow = sheet.getLastRow();
  if (lastRow < startDataRow) return; // nothing to sort

  var colMap = getColumnMap(sheet);
  var dateColNum = colMap['Appointment Date'] || 16;

  var cache = CacheService.getScriptCache();
  cache.put('SUPPRESS_ALL_ONEDITS', 'true', 30);
  try {
    var dataRange = sheet.getRange(startDataRow, 1, lastRow - headerRow, sheet.getLastColumn());
    // Sort descending by Appointment Date so the newest appointment is at the top
    dataRange.sort({ column: dateColNum, ascending: false });
  } finally {
    cache.remove('SUPPRESS_ALL_ONEDITS');
  }
}
