import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'status',
        'connectionState',
        'callState',
        'timer',
        'recordingState',
        'callButton',
        'recordingButton',
        'muteButton',
        'hangupButton',
        'keypadButton',
        'keypad',
        'digits',
        'contact',
        'primaryPhone',
    ];

    static values = {
        browserCallPrepareUrl: String,
        browserSessionAllocateUrlTemplate: String,
        browserSessionEventUrlTemplate: String,
        csrfToken: String,
        eventStreamUrlTemplate: String,
        propertyId: String,
        contactId: String,
    };

    connect() {
        this.eventStream = null;
        this.telnyxClient = null;
        this.activeCall = null;
        this.localStream = null;
        this.browserSessionToken = null;
        this.providerSessionId = null;
        this.approvedDestinationNumber = null;
        this.timerHandle = null;
        this.timerStartedAt = null;
        this.connected = false;
        this.muted = false;
        this.keypadOpen = false;
        this.recording = false;
        this.activeDigits = '';
        this.renderIdleState();
    }

    disconnect() {
        this.teardownStream();
        this.disconnectTelnyx();
        this.stopLocalMedia();
        if (this.timerHandle) {
            window.clearInterval(this.timerHandle);
        }
    }

    async startCall(event) {
        event?.preventDefault();

        const contactId = this.contactTarget?.value;
        const primaryPhone = this.primaryPhoneTarget?.value;
        if (!contactId || !primaryPhone) {
            this.setStatus('Select a contact with a phone number first.');
            return;
        }

        // If already connected, trigger outbound dial via server
        if (this.connected && null !== this.providerSessionId) {
            await this.dialViaServer();
            return;
        }

        this.setBusy(true);
        this.setConnectionState('Requesting microphone permission...');
        this.setCallState('Idle');

        try {
            await this.requestMicrophone();
            const preparePayload = await this.postJson(this.browserCallPrepareUrlValue, {
                _token: this.csrfTokenValue,
            });
            if (!preparePayload.ok) {
                throw new Error(preparePayload.error ?? 'Browser call could not be prepared.');
            }

            const allocatePayload = await this.postJson(
                this.browserSessionAllocateUrlTemplateValue.replace('{providerSessionId}', preparePayload.providerSessionId ?? preparePayload.callSession?.providerSessionId ?? ''),
                {},
            );
            if (!allocatePayload.ok) {
                throw new Error(allocatePayload.error ?? 'Browser softphone session could not be allocated.');
            }

            this.providerSessionId = preparePayload.providerSessionId ?? preparePayload.callSession?.providerSessionId ?? null;
            this.approvedDestinationNumber = preparePayload.approvedDestinationNumber ?? preparePayload.callSession?.clientPhoneNumber ?? null;
            this.browserSessionToken = allocatePayload.browserSessionToken ?? null;
            this.setConnectionState('Connecting to Telnyx...');
            await this.reportConnectionEvent('sdk_connecting', {
                browserSessionToken: this.browserSessionToken,
                providerSessionId: this.providerSessionId,
            });

            const TelnyxRTC = await this.resolveTelnyxRTC();
            if ('function' !== typeof TelnyxRTC) {
                throw new Error('Telnyx WebRTC SDK could not be loaded.');
            }
            this.telnyxClient = new TelnyxRTC({
                login_token: preparePayload.token,
                keepConnectionAliveOnSocketClose: true,
                mutedMicOnStart: false,
                debug: false,
            });
            this.registerTelnyxEvents();
            this.telnyxClient.connect();

            const streamUrl = preparePayload.statusStreamUrl
                ?? this.eventStreamUrlTemplateValue.replace('{providerSessionId}', this.providerSessionId ?? '');
            if (streamUrl && streamUrl.includes('/api/calls/')) {
                this.openStream(streamUrl);
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Browser softphone connection failed.';
            this.setStatus(message);
            this.setConnectionState('Connection failed.');
            await this.reportConnectionEvent('sdk_error', {
                browserSessionToken: this.browserSessionToken,
                message,
                errorCode: error instanceof Error ? error.name : null,
            });
            this.setBusy(false);
            this.stopLocalMedia();
            this.disconnectTelnyx();
        }
    }

    toggleMute() {
        if (!this.connected) {
            return;
        }

        this.muted = !this.muted;
        this.applyMuteState();
        this.muteButtonTarget.textContent = this.muted ? 'Unmute' : 'Mute';
        this.muteButtonTarget.classList.toggle('active', this.muted);
        this.setStatus(this.muted ? 'Microphone muted.' : 'Microphone unmuted.');

        // Phase 9J: sync mute state to platform (Telnyx Call Control API).
        if (null !== this.providerSessionId) {
            const dialUrl = `/crm/properties/${this.propertyIdValue}/contacts/${this.contactIdValue}/browser-call/mute`;
            fetch(dialUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    _token: this.csrfTokenValue,
                    providerSessionId: this.providerSessionId,
                    action: this.muted ? 'mute' : 'unmute',
                }),
            }).catch(() => {
                // Best-effort platform sync; local media track already toggled.
            });
        }

        // Also mute via Telnyx SDK call object if available (immediate effect).
        if (this.activeCall && 'function' === typeof this.activeCall.mute) {
            this.activeCall.mute(this.muted);
        }
    }

    toggleKeypad() {
        this.keypadOpen = !this.keypadOpen;
        this.keypadTarget.hidden = !this.keypadOpen;
        this.keypadButtonTarget.classList.toggle('active', this.keypadOpen);
        this.setStatus(this.keypadOpen ? 'Keypad opened.' : 'Keypad closed.');
    }

    async pressDigit(event) {
        if (!this.connected) {
            return;
        }

        const digit = event.currentTarget?.dataset?.digit ?? '';
        if ('' === digit) {
            return;
        }

        this.activeDigits += digit;
        this.digitsTarget.value = this.activeDigits;
        this.setStatus(`Sent tone ${digit}.`);

        // Phase 9J: send DTMF to platform and browser-side SDK.
        if (null !== this.providerSessionId) {
            const dialUrl = `/crm/properties/${this.propertyIdValue}/contacts/${this.contactIdValue}/browser-call/dtmf`;
            try {
                await fetch(dialUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        _token: this.csrfTokenValue,
                        providerSessionId: this.providerSessionId,
                        digits: digit,
                    }),
                });
            } catch {
                // Best-effort platform DTMF; SDK below handles local.
            }
        }

        // Also send via Telnyx SDK call object for immediate UX feedback.
        if (this.activeCall && 'function' === typeof this.activeCall.sendDtmf) {
            this.activeCall.sendDtmf(digit);
        }
    }

    async toggleRecording() {
        if (!this.connected) {
            return;
        }

        const wasRecording = this.recording;
        this.recording = !this.recording;
        this.recordingButtonTarget.textContent = this.recording ? 'Stop Recording' : 'Start Recording';
        this.recordingButtonTarget.classList.toggle('btn-danger', this.recording);
        this.recordingButtonTarget.classList.toggle('btn-outline-danger', !this.recording);

        if (null !== this.providerSessionId) {
            const dialUrl = `/crm/properties/${this.propertyIdValue}/contacts/${this.contactIdValue}/browser-call/recording`;
            try {
                const response = await fetch(dialUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        _token: this.csrfTokenValue,
                        providerSessionId: this.providerSessionId,
                        action: this.recording ? 'start' : 'stop',
                    }),
                });
                const payload = await response.json();

                if (payload.ok) {
                    this.recordingStateTarget.textContent = payload.recordingState === 'active'
                        ? 'Recording active'
                        : 'Recording inactive';
                    this.setStatus(payload.recordingState === 'active'
                        ? 'Recording started.'
                        : 'Recording stopped.');
                    return; // Accept server as source of truth for recording state.
                }
            } catch {
                // Best-effort: fall back to local toggle below.
            }
        }

        // Fallback to local state if server call fails.
        this.recordingStateTarget.textContent = this.recording ? 'Recording active' : 'Recording inactive';
        this.setStatus(this.recording ? 'Recording started locally.' : 'Recording stopped locally.');
    }

    async hangup(event) {
        event?.preventDefault();

        // Phase 9J: terminate the real call on platform first (server-side hangup).
        if (null !== this.providerSessionId) {
            const dialUrl = `/crm/properties/${this.propertyIdValue}/contacts/${this.contactIdValue}/browser-call/hangup`;
            try {
                await fetch(dialUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        _token: this.csrfTokenValue,
                        providerSessionId: this.providerSessionId,
                    }),
                });
            } catch {
                // Best-effort platform hangup; continue with local teardown.
            }

            // Send hangup to browser softphone session for timeline sync.
            await this.reportConnectionEvent('call.hangup', {
                browserSessionToken: this.browserSessionToken,
            });
        }

        this.teardownStream();
        this.teardownCall();
        this.disconnectTelnyx();
        this.stopLocalMedia();
        this.stopTimer();
        this.connected = false;
        this.recording = false;
        this.muted = false;
        this.keypadOpen = false;
        this.activeDigits = '';
        this.renderIdleState();
        this.setStatus('Softphone disconnected.');
    }

    async requestMicrophone() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Microphone access is not available in this browser.');
        }

        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Microphone access was denied.';
            await this.reportConnectionEvent('mic_denied', {
                browserSessionToken: this.browserSessionToken,
                message,
                errorCode: error instanceof Error ? error.name : null,
            });
            throw new Error(message);
        }
    }

    registerTelnyxEvents() {
        if (!this.telnyxClient?.on) {
            throw new Error('Telnyx WebRTC SDK did not expose an event API.');
        }

        // Phase 9I: When SDK connects, capture Telnyx connection ID and report ready state.
        // Do NOT auto-dial — the CSR clicks "Place Browser Call" to initiate dialing server-side.
        this.telnyxClient.on('telnyx.ready', async () => {
            try {
                this.connected = true;
                this.setConnectionState('Connected to Telnyx.');
                this.setStatus('Browser softphone connected. Click "Place Browser Call" to dial.');

                // Capture telnyx connection ID from the SDK's active WebSocket connection
                const telnyxConnectionId = (this.telnyxClient?.connection?.id ?? null);

                await this.reportConnectionEvent('sdk_ready', {
                    browserSessionToken: this.browserSessionToken,
                    telnyxConnectionId: telnyxConnectionId,
                });

                // Re-enable the call button so the CSR can initiate dialing server-side (9I)
                this.callButtonTarget.disabled = false;
                this.callButtonTarget.textContent = 'Place Browser Call';
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Unable to complete browser softphone setup.';
                this.connected = false;
                this.setCallState('Failed');
                this.setStatus(message);
                this.setConnectionState('Setup failed.');
                this.callButtonTarget.disabled = false;
                this.callButtonTarget.textContent = 'Place Browser Call';
            }
        });

        this.telnyxClient.on('telnyx.error', async (error) => {
            const message = error?.message ?? 'Telnyx SDK error.';
            this.setConnectionState(`SDK error: ${message}`);
            this.setStatus(message);
            this.setCallState('Failed');
            await this.reportConnectionEvent('sdk_error', {
                browserSessionToken: this.browserSessionToken,
                message,
                errorCode: error?.code ?? error?.name ?? null,
                meta: { rawError: this.serializeError(error) },
            });
            this.callButtonTarget.disabled = false;
            this.callButtonTarget.textContent = 'Place Browser Call';
        });

        this.telnyxClient.on('telnyx.notification', async (notification) => {
            if ('userMediaError' === notification?.type || 'peerConnectionFailedError' === notification?.type || 'signalingStateClosed' === notification?.type) {
                const message = notification?.call?.state ?? notification?.call?.error ?? 'Browser call failed.';
                this.setCallState('Failed');
                this.setConnectionState(`Call failed: ${message}`);
                await this.reportCallEvent('call.failed', {
                    browserSessionToken: this.browserSessionToken,
                    callId: notification?.call?.id ?? this.activeCall?.id ?? null,
                    destinationNumber: this.approvedDestinationNumber,
                    errorCode: notification?.type ?? null,
                    errorMessage: message,
                    meta: {
                        notificationType: notification?.type ?? null,
                    },
                });
            }
        });
    }

    // Phase 9I: Initiate outbound dial via server-side Telnyx API call.
    // The browser softphone must be connected (telnyx.ready has fired) before this is called.
    async dialViaServer() {
        if (!this.providerSessionId) {
            throw new Error('No active call session to place the outbound dial.');
        }

        if (!this.approvedDestinationNumber) {
            throw new Error('Approved destination number is missing.');
        }

        this.setCallState('Dialing');
        this.setStatus(`Requesting outbound call to ${this.approvedDestinationNumber}...`);
        this.callButtonTarget.disabled = true;
        this.callButtonTarget.textContent = 'Dialing...';

        try {
            const dialUrl = `/crm/properties/${this.propertyIdValue}/contacts/${this.contactIdValue}/browser-call/dial`;
            const payload = {
                _token: this.csrfTokenValue,
                providerSessionId: this.providerSessionId,
            };
            const response = await this.postJson(dialUrl, payload);

            if (!response.ok) {
                throw new Error(response.error ?? 'Outbound call could not be initiated.');
            }

            // Update local state from server response
            this.setCallState('Ringing');
            this.setStatus('Call is ringing...');

            return response;
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Outbound dial failed.';
            this.setCallState('Failed');
            this.setStatus(message);
            this.callButtonTarget.disabled = false;
            this.callButtonTarget.textContent = 'Place Browser Call';
            await this.reportConnectionEvent('call.failed', {
                browserSessionToken: this.browserSessionToken,
                message,
                errorCode: error instanceof Error ? error.name : null,
                destinationNumber: this.approvedDestinationNumber,
            });
            throw new Error(message);
        }
    }

    async reportConnectionEvent(event, payload = {}) {
        if (!this.browserSessionToken) {
            return;
        }

        const url = this.browserSessionEventUrlTemplateValue.replace('{sessionToken}', this.browserSessionToken);
        try {
            await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    event,
                    ...payload,
                }),
            });
        } catch {
            // Best-effort telemetry only.
        }
    }

    async reportCallEvent(event, payload = {}) {
        if (!this.browserSessionToken) {
            return;
        }

        const url = this.browserSessionEventUrlTemplateValue.replace('{sessionToken}', this.browserSessionToken);
        try {
            await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    event,
                    ...payload,
                }),
            });
        } catch {
            // Best-effort telemetry only.
        }
    }

    async placeOutboundCall() {
        if (!this.telnyxClient || 'function' !== typeof this.telnyxClient.newCall) {
            throw new Error('Telnyx WebRTC SDK is not ready to place a call.');
        }

        if (!this.approvedDestinationNumber) {
            throw new Error('Approved destination number is missing.');
        }

        this.setCallState('Dialing');
        this.setStatus(`Dialing ${this.approvedDestinationNumber}...`);
        this.activeCall = this.telnyxClient.newCall({
            destinationNumber: this.approvedDestinationNumber,
            audio: true,
        });

        this.registerCallObjectEvents(this.activeCall);
    }

    registerCallObjectEvents(call) {
        if (!call?.on) {
            throw new Error('Telnyx call object did not expose an event API.');
        }

        call.on('telnyx.notification', async (notification) => {
            if ('callUpdate' !== notification?.type || !notification.call) {
                return;
            }

            await this.handleCallUpdate(notification.call, notification);
        });
    }

    async handleCallUpdate(call, notification = {}) {
        const state = call.state ?? notification?.call?.state ?? '';
        const callId = call.id ?? notification?.call?.id ?? null;
        const destinationNumber = call.remotePartyNumber ?? this.approvedDestinationNumber;

        if ('requesting' === state) {
            this.setCallState('Dialing');
            this.setStatus(`Dialing ${destinationNumber}...`);
            await this.reportCallEvent('call.requesting', {
                browserSessionToken: this.browserSessionToken,
                callId,
                destinationNumber,
                meta: {
                    direction: call.direction ?? 'outbound',
                    state,
                },
            });
            return;
        }

        if ('ringing' === state) {
            this.setCallState('Ringing');
            this.setStatus('Ringback in progress...');
            await this.reportCallEvent('call.ringing', {
                browserSessionToken: this.browserSessionToken,
                callId,
                destinationNumber,
                meta: {
                    direction: call.direction ?? 'outbound',
                    state,
                },
            });
            return;
        }

        if ('active' === state) {
            if (!this.timerHandle) {
                this.timerStartedAt = new Date();
                this.startTimer();
            }
            this.setCallState('Connected');
            this.setStatus('Browser call connected.');
            this.hangupButtonTarget.disabled = false;
            // Phase 9J: enable mute, keypad, and recording controls once call is connected.
            this.muteButtonTarget.disabled = false;
            this.keypadButtonTarget.disabled = false;
            this.recordingButtonTarget.disabled = false;
            await this.reportCallEvent('call.active', {
                browserSessionToken: this.browserSessionToken,
                callId,
                destinationNumber,
                meta: {
                    direction: call.direction ?? 'outbound',
                    state,
                },
            });
            return;
        }

        if ('hangup' === state || 'destroy' === state) {
            this.setCallState('Ended');
            this.setStatus('Browser call ended.');
            this.stopTimer();
            this.teardownCall();
            this.connected = false;
            this.callButtonTarget.disabled = false;
            this.callButtonTarget.textContent = 'Place Browser Call';
            await this.reportCallEvent('call.hangup', {
                browserSessionToken: this.browserSessionToken,
                callId,
                destinationNumber,
                meta: {
                    direction: call.direction ?? 'outbound',
                    state,
                },
            });
            return;
        }

        if ('recovering' === state) {
            this.setCallState('Connecting');
        }
    }

    async resolveTelnyxRTC() {
        if ('function' === typeof globalThis.TelnyxRTC) {
            return globalThis.TelnyxRTC;
        }

        const module = await import('@telnyx/webrtc');
        return module.TelnyxRTC ?? module.default ?? null;
    }

    async postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const text = await response.text();
        let json = {};
        try {
            json = '' !== text ? JSON.parse(text) : {};
        } catch {
            json = {};
        }

        if (!response.ok) {
            throw new Error(json.error ?? 'Request failed.');
        }

        return json;
    }

    openStream(url) {
        this.teardownStream();
        this.eventStream = new EventSource(url);
        this.eventStream.addEventListener('ready', () => {
            this.setStatus('Browser call stream ready.');
        });
        this.eventStream.addEventListener('call.initiated', () => {
            this.setStatus('Call initiated.');
        });
        this.eventStream.addEventListener('call.ringing', () => {
            this.setStatus('Call ringing.');
        });
        this.eventStream.addEventListener('call.answered', () => {
            this.setStatus('Call answered.');
        });
        this.eventStream.addEventListener('call.completed', () => {
            this.setStatus('Call completed.');
        });
        this.eventStream.addEventListener('call.hangup', () => {
            this.setStatus('Call ended.');
        });
        this.eventStream.addEventListener('browser_call.requesting', () => {
            this.setCallState('Dialing');
        });
        this.eventStream.addEventListener('browser_call.ringing', () => {
            this.setCallState('Ringing');
        });
        this.eventStream.addEventListener('browser_call.active', () => {
            this.setCallState('Connected');
        });
        this.eventStream.addEventListener('browser_call.hangup', () => {
            this.setCallState('Ended');
        });
        this.eventStream.addEventListener('browser_call.failed', () => {
            this.setCallState('Failed');
        });
        this.eventStream.addEventListener('close', () => {
            this.setStatus('Browser call stream closed.');
        });
    }

    teardownStream() {
        if (this.eventStream) {
            this.eventStream.close();
            this.eventStream = null;
        }
    }

    disconnectTelnyx() {
        if (this.telnyxClient?.disconnect) {
            this.telnyxClient.disconnect();
        } else if (this.telnyxClient?.close) {
            this.telnyxClient.close();
        }
        this.telnyxClient = null;
    }

    teardownCall() {
        this.activeCall = null;
        this.hangupButtonTarget.disabled = true;
        this.muteButtonTarget.disabled = true;
        this.keypadButtonTarget.disabled = true;
        this.recordingButtonTarget.disabled = true;
    }

    stopLocalMedia() {
        if (this.localStream) {
            for (const track of this.localStream.getTracks()) {
                track.stop();
            }
            this.localStream = null;
        }
    }

    applyMuteState() {
        if (!this.localStream) {
            return;
        }

        for (const track of this.localStream.getAudioTracks()) {
            track.enabled = !this.muted;
        }
    }

    serializeError(error) {
        if (!error || 'object' !== typeof error) {
            return error;
        }

        return {
            name: error.name ?? null,
            message: error.message ?? null,
            code: error.code ?? null,
        };
    }

    startTimer() {
        if (this.timerHandle) {
            window.clearInterval(this.timerHandle);
        }
        this.updateTimer();
        this.timerHandle = window.setInterval(() => this.updateTimer(), 1000);
    }

    stopTimer() {
        if (this.timerHandle) {
            window.clearInterval(this.timerHandle);
            this.timerHandle = null;
        }
        this.timerStartedAt = null;
        this.timerTarget.textContent = '00:00';
    }

    updateTimer() {
        if (!this.timerStartedAt) {
            this.timerTarget.textContent = '00:00';
            return;
        }

        const elapsedSeconds = Math.max(0, Math.floor((Date.now() - this.timerStartedAt.getTime()) / 1000));
        const minutes = String(Math.floor(elapsedSeconds / 60)).padStart(2, '0');
        const seconds = String(elapsedSeconds % 60).padStart(2, '0');
        this.timerTarget.textContent = `${minutes}:${seconds}`;
    }

    setBusy(isBusy) {
        this.callButtonTarget.disabled = isBusy;
        this.callButtonTarget.textContent = isBusy ? 'Connecting...' : 'Place Browser Call';
        if (isBusy) {
            this.statusTarget.textContent = 'Connecting browser softphone...';
        }
    }

    setStatus(message) {
        this.statusTarget.textContent = message;
    }

    setConnectionState(message) {
        this.connectionStateTarget.textContent = message;
    }

    setCallState(message) {
        if (this.hasCallStateTarget) {
            this.callStateTarget.textContent = message;
        }
    }

    renderIdleState() {
        this.connected = false;
        this.activeCall = null;
        this.recordingButtonTarget.disabled = true;
        this.hangupButtonTarget.disabled = true;
        this.muteButtonTarget.disabled = true;
        this.keypadButtonTarget.disabled = true;
        this.keypadTarget.hidden = true;
        this.timerTarget.textContent = '00:00';
        this.statusTarget.textContent = 'Ready to connect the browser softphone.';
        this.connectionStateTarget.textContent = 'Disconnected';
        this.setCallState('Idle');
        this.recordingStateTarget.textContent = 'Recording inactive';
        this.callButtonTarget.disabled = false;
        this.callButtonTarget.textContent = 'Place Browser Call';
    }
}
