import 'leaflet/dist/leaflet.css';

import React from 'react';
import { createRoot } from 'react-dom/client';
import AuthGate from './AuthGate';

const rootElement = document.getElementById('app');

if (rootElement) {
    const root = createRoot(rootElement);
    root.render(
        <React.StrictMode>
            <AuthGate />
        </React.StrictMode>
    );
}