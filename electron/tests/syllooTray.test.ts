import { afterEach, describe, expect, it } from 'vitest';
import { setSyllooTrayHooks, syllooTrayHooks, withSyllooMenuItems } from '@desktop-base/syllooTray';

const noHooks = { openApp: () => undefined, menuItems: () => [], trayReady: () => undefined };

afterEach(() => setSyllooTrayHooks(noHooks));

describe('syllooTray', () => {
    it("places the app's own items just above Quit", () => {
        setSyllooTrayHooks({ ...noHooks, menuItems: () => [{ label: 'Open Sylloo App' }] });

        const menu = withSyllooMenuItems([{ label: 'Sync' }, { role: 'quit' }]);

        expect(menu).toEqual([{ label: 'Sync' }, { label: 'Open Sylloo App' }, { type: 'separator' }, { role: 'quit' }]);
    });

    it('adds them at the end when the menu has no Quit, and changes nothing without items', () => {
        setSyllooTrayHooks({ ...noHooks, menuItems: () => [{ label: 'Open' }] });
        expect(withSyllooMenuItems([{ label: 'Sync' }])).toEqual([{ label: 'Sync' }, { label: 'Open' }, { type: 'separator' }]);

        setSyllooTrayHooks(noHooks);
        expect(withSyllooMenuItems([{ label: 'Sync' }])).toEqual([{ label: 'Sync' }]);
        expect(syllooTrayHooks()).toBe(noHooks);
    });
});
