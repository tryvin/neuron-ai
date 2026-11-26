import { createBridge } from './bridge.js';

const state = {
    conversationId: 'demo',
    messages: [],
    views: [],
    cards: new Map(),
    bridges: new Map(),
    viewOrigin: 'http://127.0.0.1:8081',
    stagedContext: null,
    busy: false,
};

const transcript = document.querySelector('#transcript');
const composer = document.querySelector('#composer');
const input = document.querySelector('#message');
const sendButton = document.querySelector('#send');
const statusEl = document.querySelector('#status');
const badge = document.querySelector('#context-badge');
const notice = document.querySelector('#notice');

composer.addEventListener('submit', (event) => {
    event.preventDefault();
    const text = input.value.trim();

    if (text === '' || state.busy) {
        return;
    }

    input.value = '';
    sendChat(text);
});

load();

async function load() {
    try {
        const config = await fetchJson('/api/config');
        state.viewOrigin = config.viewOrigin ?? state.viewOrigin;

        const conversation = await fetchJson(`/api/conversation/${state.conversationId}`);
        state.messages = conversation.messages ?? [];
        state.views = conversation.views ?? [];
        state.stagedContext = conversation.stagedContext ?? null;

        renderAll();
        renderBadge();
    } catch (error) {
        showNotice(error instanceof Error ? error.message : 'Could not load the conversation.');
    }
}

function renderAll() {
    transcript.replaceChildren();
    state.cards.clear();

    for (const message of state.messages) {
        appendMessage(message);
    }
}

async function sendChat(text) {
    state.busy = true;
    sendButton.disabled = true;
    statusEl.textContent = 'Running the agent…';

    try {
        const data = await fetchJson('/api/chat', {
            method: 'POST',
            body: JSON.stringify({ conversationId: state.conversationId, message: text }),
        });

        if (data.appsSupported === false) {
            showNotice('MCP Apps not supported by this server.');
        }

        state.messages.push(...(data.messages ?? []));
        state.views.push(...(data.views ?? []));
        state.stagedContext = data.stagedContext ?? null;

        for (const message of data.messages ?? []) {
            appendMessage(message);
        }

        renderBadge();
        statusEl.textContent = '';
    } catch (error) {
        statusEl.textContent = '';
        appendBubble('assistant', `Chat failed: ${error instanceof Error ? error.message : 'unknown error'}`);
    } finally {
        state.busy = false;
        sendButton.disabled = false;
        input.focus();
    }
}

function appendMessage(message) {
    if (message.type === 'tool_call') {
        appendToolCall(message);
        return;
    }

    if (message.type === 'tool_call_result') {
        appendToolResult(message);
        return;
    }

    appendBubble(message.role === 'user' ? 'user' : 'assistant', messageText(message));
}

function appendBubble(role, text) {
    const bubble = element('div', `bubble ${role}`, text);
    transcript.append(bubble);
    scrollToEnd();

    return bubble;
}

function appendToolCall(message) {
    const tool = message.tools?.[0] ?? {};
    const callId = tool.callId ?? message.__id ?? '';
    const parts = createToolCard(tool, callId);

    parts.status.textContent = 'Running';
    transcript.append(parts.card);
    state.cards.set(String(callId), parts);
    scrollToEnd();
}

function appendToolResult(message) {
    const tool = message.tools?.[0] ?? {};
    const callId = String(tool.callId ?? message.__id ?? '');
    let parts = state.cards.get(callId);

    if (!parts) {
        parts = createToolCard(tool, callId);
        transcript.append(parts.card);
        state.cards.set(callId, parts);
    }

    parts.status.textContent = 'Done';
    parts.body.textContent = toolText(tool);

    const view = state.views.find((candidate) => String(candidate.callId ?? '') === callId);

    if (view) {
        mountView(parts, view);
    } else {
        parts.mount.append(element('p', 'muted', 'This tool has no UI.'));
    }

    scrollToEnd();
}

function createToolCard(tool, callId) {
    const card = element('article', 'card tool-card');
    card.dataset.callId = String(callId);

    const head = element('div', 'card-head');
    const title = element('span', 'card-title', `Tool: ${tool.name ?? 'unknown'}`);
    const status = element('span', 'card-status');
    head.append(title, status);

    const details = document.createElement('details');
    const summary = element('summary', '', 'Arguments');
    const pre = document.createElement('pre');
    pre.textContent = JSON.stringify(tool.inputs ?? {}, null, 2);
    details.append(summary, pre);

    const body = element('div', 'tool-body');
    const mount = element('div', 'view-mount');

    card.append(head, details, body, mount);

    return { card, status, body, mount };
}

function mountView(parts, view) {
    if (state.bridges.has(view.id)) {
        return;
    }

    if (view.status === 'error' || !view.wire?.toolResult) {
        parts.mount.append(element('p', 'muted', view.error ?? 'The app resource could not be loaded.'));
        return;
    }

    const frame = document.createElement('iframe');
    frame.className = 'app-frame';
    frame.src = `${state.viewOrigin}/app/view/${encodeURIComponent(view.id)}`;
    frame.sandbox = 'allow-scripts allow-same-origin';
    frame.allow = view.resource?.allow ?? '';
    frame.title = `${view.tool} app view`;
    parts.mount.append(frame);

    const bridge = createBridge(frame, view, {
        viewOrigin: state.viewOrigin,
        onMessage: (text) => sendChat(text),
        onContext: () => {
            state.stagedContext = view.stagedContext ?? {};
            renderBadge();
        },
        onError: (text) => {
            parts.status.textContent = `Blocked: ${text}`;
            parts.status.classList.add('error');
        },
        onResize: (height) => {
            frame.style.height = `${Math.min(Math.max(height, 280), 900)}px`;
        },
        onDisplayMode: (mode) => {
            parts.card.classList.toggle('fullscreen', mode === 'fullscreen');
        },
    });

    state.bridges.set(view.id, bridge);
}

function renderBadge() {
    badge.hidden = state.stagedContext === null || state.stagedContext === undefined;
}

function showNotice(text) {
    notice.textContent = text;
    notice.hidden = false;
}

function scrollToEnd() {
    transcript.scrollTop = transcript.scrollHeight;
}

function element(tag, className = '', text = '') {
    const node = document.createElement(tag);

    if (className !== '') {
        node.className = className;
    }

    if (text !== '') {
        node.textContent = text;
    }

    return node;
}

function messageText(message) {
    const content = message.content;

    if (typeof content === 'string') {
        return content;
    }

    if (Array.isArray(content)) {
        return content
            .map((block) => block?.content ?? block?.text ?? '')
            .filter(Boolean)
            .join('\n');
    }

    return '';
}

function toolText(tool) {
    if (typeof tool.result === 'string') {
        return tool.result;
    }

    return tool.resultOutput?.text ?? '';
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        headers: { 'Content-Type': 'application/json' },
        ...options,
    });
    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.error ?? `Request failed (${response.status})`);
    }

    return data;
}
