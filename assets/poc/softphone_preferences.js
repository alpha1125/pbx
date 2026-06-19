const STORAGE_KEY = 'pbx.softphone.preferences';

const DEFAULT_PREFERENCES = Object.freeze({
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

function isObject(value) {
    return null !== value && 'object' === typeof value && !Array.isArray(value);
}

function toBoolean(value, fallback) {
    return 'boolean' === typeof value ? value : fallback;
}

function toStringValue(value, fallback = '') {
    if (null === value) {
        return null;
    }

    return 'string' === typeof value ? value : fallback;
}

function toNullableStringValue(value, fallback = null) {
    if (null === value) {
        return null;
    }

    return 'string' === typeof value ? value : fallback;
}

function toNumberValue(value, fallback = 1) {
    return 'number' === typeof value && Number.isFinite(value) ? value : fallback;
}

function normalizePreferences(preferences) {
    const source = isObject(preferences) ? preferences : {};
    const audio = isObject(source.audio) ? source.audio : {};
    const diagnostics = isObject(source.diagnostics) ? source.diagnostics : {};

    return {
        ...DEFAULT_PREFERENCES,
        ...source,
        selectedMicrophone: toNullableStringValue(source.selectedMicrophone, DEFAULT_PREFERENCES.selectedMicrophone),
        selectedSpeaker: toNullableStringValue(source.selectedSpeaker, DEFAULT_PREFERENCES.selectedSpeaker),
        speakerVolume: Math.min(1, Math.max(0, toNumberValue(source.speakerVolume, DEFAULT_PREFERENCES.speakerVolume))),
        audio: {
            ...DEFAULT_PREFERENCES.audio,
            echoCancellation: toBoolean(audio.echoCancellation, DEFAULT_PREFERENCES.audio.echoCancellation),
            noiseSuppression: toBoolean(audio.noiseSuppression, DEFAULT_PREFERENCES.audio.noiseSuppression),
            autoGainControl: toBoolean(audio.autoGainControl, DEFAULT_PREFERENCES.audio.autoGainControl),
        },
        diagnostics: {
            ...DEFAULT_PREFERENCES.diagnostics,
            enabled: toBoolean(diagnostics.enabled, DEFAULT_PREFERENCES.diagnostics.enabled),
        },
    };
}

function readStorage() {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch (error) {
        return null;
    }
}

function writeStorage(serializedPreferences) {
    try {
        window.localStorage.setItem(STORAGE_KEY, serializedPreferences);
    } catch (error) {
        // Ignore storage failures in restricted browser contexts.
    }
}

export const SoftphonePreferences = {
    load() {
        try {
            const raw = readStorage();
            if (!raw) {
                return normalizePreferences({});
            }

            return normalizePreferences(JSON.parse(raw));
        } catch (error) {
            return normalizePreferences({});
        }
    },

    save(preferences) {
        const normalized = normalizePreferences(preferences);
        writeStorage(JSON.stringify(normalized));
        return normalized;
    },

    getMicrophone(preferences = null) {
        return normalizePreferences(preferences).selectedMicrophone;
    },

    setMicrophone(deviceId, preferences = null) {
        const normalized = normalizePreferences(preferences);
        normalized.selectedMicrophone = toNullableStringValue(deviceId, null);
        return this.save(normalized);
    },

    getSpeaker(preferences = null) {
        return normalizePreferences(preferences).selectedSpeaker;
    },

    setSpeaker(deviceId, preferences = null) {
        const normalized = normalizePreferences(preferences);
        normalized.selectedSpeaker = toNullableStringValue(deviceId, null);
        return this.save(normalized);
    },

    getAudioSettings(preferences = null) {
        return normalizePreferences(preferences).audio;
    },

    setAudioSettings(settings, preferences = null) {
        const normalized = normalizePreferences(preferences);
        const nextSettings = isObject(settings) ? settings : {};
        normalized.audio = {
            ...normalized.audio,
            echoCancellation: toBoolean(nextSettings.echoCancellation, normalized.audio.echoCancellation),
            noiseSuppression: toBoolean(nextSettings.noiseSuppression, normalized.audio.noiseSuppression),
            autoGainControl: toBoolean(nextSettings.autoGainControl, normalized.audio.autoGainControl),
        };
        return this.save(normalized);
    },

    getDiagnosticsEnabled(preferences = null) {
        return normalizePreferences(preferences).diagnostics.enabled;
    },

    setDiagnosticsEnabled(enabled, preferences = null) {
        const normalized = normalizePreferences(preferences);
        normalized.diagnostics.enabled = toBoolean(enabled, normalized.diagnostics.enabled);
        return this.save(normalized);
    },

    getSpeakerVolume(preferences = null) {
        return normalizePreferences(preferences).speakerVolume;
    },

    setSpeakerVolume(volume, preferences = null) {
        const normalized = normalizePreferences(preferences);
        normalized.speakerVolume = Math.min(1, Math.max(0, toNumberValue(volume, normalized.speakerVolume)));
        return this.save(normalized);
    },
};

export default SoftphonePreferences;
