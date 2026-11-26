/**
 * Tiny host bridge for the example.
 *
 * The real thing should be @modelcontextprotocol/ext-apps/app-bridge or
 * MCP-UI. This file only shows the wire contract: validate the view origin,
 * forward JSON-RPC to the backend, deliver the response on the same origin,
 * and execute the actions the backend decided on.
 */
export function createBridge(iframe, view, options = {}) {
    const origin = options.viewOrigin;
    let disposed = false;
    let ready = false;

    const listener = async (event) => {
        if (disposed || event.origin !== origin || event.source !== iframe.contentWindow) {
            return;
        }

        const message = event.data;

        if (!message || message.jsonrpc !== '2.0') {
            return;
        }

        // Sandbox notifications are a browser concern. A production host uses
        // the official bridge to create the proxy; this demo ignores them and
        // must never send them to the MCP app session.
        if (typeof message.method === 'string' && message.method.startsWith('ui/notifications/sandbox-')) {
            return;
        }

        if (message.method === 'ui/notifications/size-changed') {
            const height = Number(message.params?.height ?? 0);

            if (height > 0) {
                options.onResize?.(height);
            }
        }

        try {
            const result = await rpc(message);

            if (result.response !== null && result.response !== undefined) {
                post(result.response);
            }

            for (const action of result.actions ?? []) {
                handleAction(action);
            }

            if (message.method === 'ui/notifications/initialized' && !ready) {
                ready = true;

                for (const notification of wireNotifications()) {
                    post(notification);
                }
            }
        } catch (error) {
            options.onError?.(error instanceof Error ? error.message : 'App request failed.');

            if (Object.prototype.hasOwnProperty.call(message, 'id')) {
                post({
                    jsonrpc: '2.0',
                    id: message.id,
                    error: { code: -32603, message: 'Host bridge error' },
                });
            }
        }
    };

    window.addEventListener('message', listener);

    return {
        post,
        destroy() {
            disposed = true;
            window.removeEventListener('message', listener);
        },
    };

    function post(message) {
        iframe.contentWindow?.postMessage(message, origin);
    }

    async function rpc(message) {
        const response = await fetch(`/api/view/${encodeURIComponent(view.id)}/rpc`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(message),
        });

        if (!response.ok) {
            throw new Error(`Bridge request failed (${response.status})`);
        }

        return response.json();
    }

    function wireNotifications() {
        const wire = view.wire ?? {};

        return [wire.toolInput, wire.toolResult].filter(Boolean);
    }

    function handleAction(action) {
        switch (action.type) {
            case 'message':
                options.onMessage?.(action.text ?? '');
                break;

            case 'update_model_context':
                options.onContext?.(action);
                break;

            case 'open_link':
                if (typeof action.url === 'string') {
                    window.open(action.url, '_blank', 'noopener,noreferrer');
                }
                break;

            case 'display_mode':
                options.onDisplayMode?.(action.mode ?? 'inline');
                break;

            case 'log':
                console.info('[MCP App]', action.level ?? 'info', action.data ?? {});
                break;

            case 'error':
                options.onError?.(action.message ?? 'App request failed.');
                break;

            default:
                break;
        }
    }
}
