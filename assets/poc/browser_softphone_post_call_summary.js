import React from 'react';

export function buildMockPostCallSummary(callSessionId, destinationNumber) {
    return {
        callSessionId,
        destinationNumber,
        summary: 'The customer reported an intermittent service issue and asked for a follow-up once the line is stable.',
        customerConcerns: [
            'Intermittent call quality and dropped audio',
            'Needs confirmation that service is restored',
        ],
        actionItems: [
            'Verify service status with the customer',
            'Send a follow-up update after the next monitoring window',
        ],
        keywords: [
            'service quality',
            'follow-up',
            'monitoring',
            'callback',
        ],
    };
}

export default function PostCallSummaryPanel({ summary }) {
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

