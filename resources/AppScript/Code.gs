const ENDPOINT =
    "https://reconciliations.laravel.cloud/api/transactions/import";
const CHUNK_SIZE = 500;
const SPREADSHEET_NAME = "Aaron Eisenberg's Tiller Spreadsheet - Sep 27, 2026";
const TAB_NAME = "Transactions";
const IMPORTED_AT_HEADER = "Imported At";

const HEADER_MAP = {
    Date: "date",
    Description: "description",
    Category: "category",
    Amount: "amount",
    Account: "account",
    "Account #": "account_number",
    Institution: "institution",
    Month: "month",
    Week: "week",
    "Transaction ID": "transaction_id",
    "Account ID": "account_id",
    "Import Tag": "import_tag",
    "Check Number": "check_number",
    "Full Description": "full_description",
    "Date Added": "date_added",
    "Category Hint": "category_hint",
    "Categorized By": "categorized_by",
    "Categorized Date": "categorized_date",
    Source: "source",
};

function doGet(e) {
    try {
        assertWebhookKey(e);

        return jsonResponse({
            ok: true,
            sent: sendTransactions(),
        });
    } catch (error) {
        return jsonResponse({
            ok: false,
            error: error.message,
        });
    }
}

function doPost(e) {
    try {
        assertWebhookKey(e);
        const result = markImported(parseTransactionIds(e));

        return jsonResponse({
            ok: true,
            updated: result.updated,
            missing: result.missing,
        });
    } catch (error) {
        return jsonResponse({
            ok: false,
            error: error.message,
        });
    }
}

function sendTransactions() {
    const token = PropertiesService.getScriptProperties().getProperty(
        "TRANSACTION_IMPORT_TOKEN",
    );

    if (!token) {
        throw new Error(
            "Set TRANSACTION_IMPORT_TOKEN in Project Settings → Script properties.",
        );
    }

    const lock = LockService.getScriptLock();
    lock.waitLock(10000);

    try {
        const sheet = transactionsSheet();
        const values = sheet.getDataRange().getDisplayValues();
        const headers = values[0];
        const importedAtIndex = headerIndex(headers, IMPORTED_AT_HEADER);
        const transactions = values
            .slice(1)
            .filter((row) => String(row[importedAtIndex] || "").trim() === "")
            .filter((row) => row.some((cell) => String(cell).trim() !== ""))
            .map((row) => rowToTransaction(headers, row));

        for (let start = 0; start < transactions.length; start += CHUNK_SIZE) {
            postTransactions(
                token,
                transactions.slice(start, start + CHUNK_SIZE),
            );
        }

        return transactions.length;
    } finally {
        lock.releaseLock();
    }
}

function markImported(transactionIds) {
    const lock = LockService.getScriptLock();
    lock.waitLock(10000);

    try {
        const sheet = transactionsSheet();
        const lastRow = sheet.getLastRow();
        const headers = sheet
            .getRange(1, 1, 1, sheet.getLastColumn())
            .getValues()[0];
        const idColumn = headerIndex(headers, "Transaction ID") + 1;
        const importedAtColumn = headerIndex(headers, IMPORTED_AT_HEADER) + 1;
        const wanted = {};

        transactionIds.forEach((id) => {
            wanted[id] = false;
        });

        if (lastRow < 2) {
            return { updated: [], missing: transactionIds.slice() };
        }

        const rowCount = lastRow - 1;
        const idValues = sheet.getRange(2, idColumn, rowCount, 1).getValues();
        const importedAtValues = sheet
            .getRange(2, importedAtColumn, rowCount, 1)
            .getValues();
        const now = new Date();

        for (let i = 0; i < idValues.length; i++) {
            const id = String(idValues[i][0]).trim();

            if (Object.prototype.hasOwnProperty.call(wanted, id)) {
                importedAtValues[i][0] = now;
                wanted[id] = true;
            }
        }

        sheet
            .getRange(2, importedAtColumn, rowCount, 1)
            .setValues(importedAtValues);
        SpreadsheetApp.flush();

        const updated = [];
        const missing = [];

        Object.keys(wanted).forEach((id) => {
            if (wanted[id]) {
                updated.push(id);
            } else {
                missing.push(id);
            }
        });

        return { updated: updated, missing: missing };
    } finally {
        lock.releaseLock();
    }
}

function assertWebhookKey(e) {
    const expected =
        PropertiesService.getScriptProperties().getProperty("WEBHOOK_KEY");
    const provided = e && e.parameter ? String(e.parameter.key || "") : "";

    if (!expected || provided !== expected) {
        throw new Error("Unauthorized");
    }
}

function parseTransactionIds(e) {
    let ids;

    try {
        ids = JSON.parse(e.postData.contents);
    } catch (error) {
        throw new Error("Expected a JSON array of transaction IDs.");
    }

    if (!Array.isArray(ids)) {
        throw new Error("Expected a JSON array of transaction IDs.");
    }

    return ids.map((id) => {
        const transactionId = String(id).trim();

        if (transactionId === "") {
            throw new Error("Transaction IDs must not be blank.");
        }

        return transactionId;
    });
}

function postTransactions(token, transactions) {
    const response = UrlFetchApp.fetch(ENDPOINT, {
        method: "post",
        contentType: "application/json",
        headers: {
            Authorization: "Bearer " + token,
        },
        payload: JSON.stringify({ transactions: transactions }),
        muteHttpExceptions: true,
    });
    const body = response.getContentText();
    Logger.log(response.getResponseCode() + " " + body);

    if (response.getResponseCode() !== 200) {
        throw new Error(body);
    }
}

function transactionsSheet() {
    const files = DriveApp.getFilesByName(SPREADSHEET_NAME);

    if (!files.hasNext()) {
        throw new Error("Spreadsheet not found: " + SPREADSHEET_NAME);
    }

    const file = files.next();

    if (files.hasNext()) {
        throw new Error(
            "More than one spreadsheet is named: " + SPREADSHEET_NAME,
        );
    }

    const sheet = SpreadsheetApp.open(file).getSheetByName(TAB_NAME);

    if (!sheet) {
        throw new Error("Tab not found: " + TAB_NAME);
    }

    return sheet;
}

function rowToTransaction(headers, row) {
    const transaction = {};
    let importTagSeen = false;

    headers.forEach((header, index) => {
        const key = HEADER_MAP[String(header).trim()];

        if (!key) {
            return;
        }

        if (key === "import_tag") {
            if (importTagSeen) {
                return;
            }

            importTagSeen = true;
        }

        transaction[key] = row[index] === "" ? null : row[index];
    });

    return transaction;
}

function headerIndex(headers, name) {
    const index = headers.findIndex((header) => String(header).trim() === name);

    if (index === -1) {
        throw new Error("Column not found: " + name);
    }

    return index;
}

function jsonResponse(payload) {
    return ContentService.createTextOutput(JSON.stringify(payload)).setMimeType(
        ContentService.MimeType.JSON,
    );
}
