import { importBankTour } from './importBank';
import { importOrdersTour } from './importOrders';

let resolvers = {
    'import-bank': importBankTour,
    'import-orders': importOrdersTour,
};

export function resolveTour(key, page) {
    let resolver = resolvers[key];

    if (!resolver) {
        return null;
    }

    return resolver(page);
}
