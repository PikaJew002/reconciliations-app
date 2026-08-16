export function importBankTour(page) {
    if (page.component !== 'Accounts/Imports') {
        return null;
    }

    return {
        steps: [
            {
                element: '[data-tour="import-bank-file"]',
                popover: {
                    title: 'Upload your bank export',
                    description:
                        'Export a CSV or TXT from this bank and upload about six weeks of history. That is enough to see a full cycle of spend.',
                    side: 'bottom',
                },
            },
            {
                element: '[data-tour="import-bank-submit"]',
                popover: {
                    title: 'Queue the import',
                    description:
                        'This queues the file. Matching runs after the import finishes.',
                    side: 'bottom',
                },
            },
        ],
    };
}
