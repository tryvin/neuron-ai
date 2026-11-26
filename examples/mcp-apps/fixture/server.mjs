// Local MCP Apps fixture. Not production code: no auth, loopback only.
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { z } from 'zod';
import { McpServer, createMcpHandler } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import {
    registerAppResource,
    registerAppTool,
    RESOURCE_MIME_TYPE,
} from '@modelcontextprotocol/ext-apps/server';

const here = dirname(fileURLToPath(import.meta.url));
const html = await readFile(join(here, 'view.html'), 'utf8');

const uri = 'ui://sales/dashboard.html';
const regions = ['all', 'emea', 'amer', 'apac'];
const uiResourceMeta = {
    // No external domains: the host's default-deny CSP is enough.
    csp: {},
    permissions: { clipboardWrite: {} },
    prefersBorder: true,
};

const rows = {
    all: [
        { region: 'EMEA', revenue: 124000 },
        { region: 'AMER', revenue: 198500 },
        { region: 'APAC', revenue: 87300 },
    ],
    emea: [{ region: 'EMEA', revenue: 124000 }],
    amer: [{ region: 'AMER', revenue: 198500 }],
    apac: [{ region: 'APAC', revenue: 87300 }],
};

const salesResult = (region) => {
    const selected = rows[region];
    const total = selected.reduce((sum, row) => sum + row.revenue, 0);

    return {
        content: [{ type: 'text', text: `Sales for ${region}: ${selected.length} regions, total ${total}.` }],
        structuredContent: { region, rows: selected, total },
    };
};

const createServer = () => {
    const server = new McpServer(
        { name: 'sales-fixture', version: '1.0.0' },
        {
            capabilities: {
                extensions: { 'io.modelcontextprotocol/ui': {} },
            },
        }
    );

    registerAppResource(server, 'sales-dashboard', uri, {
        title: 'Sales dashboard',
        mimeType: RESOURCE_MIME_TYPE,
        _meta: { ui: uiResourceMeta },
    }, async () => ({
        contents: [{
            uri,
            mimeType: RESOURCE_MIME_TYPE,
            text: html,
            _meta: { ui: uiResourceMeta },
        }],
    }));

    registerAppTool(server, 'get_sales', {
        title: 'Sales dashboard',
        description: 'Show sales for a region. Renders an interactive dashboard.',
        inputSchema: z.object({
            region: z.enum(regions).describe('Region to show'),
        }),
        _meta: { ui: { resourceUri: uri, visibility: ['model', 'app'] } },
    }, async ({ region }) => salesResult(region));

    registerAppTool(server, 'refresh_sales', {
        title: 'Refresh sales',
        description: 'Refresh the dashboard from inside the view. The model never sees this tool.',
        inputSchema: z.object({
            region: z.enum(regions).describe('Region to show'),
        }),
        _meta: { ui: { visibility: ['app'] } },
    }, async ({ region }) => ({
        ...salesResult(region),
        content: [{ type: 'text', text: `Refreshed sales for ${region}.` }],
    }));

    server.registerTool('get_time', {
        description: 'Return the server time as plain text, with no UI.',
        inputSchema: z.object({}),
    }, async () => ({
        content: [{ type: 'text', text: `Fixture time: ${new Date().toISOString()}` }],
    }));

    return server;
};

const handler = createMcpHandler(() => createServer(), { responseMode: 'json' });
const nodeHandler = toNodeHandler(handler);

const server = http.createServer(async (req, res) => {
    if (req.url === '/healthz') {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('ok');

        return;
    }

    if (req.url !== '/mcp') {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not found');

        return;
    }

    try {
        await nodeHandler(req, res);
    } catch (error) {
        console.error('Fixture MCP request failed:', error);

        if (!res.headersSent) {
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                jsonrpc: '2.0',
                id: null,
                error: { code: -32603, message: 'Fixture server error' },
            }));
        }
    }
});

server.listen(3001, '127.0.0.1', () => {
    console.log('MCP Apps fixture listening on http://127.0.0.1:3001/mcp');
});

process.on('SIGTERM', () => server.close());
process.on('SIGINT', () => server.close());
