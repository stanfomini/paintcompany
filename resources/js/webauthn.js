// WebAuthn / Biometric authentication
window.webauthn = {
    isSupported() {
        return !!window.PublicKeyCredential;
    },

    async hasCredential() {
        if (!this.isSupported()) return false;
        try {
            return await PublicKeyCredential.isConditionalMediationAvailable?.() ?? false;
        } catch {
            return false;
        }
    },

    async register(deviceName) {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        const optionsRes = await fetch('/webauthn/register/options', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        const options = await optionsRes.json();

        options.challenge = base64urlToBuffer(options.challenge);
        options.user.id = base64urlToBuffer(options.user.id);
        if (options.excludeCredentials) {
            options.excludeCredentials = options.excludeCredentials.map(c => ({
                ...c, id: base64urlToBuffer(c.id),
            }));
        }

        const credential = await navigator.credentials.create({ publicKey: options });

        const response = await fetch('/webauthn/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                id: credential.id,
                rawId: bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    attestationObject: bufferToBase64url(credential.response.attestationObject),
                    clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
                },
                device_name: deviceName || detectDeviceName(),
            }),
        });

        return response.ok;
    },

    async login(email) {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const body = email ? { email } : {};

        const optionsRes = await fetch('/webauthn/login/options', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body),
        });
        const options = await optionsRes.json();

        options.challenge = base64urlToBuffer(options.challenge);
        if (options.allowCredentials) {
            options.allowCredentials = options.allowCredentials.map(c => ({
                ...c, id: base64urlToBuffer(c.id),
            }));
        }

        const assertion = await navigator.credentials.get({ publicKey: options });

        const response = await fetch('/webauthn/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                id: assertion.id,
                rawId: bufferToBase64url(assertion.rawId),
                type: assertion.type,
                response: {
                    authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
                    clientDataJSON: bufferToBase64url(assertion.response.clientDataJSON),
                    signature: bufferToBase64url(assertion.response.signature),
                    userHandle: assertion.response.userHandle
                        ? bufferToBase64url(assertion.response.userHandle)
                        : null,
                },
            }),
        });

        if (response.ok) {
            const data = await response.json();
            window.location.href = data.redirect || '/';
            return true;
        }
        return false;
    },
};

function detectDeviceName() {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua)) return 'iPad';
    if (/Android/.test(ua)) return 'Android';
    if (/Mac/.test(ua)) return 'Mac';
    if (/Windows/.test(ua)) return 'Windows PC';
    return 'Unknown Device';
}

function base64urlToBuffer(base64url) {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const binary = atob(base64 + padding);
    return Uint8Array.from([...binary].map(c => c.charCodeAt(0))).buffer;
}

function bufferToBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach(b => binary += String.fromCharCode(b));
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}
