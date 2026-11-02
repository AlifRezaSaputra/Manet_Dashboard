import 'leaflet/dist/leaflet.css';

import React from 'react';
import { createRoot } from 'react-dom/client';
import Dashboard from './Dashboard';

const rootElement = document.getElementById('app');

if (rootElement) {
    const root = createRoot(rootElement);
    root.render(
        <React.StrictMode>
            <Dashboard />
        </React.StrictMode>
    );
}