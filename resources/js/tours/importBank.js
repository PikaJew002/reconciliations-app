export function importBankTour(page) {
    if (page.component === 'Accounts/Create') {
        return {
            steps: [
                {
                    element: '[data-tour="add-account-form"]',
                    popover: {
                        title: 'Add a bank account',
                        description:
                            'Create the account you want to import. After you save it, you will land on the CSV upload page.',
                        side: 'bottom',
                    },
                },
            ],
        };
    }

    if (page.component === 'Accounts/Imports') {
        return {
            steps: [
                {
                    element: '[data-tour="import-bank-file"]',
                    popover: {
                        title: 'Upload your bank CSV',
                        description:
                            'Export a CSV from this bank and upload about six weeks of history. That is enough to see a full cycle of spend.',
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

    return null;
}
