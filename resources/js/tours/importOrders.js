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
                            'Each retailer has an Imports page where you upload the export file.',
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
                        element: '[data-tour="import-amazon-form"]',
                        popover: {
                            title: 'Upload Amazon CSVs',
                            description:
                                'Amazon needs both the order summary CSV and the item details CSV. Upload about six weeks of history.',
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
