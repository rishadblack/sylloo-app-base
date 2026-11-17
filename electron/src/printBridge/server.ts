import express, { Express, NextFunction, Request, Response } from 'express';
import { readFileSync } from 'fs';
import { Server } from 'http';
import { join } from 'path';
import { checkTicket, NonceStore } from './ticket.js';

export interface BridgePrinter {
    name: string;
    displayName: string;
    isDefault: boolean;
}

export interface PrintJob {
    html: string;
    url: string;
    printer: string;
    options: PrintOptions;
}

export interface PrintOptions {
    copies: number;
    landscape: boolean;
    pageSize?: string | { width: number };
}

/** What the bridge needs from the desktop app; tests pass a fake. */
export interface BridgeHost {
    printers(): Promise<BridgePrinter[]>;
    print(job: PrintJob): Promise<void>;
    selectedPrinter(): string | null;
    selectPrinter(name: string | null): void;
}

export interface BridgeConfig {
    /**
     * The app that answers on the port ('sylloo-pos', 'sylloo-app'): the only thing /v1/hello says about it. When left
     * out: {@link defaultAppLabel}.
     */
    appLabel?: string;
    publicKeys: Record<string, string>;
    nonces?: NonceStore;
    maxPrintsPerMinute?: number;
    now?: () => number;
}

/** The page tries these in order, so a port taken by another program does not end direct printing. */
export const BRIDGE_PORTS = [38917, 38918, 38919];

export const BRIDGE_PROTOCOL = 1;

const MAX_DOCUMENT_SIZE = '25mb';

const PAGE_SIZES = ['A3', 'A4', 'A5', 'Legal', 'Letter', 'Tabloid'];

const MAX_COPIES = 20;

// Roll and label paper, in microns: from 20 mm to 1 metre wide.
const MIN_PAGE_WIDTH = 20_000;
const MAX_PAGE_WIDTH = 1_000_000;

/** A page may reach the bridge only from a secure address of its own; a non-browser caller has no Origin at all. */
function secureOrigin(value: string | undefined): string | null {
    if (!value) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'https:' && url.origin === value ? value : null;
    } catch {
        return null;
    }
}

function refuse(res: Response, status: number, code: string): void {
    res.status(status).json({ ok: false, code });
}

/** Keeps only the settings this bridge understands; anything else in the request is dropped. */
export function cleanOptions(input: unknown): PrintOptions {
    const source = (typeof input === 'object' && input !== null ? input : {}) as Record<string, unknown>;
    const copies = Number(source.copies);
    const options: PrintOptions = {
        copies: Number.isInteger(copies) && copies >= 1 ? Math.min(copies, MAX_COPIES) : 1,
        landscape: source.landscape === true,
    };

    if (typeof source.pageSize === 'string' && PAGE_SIZES.includes(source.pageSize)) {
        options.pageSize = source.pageSize;
    }

    const width = Number((source.pageSize as { width?: unknown } | null | undefined)?.width);

    if (Number.isInteger(width) && width >= MIN_PAGE_WIDTH && width <= MAX_PAGE_WIDTH) {
        options.pageSize = { width };
    }

    return options;
}

function printFailureCode(error: unknown): string {
    const code = (error as { code?: unknown })?.code;

    return typeof code === 'string' ? code : 'print_failed';
}

/**
 * An app label from a name: lower-case words joined by '-', without an npm scope ('Sylloo POS' -> 'sylloo-pos',
 * '@sylloo/app' -> 'app'); null when nothing is left.
 */
export function labelFrom(name: string | undefined | null): string | null {
    const label = String(name ?? '')
        .replace(/^@[^/]+\//, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);

    return label !== '' ? label : null;
}

/**
 * The label when the app names none: SYLLOO_APP_LABEL, else the `name` of the app's package.json (the working folder,
 * as NativePHP starts Electron), else 'sylloo-app'.
 */
export function defaultAppLabel(folder: string = process.cwd()): string {
    const fromEnv = labelFrom(process.env.SYLLOO_APP_LABEL);

    if (fromEnv) {
        return fromEnv;
    }

    try {
        const pkg = JSON.parse(readFileSync(join(folder, 'package.json'), 'utf8')) as { name?: string };

        return labelFrom(pkg.name) ?? 'sylloo-app';
    } catch {
        return 'sylloo-app';
    }
}

export function createBridgeApp(host: BridgeHost, config: BridgeConfig, port: () => number): Express {
    const app = express();
    const appLabel = config.appLabel ?? defaultAppLabel();
    const nonces = config.nonces ?? new NonceStore();
    const now = config.now ?? (() => performance.now());
    const maxPrints = config.maxPrintsPerMinute ?? 60;
    let recentPrints: number[] = [];

    app.disable('x-powered-by');

    app.use((req: Request, res: Response, next: NextFunction) => {
        // Another website pointing its own name at this PC (DNS rebinding) arrives with a different Host.
        if (req.headers.host !== `127.0.0.1:${port()}`) {
            refuse(res, 403, 'wrong_host');

            return;
        }

        const origin = secureOrigin(req.headers.origin);

        if (!origin) {
            refuse(res, 403, 'wrong_origin');

            return;
        }

        res.setHeader('Access-Control-Allow-Origin', origin);
        res.setHeader('Vary', 'Origin');
        res.setHeader('Cache-Control', 'no-store');
        res.locals.origin = origin;

        if (req.method === 'OPTIONS') {
            res.setHeader('Access-Control-Allow-Methods', 'GET, POST');
            res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
            res.setHeader('Access-Control-Allow-Private-Network', 'true');
            res.setHeader('Access-Control-Max-Age', '600');
            res.sendStatus(204);

            return;
        }

        next();
    });

    app.use(express.json({ limit: MAX_DOCUMENT_SIZE }));

    const ticketFor = (req: Request, res: Response, scope: 'print' | 'manage', html?: string): boolean => {
        const result = checkTicket(req.body?.ticket, { scope, origin: res.locals.origin, html }, config.publicKeys, nonces);

        if (result.ok === false) {
            refuse(res, 403, (result as { code: string }).code);
        }

        return result.ok;
    };

    // Says only that the bridge is here and whether a printer is chosen: no version, no printer names.
    app.get('/v1/hello', (_req, res) => {
        res.json({ ok: true, app: appLabel, bridge: BRIDGE_PROTOCOL, ready: host.selectedPrinter() !== null });
    });

    app.get('/v1/nonce', (_req, res) => {
        res.json({ ok: true, nonce: nonces.issue() });
    });

    app.post('/v1/printers', async (req, res) => {
        if (!ticketFor(req, res, 'manage')) {
            return;
        }

        res.json({ ok: true, printers: await host.printers(), selected: host.selectedPrinter() });
    });

    app.post('/v1/printer', async (req, res) => {
        if (!ticketFor(req, res, 'manage')) {
            return;
        }

        const name = req.body?.name ?? null;

        if (name !== null && (typeof name !== 'string' || !(await host.printers()).some((printer) => printer.name === name))) {
            refuse(res, 404, 'printer_not_found');

            return;
        }

        host.selectPrinter(name);
        res.json({ ok: true, selected: name });
    });

    app.post('/v1/print', async (req, res) => {
        const { html, url } = req.body ?? {};

        if (typeof html !== 'string' || typeof url !== 'string') {
            refuse(res, 422, 'bad_request');

            return;
        }

        if (!ticketFor(req, res, 'print', html)) {
            return;
        }

        let address: URL;

        try {
            address = new URL(url);
        } catch {
            refuse(res, 422, 'bad_request');

            return;
        }

        if (address.origin !== res.locals.origin) {
            refuse(res, 403, 'ticket_wrong_origin');

            return;
        }

        const windowStart = now() - 60_000;
        recentPrints = recentPrints.filter((time) => time > windowStart);

        if (recentPrints.length >= maxPrints) {
            refuse(res, 429, 'too_many_prints');

            return;
        }

        recentPrints.push(now());

        const printer = host.selectedPrinter();

        if (!printer) {
            refuse(res, 409, 'printer_not_set');

            return;
        }

        if (!(await host.printers()).some((known) => known.name === printer)) {
            refuse(res, 404, 'printer_not_found');

            return;
        }

        try {
            await host.print({ html, url: address.href, printer, options: cleanOptions(req.body.options) });
            res.json({ ok: true, printer });
        } catch (error) {
            console.error('Print bridge: print failed', error);
            refuse(res, 502, printFailureCode(error));
        }
    });

    app.use((error: { type?: string }, _req: Request, res: Response, next: NextFunction) => {
        if (res.headersSent) {
            next(error);

            return;
        }

        refuse(res, error?.type === 'entity.too.large' ? 413 : 400, error?.type === 'entity.too.large' ? 'too_large' : 'bad_request');
    });

    return app;
}

function listen(app: Express, port: number): Promise<Server> {
    return new Promise((resolve, reject) => {
        const server = app.listen(port, '127.0.0.1');

        server.once('listening', () => resolve(server));
        server.once('error', reject);
    });
}

/** Starts on the first free port, on this PC only. Returns null when all are taken: printing then stays in the browser. */
export async function startBridgeServer(host: BridgeHost, config: BridgeConfig, ports: number[] = BRIDGE_PORTS): Promise<{ server: Server; port: number } | null> {
    let current = 0;
    const app = createBridgeApp(host, config, () => current);

    for (const port of ports) {
        try {
            current = port;
            const server = await listen(app, port);

            return { server, port: (server.address() as { port: number }).port };
        } catch {
            console.warn('Print bridge: port taken', port);
        }
    }

    return null;
}
