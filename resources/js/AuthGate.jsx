import React, { useState, useEffect } from 'react';
import Login from './Login';
import Dashboard from './Dashboard';
import { logout, setUnauthorizedHandler } from './api';

export default function AuthGate() {
    const [authed, setAuthed] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        setUnauthorizedHandler(() => {
            logout();
            setNotice('Sesi berakhir. Silakan login kembali.');
            setAuthed(false);
        });
    }, []);

    if (!authed) {
        return <Login notice={notice} onSuccess={() => { setNotice(null); setAuthed(true); }} />;
    }

    return <Dashboard />;
}