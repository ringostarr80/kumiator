import { expect, test, type Locator, type Page } from '@playwright/test';

// Die Konten legt der E2eSeeder an, das Passwort kommt aus der UserFactory
async function logIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL('/dashboard');
}

// Solange das Fenster translate/scale trägt oder ein Übergang läuft, liegt es in einem eigenen
// Stapelkontext über dem Hintergrund, auch wenn es danach darunter rutscht. Erst im Ruhezustand zeigt
// ein Klick, wo das Fenster wirklich liegt.
async function waitUntilOpened(dialog: Locator): Promise<void> {
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeVisible();
    // Ohne Stylesheet, etwa bei einer liegengebliebenen public/hot, liegt nichts übereinander, und
    // jeder Klick träfe
    await expect(dialog).toHaveCSS('position', 'fixed');
    const panel = dialog.locator(':scope > div:has(button)');
    await expect(panel).toHaveCSS('translate', 'none');
    await expect(panel).toHaveCSS('scale', 'none');
    await expect.poll(() => dialog.evaluate((modal) => modal.getAnimations({ subtree: true }).length)).toBe(0);
}

// Die Klicks landen nur, wenn kein anderes Element über dem Knopf liegt. Genau daran scheitert
// ein Dialog, den sein eigener Hintergrund verdeckt.
test.describe('Dialoge auf der Profilseite', () => {
    test('bestätigen das Passwort, bevor 2FA eingerichtet wird', async ({ page }) => {
        await logIn(page, 'e2e-two-factor@example.com');
        await page.goto('/user/profile');

        await page.getByRole('button', { name: 'Enable', exact: true }).click();

        const passwordDialog = page.locator('#confirm-password-two-factor');
        await waitUntilOpened(passwordDialog);
        await passwordDialog.getByPlaceholder('Password').fill('password');
        await passwordDialog.getByRole('button', { name: 'Confirm', exact: true }).click();

        await expect(page.getByText('Finish enabling two factor authentication.')).toBeVisible();
    });

    test('führen über Passwort und Rückfrage zum Beenden der anderen Sitzungen', async ({ page }) => {
        await logIn(page, 'e2e-browser-sessions@example.com');
        await page.goto('/user/profile');

        await page.getByRole('button', { name: 'Log Out Other Browser Sessions' }).click();

        const passwordDialog = page.locator('#confirm-password-browser-sessions');
        await waitUntilOpened(passwordDialog);
        await passwordDialog.getByPlaceholder('Password').fill('password');
        await passwordDialog.getByRole('button', { name: 'Confirm', exact: true }).click();

        const logoutDialog = page.locator('.jetstream-modal', { hasText: 'Do you really want to log out' });
        await waitUntilOpened(logoutDialog);
        await logoutDialog.getByRole('button', { name: 'Log Out Other Browser Sessions' }).click();

        await expect(page.getByText('Done.', { exact: true })).toBeVisible();
    });
});
