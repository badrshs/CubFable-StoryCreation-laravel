import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';

declare global {
    interface Window {
        turnstile?: {
            render: (
                element: HTMLElement,
                options: Record<string, unknown>,
            ) => string;
            remove: (widgetId: string) => void;
        };
    }
}

const SCRIPT_SRC =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptPromise: Promise<void> | null = null;

function loadTurnstileScript(): Promise<void> {
    if (window.turnstile) {
        return Promise.resolve();
    }

    if (!scriptPromise) {
        scriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = SCRIPT_SRC;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => {
                scriptPromise = null;
                reject(new Error('Turnstile script failed to load'));
            };
            document.head.appendChild(script);
        });
    }

    return scriptPromise;
}

/**
 * Cloudflare Turnstile bot check for auth forms. Renders nothing when no
 * site key is configured (the server skips validation too). The token lands
 * in a hidden cf-turnstile-response input picked up on form submit.
 */
export default function TurnstileWidget({ error }: { error?: string }) {
    const { turnstileSiteKey } = usePage().props;
    const containerRef = useRef<HTMLDivElement>(null);
    const [token, setToken] = useState('');

    useEffect(() => {
        if (!turnstileSiteKey || !containerRef.current) {
            return;
        }

        let widgetId: string | undefined;
        let cancelled = false;

        loadTurnstileScript()
            .then(() => {
                if (cancelled || !containerRef.current || !window.turnstile) {
                    return;
                }

                widgetId = window.turnstile.render(containerRef.current, {
                    sitekey: turnstileSiteKey,
                    callback: (newToken: string) => setToken(newToken),
                    'expired-callback': () => setToken(''),
                    'error-callback': () => setToken(''),
                    appearance: 'interaction-only',
                });
            })
            .catch(() => {
                // Widget stays hidden; the server decides what to do with a
                // missing token.
            });

        return () => {
            cancelled = true;

            if (widgetId && window.turnstile) {
                window.turnstile.remove(widgetId);
            }
        };
    }, [turnstileSiteKey]);

    if (!turnstileSiteKey) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1.5">
            <div ref={containerRef} />
            <input type="hidden" name="cf-turnstile-response" value={token} />
            <InputError message={error} />
        </div>
    );
}
