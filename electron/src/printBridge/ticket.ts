import { createHash, createPublicKey, randomBytes, verify } from 'crypto';

export type TicketScope = 'print' | 'manage';

export interface TicketPayload {
    v: number;
    kid: string;
    scope: TicketScope;
    origin: string;
    nonce: string;
    doc?: string;
}

export type TicketFailure = 'ticket_invalid' | 'ticket_wrong_scope' | 'ticket_wrong_origin' | 'ticket_used' | 'document_changed';

export type TicketResult = { ok: true; payload: TicketPayload } | { ok: false; code: TicketFailure };

/** A nonce is good for this long, by this PC's own running clock: a wrong Windows date can not break printing. */
export const NONCE_LIFETIME_MS = 60_000;

const MAX_OPEN_NONCES = 32;

/**
 * The one-use numbers the bridge hands out. The server signs the number into a ticket, so a ticket is fresh and used
 * once without the PC clock and the server clock having to agree.
 */
export class NonceStore {
    private nonces = new Map<string, number>();

    constructor(private now: () => number = () => performance.now()) {}

    issue(): string {
        this.forgetExpired();

        // Oldest first: a page asking for many numbers can only push out its own unused ones.
        while (this.nonces.size >= MAX_OPEN_NONCES) {
            this.nonces.delete(this.nonces.keys().next().value as string);
        }

        const nonce = randomBytes(16).toString('hex');
        this.nonces.set(nonce, this.now());

        return nonce;
    }

    /** True once for a number this bridge issued within its lifetime. */
    consume(nonce: string): boolean {
        this.forgetExpired();

        return this.nonces.delete(nonce);
    }

    private forgetExpired(): void {
        const oldest = this.now() - NONCE_LIFETIME_MS;

        for (const [nonce, issuedAt] of this.nonces) {
            if (issuedAt < oldest) {
                this.nonces.delete(nonce);
            }
        }
    }
}

export function documentFingerprint(html: string): string {
    return createHash('sha256').update(html, 'utf8').digest('hex');
}

function signatureIsValid(segment: string, signature: Buffer, publicKeyBase64: string): boolean {
    try {
        const key = createPublicKey({
            key: { kty: 'OKP', crv: 'Ed25519', x: Buffer.from(publicKeyBase64, 'base64').toString('base64url') },
            format: 'jwk',
        });

        return verify(null, Buffer.from(segment, 'ascii'), key, signature);
    } catch {
        return false;
    }
}

function readPayload(ticket: unknown, publicKeys: Record<string, string>): TicketPayload | null {
    if (typeof ticket !== 'string' || ticket.length > 2048) {
        return null;
    }

    const [segment, signature, extra] = ticket.split('.');

    if (!segment || !signature || extra !== undefined) {
        return null;
    }

    let payload: TicketPayload;

    try {
        payload = JSON.parse(Buffer.from(segment, 'base64url').toString('utf8'));
    } catch {
        return null;
    }

    const publicKey = Object.prototype.hasOwnProperty.call(publicKeys, payload?.kid) ? publicKeys[payload.kid] : undefined;

    if (!publicKey || !signatureIsValid(segment, Buffer.from(signature, 'base64url'), publicKey)) {
        return null;
    }

    return payload.v === 1 ? payload : null;
}

/**
 * Checks a ticket against the page that sent it. The number is spent only after the signature, scope and address
 * passed, so another website can not use up the number of a real print.
 */
export function checkTicket(
    ticket: unknown,
    expected: { scope: TicketScope; origin: string; html?: string },
    publicKeys: Record<string, string>,
    nonces: NonceStore,
): TicketResult {
    const payload = readPayload(ticket, publicKeys);

    if (!payload) {
        return { ok: false, code: 'ticket_invalid' };
    }

    if (payload.scope !== expected.scope) {
        return { ok: false, code: 'ticket_wrong_scope' };
    }

    if (payload.origin !== expected.origin) {
        return { ok: false, code: 'ticket_wrong_origin' };
    }

    if (!nonces.consume(payload.nonce)) {
        return { ok: false, code: 'ticket_used' };
    }

    if (expected.html !== undefined && payload.doc !== documentFingerprint(expected.html)) {
        return { ok: false, code: 'document_changed' };
    }

    return { ok: true, payload };
}
