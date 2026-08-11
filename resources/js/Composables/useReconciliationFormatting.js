export function formatMoney(amount) {
    let value = Number(amount ?? 0);
    return value.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}

export function accountLabel(transaction) {
    if (!transaction?.account) {
        return 'Account';
    }

    if (transaction.account_last_four) {
        return `${transaction.account} ····${transaction.account_last_four}`;
    }

    return transaction.account;
}
