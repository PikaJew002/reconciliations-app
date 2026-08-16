import { addAccountTour } from './addAccount';
import { categorizeTour } from './categorize';
import { importBankTour } from './importBank';
import { importOrdersTour } from './importOrders';

let resolvers = {
    'add-account': addAccountTour,
    'import-bank': importBankTour,
    'import-orders': importOrdersTour,
    categorize: categorizeTour,
};

export function resolveTour(key, page) {
    let resolver = resolvers[key];

    if (!resolver) {
        return null;
    }

    return resolver(page);
}
