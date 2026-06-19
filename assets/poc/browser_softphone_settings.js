import React from 'react';

function valueOrNA(value) {
    if (null === value || 'undefined' === typeof value || '' === value) {
        return 'N/A';
    }

    return value;
}

function settingStateLabel(enabled) {
    if ('boolean' !== typeof enabled) {
        return 'N/A';
    }

    return enabled ? 'Enabled' : 'Disabled';
}

function DiagnosticsDetails({
    diagnosticsEnabled,
    audioSettingsPreference,
    appliedAudioSummary,
    selectedMicLabel,
    selectedSpeakerLabel,
}) {
    return React.createElement(
        'details',
        { className: 'border rounded-3 p-3 mb-2 bg-light' },
        React.createElement('summary', { className: 'fw-semibold' }, 'Diagnostics'),
        React.createElement(
            'div',
            { className: 'small text-secondary mt-2 mb-2' },
            `Diagnostics ${diagnosticsEnabled ? 'enabled' : 'disabled'}`,
        ),
        React.createElement(
            'div',
            { className: 'row g-2 mt-2 small' },
            [
                ['Codec', valueOrNA(appliedAudioSummary?.trackSettings?.codec)],
                ['Latency', valueOrNA(appliedAudioSummary?.trackSettings?.latency)],
                ['Jitter', valueOrNA(appliedAudioSummary?.trackSettings?.jitter)],
                ['Packet Loss', valueOrNA(appliedAudioSummary?.trackSettings?.packetLoss)],
                ['Microphone', valueOrNA(selectedMicLabel)],
                ['Speaker', valueOrNA(selectedSpeakerLabel)],
                ['Noise Suppression', settingStateLabel(audioSettingsPreference?.noiseSuppression)],
                ['Echo Cancellation', settingStateLabel(audioSettingsPreference?.echoCancellation)],
                ['Auto Gain Control', settingStateLabel(audioSettingsPreference?.autoGainControl)],
            ].map(([label, value]) => React.createElement(
                'div',
                { key: label, className: 'col-12 col-md-6' },
                React.createElement('div', { className: 'text-secondary' }, label),
                React.createElement('div', { className: 'fw-semibold' }, value),
            )),
        ),
    );
}

function SettingsContent({
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
    onRefreshDevices,
    onMicChange,
    onSpeakerChange,
    onSpeakerVolumeChange,
    onAudioSettingChange,
    onDiagnosticsToggle,
    onTestSpeaker,
}) {
    return React.createElement(
        React.Fragment,
        null,
        React.createElement(
            'div',
            { className: 'd-flex justify-content-between align-items-center gap-2 mb-2' },
            React.createElement('div', { className: 'fw-semibold' }, 'Audio Devices'),
            React.createElement('button', {
                type: 'button',
                className: 'btn btn-outline-secondary btn-sm',
                onClick: onRefreshDevices,
                disabled: busy,
            }, 'Refresh Devices'),
        ),
        React.createElement('div', { className: `small mb-3 ${audioStatusClass}` }, audioStatus),
        React.createElement('div', { className: 'small text-secondary mb-1' }, 'Microphone'),
        React.createElement(
            'select',
            {
                className: 'form-select form-select-sm mb-2',
                'aria-label': 'Microphone',
                value: effectiveMicrophoneId,
                onChange: onMicChange,
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
                'aria-label': 'Speaker',
                value: effectiveSpeakerId,
                onChange: onSpeakerChange,
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
        React.createElement('div', { className: 'small text-secondary mb-2' }, selectedSpeakerLabel),
        React.createElement('div', { className: 'small text-secondary mb-1' }, `Speaker Volume ${Math.round((Number(speakerVolume) || 0) * 100)}%`),
        React.createElement('input', {
            type: 'range',
            className: 'form-range mb-2',
            'aria-label': 'Speaker Volume',
            min: '0',
            max: '1',
            step: '0.05',
            value: String(speakerVolume),
            onChange: onSpeakerVolumeChange,
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
                    onChange: onAudioSettingChange,
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
                    onChange: onAudioSettingChange,
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
                    onChange: onAudioSettingChange,
                    disabled: busy,
                }),
                React.createElement('label', { className: 'form-check-label', htmlFor: 'auto-gain-toggle' }, 'Auto gain control'),
            ),
        ),
        React.createElement(
            'div',
            { className: 'form-check form-switch mb-2' },
            React.createElement('input', {
                id: 'diagnostics-toggle',
                type: 'checkbox',
                className: 'form-check-input',
                checked: diagnosticsEnabled,
                onChange: onDiagnosticsToggle,
                disabled: busy,
                'aria-label': 'Diagnostics enabled',
            }),
            React.createElement('label', { className: 'form-check-label', htmlFor: 'diagnostics-toggle' }, 'Diagnostics enabled'),
        ),
        React.createElement(
            'div',
            { className: 'border rounded-3 p-3 mb-2 bg-dark text-light' },
            React.createElement('div', { className: 'small text-info mb-2' }, 'Applied Audio Track Settings'),
            !diagnosticsEnabled
                ? React.createElement('div', { className: 'text-secondary' }, 'Diagnostics are disabled.')
                : appliedAudioSummary
                ? React.createElement(
                    'pre',
                    { className: 'mb-0 text-light', style: { whiteSpace: 'pre-wrap' } },
                    JSON.stringify(appliedAudioSummary, null, 2),
                )
                : React.createElement('div', { className: 'text-secondary' }, 'No call audio stream has been captured yet.'),
            diagnosticsEnabled && appliedAudioSummary?.warnings?.length
                ? React.createElement(
                    'div',
                    { className: 'alert alert-warning mt-2 mb-0 py-2' },
                    appliedAudioSummary.warnings.join(' '),
                )
                : null,
        ),
        React.createElement(
            'div',
            { className: 'd-flex flex-wrap gap-2 mb-2' },
            React.createElement('button', {
                type: 'button',
                className: 'btn btn-outline-secondary btn-sm',
                onClick: onTestSpeaker,
            }, 'Test Speaker'),
        ),
        !speakerRoutingSupported
            ? React.createElement('div', { className: 'small text-secondary mt-2' }, 'Speaker selection is not supported by this browser.')
            : null,
            React.createElement(DiagnosticsDetails, {
            diagnosticsEnabled,
            audioSettingsPreference,
            appliedAudioSummary,
            selectedMicLabel,
            selectedSpeakerLabel,
        }),
    );
}

export default function BrowserSoftphoneSettingsPanel(props) {
    const {
        isMobile,
        isOpen,
        onToggleOpen,
        onClose,
    } = props;

    if (isMobile) {
        if (!isOpen) {
            return null;
        }

        return React.createElement(
            'div',
            {
                className: 'position-fixed top-0 start-0 w-100 h-100',
                style: { zIndex: 1055 },
            },
            React.createElement('button', {
                type: 'button',
                className: 'position-absolute top-0 start-0 w-100 h-100 border-0 bg-dark',
                style: { opacity: 0.5 },
                onClick: onClose,
                'aria-label': 'Close settings',
            }),
            React.createElement(
                'div',
                {
                    role: 'dialog',
                    'aria-modal': 'true',
                    className: 'position-absolute bottom-0 start-0 w-100 bg-body rounded-top-4 shadow-lg p-3',
                    style: {
                        maxHeight: '85vh',
                        overflowY: 'auto',
                    },
                },
                React.createElement(
                    'div',
                    { className: 'd-flex justify-content-between align-items-center gap-2 mb-3' },
                    React.createElement('div', null,
                        React.createElement('div', { className: 'text-uppercase text-secondary small fw-semibold' }, 'Settings'),
                        React.createElement('div', { className: 'fw-semibold' }, 'Audio Devices, Audio Processing, Diagnostics'),
                    ),
                    React.createElement('button', {
                        type: 'button',
                        className: 'btn btn-outline-secondary btn-sm',
                        onClick: onClose,
                    }, 'Close'),
                ),
                React.createElement(SettingsContent, props),
            ),
        );
    }

    return React.createElement(
        'div',
        { className: 'border rounded-4 p-3 bg-body-tertiary mb-3' },
        React.createElement(
            'div',
            { className: 'd-flex justify-content-between align-items-center gap-2 mb-2' },
            React.createElement('div', null,
                React.createElement('div', { className: 'text-uppercase text-secondary small fw-semibold' }, 'Settings'),
                React.createElement('div', { className: 'fw-semibold' }, 'Audio Devices, Audio Processing, Diagnostics'),
            ),
            React.createElement('button', {
                type: 'button',
                className: 'btn btn-outline-secondary btn-sm',
                onClick: onToggleOpen,
                'aria-expanded': isOpen,
            }, isOpen ? 'Collapse' : 'Open'),
        ),
        isOpen
            ? React.createElement(SettingsContent, props)
            : React.createElement(
                'div',
                { className: 'small text-secondary' },
                'Collapsed by default.',
            ),
    );
}
