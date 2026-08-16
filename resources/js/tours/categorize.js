export function categorizeTour(page) {
    if (page.component !== 'Reconciliation/UnmatchedTransactions') {
        return null;
    }

    return {
        steps: [
            {
                element: '[data-tour="categorize-type"]',
                popover: {
                    title: 'Expense, bill, or income',
                    description:
                        'Bills are recurring obligations. Expenses are everything else you spend. Credits are income.',
                    side: 'bottom',
                },
            },
            {
                element: '[data-tour="categorize-category"]',
                popover: {
                    title: 'Name the category',
                    description:
                        'Pick an existing category, or choose Create a category to type a new name. You will reuse it on similar lines.',
                    side: 'bottom',
                },
            },
            {
                element: '[data-tour="categorize-match"]',
                popover: {
                    title: 'Make a rule if it will recur',
                    description:
                        'If this paycheck, bill, or merchant will show up again, choose a future match so the next import categorizes it for you.',
                    side: 'bottom',
                },
            },
            {
                popover: {
                    title: 'More category options',
                    description:
                        'Keep adding categories from this list as you go. To review, rename, or pick colors, open <a href="/categories">all categories</a>. To add one with a name, kind, and color up front, use the <a href="/categories/create">full add form</a>.',
                },
            },
        ],
    };
}
