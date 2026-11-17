import { app, BrowserWindow, session } from 'electron';
import { existsSync, readFileSync, writeFileSync } from 'fs';
import { join } from 'path';
import { BRIDGE_PORTS, BridgeHost, BridgePrinter, defaultAppLabel, labelFrom, PrintJob, startBridgeServer } from './server.js';

/**
 * Sylloo's direct printing for the ERP in the browser, shared by both desktop apps (Sylloo POS and Sylloo App): a small
 * service on 127.0.0.1 (ports 38917-38919) that prints a document only with a one-use ticket the ERP signed. Whichever
 * app started first holds the port and prints; the other one finds the ports taken and leaves printing to it.
 */
export interface PrintBridgeOptions {
    /**
     * The app that answers on the port, told to pages in /v1/hello ('sylloo-pos', 'sylloo-app'). When left out: the
     * Electron app's name (its package.json name), else SYLLOO_APP_LABEL or 'sylloo-app'.
     */
    appLabel?: string;
    /** The ERP's print keys by key id; the shipped Sylloo keys when left out. */
    publicKeys?: Record<string, string>;
    /** Where the chosen printer and "start with Windows" are kept; `<userData>/print-bridge.json` when left out. */
    settingsFile?: string;
    /**
     * Changes the Electron print settings just before a document prints, for example a receipt page as tall as its
     * content. `contentHeight` measures the loaded document in CSS pixels (0 when it can not be measured).
     */
    adjustPrintSettings?: (settings: Record<string, unknown>, contentHeight: () => Promise<number>) => Promise<Record<string, unknown>>;
    /** The ports tried in order; the shared three when left out. */
    ports?: number[];
}

export type { BridgeHost, BridgePrinter, PrintJob } from './server.js';

/**
 * The server's public keys by key id. Two ids are shipped so the server key can be replaced without breaking installed
 * apps. A build without keys refuses every ticket, and printing stays in the browser.
 */
export const SHIPPED_PUBLIC_KEYS: Record<string, string> = {
    // In use on the server (SYLLOO_PRINT_KEY_ID=k1), made 2026-11-15.
    k1: 'y7OeNeR1NLSs8imMeADmJcYXDbj0nPFrSdhtaRIr3Wg=',
    // Spare (private key kept offline): switch the server to SYLLOO_PRINT_KEY_ID=k2 if k1 must be replaced.
    k2: '/yjVqLU7TX4ha4six+0lcFbcY/hdl5sS/ySAMV3eAAg=',
};

const PRINT_TIMEOUT_MS = 30_000;
const MEASURE_TIMEOUT_MS = 3_000;
const PARTITION = 'print-bridge';

function publicKeys(shipped: Record<string, string> = SHIPPED_PUBLIC_KEYS): Record<string, string> {
    const development = app.isPackaged ? undefined : process.env.SYLLOO_PRINT_PUBLIC_KEY;

    return development ? { ...shipped, dev: development } : shipped;
}

/** The options of the running bridge (startPrintBridge). */
let runningOptions: PrintBridgeOptions | null = null;

interface BridgeSettings {
    printer: string | null;
    startWithWindows: boolean;
}

function settingsFile(): string {
    return runningOptions?.settingsFile ?? join(app.getPath('userData'), 'print-bridge.json');
}

/** Kept in its own file, so a till wipe or a new activation code leaves direct printing set up. */
export function readBridgeSettings(): BridgeSettings {
    const defaults: BridgeSettings = { printer: null, startWithWindows: true };

    try {
        return existsSync(settingsFile()) ? { ...defaults, ...JSON.parse(readFileSync(settingsFile(), 'utf8')) } : defaults;
    } catch {
        return defaults;
    }
}

export function writeBridgeSettings(changes: Partial<BridgeSettings>): void {
    writeFileSync(settingsFile(), JSON.stringify({ ...readBridgeSettings(), ...changes }));
}

/** The running documents by their exact address, read by the request handler of the print session. */
const jobs = new Map<string, { html: string; origin: string }>();
let sessionReady = false;

/**
 * Only the document's own business address may be loaded (its pictures, styles and fonts), without anybody's sign-in:
 * the print session holds no cookies. Scripts of the document never run (the policy below).
 */
function printSession(): Electron.Session {
    const printing = session.fromPartition(PARTITION);

    if (sessionReady) {
        return printing;
    }

    sessionReady = true;

    printing.protocol.handle('https', (request) => {
        const job = jobs.get(request.url);

        if (job) {
            return new Response(job.html, {
                headers: {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Content-Security-Policy': `default-src 'none'; img-src ${job.origin} data:; style-src ${job.origin} 'unsafe-inline'; font-src ${job.origin} data:`,
                },
            });
        }

        const origin = new URL(request.url).origin;
        const allowed = request.method === 'GET' && [...jobs.values()].some((running) => running.origin === origin);

        return allowed ? printing.fetch(request, { bypassCustomProtocolHandlers: true }) : Response.error();
    });

    return printing;
}

function failure(code: string): Error & { code: string } {
    return Object.assign(new Error(code), { code });
}

function measureHeight(window: BrowserWindow): Promise<number> {
    const measure = window.webContents
        .executeJavaScript('Promise.resolve(document.fonts && document.fonts.ready).then(() => Math.ceil(document.documentElement.scrollHeight))')
        .catch(() => 0);
    const timeout = new Promise<number>((resolve) => setTimeout(() => resolve(0), MEASURE_TIMEOUT_MS));

    return Promise.race([measure, timeout]).then((height) => Number(height) || 0);
}

function printDocument(job: PrintJob): Promise<void> {
    const url = new URL(job.url);
    url.searchParams.set('_sylloo_print', crypto.randomUUID());
    const address = url.href;

    jobs.set(address, { html: job.html, origin: url.origin });

    const window = new BrowserWindow({
        show: false,
        webPreferences: {
            session: printSession(),
            sandbox: true,
            contextIsolation: true,
            nodeIntegration: false,
        },
    });

    window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    window.webContents.on('will-navigate', (event) => event.preventDefault());

    return new Promise<void>((resolve, reject) => {
        const timer = setTimeout(() => reject(failure('print_timeout')), PRINT_TIMEOUT_MS);

        window.webContents.once('did-fail-load', (_event, _code, description, _url, isMainFrame) => {
            if (isMainFrame) {
                console.error('Print bridge: document did not load', description);
                reject(failure('print_failed'));
            }
        });

        window.webContents.once('did-finish-load', async () => {
            let settings: Record<string, unknown> = {
                silent: true,
                printBackground: true,
                deviceName: job.printer,
                copies: job.options.copies,
                landscape: job.options.landscape,
            };

            if (job.options.pageSize) {
                settings.pageSize = job.options.pageSize;
            }

            if (runningOptions?.adjustPrintSettings) {
                settings = await runningOptions.adjustPrintSettings(settings, () => measureHeight(window));
            }

            window.webContents.print(settings, (success, reason) => {
                clearTimeout(timer);

                if (success) {
                    resolve();
                } else {
                    console.error('Print bridge: printer refused', reason);
                    reject(failure('printer_failed'));
                }
            });
        });

        window.loadURL(address).catch(() => undefined);
    }).finally(() => {
        jobs.delete(address);

        if (!window.isDestroyed()) {
            window.destroy();
        }
    });
}

async function listPrinters(): Promise<BridgePrinter[]> {
    const window = new BrowserWindow({ show: false });

    try {
        const printers = await window.webContents.getPrintersAsync();

        return printers.map((printer) => ({
            name: printer.name,
            displayName: printer.displayName || printer.name,
            isDefault: Boolean((printer as { isDefault?: boolean }).isDefault || printer.options?.['printer-is-default'] === 'true'),
        }));
    } finally {
        window.destroy();
    }
}

const host: BridgeHost = {
    printers: listPrinters,
    print: printDocument,
    selectedPrinter: () => readBridgeSettings().printer,
    selectPrinter: (name) => writeBridgeSettings({ printer: name }),
};

/** Starts the bridge on the first free port; null when all are taken (another Sylloo app prints, or another program). */
export function startPrintBridge(bridgeOptions: PrintBridgeOptions = {}) {
    runningOptions = bridgeOptions;

    return startBridgeServer(
        host,
        { appLabel: bridgeOptions.appLabel ?? labelFrom(app.getName()) ?? defaultAppLabel(), publicKeys: publicKeys(bridgeOptions.publicKeys) },
        bridgeOptions.ports ?? BRIDGE_PORTS,
    );
}
