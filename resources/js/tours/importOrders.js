export function importOrdersTour(page) {
    if (page.component === 'Orders/Index') {
        return {
            steps: [
                {
                    element: '[data-tour="import-orders-retailers"]',
                    popover: {
                        title: 'Amazon and Walmart',
                        description:
                            'Import about six weeks of order history so bank charges can be matched to line items. Skip this if you do not shop at these retailers.',
                        side: 'bottom',
                    },
                },
                {
                    element: '[data-tour="import-orders-imports-link"]',
                    popover: {
                        title: 'Open Imports',
                        description:
                            'Each retailer has an Imports page. Walmart uses a JSON export; Amazon orders come from the Chrome extension.',
                        side: 'left',
                    },
                },
            ],
        };
    }

    if (page.component === 'Orders/Imports') {
        let isAmazon = page.props.merchant?.normalized_name === 'amazon';

        if (isAmazon) {
            return {
                steps: [
                    {
                        element: '[data-tour="import-amazon-history"]',
                        popover: {
                            title: 'Amazon import history',
                            description:
                                'Amazon orders arrive from the Chrome extension. Use this page to review past imports.',
                            side: 'bottom',
                        },
                    },
                ],
            };
        }

        return {
            steps: [
                {
                    element: '[data-tour="import-walmart-form"]',
                    popover: {
                        title: 'Upload Walmart orders',
                        description:
                            'Upload a Walmart orders JSON export covering about six weeks of history.',
                        side: 'bottom',
                    },
                },
            ],
        };
    }

    return null;
}
