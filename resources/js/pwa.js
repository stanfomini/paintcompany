// Register service worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register('/sw.js');

            // Listen for updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                if (!newWorker) return;

                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        // New version available — dispatch event for UI toast
                        window.dispatchEvent(new CustomEvent('sw-update-available', {
                            detail: { registration }
                        }));
                    }
                });
            });
        } catch (err) {
            console.error('SW registration failed:', err);
        }
    });

    // When new SW takes over, reload page
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        window.location.reload();
    });
}

// Capture Android install prompt
let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    window.dispatchEvent(new CustomEvent('pwa-installable'));
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa-installed'));
    localStorage.setItem('pwa-installed', 'true');
});

// Expose install trigger for Alpine/Livewire
window.pwaInstall = async () => {
    if (!deferredPrompt) return false;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;
    return outcome === 'accepted';
};

// Detect platform for install instructions
window.getPwaPlatform = () => {
    const ua = navigator.userAgent;
    const isIos = /iPad|iPhone|iPod/.test(ua);
    const isAndroid = /Android/.test(ua);
    const isSafari = /Safari/.test(ua) && !/Chrome/.test(ua);
    const isChrome = /Chrome/.test(ua) && !/Edge/.test(ua);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    if (isStandalone) return 'installed';
    if (isIos && isSafari) return 'ios-safari';
    if (isIos && !isSafari) return 'ios-chrome'; // Chrome/FF on iOS
    if (isAndroid && isChrome) return 'android';
    return 'desktop';
};

// Expose SW update trigger
window.pwaUpdate = () => {
    navigator.serviceWorker?.getRegistration().then((reg) => {
        reg?.waiting?.postMessage({ type: 'SKIP_WAITING' });
    });
};
