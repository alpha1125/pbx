import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'https://pbx.firstfire.ca';
const destinationNumber = process.env.PLAYWRIGHT_LIVE_DESTINATION ?? '+14168880123';
const liveWaitMs = Number(process.env.PLAYWRIGHT_LIVE_WAIT_MS ?? '45000');

test.skip(
    !process.env.PLAYWRIGHT_LIVE_BROWSER_SOFTPHONE,
    'Set PLAYWRIGHT_LIVE_BROWSER_SOFTPHONE=1 to run the live browser softphone repro.',
);

test('live browser softphone call keeps the transcript pane visible while the call runs', async ({ page, context }) => {
    test.setTimeout(Math.max(liveWaitMs + 60000, 120000));

    await context.grantPermissions(['microphone'], { origin: baseURL });

    const pageErrors: string[] = [];

    page.on('console', async (message) => {
        const text = message.text();
        if (
            text.includes('[browser-softphone')
            || text.includes('[browser-softphone-react]')
            || text.includes('ReferenceError')
            || text.includes('Uncaught')
        ) {
            console.log(`[console:${message.type()}] ${text}`);
        }
    });

    page.on('pageerror', (error) => {
        pageErrors.push(error.message);
        console.log(`[pageerror] ${error.message}`);
    });

    page.on('request', (request) => {
        const url = request.url();
        if (
            url.includes('/poc/browser-softphone/token')
            || url.includes('/api/poc/browser-softphone/')
        ) {
            console.log(`[request] ${request.method()} ${url}`);
        }
    });

    page.on('response', async (response) => {
        const url = response.url();
        if (
            url.includes('/poc/browser-softphone/token')
            || url.includes('/api/poc/browser-softphone/')
        ) {
            const status = response.status();
            if (url.includes('/transcript/stream')) {
                console.log(`[response] ${status} ${url}`);
                return;
            }

            let bodyText = '';
            try {
                bodyText = await response.text();
            } catch {
                bodyText = '';
            }

            console.log(`[response] ${status} ${url} ${bodyText}`.trimEnd());
        }
    });

    await page.goto(`${baseURL}/poc/browser-softphone`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('button', { name: 'Browser Call' })).toBeVisible();
    await expect(page.locator('[data-transcript-panel]')).toBeVisible();
    await expect(page.locator('[data-transcript-panel-badge]')).toHaveText('Waiting');
    await expect(page.locator('[data-transcript-panel-message]')).toContainText(
        'This pane stays visible so you can see transcripts as soon as the call starts.',
    );

    await page.locator('input[type="tel"]').fill(destinationNumber);
    await page.getByRole('button', { name: 'Browser Call' }).click();

    await expect(page.locator('[data-transcript-panel]')).toBeVisible();
    await expect(page.locator('[data-transcript-panel-badge]')).toHaveText('Listening');
    await expect(page.locator('[data-transcript-panel-message]')).toContainText(
        'Transcript segments will appear here as the call is transcribed.',
    );

    const transcriptSegments = page.locator('[data-transcript-segment-id], [data-transcript-merge-key]');
    const start = Date.now();
    let lastCount = -1;

    while (Date.now() - start < liveWaitMs) {
        const count = await transcriptSegments.count();
        if (count !== lastCount) {
            console.log(`[transcripts] segment count: ${count}`);
            lastCount = count;
        }

        if (count > 0) {
            const firstText = await transcriptSegments.first().textContent().catch(() => '');
            console.log(`[transcripts] first segment text: ${firstText ?? ''}`);
            break;
        }

        await page.waitForTimeout(2000);
    }

    const finalCount = await transcriptSegments.count();
    console.log(`[result] transcript segments seen: ${finalCount}`);

    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
});
