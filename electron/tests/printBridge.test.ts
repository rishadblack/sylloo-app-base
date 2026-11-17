import { createHash, generateKeyPairSync, KeyObject, sign } from 'crypto';
import { request, Server } from 'http';
import { AddressInfo, createServer } from 'net';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mkdtempSync, writeFileSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { BridgeHost, BridgePrinter, cleanOptions, createBridgeApp, defaultAppLabel, labelFrom, PrintJob, startBridgeServer } from '@desktop-base/printBridge/server';
import { NONCE_LIFETIME_MS, NonceStore } from '@desktop-base/printBridge/ticket';

const ORIGIN = 'https://shop.sylloo.eu';
const HTML = '<h1>Invoice ৳ 100</h1>';

const { publicKey, privateKey } = generateKeyPairSync('ed25519');
const other = generateKeyPairSync('ed25519');

function raw(key: KeyObject): string {
    return Buffer.from(key.export({ format: 'jwk' }).x as string, 'base64url').toString('base64');
}

function makeTicket(payload: Record<string, unknown>, key: KeyObject = privateKey): string {
    const segment = Buffer.from(JSON.stringify({ v: 1, kid: 'k1', ...payload })).toString('base64url');

    return `${segment}.${sign(null, Buffer.from(segment, 'ascii'), key).toString('base64url')}`;
}

function fingerprint(html: string): string {
    return createHash('sha256').update(html, 'utf8').digest('hex');
}

describe('print bridge', () => {
    let server: Server;
    let base: string;
    let port: number;
    let printed: PrintJob[];
    let selected: string | null;
    let printers: BridgePrinter[];
    let clock: number;
    let nonces: NonceStore;
    let printError: Error | null;

    const host: BridgeHost = {
        printers: async () => printers,
        print: async (job) => {
            if (printError) {
                throw printError;
            }
            printed.push(job);
        },
        selectedPrinter: () => selected,
        selectPrinter: (name) => {
            selected = name;
        },
    };

    const call = (path: string, init: RequestInit = {}, origin: string | null = ORIGIN) =>
        fetch(`${base}${path}`, {
            ...init,
            headers: { 'Content-Type': 'application/json', ...(origin ? { Origin: origin } : {}), ...(init.headers ?? {}) },
        });

    const post = (path: string, body: unknown, origin: string | null = ORIGIN) => call(path, { method: 'POST', body: JSON.stringify(body) }, origin);

    const printTicket = (overrides: Record<string, unknown> = {}) =>
        makeTicket({ scope: 'print', origin: ORIGIN, nonce: nonces.issue(), doc: fingerprint(HTML), ...overrides });

    const printBody = (ticket: string, overrides: Record<string, unknown> = {}) => ({ ticket, html: HTML, url: `${ORIGIN}/invoice/1`, ...overrides });

    beforeEach(async () => {
        printed = [];
        selected = 'Front desk';
        printers = [{ name: 'Front desk', displayName: 'Front desk', isDefault: true }];
        clock = 1000;
        printError = null;
        nonces = new NonceStore(() => clock);

        const app = createBridgeApp(host, { appLabel: 'sylloo-pos', publicKeys: { k1: raw(publicKey) }, nonces, maxPrintsPerMinute: 3, now: () => clock }, () => port);

        await new Promise<void>((resolve) => {
            server = app.listen(0, '127.0.0.1', () => resolve());
        });

        port = (server.address() as AddressInfo).port;
        base = `http://127.0.0.1:${port}`;
    });

    afterEach(async () => {
        await new Promise((resolve) => server.close(resolve));
    });

    it('prints a document with a valid ticket on the chosen printer', async () => {
        const response = await post('/v1/print', printBody(printTicket()));

        expect(response.status).toBe(200);
        expect(await response.json()).toEqual({ ok: true, printer: 'Front desk' });
        expect(printed).toHaveLength(1);
        expect(printed[0]).toMatchObject({ html: HTML, printer: 'Front desk', options: { copies: 1, landscape: false } });
    });

    it('tells a page only that it is here, with no printer names or version', async () => {
        const body = await (await call('/v1/hello')).json();

        expect(body).toEqual({ ok: true, app: 'sylloo-pos', bridge: 1, ready: true });
    });

    it('refuses a print without a ticket', async () => {
        const response = await post('/v1/print', { html: HTML, url: `${ORIGIN}/a` });

        expect(response.status).toBe(403);
        expect((await response.json()).code).toBe('ticket_invalid');
        expect(printed).toHaveLength(0);
    });

    it('refuses a ticket signed by another key', async () => {
        const ticket = makeTicket({ scope: 'print', origin: ORIGIN, nonce: nonces.issue(), doc: fingerprint(HTML) }, other.privateKey);

        expect((await (await post('/v1/print', printBody(ticket))).json()).code).toBe('ticket_invalid');
    });

    it('refuses a ticket made for another address', async () => {
        const response = await post('/v1/print', printBody(printTicket()), 'https://evil.example');

        expect((await response.json()).code).toBe('ticket_wrong_origin');
        expect(printed).toHaveLength(0);
    });

    it('refuses a changed document', async () => {
        const response = await post('/v1/print', printBody(printTicket(), { html: '<h1>Other</h1>' }));

        expect((await response.json()).code).toBe('document_changed');
    });

    it('refuses a document address outside the business', async () => {
        const response = await post('/v1/print', printBody(printTicket(), { url: 'https://evil.example/x' }));

        expect((await response.json()).code).toBe('ticket_wrong_origin');
    });

    it('refuses a ticket used twice', async () => {
        const ticket = printTicket();

        expect((await post('/v1/print', printBody(ticket))).status).toBe(200);
        expect((await (await post('/v1/print', printBody(ticket))).json()).code).toBe('ticket_used');
        expect(printed).toHaveLength(1);
    });

    it('refuses an old ticket', async () => {
        const ticket = printTicket();
        clock += NONCE_LIFETIME_MS + 1;

        expect((await (await post('/v1/print', printBody(ticket))).json()).code).toBe('ticket_used');
    });

    it('refuses a print ticket for managing printers', async () => {
        const response = await post('/v1/printers', { ticket: printTicket() });

        expect((await response.json()).code).toBe('ticket_wrong_scope');
    });

    it('does not spend the number when the address is wrong', async () => {
        const nonce = nonces.issue();
        const ticket = printTicket({ nonce });

        await post('/v1/print', printBody(ticket), 'https://evil.example');

        expect(nonces.consume(nonce)).toBe(true);
    });

    it('shows the printers only with a ticket', async () => {
        expect((await post('/v1/printers', {})).status).toBe(403);

        const body = await (await post('/v1/printers', { ticket: makeTicket({ scope: 'manage', origin: ORIGIN, nonce: nonces.issue() }) })).json();

        expect(body.printers[0].name).toBe('Front desk');
        expect(body.selected).toBe('Front desk');
    });

    it('saves only a printer of this computer and can clear the choice', async () => {
        const ticket = () => makeTicket({ scope: 'manage', origin: ORIGIN, nonce: nonces.issue() });

        expect((await post('/v1/printer', { ticket: ticket(), name: 'Nowhere' })).status).toBe(404);
        expect(selected).toBe('Front desk');

        await post('/v1/printer', { ticket: ticket(), name: null });
        expect(selected).toBeNull();
    });

    it('answers in plain codes when the printer is gone or not chosen', async () => {
        printers = [];
        expect((await (await post('/v1/print', printBody(printTicket()))).json()).code).toBe('printer_not_found');

        selected = null;
        expect((await (await post('/v1/print', printBody(printTicket()))).json()).code).toBe('printer_not_set');
    });

    it('reports a failed print', async () => {
        printError = Object.assign(new Error('x'), { code: 'printer_failed' });
        const response = await post('/v1/print', printBody(printTicket()));

        expect(response.status).toBe(502);
        expect((await response.json()).code).toBe('printer_failed');
    });

    it('limits prints per minute', async () => {
        for (let i = 0; i < 3; i++) {
            expect((await post('/v1/print', printBody(printTicket()))).status).toBe(200);
        }

        expect((await post('/v1/print', printBody(printTicket()))).status).toBe(429);

        clock += 61_000;
        expect((await post('/v1/print', printBody(printTicket()))).status).toBe(200);
    });

    it('refuses a request addressed to another host name (DNS rebinding)', async () => {
        const answer = await new Promise<{ status: number; body: string }>((resolve, reject) => {
            const sent = request(
                { host: '127.0.0.1', port, path: '/v1/hello', headers: { Host: `evil.example:${port}`, Origin: ORIGIN } },
                (res) => {
                    let body = '';
                    res.on('data', (chunk) => (body += chunk));
                    res.on('end', () => resolve({ status: res.statusCode ?? 0, body }));
                },
            );
            sent.on('error', reject);
            sent.end();
        });

        expect(answer.status).toBe(403);
        expect(JSON.parse(answer.body).code).toBe('wrong_host');
    });

    it('refuses a caller that is not a secure page', async () => {
        expect((await call('/v1/hello', {}, null)).status).toBe(403);
        expect((await call('/v1/hello', {}, 'http://shop.sylloo.eu')).status).toBe(403);
    });

    it('allows a page across the local boundary only by its own address', async () => {
        const response = await call('/v1/print', { method: 'OPTIONS' });

        expect(response.status).toBe(204);
        expect(response.headers.get('access-control-allow-origin')).toBe(ORIGIN);
        expect(response.headers.get('access-control-allow-private-network')).toBe('true');
    });

    it('keeps only known print settings, within limits', () => {
        expect(cleanOptions({ copies: 500, landscape: true, pageSize: 'A4', silent: false, deviceName: 'x' })).toEqual({ copies: 20, landscape: true, pageSize: 'A4' });
        expect(cleanOptions({ copies: -1, pageSize: 'Huge' })).toEqual({ copies: 1, landscape: false });
        expect(cleanOptions({ pageSize: { width: 80000, height: 1 } })).toEqual({ copies: 1, landscape: false, pageSize: { width: 80000 } });
        expect(cleanOptions({ pageSize: { width: 1 } })).toEqual({ copies: 1, landscape: false });
    });
});

describe('print bridge ports', () => {
    const quietHost: BridgeHost = { printers: async () => [], print: async () => undefined, selectedPrinter: () => null, selectPrinter: () => undefined };

    const freePort = () =>
        new Promise<number>((resolve) => {
            const probe = createServer().listen(0, '127.0.0.1', () => {
                const { port } = probe.address() as AddressInfo;
                probe.close(() => resolve(port));
            });
        });

    it('names the app that holds the port, and leaves printing to whichever app started first', async () => {
        const taken = await freePort();
        const first = await startBridgeServer(quietHost, { appLabel: 'sylloo-app', publicKeys: {} }, [taken]);

        try {
            const second = await startBridgeServer(quietHost, { appLabel: 'sylloo-pos', publicKeys: {} }, [taken]);
            const hello = await (await fetch(`http://127.0.0.1:${taken}/v1/hello`, { headers: { Origin: 'https://shop.sylloo.app' } })).json();

            expect(second).toBeNull();
            expect(hello).toEqual({ ok: true, app: 'sylloo-app', bridge: 1, ready: false });
        } finally {
            await new Promise((resolve) => first!.server.close(resolve));
        }
    });
});

describe('print bridge app label', () => {
    it('makes a label from a name', () => {
        expect(labelFrom('Sylloo POS')).toBe('sylloo-pos');
        expect(labelFrom('@sylloo/sylloo-app')).toBe('sylloo-app');
        expect(labelFrom('  ')).toBeNull();
        expect(labelFrom(undefined)).toBeNull();
    });

    it('takes the app package name when the app names no label', () => {
        const folder = mkdtempSync(join(tmpdir(), 'bridge-label-'));
        writeFileSync(join(folder, 'package.json'), JSON.stringify({ name: 'Sylloo POS' }));
        const before = process.env.SYLLOO_APP_LABEL;
        delete process.env.SYLLOO_APP_LABEL;

        try {
            expect(defaultAppLabel(folder)).toBe('sylloo-pos');
            expect(defaultAppLabel(join(folder, 'missing'))).toBe('sylloo-app');
            process.env.SYLLOO_APP_LABEL = 'Sylloo Kiosk';
            expect(defaultAppLabel(folder)).toBe('sylloo-kiosk');
        } finally {
            if (before === undefined) {
                delete process.env.SYLLOO_APP_LABEL;
            } else {
                process.env.SYLLOO_APP_LABEL = before;
            }
        }
    });

    it('answers /v1/hello with the default label', async () => {
        process.env.SYLLOO_APP_LABEL = 'sylloo-pos';
        const quietHost: BridgeHost = { printers: async () => [], print: async () => undefined, selectedPrinter: () => null, selectPrinter: () => undefined };
        let listening = 0;
        const app = createBridgeApp(quietHost, { publicKeys: {} }, () => listening);
        const server = await new Promise<Server>((resolve) => {
            const started = app.listen(0, '127.0.0.1', () => resolve(started));
        });
        listening = (server.address() as AddressInfo).port;

        try {
            const { port } = server.address() as AddressInfo;
            const hello = await (await fetch(`http://127.0.0.1:${port}/v1/hello`, { headers: { Origin: 'https://shop.sylloo.app' } })).json();

            expect(hello.app).toBe('sylloo-pos');
        } finally {
            delete process.env.SYLLOO_APP_LABEL;
            await new Promise((resolve) => server.close(resolve));
        }
    });
});
