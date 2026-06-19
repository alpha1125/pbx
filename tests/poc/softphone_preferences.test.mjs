import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';

function createWindow(initialState = {}) {
    const storage = new Map(Object.entries(initialState));

    return {
        localStorage: {
            getItem(key) {
                return storage.has(key) ? storage.get(key) : null;
            },
            setItem(key, value) {
                storage.set(key, String(value));
            },
            removeItem(key) {
                storage.delete(key);
            },
        },
        __storage: storage,
    };
}

globalThis.window = createWindow();

const { default: SoftphonePreferences } = await import('../../assets/poc/softphone_preferences.js');

beforeEach(() => {
    globalThis.window = createWindow();
});

test('loads the default softphone preferences', () => {
    assert.deepEqual(SoftphonePreferences.load(), {
        selectedMicrophone: null,
        selectedSpeaker: null,
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: false,
        },
        diagnostics: {
            enabled: true,
        },
        speakerVolume: 1,
    });
});

test('saves and reloads the preference payload', () => {
    const saved = SoftphonePreferences.save({
        selectedMicrophone: 'mic-1',
        selectedSpeaker: 'speaker-1',
        audio: {
            echoCancellation: false,
            noiseSuppression: true,
            autoGainControl: true,
        },
        diagnostics: {
            enabled: false,
        },
        speakerVolume: 0.35,
    });

    assert.deepEqual(saved, {
        selectedMicrophone: 'mic-1',
        selectedSpeaker: 'speaker-1',
        audio: {
            echoCancellation: false,
            noiseSuppression: true,
            autoGainControl: true,
        },
        diagnostics: {
            enabled: false,
        },
        speakerVolume: 0.35,
    });
    assert.equal(globalThis.window.localStorage.getItem('pbx.softphone.preferences'), JSON.stringify(saved));
    assert.deepEqual(SoftphonePreferences.load(), saved);
});

test('normalizes invalid preference values back to defaults', () => {
    const saved = SoftphonePreferences.save({
        selectedMicrophone: 123,
        selectedSpeaker: ['speaker-1'],
        audio: {
            echoCancellation: 'nope',
            noiseSuppression: null,
            autoGainControl: 1,
        },
        diagnostics: {
            enabled: 'yes',
        },
        speakerVolume: 12,
    });

    assert.deepEqual(saved, {
        selectedMicrophone: null,
        selectedSpeaker: null,
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: false,
        },
        diagnostics: {
            enabled: true,
        },
        speakerVolume: 1,
    });
});

test('updates selected device and diagnostics flags independently', () => {
    const initial = SoftphonePreferences.save({});
    const microphoneSelected = SoftphonePreferences.setMicrophone('mic-2', initial);
    const speakerSelected = SoftphonePreferences.setSpeaker('speaker-2', microphoneSelected);
    const diagnosticsDisabled = SoftphonePreferences.setDiagnosticsEnabled(false, speakerSelected);

    assert.equal(SoftphonePreferences.getMicrophone(diagnosticsDisabled), 'mic-2');
    assert.equal(SoftphonePreferences.getSpeaker(diagnosticsDisabled), 'speaker-2');
    assert.equal(SoftphonePreferences.getDiagnosticsEnabled(diagnosticsDisabled), false);
});
