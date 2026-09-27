let MONTHS = {
    jan: 1,
    january: 1,
    feb: 2,
    february: 2,
    mar: 3,
    march: 3,
    apr: 4,
    april: 4,
    may: 5,
    jun: 6,
    june: 6,
    jul: 7,
    july: 7,
    aug: 8,
    august: 8,
    sep: 9,
    sept: 9,
    september: 9,
    oct: 10,
    october: 10,
    nov: 11,
    november: 11,
    dec: 12,
    december: 12,
};

export function dateOnly(value) {
    if (value == null || value === '') {
        return '';
    }

    let match = String(value).match(/^(\d{4}-\d{2}-\d{2})/);

    return match ? match[1] : '';
}

export function hasAmountTarget(value) {
    if (value == null || value === '') {
        return false;
    }

    return Number.isFinite(Number(value));
}

export function hasDateTarget(value) {
    return dateOnly(value) !== '';
}

export function normalizeTransactions(options, merchants = []) {
    return (options ?? []).map((option) =>
        normalizeTransaction(option, merchants),
    );
}

export function formatPostedDate(value) {
    let iso = dateOnly(value);

    if (!iso) {
        return 'No date';
    }

    let date = new Date(`${iso}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return 'No date';
    }

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

export function transactionMatchesQuery(transaction, query) {
    let tokens = String(query || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (tokens.length === 0) {
        return true;
    }

    return tokens.every((token) =>
        tokenMatches(transaction, classifyToken(token)),
    );
}

export function isExactAmount(transaction, targetAmount) {
    if (!hasAmountTarget(targetAmount)) {
        return false;
    }

    return (
        Math.abs(transaction.absAmount - Math.abs(Number(targetAmount))) < 0.01
    );
}

export function isNearAmount(transaction, targetAmount) {
    if (!hasAmountTarget(targetAmount)) {
        return false;
    }

    let targetAbs = Math.abs(Number(targetAmount));
    let diff = Math.abs(transaction.absAmount - targetAbs);
    let threshold = Math.max(1, targetAbs * 0.05);

    return diff <= threshold + 1e-9;
}

export function isNearDate(transaction, targetDate) {
    let days = daysBetween(transaction.postedAt, targetDate);

    return days != null && days <= 3;
}

export function transactionMeta(transaction, { preset, targetAmount, targetDate }) {
    let parts = [];

    if (transaction.account) {
        parts.push(
            transaction.accountLastFour
                ? `${transaction.account} ····${transaction.accountLastFour}`
                : transaction.account,
        );
    }

    if (transaction.cardLastFour) {
        parts.push(`card ····${transaction.cardLastFour}`);
    }

    let rankedPreset =
        preset === 'link' || preset === 'payment' || preset === 'venmo';

    if (rankedPreset && isExactAmount(transaction, targetAmount)) {
        parts.push('Exact amount');
    }

    if (rankedPreset && hasDateTarget(targetDate)) {
        let days = daysBetween(transaction.postedAt, targetDate);

        if (days != null && days <= 14) {
            if (days === 0) {
                parts.push('Same day');
            } else if (preset === 'link') {
                parts.push(
                    days === 1
                        ? '1 day from expected'
                        : `${days} days from expected`,
                );
            } else {
                parts.push(days === 1 ? '1 day apart' : `${days} days apart`);
            }
        }
    }

    return parts.join(' · ');
}

export function pickerChips({
    transactions,
    preset,
    targetAmount,
    targetDate,
    targetCardLastFour,
}) {
    let chips = [];

    if (preset === 'source') {
        let labels = new Map();

        for (let transaction of transactions) {
            if (!transaction.merchant) {
                continue;
            }

            labels.set(merchantChipId(transaction), transaction.merchant);
        }

        if (labels.size > 1) {
            for (let [id, label] of labels) {
                chips.push({ id, label, group: 'merchant' });
            }
        }
    }

    if (preset === 'link') {
        if (
            hasAmountTarget(targetAmount) &&
            subsetNarrows(transactions, (transaction) =>
                isNearAmount(transaction, targetAmount),
            )
        ) {
            chips.push({
                id: 'near-amount',
                label: 'Near amount',
                group: 'toggle',
            });
        }

        if (
            hasDateTarget(targetDate) &&
            subsetNarrows(transactions, (transaction) =>
                isNearDate(transaction, targetDate),
            )
        ) {
            chips.push({ id: 'near-date', label: 'Near date', group: 'toggle' });
        }
    }

    if (preset === 'payment' || preset === 'venmo') {
        if (
            hasAmountTarget(targetAmount) &&
            subsetNarrows(transactions, (transaction) =>
                isExactAmount(transaction, targetAmount),
            )
        ) {
            chips.push({
                id: 'exact-amount',
                label: 'Exact amount',
                group: 'toggle',
            });
        }
    }

    if (preset === 'payment' && targetCardLastFour) {
        let card = String(targetCardLastFour);

        if (
            subsetNarrows(
                transactions,
                (transaction) => transaction.cardLastFour === card,
            )
        ) {
            chips.push({
                id: 'this-card',
                label: `Card ····${card}`,
                group: 'toggle',
            });
        }
    }

    if (preset === 'venmo') {
        let accounts = new Map();

        for (let transaction of transactions) {
            let id = accountChipId(transaction);

            if (!id) {
                continue;
            }

            accounts.set(id, accountChipLabel(transaction));
        }

        if (accounts.size > 1) {
            for (let [id, label] of accounts) {
                chips.push({ id, label, group: 'account' });
            }
        }
    }

    if (preset === 'reimbursement') {
        let hasCredits = transactions.some((transaction) => transaction.amount > 0);
        let hasDebits = transactions.some((transaction) => transaction.amount <= 0);

        if (hasCredits && hasDebits) {
            chips.push({ id: 'side:all', label: 'All', group: 'side' });
            chips.push({ id: 'side:credit', label: 'Credits', group: 'side' });
            chips.push({ id: 'side:debit', label: 'Debits', group: 'side' });
        }
    }

    return chips;
}

export function pickerSections({
    transactions,
    allTransactions,
    preset,
    filters,
    targetAmount,
    targetDate,
    targetCardLastFour,
}) {
    let filtered = applyFilters(transactions, preset, filters, {
        targetAmount,
        targetDate,
        targetCardLastFour,
    });
    let closestId = null;

    if (
        preset === 'link' &&
        allTransactions.length > 1 &&
        (hasAmountTarget(targetAmount) || hasDateTarget(targetDate))
    ) {
        let ranked = sortTransactions(
            allTransactions,
            'link',
            targetAmount,
            targetDate,
        );
        closestId = String(ranked[0].id);
    }

    if (preset === 'reimbursement' && filters.side === 'all') {
        let credits = sortTransactions(
            filtered.filter((transaction) => transaction.amount > 0),
            'reimbursement',
            targetAmount,
            targetDate,
        );
        let debits = sortTransactions(
            filtered.filter((transaction) => transaction.amount <= 0),
            'reimbursement',
            targetAmount,
            targetDate,
        );
        let sections = [];

        if (credits.length > 0) {
            sections.push({ key: 'credits', label: 'Credits', items: credits });
        }

        if (debits.length > 0) {
            sections.push({ key: 'debits', label: 'Debits', items: debits });
        }

        if (sections.length === 1) {
            sections[0].label = null;
        }

        return { sections, closestId: null };
    }

    let items = sortTransactions(filtered, preset, targetAmount, targetDate);

    return {
        sections: items.length
            ? [{ key: 'all', label: null, items }]
            : [],
        closestId,
    };
}

function normalizeTransaction(option, merchants) {
    let merchant = option.merchant || '';

    if (!merchant && option.merchant_id != null) {
        let match = merchants.find(
            (item) => String(item.id) === String(option.merchant_id),
        );
        merchant = match?.name || '';
    }

    let amount = Number(option.amount ?? 0);

    return {
        id: option.id,
        postedAt: dateOnly(option.posted_at || option.transaction_date),
        description: option.description || '',
        amount,
        absAmount: Math.abs(amount),
        account: option.account || '',
        accountLastFour: option.account_last_four
            ? String(option.account_last_four)
            : '',
        cardLastFour: option.card_last_four ? String(option.card_last_four) : '',
        merchant,
        merchantId: option.merchant_id ?? null,
    };
}

function classifyToken(token) {
    let lower = token.toLowerCase();

    if (MONTHS[lower]) {
        return { type: 'month', month: MONTHS[lower] };
    }

    let iso = lower.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (iso) {
        return {
            type: 'date',
            year: Number(iso[1]),
            month: Number(iso[2]),
            day: Number(iso[3]),
        };
    }

    let slash = lower.match(/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/);

    if (slash) {
        let year = slash[3] ? Number(slash[3]) : null;

        if (year != null && year < 100) {
            year += 2000;
        }

        return {
            type: 'date',
            year,
            month: Number(slash[1]),
            day: Number(slash[2]),
        };
    }

    if (/^\$\d+(?:\.\d{0,2})?$/.test(lower) || /^\d+\.\d{0,2}$/.test(lower)) {
        return { type: 'money', value: lower.replace('$', '') };
    }

    if (/^\d{1,2}$/.test(lower)) {
        let day = Number(lower);

        if (day >= 1 && day <= 31) {
            return { type: 'day-or-text', day, text: lower };
        }
    }

    if (/^(19|20)\d{2}$/.test(lower)) {
        return { type: 'year', year: Number(lower) };
    }

    return { type: 'text', text: lower };
}

function tokenMatches(transaction, token) {
    let posted = dateParts(transaction.postedAt);

    if (token.type === 'month') {
        return posted?.month === token.month;
    }

    if (token.type === 'year') {
        return posted?.year === token.year || haystack(transaction).includes(String(token.year));
    }

    if (token.type === 'date') {
        if (!posted || posted.month !== token.month || posted.day !== token.day) {
            return false;
        }

        return token.year == null || posted.year === token.year;
    }

    if (token.type === 'money') {
        return moneyMatches(transaction.absAmount, token.value);
    }

    if (token.type === 'day-or-text') {
        return (
            posted?.day === token.day || haystack(transaction).includes(token.text)
        );
    }

    return haystack(transaction).includes(token.text);
}

function haystack(transaction) {
    return [
        transaction.description,
        transaction.merchant,
        transaction.account,
        transaction.cardLastFour,
        transaction.accountLastFour,
    ]
        .join(' ')
        .toLowerCase();
}

function moneyMatches(amount, tokenValue) {
    let text = Math.abs(Number(amount)).toFixed(2);

    if (tokenValue.endsWith('.')) {
        return text.split('.')[0] === tokenValue.slice(0, -1);
    }

    if (!tokenValue.includes('.')) {
        return text.split('.')[0] === tokenValue;
    }

    return text.startsWith(tokenValue);
}

function dateParts(value) {
    let iso = dateOnly(value);

    if (!iso) {
        return null;
    }

    let [year, month, day] = iso.split('-').map(Number);

    return { year, month, day };
}

function daysBetween(left, right) {
    let start = dateParts(left);
    let end = dateParts(dateOnly(right));

    if (!start || !end) {
        return null;
    }

    let startUtc = Date.UTC(start.year, start.month - 1, start.day);
    let endUtc = Date.UTC(end.year, end.month - 1, end.day);

    return Math.round(Math.abs(startUtc - endUtc) / 86400000);
}

function subsetNarrows(transactions, predicate) {
    let count = transactions.filter(predicate).length;

    return count > 0 && count < transactions.length;
}

function merchantChipId(transaction) {
    return `merchant:${transaction.merchantId ?? transaction.merchant}`;
}

function accountChipId(transaction) {
    if (!transaction.account && !transaction.accountLastFour) {
        return '';
    }

    return `account:${transaction.account}|${transaction.accountLastFour}`;
}

function accountChipLabel(transaction) {
    if (transaction.accountLastFour) {
        return `${transaction.account} ····${transaction.accountLastFour}`;
    }

    return transaction.account;
}

function applyFilters(transactions, preset, filters, targets) {
    let items = transactions;

    if (filters.merchant) {
        items = items.filter(
            (transaction) => merchantChipId(transaction) === filters.merchant,
        );
    }

    if (filters.account) {
        items = items.filter(
            (transaction) => accountChipId(transaction) === filters.account,
        );
    }

    if (filters.nearAmount && hasAmountTarget(targets.targetAmount)) {
        items = items.filter((transaction) =>
            isNearAmount(transaction, targets.targetAmount),
        );
    }

    if (filters.nearDate && hasDateTarget(targets.targetDate)) {
        items = items.filter((transaction) =>
            isNearDate(transaction, targets.targetDate),
        );
    }

    if (filters.exactAmount && hasAmountTarget(targets.targetAmount)) {
        items = items.filter((transaction) =>
            isExactAmount(transaction, targets.targetAmount),
        );
    }

    if (filters.thisCard && targets.targetCardLastFour) {
        let card = String(targets.targetCardLastFour);
        items = items.filter((transaction) => transaction.cardLastFour === card);
    }

    if (preset === 'reimbursement' && filters.side === 'credit') {
        items = items.filter((transaction) => transaction.amount > 0);
    } else if (preset === 'reimbursement' && filters.side === 'debit') {
        items = items.filter((transaction) => transaction.amount <= 0);
    }

    return items;
}

function sortTransactions(transactions, preset, targetAmount, targetDate) {
    let items = [...transactions];

    if (preset === 'source' || preset === 'reimbursement') {
        items.sort(compareNewest);

        return items;
    }

    let primary = preset === 'link' ? 'date' : 'amount';

    items.sort((left, right) =>
        compareDistance(left, right, primary, targetAmount, targetDate),
    );

    return items;
}

function compareDistance(left, right, primary, targetAmount, targetDate) {
    let amountRank = (transaction) =>
        hasAmountTarget(targetAmount)
            ? Math.abs(transaction.absAmount - Math.abs(Number(targetAmount)))
            : 0;
    let dateRank = (transaction) => {
        if (!hasDateTarget(targetDate)) {
            return 0;
        }

        let days = daysBetween(transaction.postedAt, targetDate);

        return days == null ? Number.POSITIVE_INFINITY : days;
    };
    let leftAmount = amountRank(left);
    let rightAmount = amountRank(right);
    let leftDate = dateRank(left);
    let rightDate = dateRank(right);

    if (primary === 'date') {
        if (leftDate !== rightDate) {
            return leftDate - rightDate;
        }

        if (leftAmount !== rightAmount) {
            return leftAmount - rightAmount;
        }
    } else {
        if (leftAmount !== rightAmount) {
            return leftAmount - rightAmount;
        }

        if (leftDate !== rightDate) {
            return leftDate - rightDate;
        }
    }

    return compareNewest(left, right);
}

function compareNewest(left, right) {
    if (left.postedAt !== right.postedAt) {
        if (!left.postedAt) {
            return 1;
        }

        if (!right.postedAt) {
            return -1;
        }

        return left.postedAt < right.postedAt ? 1 : -1;
    }

    return Number(right.id) - Number(left.id);
}
