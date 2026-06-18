import React, { useContext, useEffect, useMemo, useRef, useState } from 'react';
import { TelnyxRTCContext, TelnyxRTCProvider, useCallbacks, useNotification } from '@telnyx/react-client';
import SoftphonePreferences from './softphone_preferences.js';

const DTMF_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];
const E164_REGEX = /^\+[1-9]\d{7,19}$/;
const RINGBACK_ON_MS = 2000;
const RINGBACK_OFF_MS = 4000;

function normalizePhoneNumber(value) {
    if ('string' !== typeof value) {
        return null;
    }

    const number = value.replace(/\s+/g, '').trim();
    return E164_REGEX.test(number) ? number : null;
}

function supportsSpeakerSelection() {
    return 'undefined' !== typeof HTMLMediaElement && 'function' === typeof HTMLMediaElement.prototype.setSinkId;
}

function mapAudioDevices(devices) {
    return devices
        .filter((device) => 'audioinput' === device.kind || 'audiooutput' === device.kind)
        .map((device, index) => ({
            deviceId: device.deviceId,
            kind: device.kind,
            label: device.label || `${'audioinput' === device.kind ? 'Microphone' : 'Speaker'} ${index + 1}`,
            groupId: device.groupId || '',
        }));
}

function findDeviceById(devices, deviceId) {
    if (!deviceId) {
        return null;
    }

    return devices.find((device) => device.deviceId === deviceId) ?? null;
}

function stopMediaStream(stream) {
    if (!stream || 'function' !== typeof stream.getTracks) {
        return;
    }

    stream.getTracks().forEach((track) => track.stop());
}

function getRequestedAudioConstraints(microphoneId, audioSettings) {
    const selectedAudioSettings = audioSettings || {};

    return {
        audio: {
            ...(microphoneId ? { deviceId: { exact: microphoneId } } : {}),
            echoCancellation: selectedAudioSettings.echoCancellation,
            noiseSuppression: selectedAudioSettings.noiseSuppression,
            autoGainControl: selectedAudioSettings.autoGainControl,
        },
    };
}

function inspectAppliedAudioSettings(stream, requestedAudioSettings) {
    const audioTrack = stream?.getAudioTracks?.()[0] ?? null;
    const settings = audioTrack?.getSettings?.() ?? {};

    const keys = ['echoCancellation', 'noiseSuppression', 'autoGainControl'];
    const applied = {};
    const warnings = [];

    keys.forEach((key) => {
        const requested = requestedAudioSettings?.[key];
        const reported = Object.prototype.hasOwnProperty.call(settings, key) ? settings[key] : undefined;
        applied[key] = reported ?? null;

        if ('boolean' === typeof requested && 'boolean' === typeof reported) {
            if (requested !== reported) {
                warnings.push(`${key} was requested as ${requested} but the browser reported ${reported}.`);
            }
        } else if ('boolean' === typeof requested && 'undefined' === typeof reported) {
            warnings.push(`${key} was requested but the browser did not report it.`);
        }
    });

    return {
        requested: {
            echoCancellation: requestedAudioSettings?.echoCancellation ?? null,
            noiseSuppression: requestedAudioSettings?.noiseSuppression ?? null,
            autoGainControl: requestedAudioSettings?.autoGainControl ?? null,
        },
        applied,
        warnings,
        trackSettings: settings,
    };
}

function summarizeNotification(notification) {
    if (!notification || 'object' !== typeof notification) {
        return notification;
    }

    const call = notification.call;

    return {
        type: notification.type ?? null,
        eventName: notification.name ?? null,
        message: notification.message ?? null,
        call: call
            ? {
                id: call.id ?? null,
                state: call.state ?? null,
                direction: call.direction ?? null,
                callerNumber: call.callerNumber ?? call.from ?? null,
                destinationNumber: call.destinationNumber ?? call.to ?? null,
                sipCode: call.sipCode ?? null,
                sipReason: call.sipReason ?? null,
                hasRemoteStream: Boolean(call.remoteStream),
                hasLocalStream: Boolean(call.localStream),
            }
            : null,
    };
}

function createRingbackController() {
    let audioContext = null;
    let gainNode = null;
    let timerHandle = null;
    let active = false;

    const stopTone = () => {
        if (timerHandle) {
            window.clearTimeout(timerHandle);
            timerHandle = null;
        }

        if (gainNode) {
            try {
                gainNode.gain.setValueAtTime(0, audioContext?.currentTime ?? 0);
            } catch (error) {
                // Ignore tone cleanup issues.
            }
        }
    };

    const playTone = () => {
        if (!audioContext || !gainNode) {
            return;
        }

        const now = audioContext.currentTime;
        const osc1 = audioContext.createOscillator();
        const osc2 = audioContext.createOscillator();
        osc1.type = 'sine';
        osc2.type = 'sine';
        osc1.frequency.setValueAtTime(440, now);
        osc2.frequency.setValueAtTime(480, now);
        osc1.connect(gainNode);
        osc2.connect(gainNode);

        gainNode.gain.cancelScheduledValues(now);
        gainNode.gain.setValueAtTime(0.0001, now);
        gainNode.gain.exponentialRampToValueAtTime(0.2, now + 0.02);

        osc1.start(now);
        osc2.start(now);
        osc1.stop(now + (RINGBACK_ON_MS / 1000));
        osc2.stop(now + (RINGBACK_ON_MS / 1000));

        osc1.onended = () => {
            try {
                osc1.disconnect();
            } catch (error) {
                // Ignore cleanup errors.
            }
        };
        osc2.onended = () => {
            try {
                osc2.disconnect();
            } catch (error) {
                // Ignore cleanup errors.
            }
        };

        timerHandle = window.setTimeout(() => {
            if (!active) {
                return;
            }

            gainNode.gain.cancelScheduledValues(audioContext.currentTime);
            gainNode.gain.setValueAtTime(0.0001, audioContext.currentTime);
            timerHandle = window.setTimeout(() => {
                if (active) {
                    playTone();
                }
            }, RINGBACK_OFF_MS);
        }, RINGBACK_ON_MS);
    };

    const controller = {
        async prepare() {
            if (!audioContext) {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) {
                    throw new Error('Ringback audio is not supported in this browser.');
                }

                audioContext = new AudioContextClass();
                gainNode = audioContext.createGain();
                gainNode.gain.value = 0.0001;
                gainNode.connect(audioContext.destination);
            }

            if ('suspended' === audioContext.state) {
                await audioContext.resume();
            }
        },
        async start() {
            if (active) {
                return;
            }

            active = true;

            await controller.prepare();

            playTone();
        },
        stop() {
            active = false;
            stopTone();
        },
        async destroy() {
            active = false;
            stopTone();

            if (audioContext) {
                try {
                    await audioContext.close();
                } catch (error) {
                    // Ignore close errors on teardown.
                }
            }

            audioContext = null;
            gainNode = null;
        },
    };

    return controller;
}

function joinLogLine(message, data = null) {
    if (null === data) {
        return message;
    }

    try {
        return `${message} ${JSON.stringify(data, null, 2)}`;
    } catch (error) {
        try {
            return `${message} ${JSON.stringify(summarizeNotification(data), null, 2)}`;
        } catch (secondaryError) {
            return `${message} [unserializable payload]`;
        }
    }
}

function BrowserSoftphoneSession({
    dialRequest,
    onSessionEnd,
    onLog,
    onStatusChange,
    onConnectionStateChange,
    ringbackController,
    localStream,
    selectedSpeakerId,
    speakerRoutingSupported,
    speakerVolume,
    onAudioWarning = () => {},
}) {
    const client = useContext(TelnyxRTCContext);
    const notification = useNotification();
    const [activeCall, setActiveCall] = useState(null);
    const dialedRef = useRef(false);
    const ringbackRef = useRef(ringbackController ?? null);
    const remoteAudioRef = useRef(null);

    useEffect(() => {
        ringbackRef.current = ringbackController ?? ringbackRef.current ?? createRingbackController();
    }, [ringbackController]);

    useEffect(() => {
        const audio = remoteAudioRef.current;
        if (!audio) {
            return;
        }

        audio.volume = Math.max(0, Math.min(1, Number(speakerVolume) || 0));
        if (!activeCall?.remoteStream) {
            audio.pause();
            audio.srcObject = null;
            return;
        }

        audio.srcObject = activeCall.remoteStream;
        if (speakerRoutingSupported && selectedSpeakerId && 'function' === typeof audio.setSinkId) {
            audio.setSinkId(selectedSpeakerId).catch((error) => {
                const message = error instanceof Error ? error.message : 'Speaker routing failed.';
                onAudioWarning(message);
                onLog('Speaker routing failed.', { message, selectedSpeakerId });
            });
        }

        audio.play().catch((error) => {
            const message = error instanceof Error ? error.message : 'Unable to play remote audio.';
            onAudioWarning(message);
            onLog('Remote audio playback failed.', { message });
        });
    }, [activeCall?.remoteStream, onAudioWarning, onLog, selectedSpeakerId, speakerRoutingSupported, speakerVolume]);

    useCallbacks({
        onReady: () => {
            onConnectionStateChange('Ready');
            onLog('telnyx.ready received.');

            if (!client || dialedRef.current || !dialRequest) {
                return;
            }

            dialedRef.current = true;
            onStatusChange(`Calling ${dialRequest.destinationNumber}...`);
            onConnectionStateChange('Dialing');

            void (async () => {
                try {
                    const call = client.newCall({
                        destinationNumber: dialRequest.destinationNumber,
                        callerNumber: dialRequest.callerNumber,
                        audio: true,
                        video: false,
                        localStream: localStream ?? undefined,
                    });

                    setActiveCall(call);
                    onLog('Outbound call created.', {
                        destinationNumber: dialRequest.destinationNumber,
                        callerNumber: dialRequest.callerNumber,
                        hasLocalStream: Boolean(localStream),
                    });
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'Unable to start outbound call.';
                    onStatusChange(message);
                    onConnectionStateChange('Call failed');
                    onLog('newCall failed.', { message });
                    onSessionEnd();
                }
            })();
        },
        onError: (error) => {
            const message = error instanceof Error ? error.message : 'Telnyx client error.';
            onStatusChange(message);
            onConnectionStateChange('Connection failed');
            onLog('telnyx.error', { message });
        },
        onSocketError: (error) => {
            const message = error instanceof Error ? error.message : 'Telnyx socket error.';
            onStatusChange(message);
            onConnectionStateChange('Connection failed');
            onLog('telnyx.socket.error', { message });
        },
        onSocketClose: () => {
            onLog('telnyx.socket.close');
        },
        onNotification: (message) => {
            onLog('telnyx.notification', summarizeNotification(message));
        },
    });

    useEffect(() => {
        if (!notification) {
            return;
        }

        if (notification.call) {
            setActiveCall(notification.call);
        }

        if ('callUpdate' !== notification.type || !notification.call) {
            return;
        }

        const callState = notification.call.state;
        if ('ringing' === callState) {
            onStatusChange('Ringback in progress...');
            onConnectionStateChange('Ringing');
            ringbackRef.current?.start().catch((error) => {
                const message = error instanceof Error ? error.message : 'Unable to start ringback tone.';
                onLog('Ringback failed.', { message });
            });
        } else if ('active' === callState) {
            onStatusChange('Call connected.');
            onConnectionStateChange('Connected');
            ringbackRef.current?.stop();
        } else if ('destroy' === callState) {
            onStatusChange('Call ended.');
            onConnectionStateChange('Disconnected');
            ringbackRef.current?.stop();
            onSessionEnd();
        }
    }, [localStream, notification, onConnectionStateChange, onLog, onSessionEnd, onStatusChange]);

    useEffect(() => {
        return () => {
            ringbackRef.current?.destroy();
        };
    }, []);

    useEffect(() => {
        return () => {
            stopMediaStream(localStream);
        };
    }, [localStream]);

    useEffect(() => {
        return () => {
            const audio = remoteAudioRef.current;
            if (audio) {
                audio.pause();
                audio.srcObject = null;
            }
        };
    }, []);

    const dialTone = (digit) => {
        if (!activeCall || 'function' !== typeof activeCall.dtmf) {
            return;
        }

        try {
            activeCall.dtmf(digit);
            onLog(`DTMF sent: ${digit}`);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'DTMF failed.';
            onLog('DTMF failed.', { digit, message });
        }
    };

    const hangup = () => {
        if (activeCall && 'function' === typeof activeCall.hangup) {
            try {
                activeCall.hangup();
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Hangup failed.';
                onLog('Hangup failed.', { message });
            }
        }

        ringbackRef.current?.stop();
        onSessionEnd();
    };

    return React.createElement(
        'div',
        { className: 'mt-4 p-3 border rounded-4 bg-body-tertiary' },
        React.createElement(
            'div',
            { className: 'd-flex justify-content-between align-items-center gap-3 mb-3' },
            React.createElement('div', null,
                React.createElement('div', { className: 'small text-secondary' }, 'Connection State'),
                React.createElement('div', { className: 'fw-semibold' }, 'Ready'),
            ),
            React.createElement('button', {
                type: 'button',
                className: 'btn btn-outline-danger',
                disabled: !activeCall,
                onClick: hangup,
            }, 'Hangup'),
        ),
        React.createElement('div', { className: 'mb-3' },
            React.createElement('div', { className: 'small text-secondary mb-1' }, 'Call State'),
            React.createElement('div', { className: 'fw-semibold' }, activeCall?.state ?? 'Idle'),
        ),
        React.createElement('div', { className: 'mb-3' },
            React.createElement('div', { className: 'small text-secondary mb-1' }, 'Dial Pad'),
            React.createElement(
                'div',
                { className: 'd-grid gap-2', style: { gridTemplateColumns: 'repeat(3, 1fr)' } },
                ...DTMF_KEYS.map((digit) => React.createElement(
                    'button',
                    {
                        key: digit,
                        type: 'button',
                        className: 'btn btn-outline-secondary fw-semibold py-3',
                        disabled: !activeCall,
                        onClick: () => dialTone(digit),
                    },
                    digit,
                )),
            ),
        ),
        React.createElement('audio', {
            ref: remoteAudioRef,
            autoPlay: true,
            playsInline: true,
            hidden: true,
        }),
    );
}

export default function BrowserSoftphoneApp({
    tokenUrl,
    defaultDestinationNumber,
    defaultCallerNumber,
}) {
    const [destinationNumber, setDestinationNumber] = useState(defaultDestinationNumber ?? '');
    const [credential, setCredential] = useState(null);
    const [dialRequest, setDialRequest] = useState(null);
    const [status, setStatus] = useState('Ready to request a Telnyx token.');
    const [connectionState, setConnectionState] = useState('Disconnected');
    const [logs, setLogs] = useState([]);
    const [busy, setBusy] = useState(false);
    const [preferences, setPreferences] = useState(() => SoftphonePreferences.load());
    const [microphones, setMicrophones] = useState([]);
    const [speakers, setSpeakers] = useState([]);
    const [audioStatus, setAudioStatus] = useState('Loading audio devices...');
    const [audioDiagnostics, setAudioDiagnostics] = useState(null);
    const [localStream, setLocalStream] = useState(null);
    const ringbackControllerRef = useRef(null);
    const speakerTestAudioRef = useRef(null);
    const logOutputRef = useRef(null);
    const preferencesRef = useRef(preferences);

    if (!ringbackControllerRef.current) {
        ringbackControllerRef.current = createRingbackController();
    }

    const speakerRoutingSupported = supportsSpeakerSelection();

    useEffect(() => {
        preferencesRef.current = preferences;
    }, [preferences]);

    const appendLog = (message, data = null) => {
        setLogs((prev) => [...prev, joinLogLine(message, data)]);
        if ('undefined' !== typeof console) {
            console.debug('[browser-softphone-react]', message, data);
        }
    };

    useEffect(() => {
        const element = logOutputRef.current;
        if (element) {
            element.scrollTop = element.scrollHeight;
        }
    }, [logs]);

    const persistPreferences = (updater) => {
        setPreferences((current) => {
            const nextPreferences = 'function' === typeof updater ? updater(current) : updater;
            const savedPreferences = SoftphonePreferences.save(nextPreferences);
            preferencesRef.current = savedPreferences;
            return savedPreferences;
        });
    };

    const resetSession = () => {
        setCredential(null);
        setDialRequest(null);
        setBusy(false);
        setConnectionState('Disconnected');
        setLocalStream((currentStream) => {
            stopMediaStream(currentStream);
            return null;
        });
        setAudioDiagnostics(null);
        ringbackControllerRef.current?.stop();
    };

    const updateAudioCatalog = async () => {
        if (!navigator.mediaDevices?.enumerateDevices || !navigator.mediaDevices?.getUserMedia) {
            setAudioStatus('Audio device selection is not available in this browser.');
            setMicrophones([]);
            setSpeakers([]);
            return {
                microphones: [],
                speakers: [],
                effectiveMicrophoneId: '',
                effectiveSpeakerId: '',
                preferredMicrophoneId: '',
                preferredSpeakerId: '',
            };
        }

        let permissionStream = null;
        try {
            permissionStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Unable to access the microphone.';
            if (error instanceof DOMException && 'NotAllowedError' === error.name) {
                setAudioStatus('Microphone permission denied. Audio devices cannot be listed until permission is granted.');
            } else {
                setAudioStatus(message);
            }
            appendLog('Audio device permission failed.', { message });
            setMicrophones([]);
            setSpeakers([]);
            throw error;
        } finally {
            if (permissionStream) {
                permissionStream.getTracks().forEach((track) => track.stop());
            }
        }

        const devices = mapAudioDevices(await navigator.mediaDevices.enumerateDevices());
        const inputs = devices.filter((device) => 'audioinput' === device.kind);
        const outputs = devices.filter((device) => 'audiooutput' === device.kind);
        const currentPreferences = preferencesRef.current;
        const preferredMicrophoneId = SoftphonePreferences.getMicrophone(currentPreferences);
        const preferredSpeakerId = SoftphonePreferences.getSpeaker(currentPreferences);
        const effectiveMicrophoneId = findDeviceById(inputs, preferredMicrophoneId)?.deviceId
            ?? inputs[0]?.deviceId
            ?? '';
        const effectiveSpeakerId = speakerRoutingSupported
            ? findDeviceById(outputs, preferredSpeakerId)?.deviceId
                ?? outputs[0]?.deviceId
                ?? ''
            : '';

        setMicrophones(inputs);
        setSpeakers(outputs);

        if (preferredMicrophoneId && preferredMicrophoneId !== effectiveMicrophoneId) {
            setAudioStatus('Selected microphone unavailable. Using the default microphone.');
            appendLog('Selected microphone became unavailable.', {
                previousMicId: preferredMicrophoneId,
                fallbackMicId: effectiveMicrophoneId || null,
            });
        } else if (speakerRoutingSupported && preferredSpeakerId && preferredSpeakerId !== effectiveSpeakerId) {
            setAudioStatus('Selected speaker unavailable. Using the default speaker.');
            appendLog('Selected speaker became unavailable.', {
                previousSpeakerId: preferredSpeakerId,
                fallbackSpeakerId: effectiveSpeakerId || null,
            });
        } else if (!inputs.length) {
            setAudioStatus('No microphones found.');
        } else if (speakerRoutingSupported && !outputs.length) {
            setAudioStatus('No speakers found.');
        } else if (!speakerRoutingSupported) {
            setAudioStatus('Speaker selection is not supported by this browser.');
        } else {
            setAudioStatus('Audio devices loaded.');
        }

        return {
            microphones: inputs,
            speakers: outputs,
            effectiveMicrophoneId,
            effectiveSpeakerId,
            preferredMicrophoneId,
            preferredSpeakerId,
        };
    };

    useEffect(() => {
        let cancelled = false;

        const refreshDevices = async () => {
            try {
                if (cancelled) {
                    return;
                }

                await updateAudioCatalog();
            } catch (error) {
                if (cancelled) {
                    return;
                }

                const message = error instanceof Error ? error.message : 'Unable to refresh audio devices.';
                setAudioStatus(message);
            }
        };

        void refreshDevices();

        const handleDeviceChange = () => {
            void refreshDevices();
        };

        if (navigator.mediaDevices?.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange', handleDeviceChange);
        }

        return () => {
            cancelled = true;
            if (navigator.mediaDevices?.removeEventListener) {
                navigator.mediaDevices.removeEventListener('devicechange', handleDeviceChange);
            }
        };
    }, []);

    const acquireMicrophoneStream = async (audioConstraints) => {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Microphone access is not available in this browser.');
        }

        try {
            return await navigator.mediaDevices.getUserMedia({
                audio: audioConstraints,
            });
        } catch (error) {
            const exactDeviceId = audioConstraints?.deviceId?.exact;
            if (!exactDeviceId) {
                throw error;
            }

            const fallbackStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: audioConstraints.echoCancellation,
                    noiseSuppression: audioConstraints.noiseSuppression,
                    autoGainControl: audioConstraints.autoGainControl,
                },
            });

            appendLog('Selected microphone unavailable. Falling back to the default microphone.', {
                selectedMicId: exactDeviceId,
            });
            setAudioStatus('Selected microphone unavailable. Using the default microphone.');

            return fallbackStream;
        }
    };

    const requestToken = async () => {
        const response = await fetch(tokenUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                destinationNumber,
            }),
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || true !== payload.ok) {
            throw new Error(payload?.error ?? `Token request failed with HTTP ${response.status}.`);
        }

        return payload;
    };

    const startCall = async () => {
        if (busy) {
            return;
        }

        const normalizedDestination = normalizePhoneNumber(destinationNumber);
        if (!normalizedDestination) {
            setStatus('Destination number must be an E.164-style number starting with +.');
            return;
        }

        setBusy(true);
        setStatus('Requesting microphone permission...');
        setAudioStatus('Refreshing audio devices...');
        appendLog('Starting browser softphone session.', { destinationNumber: normalizedDestination });

        try {
            await ringbackControllerRef.current?.prepare();
            const catalog = await updateAudioCatalog();
            if (!catalog.microphones.length) {
                throw new Error('No microphones found.');
            }
            const currentPreferences = preferencesRef.current;
            const requestedAudioConstraints = getRequestedAudioConstraints(
                SoftphonePreferences.getMicrophone(currentPreferences),
                SoftphonePreferences.getAudioSettings(currentPreferences),
            );
            const stream = await acquireMicrophoneStream(requestedAudioConstraints.audio);
            const diagnostics = inspectAppliedAudioSettings(stream, requestedAudioConstraints.audio);
            setLocalStream(stream);
            setAudioDiagnostics(diagnostics);
            if (diagnostics.warnings.length > 0) {
                setAudioStatus(diagnostics.warnings.join(' '));
                appendLog('Audio processing warnings.', { warnings: diagnostics.warnings });
            } else {
                setAudioStatus('Audio processing settings applied.');
            }
            setStatus('Dialing...');
            setConnectionState('Dialing');
            await ringbackControllerRef.current?.start();
            const payload = await requestToken();

            setStatus('Connecting to Telnyx...');
            setConnectionState('Connecting');
            setDialRequest({
                destinationNumber: payload.destinationNumber ?? normalizedDestination,
                callerNumber: payload.callerNumber ?? defaultCallerNumber,
            });
            setCredential({ login_token: payload.token });
            appendLog('Telnyx token received.', { audioDiagnostics: diagnostics });
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Browser call could not be started.';
            setStatus(message);
            setConnectionState('Connection failed');
            appendLog('Browser call failed to start.', { message });
            ringbackControllerRef.current?.stop();
            resetSession();
        } finally {
            setBusy(false);
        }
    };

    const refreshDevices = async () => {
        setAudioStatus('Refreshing audio devices...');
        try {
            await updateAudioCatalog();
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Unable to refresh audio devices.';
            setAudioStatus(message);
            appendLog('Audio device refresh failed.', { message });
        }
    };

    const handleMicChange = (event) => {
        const microphoneId = event.currentTarget.value;
        persistPreferences((currentPreferences) => SoftphonePreferences.setMicrophone(microphoneId, currentPreferences));
        setAudioStatus('Microphone selection saved locally.');
    };

    const handleSpeakerChange = (event) => {
        const speakerId = event.currentTarget.value;
        persistPreferences((currentPreferences) => SoftphonePreferences.setSpeaker(speakerId, currentPreferences));
        setAudioStatus('Speaker selection saved locally.');
    };

    const handleSpeakerVolumeChange = (event) => {
        const value = Number.parseFloat(event.currentTarget.value);
        if (Number.isFinite(value)) {
            persistPreferences((currentPreferences) => SoftphonePreferences.setSpeakerVolume(value, currentPreferences));
        }
    };

    const handleAudioSettingChange = (event) => {
        const { name, checked } = event.currentTarget;
        persistPreferences((currentPreferences) => SoftphonePreferences.setAudioSettings({
            ...SoftphonePreferences.getAudioSettings(currentPreferences),
            [name]: checked,
        }, currentPreferences));
    };

    const playSpeakerTestTone = async () => {
        const audio = speakerTestAudioRef.current;
        if (!audio) {
            setAudioStatus('Speaker test audio element is not available.');
            return;
        }

        if (!window.AudioContext && !window.webkitAudioContext) {
            setAudioStatus('Speaker test is not supported in this browser.');
            return;
        }

        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            const context = new AudioContextClass();
            const destination = context.createMediaStreamDestination();
            const gainNode = context.createGain();
            const oscillator = context.createOscillator();

            oscillator.type = 'sine';
            oscillator.frequency.value = 660;
            gainNode.gain.value = 0.1;
            oscillator.connect(gainNode);
            gainNode.connect(destination);

            audio.srcObject = destination.stream;
            audio.volume = Math.max(0, Math.min(1, Number(speakerVolume) || 0));

            const currentSpeakerId = effectiveSpeakerId;
            if (speakerRoutingSupported && currentSpeakerId && 'function' === typeof audio.setSinkId) {
                await audio.setSinkId(currentSpeakerId);
            }

            await context.resume();
            await audio.play();
            oscillator.start();
            oscillator.stop(context.currentTime + 0.8);
            oscillator.onended = async () => {
                try {
                    audio.pause();
                    audio.srcObject = null;
                } catch (error) {
                    // Ignore cleanup errors.
                }

                try {
                    await context.close();
                } catch (error) {
                    // Ignore cleanup errors.
                }
            };
            setAudioStatus('Speaker test playing.');
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Speaker test failed.';
            setAudioStatus(message);
            appendLog('Speaker test failed.', { message });
        }
    };

    const selectedMicrophonePreference = SoftphonePreferences.getMicrophone(preferences);
    const selectedSpeakerPreference = SoftphonePreferences.getSpeaker(preferences);
    const audioSettingsPreference = SoftphonePreferences.getAudioSettings(preferences);
    const speakerVolume = SoftphonePreferences.getSpeakerVolume(preferences);
    const effectiveMicrophoneId = findDeviceById(microphones, selectedMicrophonePreference)?.deviceId
        ?? microphones[0]?.deviceId
        ?? '';
    const effectiveSpeakerId = speakerRoutingSupported
        ? findDeviceById(speakers, selectedSpeakerPreference)?.deviceId
            ?? speakers[0]?.deviceId
            ?? ''
        : '';
    const selectedMicLabel = findDeviceById(microphones, effectiveMicrophoneId)?.label ?? 'Default microphone';
    const selectedSpeakerLabel = speakerRoutingSupported
        ? findDeviceById(speakers, effectiveSpeakerId)?.label ?? 'Default speaker'
        : 'Speaker routing unsupported';
    const audioStatusClass = /permission denied|no microphones|no speakers|failed|not supported|unable/i.test(audioStatus)
        ? 'text-danger'
        : 'text-secondary';
    const appliedAudioSummary = audioDiagnostics
        ? {
            requested: audioDiagnostics.requested,
            applied: audioDiagnostics.applied,
            trackSettings: audioDiagnostics.trackSettings,
            warnings: audioDiagnostics.warnings,
        }
        : null;

    const memoizedSession = useMemo(() => {
        if (!credential || !dialRequest) {
            return null;
        }

        return React.createElement(
            TelnyxRTCProvider,
            {
                credential,
                options: {
                    mutedMicOnStart: false,
                    keepConnectionAliveOnSocketClose: true,
                },
            },
            React.createElement(BrowserSoftphoneSession, {
                key: credential.login_token,
                dialRequest,
                ringbackController: ringbackControllerRef.current,
                localStream,
                selectedSpeakerId: effectiveSpeakerId,
                speakerRoutingSupported,
                speakerVolume,
                onSessionEnd: resetSession,
                onLog: appendLog,
                onStatusChange: setStatus,
                onConnectionStateChange: setConnectionState,
                onAudioWarning: setAudioStatus,
            }),
        );
    }, [credential, dialRequest, effectiveSpeakerId, localStream, speakerRoutingSupported, speakerVolume]);

    return React.createElement(
        'div',
        { className: 'p-4 p-md-5 rounded-4 border bg-body shadow-sm' },
        React.createElement(
            'div',
            { className: 'd-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4' },
            React.createElement(
                'div',
                null,
                React.createElement('div', { className: 'text-uppercase text-secondary small fw-semibold' }, 'Telnyx WebRTC'),
                React.createElement('h1', { className: 'display-6 mb-1' }, 'Browser Softphone POC'),
                React.createElement(
                    'p',
                    { className: 'text-secondary mb-0' },
                    'Fetch a short-lived login token from Symfony, then place an outbound browser call through Telnyx React Client.',
                ),
            ),
            React.createElement(
                'div',
                { className: 'text-md-end' },
                React.createElement('div', { className: 'small text-secondary' }, 'Caller number'),
                React.createElement('div', { className: 'font-monospace' }, defaultCallerNumber || 'Not configured'),
                React.createElement('div', { className: 'small text-secondary mt-2' }, 'Destination number'),
                React.createElement('input', {
                    type: 'tel',
                    className: 'form-control form-control-sm font-monospace text-md-end',
                    value: destinationNumber,
                    placeholder: '+15551231234',
                    onChange: (event) => setDestinationNumber(event.target.value),
                }),
            ),
            React.createElement(
                'div',
                { className: 'd-flex gap-2' },
                React.createElement('button', {
                    type: 'button',
                    className: 'btn btn-primary btn-lg',
                    onClick: startCall,
                    disabled: busy,
                }, busy ? 'Starting...' : 'Browser Call'),
                React.createElement('button', {
                    type: 'button',
                    className: 'btn btn-outline-secondary btn-lg',
                    onClick: resetSession,
                    disabled: busy && !credential,
                }, 'Reset'),
            ),
        ),
        React.createElement(
            'div',
            { className: 'row g-4' },
            React.createElement(
                'div',
                { className: 'col-12 col-lg-5' },
                React.createElement(
                    'div',
                    { className: 'border rounded-4 p-3 bg-body-tertiary h-100' },
                    React.createElement('div', { className: 'small text-secondary mb-1' }, 'Status'),
                    React.createElement('div', { className: 'alert alert-info mb-3' }, status),
                    React.createElement(
                        'div',
                        { className: 'border rounded-4 p-3 bg-body mb-3' },
                        React.createElement(
                            'div',
                            { className: 'd-flex justify-content-between align-items-center gap-2 mb-2' },
                            React.createElement('div', { className: 'fw-semibold' }, 'Audio Devices'),
                            React.createElement('button', {
                                type: 'button',
                                className: 'btn btn-outline-secondary btn-sm',
                                onClick: refreshDevices,
                                disabled: busy,
                            }, 'Refresh Devices'),
                        ),
                        React.createElement('div', { className: `small mb-3 ${audioStatusClass}` }, audioStatus),
                        React.createElement('div', { className: 'small text-secondary mb-1' }, 'Microphone'),
                        React.createElement(
                            'select',
                            {
                                className: 'form-select form-select-sm mb-2',
                                value: effectiveMicrophoneId,
                                onChange: handleMicChange,
                                disabled: busy || 0 === microphones.length,
                            },
                            0 === microphones.length
                                ? React.createElement('option', { value: '' }, 'No microphones available')
                                : [
                                    React.createElement('option', { key: 'mic-default', value: '' }, 'Default microphone'),
                                    ...microphones.map((device) => React.createElement(
                                        'option',
                                        {
                                            key: device.deviceId,
                                            value: device.deviceId,
                                        },
                                        device.label,
                                    )),
                                    ],
                        ),
                        React.createElement('div', { className: 'small text-secondary mb-2' }, selectedMicLabel),
                        React.createElement('div', { className: 'small text-secondary mb-1' }, 'Speaker'),
                        React.createElement(
                            'select',
                            {
                                className: 'form-select form-select-sm mb-2',
                                value: effectiveSpeakerId,
                                onChange: handleSpeakerChange,
                                disabled: busy || !speakerRoutingSupported || 0 === speakers.length,
                            },
                            !speakerRoutingSupported
                                ? React.createElement('option', { value: '' }, 'Speaker routing unsupported')
                                : 0 === speakers.length
                                    ? React.createElement('option', { value: '' }, 'No speakers available')
                                    : [
                                        React.createElement('option', { key: 'speaker-default', value: '' }, 'Default speaker'),
                                        ...speakers.map((device) => React.createElement(
                                            'option',
                                            {
                                                key: device.deviceId,
                                                value: device.deviceId,
                                            },
                                            device.label,
                                        )),
                                    ],
                        ),
                        React.createElement('div', { className: 'small text-secondary mb-1' }, `Speaker Volume ${Math.round((Number(speakerVolume) || 0) * 100)}%`),
                        React.createElement('input', {
                            type: 'range',
                            className: 'form-range mb-2',
                            min: '0',
                            max: '1',
                            step: '0.05',
                            value: String(speakerVolume),
                            onChange: handleSpeakerVolumeChange,
                        }),
                        React.createElement(
                            'div',
                            { className: 'border rounded-3 p-3 mb-2 bg-light' },
                            React.createElement('div', { className: 'small text-secondary mb-2' }, 'Audio Processing'),
                            React.createElement(
                                'div',
                                { className: 'form-check mb-2' },
                                React.createElement('input', {
                                    id: 'echo-cancellation-toggle',
                                    type: 'checkbox',
                                    className: 'form-check-input',
                                    name: 'echoCancellation',
                                    checked: audioSettingsPreference.echoCancellation,
                                    onChange: handleAudioSettingChange,
                                    disabled: busy,
                                }),
                                React.createElement('label', { className: 'form-check-label', htmlFor: 'echo-cancellation-toggle' }, 'Echo cancellation'),
                            ),
                            React.createElement(
                                'div',
                                { className: 'form-check mb-2' },
                                React.createElement('input', {
                                    id: 'noise-suppression-toggle',
                                    type: 'checkbox',
                                    className: 'form-check-input',
                                    name: 'noiseSuppression',
                                    checked: audioSettingsPreference.noiseSuppression,
                                    onChange: handleAudioSettingChange,
                                    disabled: busy,
                                }),
                                React.createElement('label', { className: 'form-check-label', htmlFor: 'noise-suppression-toggle' }, 'Noise suppression'),
                            ),
                            React.createElement(
                                'div',
                                { className: 'form-check' },
                                React.createElement('input', {
                                    id: 'auto-gain-toggle',
                                    type: 'checkbox',
                                    className: 'form-check-input',
                                    name: 'autoGainControl',
                                    checked: audioSettingsPreference.autoGainControl,
                                    onChange: handleAudioSettingChange,
                                    disabled: busy,
                                }),
                                React.createElement('label', { className: 'form-check-label', htmlFor: 'auto-gain-toggle' }, 'Auto gain control'),
                            ),
                        ),
                        React.createElement(
                            'div',
                            { className: 'border rounded-3 p-3 mb-2 bg-dark text-light' },
                            React.createElement('div', { className: 'small text-info mb-2' }, 'Applied Audio Track Settings'),
                            appliedAudioSummary
                                ? React.createElement(
                                    'pre',
                                    { className: 'mb-0 text-light', style: { whiteSpace: 'pre-wrap' } },
                                    JSON.stringify(appliedAudioSummary, null, 2),
                                )
                                : React.createElement('div', { className: 'text-secondary' }, 'No call audio stream has been captured yet.'),
                            appliedAudioSummary?.warnings?.length
                                ? React.createElement(
                                    'div',
                                    { className: 'alert alert-warning mt-2 mb-0 py-2' },
                                    appliedAudioSummary.warnings.join(' '),
                                )
                                : null,
                        ),
                        React.createElement(
                            'div',
                            { className: 'd-flex flex-wrap gap-2' },
                            React.createElement('button', {
                                type: 'button',
                                className: 'btn btn-outline-secondary btn-sm',
                                onClick: playSpeakerTestTone,
                            }, 'Test Speaker'),
                            React.createElement('div', { className: 'small text-secondary align-self-center' }, selectedSpeakerLabel),
                        ),
                        !speakerRoutingSupported
                            ? React.createElement('div', { className: 'small text-secondary mt-2' }, 'Speaker selection is not supported by this browser.')
                            : null,
                    ),
                    React.createElement('div', { className: 'small text-secondary mb-1' }, 'Connection State'),
                    React.createElement('div', { className: 'fw-semibold mb-3' }, connectionState),
                    React.createElement('div', { className: 'small text-secondary mb-1' }, 'Default Destination'),
                    React.createElement('div', { className: 'font-monospace mb-3' }, defaultDestinationNumber || '-'),
                    React.createElement('div', { className: 'small text-secondary mb-1' }, 'Debug Output'),
                    React.createElement(
                        'pre',
                        {
                            ref: logOutputRef,
                            className: 'border rounded-3 p-3 bg-dark text-light mb-0',
                            style: { minHeight: '18rem', maxHeight: '18rem', overflowY: 'auto', whiteSpace: 'pre-wrap' },
                        },
                        logs.length > 0 ? logs.join('\n') : 'No events yet.',
                    ),
                ),
            ),
            React.createElement(
                'div',
                { className: 'col-12 col-lg-7' },
                credential && dialRequest
                    ? memoizedSession
                    : React.createElement(
                        'div',
                        { className: 'border rounded-4 p-3 bg-body-tertiary h-100' },
                        React.createElement('div', { className: 'text-secondary' }, 'Press Browser Call to request a token and start the Telnyx React client.'),
                    ),
            ),
        ),
        React.createElement('audio', {
            ref: speakerTestAudioRef,
            hidden: true,
            'aria-hidden': 'true',
        }),
    );
}
