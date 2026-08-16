export function addAccountTour(page) {
    if (page.component !== 'Accounts/Create') {
        return null;
    }

    return {
        steps: [
            {
                element: '[data-tour="add-account-form"]',
                popover: {
                    title: 'Add a bank account',
                    description:
                        'Create each account you will import. Checking, savings, and cards can each be their own account. After you save one, you will land on its CSV upload page.',
                    side: 'bottom',
                },
            },
        ],
    };
}
