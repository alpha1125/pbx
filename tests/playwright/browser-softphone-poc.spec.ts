import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'https://pbx.firstfire.ca';

async function installBrowserSoftphoneMocks(page) {
  await page.addInitScript(() => {
    const audioTrack = {
      enabled: true,
      stop() {},
    };

    (window as Window & { __browserSoftphoneAudioTrack?: { enabled: boolean } }).__browserSoftphoneAudioTrack = audioTrack;

    const deviceState = {
      devices: [
        { kind: 'audioinput', deviceId: 'mic-1', label: 'Microphone 1', groupId: 'group-1' },
        { kind: 'audiooutput', deviceId: 'speaker-1', label: 'Speaker 1', groupId: 'group-1' },
      ],
      listeners: new Set<() => void>(),
    };

    (window as Window & {
      __browserSoftphoneSetDevices?: (devices: Array<{ kind: string; deviceId: string; label: string; groupId: string }>) => void;
      __browserSoftphoneDispatchDeviceChange?: () => void;
    }).__browserSoftphoneSetDevices = (devices) => {
      deviceState.devices = devices;
    };

    (window as Window & {
      __browserSoftphoneDispatchDeviceChange?: () => void;
    }).__browserSoftphoneDispatchDeviceChange = () => {
      deviceState.listeners.forEach((listener) => listener());
    };

    Object.defineProperty(navigator, 'mediaDevices', {
      value: {
        async getUserMedia() {
          return {
            getTracks: () => [audioTrack],
            getAudioTracks: () => [audioTrack],
          };
        },
        async enumerateDevices() {
          return deviceState.devices;
        },
        addEventListener(type: string, listener: () => void) {
          if ('devicechange' === type) {
            deviceState.listeners.add(listener);
          }
        },
        removeEventListener(type: string, listener: () => void) {
          if ('devicechange' === type) {
            deviceState.listeners.delete(listener);
          }
        },
      },
      configurable: true,
    });

    class FakeAudioContext {
      currentTime = 0;
      destination = {};

      resume = async () => {};
      close = async () => {};

      createGain() {
        return {
          gain: {
            value: 0,
            setValueAtTime() {},
            cancelScheduledValues() {},
            exponentialRampToValueAtTime() {},
          },
          connect() {},
        };
      }

      createOscillator() {
        return {
          type: 'sine',
          frequency: {
            setValueAtTime() {},
          },
          connect() {},
          start() {},
          stop() {},
          disconnect() {},
        };
      }
    }

    (window as Window & { AudioContext?: typeof FakeAudioContext }).AudioContext = FakeAudioContext as unknown as typeof AudioContext;
    (window as Window & { webkitAudioContext?: typeof FakeAudioContext }).webkitAudioContext = FakeAudioContext as unknown as typeof AudioContext;

    Object.defineProperty(HTMLMediaElement.prototype, 'setSinkId', {
      value: async () => undefined,
      configurable: true,
    });

    (window as Window & { EventSource?: typeof EventSource }).EventSource = class {
      url: string;
      listeners: Record<string, (...args: unknown[]) => void> = {};
      closed = false;

      constructor(url: string) {
        this.url = url;
        (window as Window & {
          __browserSoftphoneTranscriptSources?: Array<{ url: string; emit: (type: string, payload: unknown) => void }>;
        }).__browserSoftphoneTranscriptSources ??= [];
        (window as Window & {
          __browserSoftphoneTranscriptSources?: Array<{ url: string; emit: (type: string, payload: unknown) => void }>;
        }).__browserSoftphoneTranscriptSources?.push(this);
      }

      addEventListener(type: string, callback: (...args: unknown[]) => void) {
        this.listeners[type] = callback;
      }

      emit(type: string, payload: unknown) {
        if (this.closed) {
          return;
        }

        const event = {
          data: JSON.stringify(payload),
        };

        this.listeners[type]?.(event);
        if ('message' !== type) {
          this.listeners.message?.(event);
        }
      }

      close() {
        this.closed = true;
      }
    } as unknown as typeof EventSource;

    (window as Window & {
      __browserSoftphoneEmitTranscript?: (payload: unknown, type?: string) => void;
      __browserSoftphoneTranscriptSources?: Array<{ url: string; emit: (type: string, payload: unknown) => void }>;
    }).__browserSoftphoneEmitTranscript = (payload, type = 'transcript.segment') => {
      (window as Window & {
        __browserSoftphoneTranscriptSources?: Array<{ url: string; emit: (type: string, payload: unknown) => void }>;
      }).__browserSoftphoneTranscriptSources?.forEach((source) => source.emit(type, payload));
    };

    class FakeTelnyxRTC {
      handlers: Record<string, (...args: unknown[]) => void> = {};

      constructor() {}

      on(event: string, callback: (...args: unknown[]) => void) {
        this.handlers[event] = callback;
        return this;
      }

      connect() {
        setTimeout(() => {
          this.handlers['telnyx.ready']?.();
        }, 0);
      }

      newCall(options: { destinationNumber: string }) {
        const call = {
          id: 'browser-softphone-call-1',
          state: 'active',
          direction: 'outbound',
          destinationNumber: options.destinationNumber,
          localStream: (window as Window & { __browserSoftphoneAudioTrack?: { enabled: boolean } }).__browserSoftphoneAudioTrack
            ? {
                getAudioTracks: () => [(window as Window & { __browserSoftphoneAudioTrack?: { enabled: boolean } }).__browserSoftphoneAudioTrack!],
              }
            : null,
          remoteStream: null,
          hangup() {},
          dtmf() {},
        };

        setTimeout(() => {
          this.handlers['telnyx.notification']?.({ type: 'callUpdate', call });
        }, 0);

        return call;
      }

      disconnect() {}
    }

    (window as Window & { TelnyxRTC?: typeof FakeTelnyxRTC }).TelnyxRTC = FakeTelnyxRTC as unknown as typeof TelnyxRTC;
  });

  await page.route('**/poc/browser-softphone/token', async (route) => {
    await route.fulfill({
      json: {
        ok: true,
        token: 'header.payload.signature',
        destinationNumber: '+15557654321',
        callerNumber: '+15551231234',
        callSessionId: 'poc-call-1',
        transcriptTopic: '/poc/browser-softphone/poc-call-1/transcript',
        transcriptStreamUrl: '/api/poc/browser-softphone/poc-call-1/transcript/stream',
      },
    });
  });
}

async function openSoftphone(page) {
  await page.goto(`${baseURL}/poc/browser-softphone`);
  await expect(page.getByRole('button', { name: 'Browser Call' })).toBeVisible();
}

async function openSoftphoneSettings(page) {
  await openSoftphone(page);
  await page.getByRole('button', { name: 'Settings ⚙' }).click();
  await expect(page.getByLabel('Microphone')).toBeVisible();
}

async function readSoftphonePreferences(page) {
  return await page.evaluate(() => {
    const raw = window.localStorage.getItem('pbx.softphone.preferences');
    return raw ? JSON.parse(raw) : null;
  });
}

test('browser softphone mute toggles local state without backend calls', async ({ page }) => {
  await installBrowserSoftphoneMocks(page);
  await openSoftphone(page);

  await page.getByRole('button', { name: 'Browser Call' }).click();

  await expect(page.getByRole('button', { name: 'Mute' })).toBeVisible();
  await expect(page.getByText('Live 🟢')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Hangup' })).toBeVisible();

  await page.getByRole('button', { name: 'Mute' }).click();
  await expect(page.getByRole('button', { name: 'Unmute' })).toBeVisible();
  await expect(page.getByText('Muted 🔴')).toBeVisible();
  await expect.poll(async () => page.evaluate(() => (window as Window & { __browserSoftphoneAudioTrack?: { enabled: boolean } }).__browserSoftphoneAudioTrack?.enabled)).toBe(false);

  await page.getByRole('button', { name: 'Unmute' }).click();
  await expect(page.getByRole('button', { name: 'Mute' })).toBeVisible();
  await expect(page.getByText('Live 🟢')).toBeVisible();
  await expect.poll(async () => page.evaluate(() => (window as Window & { __browserSoftphoneAudioTrack?: { enabled: boolean } }).__browserSoftphoneAudioTrack?.enabled)).toBe(true);
});

test('browser softphone desktop settings panel opens collapsed content', async ({ page }) => {
  await installBrowserSoftphoneMocks(page);
  await openSoftphone(page);

  await expect(page.getByRole('button', { name: 'Refresh Devices' })).toHaveCount(0);

  await page.getByRole('button', { name: 'Settings ⚙' }).click();

  await expect(page.getByRole('button', { name: 'Refresh Devices' })).toBeVisible();
  await expect(page.getByText('Audio Processing')).toBeVisible();
  await expect(page.getByText('Diagnostics')).toBeVisible();
});

test('browser softphone mobile settings panel opens as a bottom sheet', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await installBrowserSoftphoneMocks(page);
  await openSoftphone(page);

  await page.getByRole('button', { name: 'Settings ⚙' }).click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Refresh Devices' })).toBeVisible();
  await expect(dialog.getByText('Audio Devices')).toBeVisible();
  await expect(dialog.getByText('Audio Processing')).toBeVisible();
  await expect(dialog.getByText('Diagnostics')).toBeVisible();
});

test('browser softphone persists audio device preferences across reloads', async ({ page }) => {
  await installBrowserSoftphoneMocks(page);
  await openSoftphoneSettings(page);

  await page.getByLabel('Microphone').selectOption('mic-1');
  await page.getByLabel('Speaker').selectOption('speaker-1');
  await page.getByLabel('Diagnostics enabled').uncheck();

  await expect.poll(async () => readSoftphonePreferences(page)).toMatchObject({
    selectedMicrophone: 'mic-1',
    selectedSpeaker: 'speaker-1',
    diagnostics: { enabled: false },
    audio: {
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: false,
    },
  });

  await page.reload();
  await expect(page.getByRole('button', { name: 'Browser Call' })).toBeVisible();
  await page.getByRole('button', { name: 'Settings ⚙' }).click();

  await expect(page.getByLabel('Microphone')).toHaveValue('mic-1');
  await expect(page.getByLabel('Speaker')).toHaveValue('speaker-1');
  await expect(page.getByLabel('Diagnostics enabled')).not.toBeChecked();
});

test('browser softphone refreshes device choices when the media device list changes', async ({ page }) => {
  await installBrowserSoftphoneMocks(page);
  await openSoftphoneSettings(page);

  await expect(page.getByLabel('Microphone')).toHaveValue('');

  await page.evaluate(() => {
    (window as Window & {
      __browserSoftphoneSetDevices?: (devices: Array<{ kind: string; deviceId: string; label: string; groupId: string }>) => void;
      __browserSoftphoneDispatchDeviceChange?: () => void;
    }).__browserSoftphoneSetDevices?.([
      { kind: 'audioinput', deviceId: 'mic-2', label: 'Microphone 2', groupId: 'group-2' },
      { kind: 'audiooutput', deviceId: 'speaker-2', label: 'Speaker 2', groupId: 'group-2' },
    ]);
    (window as Window & {
      __browserSoftphoneDispatchDeviceChange?: () => void;
    }).__browserSoftphoneDispatchDeviceChange?.();
  });

  await expect(page.getByLabel('Microphone')).toHaveValue('mic-2');
  await expect(page.getByLabel('Speaker')).toHaveValue('speaker-2');
  await expect(page.getByText('Audio devices loaded.')).toBeVisible();
});

test('browser softphone appends transcript segments from the live stream once', async ({ page }) => {
  await installBrowserSoftphoneMocks(page);
  await openSoftphone(page);

  await page.getByRole('button', { name: 'Browser Call' }).click();

  await expect(page.getByText('/poc/browser-softphone/poc-call-1/transcript')).toBeVisible();
  await expect(page.locator('[data-transcript-scroll-region]')).toBeVisible();

  await page.evaluate(() => {
    const emitTranscript = (payload: unknown) => {
      (window as Window & {
        __browserSoftphoneEmitTranscript?: (payload: unknown, type?: string) => void;
      }).__browserSoftphoneEmitTranscript?.(payload);
    };

    for (let index = 1; index <= 18; index += 1) {
      emitTranscript({
        topic: '/poc/browser-softphone/poc-call-1/transcript',
        callSessionId: 'poc-call-1',
        segment: {
          id: index,
          sequence: index,
          speaker: index % 2 === 0 ? 'csr' : 'customer',
          text: `Transcript line ${index}`,
          occurredAt: `2026-06-18T12:${String(index).padStart(2, '0')}:00Z`,
          displayTime: `12:${String(index).padStart(2, '0')} PM`,
          isFinal: true,
          sourceEventId: `evt-${index}`,
        },
      });
    }
  });

  const emitTranscript = (payload: unknown) => page.evaluate((value) => {
    (window as Window & {
      __browserSoftphoneEmitTranscript?: (payload: unknown, type?: string) => void;
    }).__browserSoftphoneEmitTranscript?.(value);
  }, payload);

  await emitTranscript({
    topic: '/poc/browser-softphone/poc-call-1/transcript',
    callSessionId: 'poc-call-1',
    segment: {
      id: 19,
      sequence: 19,
      speaker: 'customer',
      text: 'Hello from the transcript stre',
      occurredAt: '2026-06-18T12:19:00Z',
      displayTime: '12:19 PM',
      isFinal: false,
      sourceEventId: 'evt-19',
    },
  });

  const interimBubble = page.locator('[data-transcript-merge-key="evt-19"]');
  await expect(interimBubble).toHaveCount(1);
  await expect(interimBubble).toHaveAttribute('data-transcript-final', 'false');
  await expect(interimBubble).toHaveAttribute('data-transcript-side', 'left');
  await expect(interimBubble).toHaveAttribute('data-bubble-tone', 'secondary');
  await expect(interimBubble.getByText('Hello from the transcript stre')).toBeVisible();
  await expect(interimBubble.getByText('Typing...')).toBeVisible();
  await expect(interimBubble.getByText('Customer')).toBeVisible();
  await expect(interimBubble.getByText('12:19 PM')).toBeVisible();
  await expect(interimBubble.locator('[data-transcript-bubble="true"]')).toHaveCSS('font-style', 'italic');

  await emitTranscript({
    topic: '/poc/browser-softphone/poc-call-1/transcript',
    callSessionId: 'poc-call-1',
    segment: {
      id: 19,
      sequence: 19,
      speaker: 'customer',
      text: 'Hello from the transcript stream',
      occurredAt: '2026-06-18T12:19:00Z',
      displayTime: '12:19 PM',
      isFinal: true,
      sourceEventId: 'evt-19',
    },
  });

  const customerBubble = page.locator('[data-transcript-merge-key="evt-19"]');
  await expect(customerBubble).toHaveCount(1);
  await expect(customerBubble).toHaveAttribute('data-transcript-final', 'true');
  await expect(customerBubble).toHaveAttribute('data-transcript-side', 'left');
  await expect(customerBubble).toHaveAttribute('data-bubble-tone', 'secondary');
  await expect(customerBubble.getByText('Hello from the transcript stream')).toBeVisible();
  await expect(customerBubble.getByText('Customer')).toBeVisible();
  await expect(customerBubble.getByText('12:19 PM')).toBeVisible();
  await expect(customerBubble.getByText('Typing...')).toHaveCount(0);
  await expect(customerBubble.locator('[data-transcript-bubble="true"]')).toHaveCSS('font-style', 'normal');

  const csrBubble = page.locator('[data-transcript-segment-id="2"]');
  await expect(csrBubble).toHaveAttribute('data-transcript-side', 'right');
  await expect(csrBubble).toHaveAttribute('data-bubble-tone', 'primary');
  await expect(csrBubble.getByText('Transcript line 2')).toBeVisible();
  await expect(csrBubble.getByText('CSR')).toBeVisible();

  await expect.poll(async () => page.evaluate(() => {
    const element = document.querySelector('[data-transcript-scroll-region]');
    if (!(element instanceof HTMLElement)) {
      return false;
    }

    return element.scrollHeight > element.clientHeight && element.scrollTop > 0;
  })).toBe(true);
});

test('browser softphone shows a post call summary placeholder after hangup', async ({ page }) => {
  await installBrowserSoftphoneMocks(page);
  await openSoftphone(page);

  await page.getByRole('button', { name: 'Browser Call' }).click();

  await expect(page.getByRole('button', { name: 'Hangup' })).toBeVisible();
  await expect(page.locator('[data-post-call-summary]')).toHaveCount(0);

  await page.getByRole('button', { name: 'Hangup' }).click();

  const summaryPanel = page.locator('[data-post-call-summary]');
  await expect(summaryPanel).toHaveCount(1);
  await expect(summaryPanel.getByText('Call Summary')).toBeVisible();
  await expect(summaryPanel.getByText('Customer concerns')).toBeVisible();
  await expect(summaryPanel.getByText('Action items')).toBeVisible();
  await expect(summaryPanel.getByText('Keywords')).toBeVisible();
  await expect(summaryPanel.getByText('The customer reported an intermittent service issue')).toBeVisible();
  await expect(summaryPanel.getByText('Intermittent call quality and dropped audio')).toBeVisible();
  await expect(summaryPanel.getByText('Verify service status with the customer')).toBeVisible();
  await expect(summaryPanel.getByText('service quality')).toBeVisible();
});
