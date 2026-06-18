import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'callButton',
        'hangupButton',
        'status',
        'connectionState',
        'callState',
        'destination',
        'destinationInput',
        'log',
    ];

    static values = {
        tokenUrl: String,
    };

    connect() {
        this.client = null;
        this.activeCall = null;
        this.pendingOutbound = false;
        this.callRequested = false;
        this.remoteAudioId = 'browser-softphone-poc-remote-audio';
        this.setStatus('Ready to request a Telnyx token.');
        this.setConnectionState('Disconnected');
        this.setCallState('Idle');
        this.log('POC controller connected.');
    }

    disconnect() {
        this.disconnectClient();
    }

    async startCall(event) {
        event?.preventDefault();

        if (this.callRequested) {
            return;
        }

        this.callRequested = true;
        this.pendingOutbound = true;
        this.setBusy(true);
        this.setStatus('Requesting microphone permission...');
        this.log('Starting browser call flow.');

        try {
            await this.requestMicrophonePermission();
            const payload = await this.requestToken();
            this.setStatus('Connecting to Telnyx...');
            this.setConnectionState('Connecting');
            this.setDestination(payload.destinationNumber);
            await this.connectClient(payload.token, payload.destinationNumber, payload.callerNumber);
        } catch (error) {
            const message = this.errorMessage(error, 'Browser call could not be started.');
            this.log('Error during startCall.', { message });
            this.setStatus(message);
            this.setConnectionState('Connection failed');
            this.setCallState('Idle');
            this.callButtonTarget.disabled = false;
            this.hangupButtonTarget.disabled = true;
            this.pendingOutbound = false;
            this.callRequested = false;
            this.disconnectClient();
        }
    }

    async hangup(event) {
        event?.preventDefault();

        if (this.activeCall && 'function' === typeof this.activeCall.hangup) {
            this.log('Sending hangup to active call.');
            try {
                this.activeCall.hangup();
            } catch (error) {
                this.log('Hangup failed.', { message: this.errorMessage(error, 'Hangup failed.') });
            }
        }

        this.activeCall = null;
        this.pendingOutbound = false;
        this.callRequested = false;
        this.setCallState('Idle');
        this.setStatus('Call ended.');
        this.callButtonTarget.disabled = false;
        this.hangupButtonTarget.disabled = true;
        this.setConnectionState('Disconnected');
        this.disconnectClient();
    }

    async pressDigit(event) {
        event?.preventDefault();
        const digit = event.currentTarget?.dataset?.digit ?? '';
        if (!digit || !this.activeCall || 'function' !== typeof this.activeCall.dtmf) {
            return;
        }

        try {
            this.activeCall.dtmf(digit);
            this.log(`DTMF sent: ${digit}`);
            this.setStatus(`Sent DTMF ${digit}.`);
        } catch (error) {
            this.log('DTMF failed.', { digit, message: this.errorMessage(error, 'DTMF failed.') });
        }
    }

    async connectClient(token, destinationNumber, callerNumber) {
        const TelnyxRTC = await this.resolveTelnyxRTC();
        if ('function' !== typeof TelnyxRTC) {
            throw new Error('Telnyx WebRTC SDK could not be loaded.');
        }

        this.client = new TelnyxRTC({
            login_token: token,
            keepConnectionAliveOnSocketClose: true,
        });
        this.client.remoteElement = this.remoteAudioId;

        this.client
            .on('telnyx.ready', () => {
                this.log('telnyx.ready received.');
                this.setConnectionState('Ready');
                if (this.pendingOutbound) {
                    this.placeOutboundCall(destinationNumber, callerNumber);
                }
            })
            .on('telnyx.notification', (notification) => {
                this.log('telnyx.notification', notification);
                if (notification?.call) {
                    this.activeCall = notification.call;
                }
            })
            .on('telnyx.error', (notification) => {
                this.log('telnyx.error', notification);
                this.setStatus('Telnyx reported an error.');
            })
            .on('telnyx.warning', (notification) => {
                this.log('telnyx.warning', notification);
            });

        this.client.connect();
        this.log('Telnyx client connecting.');
    }

    placeOutboundCall(destinationNumber, callerNumber) {
        if (!this.client) {
            throw new Error('Telnyx client is not connected.');
        }

        this.pendingOutbound = false;
        this.setCallState('Dialing...');
        this.setStatus(`Calling ${destinationNumber}...`);
        this.log('Placing outbound call.', { destinationNumber, callerNumber });

        const call = this.client.newCall({
            destinationNumber,
            callerNumber,
            audio: true,
        });

        this.activeCall = call;
        this.callButtonTarget.disabled = true;
        this.hangupButtonTarget.disabled = false;
        this.setCallState('Call started');
        this.log('Outbound call object created.');

        return call;
    }

    async requestToken() {
        const response = await fetch(this.tokenUrlValue, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                destinationNumber: this.hasDestinationInputTarget ? this.destinationInputTarget.value : null,
            }),
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || true !== payload.ok) {
            throw new Error(payload?.error ?? `Token request failed with HTTP ${response.status}.`);
        }

        if ('string' !== typeof payload.token || '' === payload.token.trim()) {
            throw new Error('Token response did not contain a Telnyx login token.');
        }

        return payload;
    }

    async requestMicrophonePermission() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Microphone access is not available in this browser.');
        }

        this.log('Requesting microphone permission.');
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        stream.getTracks().forEach((track) => track.stop());
        this.log('Microphone permission granted.');
    }

    async resolveTelnyxRTC() {
        if ('function' === typeof globalThis.TelnyxRTC) {
            return globalThis.TelnyxRTC;
        }

        const module = await import('@telnyx/webrtc');
        return module.TelnyxRTC ?? module.default ?? null;
    }

    disconnectClient() {
        if (!this.client) {
            return;
        }

        try {
            this.client.disconnect();
        } catch (error) {
            this.log('Telnyx client disconnect failed.', { message: this.errorMessage(error, 'Disconnect failed.') });
        }

        this.client = null;
    }

    setBusy(busy) {
        this.callButtonTarget.disabled = busy;
        this.hangupButtonTarget.disabled = busy;
    }

    setStatus(message) {
        this.statusTarget.textContent = message;
    }

    setConnectionState(message) {
        this.connectionStateTarget.textContent = message;
    }

    setCallState(message) {
        this.callStateTarget.textContent = message;
    }

    setDestination(message) {
        this.destinationTarget.textContent = message;
    }

    log(message, data = null) {
        const line = null === data ? message : `${message} ${JSON.stringify(data, null, 2)}`;
        this.logTarget.textContent = `${this.logTarget.textContent}${this.logTarget.textContent ? '\n' : ''}${line}`;
        this.logTarget.scrollTop = this.logTarget.scrollHeight;
        console.debug('[browser-softphone-poc]', message, data);
    }

    errorMessage(error, fallback) {
        if (error instanceof Error && '' !== error.message) {
            return error.message;
        }

        if ('string' === typeof error && '' !== error) {
            return error;
        }

        return fallback;
    }
}
