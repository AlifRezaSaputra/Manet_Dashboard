import React, { useState } from 'react';
import { login } from './api';

export default function Login({ onSuccess, notice }) {
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        if (loading || !password) return;
        setLoading(true);
        setError(null);
        try {
            await login(password);
            onSuccess();
        } catch (err) {
            setError(err.message);
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-900 flex items-center justify-center p-4 font-sans">
            <div className="w-full max-w-sm bg-white rounded-2xl shadow-2xl p-8">
                <h1 className="text-center text-xl font-black font-mono uppercase tracking-wider text-slate-900">
                    MANET Dashboard
                </h1>
                <p className="mt-1 text-center text-[11px] font-mono text-slate-500 uppercase tracking-wider">
                    Basecamp Pendakian Puncak Wangu
                </p>

                {notice && !error && (
                    <div className="mt-5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-mono px-3 py-2 text-center">
                        {notice}
                    </div>
                )}

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <div>
                        <label htmlFor="pw" className="block text-xs font-mono font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Password
                        </label>
                        <input
                            id="pw"
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            autoFocus
                            autoComplete="off"
                            disabled={loading}
                            className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm font-mono text-slate-900 bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500"
                        />
                    </div>

                    {error && (
                        <div role="alert" className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-mono px-3 py-2 text-center">
                            {error}
                        </div>
                    )}

                    <button
                        type="submit"
                        disabled={loading || !password}
                        className="w-full py-2.5 rounded-xl bg-slate-900 hover:bg-slate-700 text-white font-mono font-bold text-sm transition-all disabled:opacity-50 active:scale-95"
                    >
                        {loading ? 'MEMERIKSA...' : 'LOGIN'}
                    </button>
                </form>
            </div>
        </div>
    );
}