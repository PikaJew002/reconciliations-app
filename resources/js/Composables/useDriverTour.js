import { router, usePage } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, watch } from 'vue';
import { createTour } from '../tours/createTour';
import { resolveTour } from '../tours/registry';

function tourKeyFromUrl(url) {
    let query = String(url).split('?')[1] ?? '';

    return new URLSearchParams(query).get('tour');
}

function pathWithoutTour(url) {
    let [path, queryString] = String(url).split('?');
    let params = new URLSearchParams(queryString ?? '');

    if (!params.has('tour')) {
        return null;
    }

    params.delete('tour');
    let nextQuery = params.toString();

    return nextQuery ? `${path}?${nextQuery}` : path;
}

export function useDriverTour() {
    let page = usePage();
    let instance = null;
    let startedKey = null;
    let skipPersist = false;
    let didHighlight = false;

    let destroyTour = ({ persist } = { persist: false }) => {
        if (!instance) {
            return;
        }

        skipPersist = !persist;
        instance.destroy();
        instance = null;
        startedKey = null;
    };

    let persistTour = (key, status) => {
        let nextUrl = pathWithoutTour(page.url);

        if (nextUrl) {
            router.replace({
                url: nextUrl,
                preserveState: true,
                preserveScroll: true,
            });
        }

        router.post(
            `/onboarding/tours/${key}`,
            { status },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    let startIfNeeded = async () => {
        let key = tourKeyFromUrl(page.url);

        if (!key) {
            destroyTour({ persist: false });

            return;
        }

        let onboarding = page.props.onboarding;
        let step = onboarding?.visible
            ? onboarding.steps?.find((item) => item.key === key)
            : null;

        if (!step || step.complete || !step.tour) {
            destroyTour({ persist: false });

            return;
        }

        let tour = resolveTour(key, page);

        if (!tour) {
            destroyTour({ persist: false });

            return;
        }

        if (instance && startedKey === key) {
            return;
        }

        destroyTour({ persist: false });
        await nextTick();

        skipPersist = false;
        didHighlight = false;
        startedKey = key;

        let tourKey = key;

        instance = createTour({
            steps: tour.steps,
            onHighlighted: () => {
                didHighlight = true;
            },
            onDestroyed: (status) => {
                let shouldPersist = !skipPersist && didHighlight;
                instance = null;
                startedKey = null;

                if (shouldPersist) {
                    persistTour(tourKey, status);
                }
            },
        });

        instance.drive();
    };

    let stopBefore = router.on('before', (event) => {
        let visit = event.detail.visit;

        if (visit.only?.length) {
            return;
        }

        let href =
            typeof visit.url === 'string'
                ? visit.url
                : (visit.url?.pathname ?? '');

        if (href.includes('/onboarding/')) {
            return;
        }

        destroyTour({ persist: false });
    });

    watch(
        () => [page.url, page.component],
        () => {
            startIfNeeded();
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        stopBefore();
        destroyTour({ persist: false });
    });
}
