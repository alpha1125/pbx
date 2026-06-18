import React from 'react';
import { createRoot } from 'react-dom/client';
import BrowserSoftphoneApp from './browser_softphone_app.js';

function mountBrowserSoftphone() {
    const rootElement = document.getElementById('browser-softphone-root');
    if (!rootElement) {
        return;
    }

    const tokenUrl = rootElement.dataset.tokenUrl || '';
    const defaultDestinationNumber = rootElement.dataset.destinationNumber || '';
    const defaultCallerNumber = rootElement.dataset.callerNumber || '';

    createRoot(rootElement).render(
        React.createElement(React.StrictMode, null,
            React.createElement(BrowserSoftphoneApp, {
                tokenUrl,
                defaultDestinationNumber,
                defaultCallerNumber,
            }),
        ),
    );
}

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', mountBrowserSoftphone, { once: true });
} else {
    mountBrowserSoftphone();
}
