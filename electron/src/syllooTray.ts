import type { MenuItemConstructorOptions } from 'electron';

/**
 * Sylloo: one tray icon. NativePHP's menu bar icon is the only icon once PHP runs: a double click opens the app, and its
 * right-click menu also holds the app's own items (the till: Open Sylloo POS, Start with Windows). The print bridge's
 * own icon only bridges the time before that (a Windows sign-in start) and is removed when the menu bar icon is ready.
 *
 * The app registers its hooks once (setSyllooTrayHooks); NativePHP's menuBar API (api/menuBar.ts, patched in each app)
 * reads them with syllooTrayHooks() and withSyllooMenuItems().
 */
export interface SyllooTrayHooks {
    /** Shows the app (double click). */
    openApp: () => void;
    /** Items added to the right-click menu, just above Quit. */
    menuItems: () => MenuItemConstructorOptions[];
    /** The menu bar icon is ready: remove the bridge's own icon. */
    trayReady: () => void;
}

let hooks: SyllooTrayHooks | null = null;

export function setSyllooTrayHooks(value: SyllooTrayHooks): void {
    hooks = value;
}

export function syllooTrayHooks(): SyllooTrayHooks | null {
    return hooks;
}

/** The compiled context menu with the app's own items placed just above Quit (or at the end). */
export function withSyllooMenuItems(entries: MenuItemConstructorOptions[]): MenuItemConstructorOptions[] {
    const extra = hooks?.menuItems() ?? [];

    if (extra.length === 0) {
        return entries;
    }

    const quitAt = entries.findIndex((entry) => entry.role === 'quit');
    const at = quitAt === -1 ? entries.length : quitAt;

    return [...entries.slice(0, at), ...extra, { type: 'separator' }, ...entries.slice(at)];
}
