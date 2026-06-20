import React, { useEffect, useRef } from 'react';

function normalizeTranscriptSpeaker(speaker) {
    const normalized = 'string' === typeof speaker ? speaker.trim().toLowerCase() : '';

    return ['csr', 'agent', 'operator', 'representative'].includes(normalized) ? 'csr' : 'customer';
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

function transcriptSegmentMergeKey(segment) {
    if (!segment) {
        return null;
    }

    return segment.sourceEventId ?? segment.fingerprint ?? `segment:${segment.id}`;
}

function transcriptSegmentKey(segment) {
    if (!segment) {
        return null;
    }

    return transcriptSegmentMergeKey(segment);
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

export default function TranscriptFeedPanel({
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
