import { driver } from 'driver.js';

export function createTour({ steps, onHighlighted, onDestroyed }) {
    let outcome = 'dismissed';

    return driver({
        showProgress: true,
        allowClose: true,
        allowKeyboardControl: true,
        overlayClickBehavior: 'close',
        popoverClass: 'onboarding-driver-popover',
        stagePadding: 8,
        skipMissingElement: true,
        waitForElement: 2000,
        steps,
        onHighlighted,
        onPopoverRender: (popover) => {
            popover.closeButton?.setAttribute('aria-label', 'Dismiss tour');
        },
        onDoneClick: (element, step, { driver: instance }) => {
            outcome = 'completed';
            instance.destroy();
        },
        onDestroyed: (element, step, opts) => {
            onDestroyed?.(outcome, element, step, opts);
        },
    });
}
