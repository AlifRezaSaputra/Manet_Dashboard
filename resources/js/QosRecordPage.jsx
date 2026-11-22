import React, { useState, useEffect, useCallback } from 'react';
import { fetchQosTest, startQosTest, stopQosTest, fetchQosEvents } from './api';

const TOPOLOGY_LABEL = { direct: 'Direct', one_relay: 'One Relay', two_relays: 'Two Relays' };
const STATUS_STYLE = {
    pending: 'bg-slate-500', recording: 'bg-red-600',
    completed: 'bg-emerald-600', failed: 'bg-amber-600',
};

function formatTime(value) {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleString('id-ID', {
        timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    }) + ' WIB';
}

function formatDuration(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = String(Math.floor(s / 3600)).padStart(2, '0');
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const sec = String(s % 60).padStart(2, '0');
    return `${h}:${m}:${sec}`;
}

export default function QosRecordPage({ testId, onBack }) {
    const [test, setTest] = useState(null);
    const [events, setEvents] = useState({ total: 0, by_type: [], latest: [] });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState(null);
    const [confirmStop, setConfirmStop] = useState(false);
    const [now, setNow] = useState(Date.now());

    const loadEvents = useCallback(async () => {
        try {
            setEvents(await fetchQosEvents(testId, 50));
            setError(null);
        } catch (err) {
            setError(err.message);
        }
    }, [testId]);

    // Muat sesi + event sekali
    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const t = await fetchQosTest(testId);
                if (cancelled) return;
                setTest(t);
                await loadEvents();
            } catch (err) {
                if (!cancelled) setError(err.message);
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => { cancelled = true; };
    }, [testId, loadEvents]);

    const recording = test?.status === 'recording';

    // Polling event + timer hanya saat recording
    useEffect(() => {
        if (!recording) return;
        const poll = setInterval(loadEvents, 3000);
        const tick = setInterval(() => setNow(Date.now()), 1000);
        return () => { clearInterval(poll); clearInterval(tick); };
    }, [recording, loadEvents]);

    const doStart = async () => {
        setBusy(true);
        setActionError(null);
        try {
            setTest(await startQosTest(testId));
            await loadEvents();
        } catch (err) {
            setActionError(err.message);
        } finally {
            setBusy(false);
        }
    };

    const doStop = async () => {
        setBusy(true);
        setActionError(null);
        try {
            setTest(await stopQosTest(testId));
            await loadEvents();
            setConfirmStop(false);
        } catch (err) {
            setActionError(err.message); // modal tetap terbuka
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (!confirmStop) return;
        const onKey = (e) => { if (e.key === 'Escape' && !busy) setConfirmStop(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [confirmStop, busy]);

    if (loading) {
        return <main className="flex-1 p-10 text-center text-sm font-mono text-slate-500">Memuat sesi...</main>;
    }

    if (!test) {
        return (
            <main className="flex-1 p-6">
                <div className="bg-red-50 text-red-700 text-xs font-mono px-4 py-3 rounded-lg border border-red-200">
                    Gagal memuat sesi ({error}).
                </div>
                <button onClick={onBack} className="mt-4 text-xs font-mono font-bold text-slate-600 hover:text-slate-900">&larr; KEMBALI</button>
            </main>
        );
    }

    const elapsed = recording
        ? (now - new Date(test.started_at).getTime()) / 1000
        : (test.duration_seconds ?? 0);

    return (
        <main className="flex-1 p-6 bg-slate-100/70 space-y-6">
            <button onClick={onBack} className="text-xs font-mono font-bold text-slate-600 hover:text-slate-900">&larr; KEMBALI KE DAFTAR</button>

            <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-5 py-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">{test.test_code}</h2>
                        <p className="text-xs text-slate-500 mt-0.5">{test.name}</p>
                    </div>
                    <span className={`${STATUS_STYLE[test.status] ?? 'bg-slate-500'} text-white text-[11px] font-mono font-bold px-2.5 py-1 rounded uppercase tracking-wider`}>
                        {test.status}
                    </span>
                </div>

                <div className="p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-mono">
                    <div>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider">Topologi</p>
                        <p className="font-bold text-slate-800 mt-1">{TOPOLOGY_LABEL[test.topology_type] ?? test.topology_type}</p>
                        <p className="text-slate-600 mt-1">{(test.topology_config?.path ?? []).join(' → ')}</p>
                    </div>
                    <div>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider">Mulai</p>
                        <p className="font-bold text-slate-800 mt-1">{formatTime(test.started_at)}</p>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider mt-3">Selesai</p>
                        <p className="font-bold text-slate-800 mt-1">{formatTime(test.ended_at)}</p>
                    </div>
                    <div>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider">Durasi</p>
                        <p className="text-2xl font-black text-slate-800 mt-1">
                            {test.status === 'pending' ? '--:--:--' : formatDuration(elapsed)}
                        </p>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider mt-3">Event diterima</p>
                        <p className="text-2xl font-black text-slate-800 mt-1">{events.total}</p>
                    </div>
                </div>

                <div className="px-5 pb-5 flex flex-wrap items-center gap-3">
                    {test.status === 'pending' && (
                        <button
                            onClick={doStart}
                            disabled={busy}
                            className="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-mono font-bold text-sm disabled:opacity-50 active:scale-95 transition-all"
                        >
                            {busy ? 'MEMPROSES...' : 'START RECORDING'}
                        </button>
                    )}
                    {recording && (
                        <button
                            onClick={() => { setActionError(null); setConfirmStop(true); }}
                            className="px-5 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-mono font-bold text-sm active:scale-95 transition-all"
                        >
                            STOP RECORDING
                        </button>
                    )}
                    {test.status === 'completed' && (
                        <>
                            <p className="text-xs font-mono text-slate-500">Rekaman selesai. Data mentah tersimpan dan tidak bisa diubah.</p>
                            {onOpenResult && (
                                <button
                                    onClick={onOpenResult}
                                    className="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-700 text-white font-mono font-bold text-sm active:scale-95 transition-all"
                                >
                                    LIHAT HASIL
                                </button>
                            )}
                        </>
                    )}
                    {actionError && !confirmStop && (
                        <p role="alert" className="text-xs font-mono text-red-600">{actionError}</p>
                    )}
                </div>
            </section>

            {error && (
                <div className="bg-red-50 text-red-700 text-xs font-mono px-4 py-2 rounded-lg border border-red-200">
                    Gagal memuat event ({error}). Data di layar mungkin tidak terbaru.
                </div>
            )}

            <section className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div className="px-5 py-3.5 bg-slate-50 border-b border-slate-200">
                        <h3 className="font-mono font-bold text-xs uppercase text-slate-700 tracking-wider">Event per tipe</h3>
                    </div>
                    {events.by_type.length === 0 ? (
                        <p className="p-5 text-xs font-mono text-slate-500">Belum ada event.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100 text-xs font-mono">
                            {events.by_type.map((r) => (
                                <li key={`${r.event_type}-${r.packet_type}`} className="px-5 py-2.5 flex justify-between">
                                    <span>{r.event_type} · {r.packet_type}</span>
                                    <span className="font-bold">{r.total}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="lg:col-span-2 bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div className="px-5 py-3.5 bg-slate-50 border-b border-slate-200">
                        <h3 className="font-mono font-bold text-xs uppercase text-slate-700 tracking-wider">Event terbaru</h3>
                    </div>
                    {events.latest.length === 0 ? (
                        <p className="p-5 text-xs font-mono text-slate-500">
                            {recording ? 'Menunggu event dari collector...' : 'Belum ada event untuk sesi ini.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs font-mono">
                                <thead className="bg-slate-50 text-slate-500 uppercase tracking-wider">
                                    <tr>
                                        <th className="px-4 py-2">Waktu</th>
                                        <th className="px-4 py-2">Node</th>
                                        <th className="px-4 py-2">Event</th>
                                        <th className="px-4 py-2">Paket</th>
                                        <th className="px-4 py-2">Sumber → Tujuan</th>
                                        <th className="px-4 py-2">Seq</th>
                                        <th className="px-4 py-2">RSSI</th>
                                        <th className="px-4 py-2">SNR</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {events.latest.map((e) => (
                                        <tr key={e.id} className="border-b border-slate-100 hover:bg-slate-50">
                                            <td className="px-4 py-2 whitespace-nowrap">{formatTime(e.collected_at)}</td>
                                            <td className="px-4 py-2">{e.node_id}</td>
                                            <td className="px-4 py-2">{e.event_type}</td>
                                            <td className="px-4 py-2">{e.packet_type}</td>
                                            <td className="px-4 py-2 whitespace-nowrap">{e.source_id ?? '-'} → {e.destination_id ?? '-'}</td>
                                            <td className="px-4 py-2">{e.sequence_number ?? '-'}</td>
                                            <td className="px-4 py-2">{e.rssi ?? 'N/A'}</td>
                                            <td className="px-4 py-2">{e.snr ?? 'N/A'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </section>

            {confirmStop && (
                <div
                    className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-[60]"
                    onClick={() => { if (!busy) setConfirmStop(false); }}
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        className="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border border-slate-200"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <h3 className="text-center text-lg font-black font-mono uppercase tracking-wide text-slate-900">Hentikan rekaman?</h3>
                        <p className="mt-2 text-center text-sm text-slate-600">
                            Sesi <span className="font-bold text-slate-900">{test.test_code}</span> akan diselesaikan dan tidak menerima event lagi.
                            Data mentah tetap tersimpan.
                        </p>
                        {actionError && (
                            <div className="mt-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-mono px-3 py-2 text-center">
                                {actionError}
                            </div>
                        )}
                        <div className="mt-6 grid grid-cols-2 gap-3">
                            <button
                                autoFocus
                                onClick={() => setConfirmStop(false)}
                                disabled={busy}
                                className="py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 font-mono font-bold text-sm disabled:opacity-50 active:scale-95 transition-all"
                            >
                                BATAL
                            </button>
                            <button
                                onClick={doStop}
                                disabled={busy}
                                className="py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-mono font-bold text-sm disabled:opacity-60 active:scale-95 transition-all"
                            >
                                {busy ? 'MEMPROSES...' : 'YA, HENTIKAN'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </main>
    );
}