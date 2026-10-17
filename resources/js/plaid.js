export function loadPlaid() {
    if (window.Plaid) {
        return Promise.resolve(window.Plaid);
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
        script.async = true;
        script.onload = () => resolve(window.Plaid);
        script.onerror = () => reject(new Error('Plaid Link failed to load.'));
        document.head.appendChild(script);
    });
}
