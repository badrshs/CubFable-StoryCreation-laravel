const COOKIE_NAME = 'cf_fp';
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60;

const hasValidFingerprintCookie = (): boolean => {
    const match = document.cookie.match(/(?:^|;\s*)cf_fp=([^;]*)/);

    return match !== null && /^[a-f0-9]{16,64}$/i.test(match[1]);
};

const writeFingerprintCookie = (visitorId: string): void => {
    const secure = location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${COOKIE_NAME}=${visitorId};path=/;max-age=${COOKIE_MAX_AGE};SameSite=Lax${secure}`;
};

const computeFingerprint = async (): Promise<void> => {
    try {
        const FingerprintJS = await import('@fingerprintjs/fingerprintjs');
        const agent = await FingerprintJS.load();
        const { visitorId } = await agent.get();
        writeFingerprintCookie(visitorId);
    } catch {
        // Best-effort: a missing fingerprint only weakens abuse clustering.
    }
};

/**
 * Computes a browser fingerprint once and stores it in the cf_fp cookie so
 * the server sees it on every request. Runs during browser idle time and
 * loads FingerprintJS lazily to keep it out of the main bundle.
 */
export function ensureDeviceFingerprint(): void {
    if (typeof document === 'undefined' || hasValidFingerprintCookie()) {
        return;
    }

    if (typeof requestIdleCallback === 'function') {
        requestIdleCallback(() => void computeFingerprint());
    } else {
        setTimeout(() => void computeFingerprint(), 1000);
    }
}
