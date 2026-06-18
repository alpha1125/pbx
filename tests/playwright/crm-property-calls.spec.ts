import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'https://pbx.firstfire.ca';
const email = process.env.PLAYWRIGHT_EMAIL ?? 'demo@firstfire.example';
const password = process.env.PLAYWRIGHT_PASSWORD ?? 'demo1234';

test('property page exposes browser and bridge call actions', async ({ page }) => {
  await page.goto(`${baseURL}/login`);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await page.goto(`${baseURL}/crm/properties/1`);

  await expect(page.getByText('Browser Softphone')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Browser Call' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Bridge Call' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Place Browser Call' })).toBeVisible();
  await expect(page.getByText('Call state:')).toBeVisible();
  await expect(page.getByText('Idle')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mute' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Keypad' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Start Recording' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Hang Up' })).toBeDisabled();
});

test('browser softphone dials the approved number and reports call states', async ({ page }) => {
  await page.addInitScript(() => {
    (window as Window & { __telnyxNewCallCount: number }).__telnyxNewCallCount = 0;
  });
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'mediaDevices', {
      value: {
        getUserMedia: async () => ({
          getTracks: () => [],
          getAudioTracks: () => [],
        }),
      },
      configurable: true,
    });

    window.EventSource = class {
      constructor() {}
      addEventListener() {}
      close() {}
    };

    window.TelnyxRTC = class {
      handlers = {};
      callHandlers = {};

      constructor(config) {
        this.config = config;
      }

      on(event, callback) {
        this.handlers[event] = callback;
      }

      connect() {
        setTimeout(() => this.handlers['telnyx.ready']?.(), 0);
      }

      newCall(options) {
        (window as Window & { __telnyxNewCallCount: number }).__telnyxNewCallCount += 1;
        const call = {
          id: 'browser-call-1',
          state: 'requesting',
          direction: 'outbound',
          remotePartyNumber: options.destinationNumber,
          handlers: {},
          on(event, callback) {
            this.handlers[event] = callback;
          },
        };

        setTimeout(() => call.handlers['telnyx.notification']?.({ type: 'callUpdate', call }), 0);
        setTimeout(() => {
          call.state = 'ringing';
          call.handlers['telnyx.notification']?.({ type: 'callUpdate', call });
        }, 20);
        setTimeout(() => {
          call.state = 'active';
          call.handlers['telnyx.notification']?.({ type: 'callUpdate', call });
        }, 40);
        setTimeout(() => {
          call.state = 'hangup';
          call.handlers['telnyx.notification']?.({ type: 'callUpdate', call });
        }, 60);

        return call;
      }

      disconnect() {}
    };
  });

  await page.route('**/crm/properties/1/contacts/**/browser-call/prepare', async (route) => {
    await route.fulfill({
        json: {
          ok: true,
          providerSessionId: 'provider-session-1',
          token: 'header.eyJleHAiOjE4OTM0NTYwMDB9.sig',
          tokenExpiresAt: '2030-01-01T00:00:00+00:00',
          statusStreamUrl: '/api/calls/provider-session-1/events/stream',
          approvedDestinationNumber: '+14165550123',
          callSession: {
            providerSessionId: 'provider-session-1',
            callState: 'initiated',
          },
        },
    });
  });
  await page.route('**/api/calls/provider-session-1/browser-session', async (route) => {
    await route.fulfill({
      json: {
        ok: true,
        browserSoftphoneSessionId: 1,
        browserSessionToken: 'browser-session-token-1',
      },
    });
  });
  await page.route('**/api/browser-softphone-sessions/browser-session-token-1/events', async (route) => {
    const body = route.request().postDataJSON?.() ?? {};
    await route.fulfill({
      json: {
        ok: true,
        status: 'active',
        connectionState: body.event?.startsWith('sdk_') ? 'sdk_ready' : 'sdk_ready',
        callState: body.event === 'call.hangup' ? 'ended' : body.event === 'call.active' ? 'connected' : body.event === 'call.ringing' ? 'ringing' : body.event === 'call.requesting' ? 'dialing' : 'failed',
      },
    });
  });

  await page.goto(`${baseURL}/login`);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await page.goto(`${baseURL}/crm/properties/1`);
  await expect.poll(async () => page.evaluate(() => (window as Window & { __telnyxNewCallCount: number }).__telnyxNewCallCount)).toBe(0);
  await page.getByRole('button', { name: 'Place Browser Call' }).click();
  await expect.poll(async () => page.evaluate(() => (window as Window & { __telnyxNewCallCount: number }).__telnyxNewCallCount)).toBe(1);

  await expect(page.getByText('Browser softphone connected. Placing approved call...')).toBeVisible({ timeout: 60000 });
  await expect(page.getByText('Connected to Telnyx.')).toBeVisible();
  await expect(page.getByText('Dialing +14165550123...')).toBeVisible();
  await expect(page.getByText('Call state:')).toBeVisible();
  await expect(page.getByText('Connected')).toBeVisible();
  await expect(page.getByText('Ended')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Start Recording' })).toBeDisabled();
});
