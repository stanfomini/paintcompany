// Push notification subscription management
window.pushNotifications = {
    async subscribe() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Push not supported');
            return false;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return false;

        const registration = await navigator.serviceWorker.ready;
        const vapidKey = document.querySelector('meta[name="vapid-public-key"]')?.content;
        if (!vapidKey) return false;

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidKey),
        });

        // Encode keys as URL-safe base64 (required by Web Push standard)
        const p256dh = arrayBufferToBase64Url(subscription.getKey('p256dh'));
        const auth = arrayBufferToBase64Url(subscription.getKey('auth'));

        const response = await fetch('/push/subscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                endpoint: subscription.endpoint,
                content_encoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
                keys: { p256dh, auth },
            }),
        });

        return response.ok;
    },

    async unsubscribe() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return false;

        await fetch('/push/unsubscribe', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });

        await subscription.unsubscribe();
        return true;
    },

    async isSubscribed() {
        if (!('serviceWorker' in navigator)) return false;
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        return !!subscription;
    },

    isSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },
};

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

function arrayBufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach((b) => (binary += String.fromCharCode(b)));
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
