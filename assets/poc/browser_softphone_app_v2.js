import React, { useContext, useEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { TelnyxRTCContext, useCallbacks, useNotification } from '@telnyx/react-client';
import { TelnyxRTC as ImportedTelnyxRTC } from '@telnyx/webrtc';
import SoftphonePreferences from './softphone_preferences.js';
import BrowserSoftphoneSettingsPanel from './browser_softphone_settings.js';
import BrowserSoftphoneTranscriptPanel from './browser_softphone_transcript_panel.js';
import BrowserSoftphonePostCallSummaryPanel, { buildMockPostCallSummary } from './browser_softphone_post_call_summary.js';

const DTMF_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];
const E164_REGEX = /^\+[1-9]\d{7,19}$/;
const RINGBACK_ON_MS = 2000;
const RINGBACK_OFF_MS = 4000;
const BROWSER_SOFTPHONE_APP_VERSION = 'v2';

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

function resolveTelnyxRTCClass() {
    if ('function' === typeof globalThis.TelnyxRTC) {
        return globalThis.TelnyxRTC;
    }

    return ImportedTelnyxRTC;
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

function setStreamMuted(stream, muted) {
    if (!stream || 'function' !== typeof stream.getAudioTracks) {
        return;
    }

    stream.getAudioTracks().forEach((track) => {
        track.enabled = !muted;
    });
}

function useMediaQuery(query) {
    const [matches, setMatches] = useState(() => {
        if ('undefined' === typeof window || 'function' !== typeof window.matchMedia) {
            return false;
        }

        return window.matchMedia(query).matches;
    });

    useEffect(() => {
        if ('undefined' === typeof window || 'function' !== typeof window.matchMedia) {
            return undefined;
        }

        const mediaQuery = window.matchMedia(query);
        const handleChange = () => {
            setMatches(mediaQuery.matches);
        };

        handleChange();
        if ('function' === typeof mediaQuery.addEventListener) {
            mediaQuery.addEventListener('change', handleChange);

            return () => {
                mediaQuery.removeEventListener('change', handleChange);
            };
        }

        mediaQuery.addListener(handleChange);

        return () => {
            mediaQuery.removeListener(handleChange);
        };
    }, [query]);

    return matches;
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

function BrowserSoftphoneTelnyxProvider({
    credential,
    options,
    children,
}) {
    const ClientClass = resolveTelnyxRTCClass();
    const client = useMemo(() => {
        if (!credential) {
            return null;
        }

        try {
            return new ClientClass({
                login_token: '',
                ...credential,
                ...options,
            });
        } catch (error) {
            return null;
        }
    }, [
        ClientClass,
        credential?.login_token,
        credential?.login,
        credential?.password,
        JSON.stringify(options ?? {}),
    ]);

    useEffect(() => {
        if (!client) {
            return undefined;
        }

        const handleError = () => {
            try {
                client.disconnect?.();
            } catch (error) {
                // Ignore cleanup failures.
            }
        };

        client.on?.('telnyx.error', handleError);
        client.on?.('telnyx.socket.error', handleError);
        client.connect?.();

        return () => {
            try {
                client.off?.('telnyx.error', handleError);
                client.off?.('telnyx.socket.error', handleError);
            } catch (error) {
                // Ignore listener cleanup issues.
            }

            try {
                client.disconnect?.();
            } catch (error) {
                // Ignore disconnect cleanup failures.
            }
        };
    }, [client]);

    return React.createElement(
        TelnyxRTCContext.Provider,
        { value: client },
        children,
    );
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
    callSessionId,
    onSessionEnd,
    onCallIdResolved,
    onLog,
    onStatusChange,
    onConnectionStateChange,
    ringbackController,
    localStream,
    muted,
    onToggleMute,
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
    const sessionEndedRef = useRef(false);

    useEffect(() => {
        ringbackRef.current = ringbackController ?? ringbackRef.current ?? createRingbackController();
    }, [ringbackController]);

    useEffect(() => {
        setStreamMuted(localStream, muted);
    }, [localStream, muted]);

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
                        callId: call?.id ?? null,
                        destinationNumber: dialRequest.destinationNumber,
                        callerNumber: dialRequest.callerNumber,
                        hasLocalStream: Boolean(localStream),
                    });
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'Unable to start outbound call.';
                    onStatusChange(message);
                    onConnectionStateChange('Call failed');
                    onLog('newCall failed.', { message });
                    onSessionEnd({ showSummary: false });
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
            const callControlId = notification.call.telnyxIDs?.telnyxCallControlId ?? null;
            onLog('Browser call active notification received.', {
                callId: notification.call.id ?? null,
                telnyxCallControlId: callControlId,
            });
            if ('function' === typeof onCallIdResolved) {
                onCallIdResolved(callControlId ?? notification.call.id ?? null);
            }
        } else if ('destroy' === callState) {
            onStatusChange('Call ended.');
            onConnectionStateChange('Disconnected');
            ringbackRef.current?.stop();
            endSession({ showSummary: true });
        }
    }, [localStream, notification, onCallIdResolved, onConnectionStateChange, onLog, onStatusChange]);

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

    const endSession = (options = {}) => {
        if (sessionEndedRef.current) {
            return;
        }

        sessionEndedRef.current = true;
        onSessionEnd(options);
    };

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
        endSession({ showSummary: true });
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
            React.createElement(
                'div',
                { className: 'd-flex flex-wrap justify-content-end gap-2' },
                React.createElement('button', {
                    type: 'button',
                    className: muted ? 'btn btn-danger' : 'btn btn-outline-secondary',
                    disabled: !activeCall,
                    onClick: onToggleMute,
                    title: 'Keyboard shortcut reserved for a later phase.',
                }, muted ? 'Unmute' : 'Mute'),
                React.createElement('button', {
                    type: 'button',
                    className: 'btn btn-outline-danger',
                    disabled: !activeCall,
                    onClick: hangup,
                }, 'Hangup'),
            ),
        ),
        React.createElement(
            'div',
            { className: 'mb-3' },
            React.createElement(
                'span',
                {
                    className: muted ? 'badge text-bg-danger' : 'badge text-bg-success',
                },
                muted ? 'Muted 🔴' : 'Live 🟢',
            ),
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
    const [muted, setMuted] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [callSessionId, setCallSessionId] = useState(null);
    const [browserCallId, setBrowserCallId] = useState(null);
    const [transcriptTopic, setTranscriptTopic] = useState(null);
    const [transcriptStreamUrl, setTranscriptStreamUrl] = useState(null);
    const [transcriptStatus, setTranscriptStatus] = useState('Transcript stream idle.');
    const [transcriptSegments, setTranscriptSegments] = useState([]);
    const [postCallSummary, setPostCallSummary] = useState(null);
    const ringbackControllerRef = useRef(null);
    const speakerTestAudioRef = useRef(null);
    const logOutputRef = useRef(null);
    const transcriptSourceRef = useRef(null);
    const preferencesRef = useRef(preferences);
    const activeCallIdRef = useRef(null);

    if (!ringbackControllerRef.current) {
        ringbackControllerRef.current = createRingbackController();
    }

    const speakerRoutingSupported = supportsSpeakerSelection();
    const isMobileLayout = useMediaQuery('(max-width: 991.98px)');

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

    const resetSession = (options = {}) => {
        const shouldShowSummary = true === options?.showSummary;
        const preserveTranscript = true === options?.preserveTranscript;
        transcriptSourceRef.current?.close?.();
        transcriptSourceRef.current = null;
        setPostCallSummary(shouldShowSummary ? buildMockPostCallSummary(callSessionId, destinationNumber) : null);
        setBusy(false);
        setMuted(false);
        setConnectionState('Disconnected');
        setLocalStream((currentStream) => {
            stopMediaStream(currentStream);
            return null;
        });
        setAudioDiagnostics(null);
        if (!preserveTranscript) {
            setCredential(null);
            setDialRequest(null);
            activeCallIdRef.current = null;
            setBrowserCallId(null);
            setCallSessionId(null);
            setTranscriptTopic(null);
            setTranscriptStreamUrl(null);
            setTranscriptStatus('Transcript stream idle.');
            setTranscriptSegments([]);
        } else {
            setTranscriptStatus('Transcript stream ended. Transcript is preserved for review.');
        }
        ringbackControllerRef.current?.stop();
    };

    const handleCallIdResolved = (callId) => {
        const normalizedCallId = 'string' === typeof callId ? callId.trim() : '';
        if (!normalizedCallId || activeCallIdRef.current === normalizedCallId) {
            return;
        }

        activeCallIdRef.current = normalizedCallId;
        setBrowserCallId(normalizedCallId);
        setTranscriptStatus(`Browser call ${normalizedCallId} connected. Waiting for transcript events.`);

        if (callSessionId) {
            const registerCallControl = async () => {
                const url = `/api/poc/browser-softphone/${encodeURIComponent(callSessionId)}/call-control`;
                console.debug('[browser-softphone-react] requesting call-control registration.', {
                    callSessionId,
                    callControlId: normalizedCallId,
                    url,
                });

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            callControlId: normalizedCallId,
                        }),
                    });
                    const responseText = await response.text();
                    let responseBody = null;
                    try {
                        responseBody = JSON.parse(responseText);
                    } catch {
                        responseBody = responseText;
                    }

                    console.debug('[browser-softphone-react] call-control registration returned.', {
                        callSessionId,
                        callControlId: normalizedCallId,
                        url,
                        status: response.status,
                        ok: response.ok,
                        response: responseBody,
                    });
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'Unable to register browser call control id.';
                    appendLog('Failed to register browser call control id.', {
                        callSessionId,
                        callControlId: normalizedCallId,
                        message,
                    });
                    console.debug('[browser-softphone-react] call-control registration failed.', {
                        callSessionId,
                        callControlId: normalizedCallId,
                        url,
                        message,
                    });
                }
            };

            void registerCallControl();
        }
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

function normalizeTranscriptSegment(segment) {
    if (!segment || 'object' !== typeof segment) {
        return null;
    }

    const id = Number.parseInt(String(segment.id ?? segment.sequence ?? ''), 10);
    if (!Number.isFinite(id) || id <= 0) {
        return null;
    }

    const text = 'string' === typeof segment.text ? segment.text.trim() : '';
    if (!text) {
        return null;
    }

    return {
        id,
        sequence: Number.isFinite(Number.parseInt(String(segment.sequence ?? id), 10)) ? Number.parseInt(String(segment.sequence ?? id), 10) : id,
        speaker: normalizeTranscriptSpeaker(segment.speaker),
        text,
        occurredAt: 'string' === typeof segment.occurredAt ? segment.occurredAt : null,
        displayTime: 'string' === typeof segment.displayTime && segment.displayTime.trim() ? segment.displayTime.trim() : null,
        isFinal: true === segment.isFinal,
        sourceEventId: 'string' === typeof segment.sourceEventId && segment.sourceEventId.trim() ? segment.sourceEventId.trim() : null,
        fingerprint: 'string' === typeof segment.fingerprint && segment.fingerprint.trim() ? segment.fingerprint.trim() : null,
    };
}

function transcriptSegmentKey(segment) {
    if (!segment) {
        return null;
    }

    return transcriptSegmentMergeKey(segment);
}

function transcriptSegmentMergeKey(segment) {
    if (!segment) {
        return null;
    }

    return segment.sourceEventId ?? segment.fingerprint ?? `segment:${segment.id}`;
}

function normalizeTranscriptSpeaker(speaker) {
    const normalized = 'string' === typeof speaker ? speaker.trim().toLowerCase() : '';

    return ['csr', 'agent', 'operator', 'representative'].includes(normalized) ? 'csr' : 'customer';
}

function transcriptSideForSpeaker(speaker) {
    return 'csr' === speaker ? 'right' : 'left';
}

function transcriptBubbleTone(side) {
    return 'right' === side ? 'primary' : 'secondary';
}

function transcriptSpeakerLabel(speaker) {
    return 'csr' === speaker ? 'CSR' : 'Customer';
}

function formatTranscriptTime(segment) {
    if (segment?.displayTime) {
        return segment.displayTime;
    }

    if (!segment?.occurredAt) {
        return '';
    }

    const occurredAt = new Date(segment.occurredAt);
    if (Number.isNaN(occurredAt.getTime())) {
        return segment.occurredAt;
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(occurredAt);
}

function mergeTranscriptSegments(currentSegments, segment) {
    const normalizedSegment = normalizeTranscriptSegment(segment);
    if (!normalizedSegment) {
        return currentSegments;
    }

    const key = transcriptSegmentMergeKey(normalizedSegment);
    const nextSegments = [...currentSegments];
    const matchedIndex = nextSegments.findIndex((currentSegment) => transcriptSegmentMergeKey(currentSegment) === key);

    if (-1 === matchedIndex) {
        return [...nextSegments, normalizedSegment].sort((left, right) => {
            const sequenceDelta = (left.sequence ?? left.id ?? 0) - (right.sequence ?? right.id ?? 0);

            return 0 !== sequenceDelta ? sequenceDelta : left.id - right.id;
        });
    }

    const existingSegment = nextSegments[matchedIndex];
    if (
        existingSegment.fingerprint
        && normalizedSegment.fingerprint
        && existingSegment.fingerprint === normalizedSegment.fingerprint
        && existingSegment.isFinal === normalizedSegment.isFinal
    ) {
        return currentSegments;
    }

    if (existingSegment.isFinal && !normalizedSegment.isFinal) {
        return currentSegments;
    }

    nextSegments[matchedIndex] = {
        ...normalizedSegment,
        id: existingSegment.id,
        sequence: existingSegment.sequence,
    };

    return nextSegments.sort((left, right) => {
        const sequenceDelta = (left.sequence ?? left.id ?? 0) - (right.sequence ?? right.id ?? 0);

        return 0 !== sequenceDelta ? sequenceDelta : left.id - right.id;
    });
}

function TranscriptFeedPanel({
    status,
    topic,
    segments,
    onClear,
}) {
    const transcriptScrollRef = useRef(null);
    const hasTopic = 'string' === typeof topic && topic.trim().length > 0;

    useEffect(() => {
        const element = transcriptScrollRef.current;
        if (element) {
            element.scrollTop = element.scrollHeight;
        }
    }, [segments]);

    return React.createElement(
        'div',
        {
            className: `border border-2 rounded-4 p-3 mt-3 shadow-sm ${hasTopic ? 'border-primary-subtle bg-white' : 'border-warning-subtle bg-warning-subtle'}`,
            style: {
                background: hasTopic
                    ? 'linear-gradient(180deg, #f8fafc 0%, #eef4ff 100%)'
                    : 'linear-gradient(180deg, #fffaf0 0%, #fff2cc 100%)',
            },
            'data-transcript-panel': 'true',
        },
        React.createElement(
            'div',
            { className: 'd-flex justify-content-between align-items-start gap-3 mb-3' },
            React.createElement(
                'div',
                { className: 'd-flex align-items-start gap-3' },
                React.createElement(
                    'div',
                    {
                        className: `rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 ${hasTopic ? 'bg-primary text-white' : 'bg-warning text-dark'}`,
                        style: {
                            width: '2.75rem',
                            height: '2.75rem',
                            fontSize: '1.1rem',
                            lineHeight: 1,
                        },
                        'aria-hidden': 'true',
                    },
                    '≋',
                ),
                React.createElement(
                    'div',
                    null,
                    React.createElement(
                        'div',
                        { className: 'd-flex flex-wrap align-items-center gap-2 mb-1' },
                        React.createElement('div', { className: 'small text-secondary text-uppercase fw-semibold' }, 'Live Transcript'),
                        React.createElement(
                            'span',
                            {
                                className: `badge ${hasTopic ? 'text-bg-primary' : 'text-bg-warning'}`,
                                'data-transcript-panel-badge': 'true',
                            },
                            hasTopic ? 'Listening' : 'Waiting',
                        ),
                    ),
                    React.createElement('div', { className: 'fw-semibold text-break' }, topic || 'Waiting for call session'),
                    React.createElement('div', { className: 'small text-secondary mt-1' }, status),
                ),
            ),
            React.createElement(
                'button',
                {
                    type: 'button',
                    className: 'btn btn-outline-secondary btn-sm',
                    onClick: onClear,
                    disabled: 0 === segments.length,
                },
                'Clear',
            ),
        ),
        React.createElement(
            'div',
            {
                className: `alert ${hasTopic ? 'alert-primary' : 'alert-warning'} d-flex align-items-start gap-2 mb-3`,
                role: 'status',
                'data-transcript-panel-message': 'true',
            },
            React.createElement('div', { className: 'fw-semibold text-uppercase small mt-1' }, hasTopic ? 'Streaming live' : 'Ready for call'),
            React.createElement(
                'div',
                { className: 'small' },
                hasTopic
                    ? 'Transcript segments will appear here as the call is transcribed.'
                    : 'This pane stays visible so you can see transcripts as soon as the call starts.',
            ),
        ),
        React.createElement(
            'div',
            {
                ref: transcriptScrollRef,
                'data-transcript-scroll-region': 'true',
                className: 'd-flex flex-column gap-3 p-2',
                style: {
                    maxHeight: '24rem',
                    minHeight: '12rem',
                    overflowY: 'auto',
                    scrollBehavior: 'smooth',
                },
            },
            0 === segments.length
                ? React.createElement(
                    'div',
                    {
                        className: 'border rounded-4 bg-white bg-opacity-75 text-secondary text-center py-5 px-3',
                        style: { borderStyle: 'dashed' },
                    },
                    React.createElement('div', { className: 'fw-semibold mb-1' }, 'No transcript segments yet.'),
                    React.createElement('div', { className: 'small' }, 'Transcript bubbles will appear here as segments arrive.'),
                )
                : React.createElement(
                    'div',
                    { className: 'd-flex flex-column gap-3' },
                    segments.map((segment) => React.createElement(
                        'article',
                        {
                            key: transcriptSegmentKey(segment),
                            'data-transcript-segment-id': String(segment.id),
                            'data-transcript-merge-key': transcriptSegmentMergeKey(segment),
                            'data-transcript-side': transcriptSideForSpeaker(segment.speaker),
                            'data-transcript-speaker': segment.speaker,
                            'data-transcript-final': segment.isFinal ? 'true' : 'false',
                            'data-bubble-tone': transcriptBubbleTone(transcriptSideForSpeaker(segment.speaker)),
                            className: `d-flex ${'right' === transcriptSideForSpeaker(segment.speaker) ? 'justify-content-end' : 'justify-content-start'}`,
                        },
                        React.createElement(
                            'div',
                            {
                                className: `rounded-4 px-3 py-2 shadow-sm ${'right' === transcriptSideForSpeaker(segment.speaker) ? 'bg-primary text-white' : 'bg-white border border-1 border-primary-subtle'}`,
                                style: {
                                    maxWidth: '82%',
                                    opacity: segment.isFinal ? 1 : 0.78,
                                    fontStyle: segment.isFinal ? 'normal' : 'italic',
                                },
                                'data-transcript-bubble': 'true',
                            },
                            React.createElement(
                                'div',
                                { className: `d-flex justify-content-between gap-3 small mb-1 ${'right' === transcriptSideForSpeaker(segment.speaker) ? 'text-white-50' : 'text-secondary'}` },
                                React.createElement('div', { className: 'fw-semibold text-uppercase' }, transcriptSpeakerLabel(segment.speaker)),
                                React.createElement('time', {
                                    dateTime: segment.occurredAt ?? undefined,
                                    'data-transcript-timestamp': 'true',
                                }, formatTranscriptTime(segment)),
                            ),
                            React.createElement('div', { className: 'lh-base' }, segment.text),
                            !segment.isFinal
                                ? React.createElement(
                                    'div',
                                    { className: `small mt-1 ${'right' === transcriptSideForSpeaker(segment.speaker) ? 'text-white-50' : 'text-secondary'}` },
                                    'Typing...',
                                )
                                : null,
                        ),
                    )),
                ),
        ),
    );
}

function PostCallSummaryPanel({ summary }) {
    if (!summary) {
        return null;
    }

    return React.createElement(
        'div',
        {
            className: 'border rounded-4 p-3 mt-3 shadow-sm',
            'data-post-call-summary': 'true',
            style: {
                background: 'linear-gradient(180deg, #fffaf2 0%, #fff1d8 100%)',
            },
        },
        React.createElement('div', { className: 'small text-uppercase fw-semibold text-secondary mb-1' }, 'Post Call'),
        React.createElement('div', { className: 'h5 mb-2' }, 'Call Summary'),
        React.createElement('div', { className: 'text-secondary mb-3' }, summary.summary),
        React.createElement(
            'div',
            { className: 'row g-3' },
            React.createElement(
                'div',
                { className: 'col-12 col-md-6' },
                React.createElement('div', { className: 'fw-semibold mb-2' }, 'Customer concerns'),
                React.createElement(
                    'ul',
                    { className: 'list-unstyled mb-0 d-grid gap-2' },
                    summary.customerConcerns.map((concern) => React.createElement(
                        'li',
                        {
                            key: concern,
                            className: 'border rounded-3 bg-white px-3 py-2',
                        },
                        concern,
                    )),
                ),
            ),
            React.createElement(
                'div',
                { className: 'col-12 col-md-6' },
                React.createElement('div', { className: 'fw-semibold mb-2' }, 'Action items'),
                React.createElement(
                    'ul',
                    { className: 'list-unstyled mb-0 d-grid gap-2' },
                    summary.actionItems.map((actionItem) => React.createElement(
                        'li',
                        {
                            key: actionItem,
                            className: 'border rounded-3 bg-white px-3 py-2',
                        },
                        actionItem,
                    )),
                ),
            ),
        ),
        React.createElement(
            'div',
            { className: 'mt-3' },
            React.createElement('div', { className: 'fw-semibold mb-2' }, 'Keywords'),
            React.createElement(
                'div',
                { className: 'd-flex flex-wrap gap-2' },
                summary.keywords.map((keyword) => React.createElement(
                    'span',
                    {
                        key: keyword,
                        className: 'badge text-bg-dark',
                    },
                    keyword,
                )),
            ),
        ),
    );
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

    useEffect(() => {
        if (!transcriptStreamUrl || 'function' !== typeof window.EventSource) {
            if (!transcriptStreamUrl) {
                setTranscriptStatus('Transcript stream idle.');
            } else {
                setTranscriptStatus('Transcript stream is not supported in this browser.');
            }

            return undefined;
        }

        const eventSource = new EventSource(transcriptStreamUrl);
        transcriptSourceRef.current = eventSource;
        setTranscriptStatus(`Connecting transcript stream for ${callSessionId ?? 'call session'}...`);

        const handleTranscriptSegment = (event) => {
            try {
                if ('undefined' !== typeof window) {
                    window.__browserSoftphoneTranscriptEventCount = (window.__browserSoftphoneTranscriptEventCount ?? 0) + 1;
                }
                const payload = JSON.parse(event.data);
                const segment = payload?.segment ?? payload;
                appendTranscriptSegment(segment);
                setTranscriptStatus(`Transcript stream connected to ${payload?.topic ?? transcriptStreamUrl}.`);
            } catch (error) {
                setTranscriptStatus('Transcript stream received an invalid payload.');
            }
        };

        if ('undefined' !== typeof window) {
            window.__browserSoftphoneTranscriptSink = handleTranscriptSegment;
        }

        const handleReady = (event) => {
            try {
                const payload = JSON.parse(event.data);
                setTranscriptStatus(`Transcript stream connected to ${payload?.topic ?? transcriptStreamUrl}.`);
            } catch (error) {
                setTranscriptStatus(`Transcript stream connected to ${transcriptStreamUrl}.`);
            }
        };

        const handleHeartbeat = () => {
            if (transcriptStreamUrl) {
                setTranscriptStatus(`Listening on ${transcriptStreamUrl}.`);
            }
        };

        eventSource.addEventListener('transcript.segment', handleTranscriptSegment);
        eventSource.addEventListener('message', handleTranscriptSegment);
        eventSource.addEventListener('ready', handleReady);
        eventSource.addEventListener('heartbeat', handleHeartbeat);

        return () => {
            eventSource.close();
            if (transcriptSourceRef.current === eventSource) {
                transcriptSourceRef.current = null;
            }

            if ('undefined' !== typeof window && window.__browserSoftphoneTranscriptSink === handleTranscriptSegment) {
                delete window.__browserSoftphoneTranscriptSink;
            }
        };
    }, [callSessionId, transcriptStreamUrl]);

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

    const appendTranscriptSegment = (segment) => {
        const update = () => {
            setTranscriptSegments((currentSegments) => {
                if (!segment || 'object' !== typeof segment) {
                    return currentSegments;
                }

                const id = Number.parseInt(String(segment.id ?? segment.sequence ?? ''), 10);
                if (!Number.isFinite(id) || id <= 0) {
                    return currentSegments;
                }

                const text = 'string' === typeof segment.text ? segment.text.trim() : '';
                if (!text) {
                    return currentSegments;
                }

                const normalizedSegment = {
                    id,
                    sequence: Number.isFinite(Number.parseInt(String(segment.sequence ?? id), 10)) ? Number.parseInt(String(segment.sequence ?? id), 10) : id,
                    speaker: ['csr', 'agent', 'operator', 'representative'].includes('string' === typeof segment.speaker ? segment.speaker.trim().toLowerCase() : '') ? 'csr' : 'customer',
                    text,
                    occurredAt: 'string' === typeof segment.occurredAt ? segment.occurredAt : null,
                    displayTime: 'string' === typeof segment.displayTime && segment.displayTime.trim() ? segment.displayTime.trim() : null,
                    isFinal: true === segment.isFinal,
                    sourceEventId: 'string' === typeof segment.sourceEventId && segment.sourceEventId.trim() ? segment.sourceEventId.trim() : null,
                    fingerprint: 'string' === typeof segment.fingerprint && segment.fingerprint.trim() ? segment.fingerprint.trim() : null,
                };

                const key = normalizedSegment.sourceEventId ?? normalizedSegment.fingerprint ?? `segment:${normalizedSegment.id}`;
                const nextSegments = [...currentSegments];
                const matchedIndex = nextSegments.findIndex((currentSegment) => (currentSegment?.sourceEventId ?? currentSegment?.fingerprint ?? `segment:${currentSegment?.id}`) === key);

                if (-1 === matchedIndex) {
                    return [...nextSegments, normalizedSegment].sort((left, right) => {
                        const sequenceDelta = (left.sequence ?? left.id ?? 0) - (right.sequence ?? right.id ?? 0);

                        return 0 !== sequenceDelta ? sequenceDelta : left.id - right.id;
                    });
                }

                const existingSegment = nextSegments[matchedIndex];
                if (
                    existingSegment.fingerprint
                    && normalizedSegment.fingerprint
                    && existingSegment.fingerprint === normalizedSegment.fingerprint
                    && existingSegment.isFinal === normalizedSegment.isFinal
                ) {
                    return currentSegments;
                }

                if (existingSegment.isFinal && !normalizedSegment.isFinal) {
                    return currentSegments;
                }

                nextSegments[matchedIndex] = {
                    ...normalizedSegment,
                    id: existingSegment.id,
                    sequence: existingSegment.sequence,
                };

                return nextSegments.sort((left, right) => {
                    const sequenceDelta = (left.sequence ?? left.id ?? 0) - (right.sequence ?? right.id ?? 0);

                    return 0 !== sequenceDelta ? sequenceDelta : left.id - right.id;
                });
            });
        };

        if ('function' === typeof flushSync) {
            flushSync(update);
            return;
        }

        update();
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
        setPostCallSummary(null);
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
            setCallSessionId(payload.callSessionId ?? null);
            setTranscriptTopic(payload.transcriptTopic ?? (payload.callSessionId ? `/poc/browser-softphone/${payload.callSessionId}/transcript` : null));
            setTranscriptStreamUrl(payload.transcriptStreamUrl ?? (payload.callSessionId ? `/api/poc/browser-softphone/${payload.callSessionId}/transcript/stream` : null));
            setTranscriptStatus(payload.transcriptTopic ? `Transcript topic: ${payload.transcriptTopic}` : 'Transcript stream ready.');
            setTranscriptSegments([]);
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
            resetSession({ showSummary: false });
        } finally {
            setBusy(false);
        }
    };

    const toggleMute = () => {
        setMuted((currentMuted) => !currentMuted);
    };

    const toggleSettings = () => {
        setSettingsOpen((current) => !current);
    };

    const closeSettings = () => {
        setSettingsOpen(false);
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

    const handleDiagnosticsToggle = (event) => {
        const { checked } = event.currentTarget;
        persistPreferences((currentPreferences) => SoftphonePreferences.setDiagnosticsEnabled(checked, currentPreferences));
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
    const diagnosticsEnabled = SoftphonePreferences.getDiagnosticsEnabled(preferences);
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
            BrowserSoftphoneTelnyxProvider,
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
                callSessionId,
                ringbackController: ringbackControllerRef.current,
                localStream,
                selectedSpeakerId: effectiveSpeakerId,
                speakerRoutingSupported,
                speakerVolume,
                muted,
                onSessionEnd: (sessionOptions) => resetSession({
                    ...sessionOptions,
                    preserveTranscript: true === sessionOptions?.showSummary,
                }),
                onCallIdResolved: handleCallIdResolved,
                onLog: appendLog,
                onStatusChange: setStatus,
                onConnectionStateChange: setConnectionState,
                onAudioWarning: setAudioStatus,
                onToggleMute: toggleMute,
            }),
        );
    }, [
        callSessionId,
        credential,
        dialRequest,
        effectiveSpeakerId,
        localStream,
        muted,
        postCallSummary,
        speakerRoutingSupported,
        speakerVolume,
        transcriptSegments,
        transcriptStatus,
        transcriptTopic,
    ]);

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
                React.createElement('button', {
                    type: 'button',
                    className: settingsOpen ? 'btn btn-dark btn-lg' : 'btn btn-outline-dark btn-lg',
                    onClick: toggleSettings,
                    'aria-expanded': settingsOpen,
                }, 'Settings ⚙'),
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
                    { className: 'border rounded-4 p-3 bg-body-tertiary h-100 position-relative' },
                    React.createElement('div', { className: 'small text-secondary mb-1' }, 'Status'),
                    React.createElement('div', { className: 'alert alert-info mb-3' }, status),
                    React.createElement(BrowserSoftphoneSettingsPanel, {
                        isMobile: isMobileLayout,
                        isOpen: settingsOpen,
                        busy,
                        audioStatus,
                        audioStatusClass,
                        microphones,
                        speakers,
                        speakerRoutingSupported,
                        effectiveMicrophoneId,
                        effectiveSpeakerId,
                        selectedMicLabel,
                        selectedSpeakerLabel,
                        speakerVolume,
                        diagnosticsEnabled,
                        audioSettingsPreference,
                        appliedAudioSummary,
                        onToggleOpen: toggleSettings,
                        onClose: closeSettings,
                        onRefreshDevices: refreshDevices,
                        onMicChange: handleMicChange,
                        onSpeakerChange: handleSpeakerChange,
                        onSpeakerVolumeChange: handleSpeakerVolumeChange,
                        onAudioSettingChange: handleAudioSettingChange,
                        onDiagnosticsToggle: handleDiagnosticsToggle,
                        onTestSpeaker: playSpeakerTestTone,
                    }),
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
                React.createElement(
                    'div',
                    { className: 'border rounded-4 p-3 bg-body-tertiary h-100' },
                    credential && dialRequest
                        ? memoizedSession
                        : React.createElement(
                            'div',
                            { className: 'text-secondary' },
                            'Press Browser Call to request a token and start the Telnyx React client.',
                        ),
                    React.createElement(BrowserSoftphoneTranscriptPanel, {
                        status: transcriptStatus,
                        topic: transcriptTopic,
                        segments: transcriptSegments,
                        onClear: () => setTranscriptSegments([]),
                    }),
                    React.createElement(BrowserSoftphonePostCallSummaryPanel, {
                        summary: postCallSummary,
                    }),
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
