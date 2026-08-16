import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export function useOnboarding() {
    let page = usePage();
    let onboarding = computed(() => page.props.onboarding ?? null);
    let panelOpen = ref(true);

    let isPresent = computed(() => onboarding.value !== null);
    let isVisible = computed(() => Boolean(onboarding.value?.visible));
    let steps = computed(() => onboarding.value?.steps ?? []);
    let completedCount = computed(
        () => steps.value.filter((step) => step.complete).length,
    );
    let totalCount = computed(() => steps.value.length);

    let headerLabel = computed(() => {
        if (!isPresent.value) {
            return null;
        }

        if (totalCount.value === 0) {
            return 'Setup';
        }

        return `Setup ${completedCount.value}/${totalCount.value}`;
    });

    watch(isVisible, (visible) => {
        if (visible) {
            panelOpen.value = true;
        }
    });

    let post = (url, data = {}) => {
        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    let hide = () => {
        panelOpen.value = false;
        post('/onboarding/hide');
    };

    let show = () => {
        panelOpen.value = true;
        post('/onboarding/show');
    };

    let skip = (step) => {
        post('/onboarding/skip', { step: step.key });
    };

    let togglePanel = () => {
        if (!isVisible.value) {
            show();

            return;
        }

        panelOpen.value = !panelOpen.value;
    };

    let stepHref = (step) => {
        if (!step.tour || step.complete) {
            return step.href;
        }

        let url = new URL(step.href, 'http://onboarding.local');
        url.searchParams.set('tour', step.tour);

        return `${url.pathname}${url.search}`;
    };

    return {
        onboarding,
        isPresent,
        isVisible,
        steps,
        panelOpen,
        headerLabel,
        hide,
        show,
        skip,
        togglePanel,
        stepHref,
    };
}
