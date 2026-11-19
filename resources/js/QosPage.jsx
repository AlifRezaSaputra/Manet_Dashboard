import React, { useState, useEffect, useMemo } from 'react';
import { fetchQosTests } from './api';
import QosCreateForm from './QosCreateForm';

const TOPOLOGY_LABEL = {
    direct: 'Direct',
    one_relay: 'One Relay',
    two_relays: 'Two Relays',
};

const STATUS_STYLE = {
    pending: 'bg-slate-500',
    recording: 'bg-red-600',
    completed: 'bg-emerald-600',
    failed: 'bg-amber-600',
};

// DB menyimpan UTC; tampilkan sebagai WIB
function formatTime(value) {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleString('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: false,
    }) + ' WIB';
}

function StatusBadge({ status }) {
    return (
        <span className={`${STATUS_STYLE[status] ?? 'bg-slate-500'} text-white text-[10px] font-mono font-bold px-2 py-0.5 rounded uppercase tracking-wider`}>
            {status}
        </span>
    );
}

function StatCard({ label, value }) {
    return (
        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p className="text-[11px] font-mono uppercase tracking-wider text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-black font-mono text-slate-800">{value}</p>
        </div>
    );
}

export default function QosPage({ nodes = [] }) {
    const [creating, setCreating] = useState(false);
    const [tests, setTests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        fetchQosTests()
            .then((data) => { if (!cancelled) { setTests(data); setError(null); } })
            .catch((err) => { if (!cancelled) setError(err.message); })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [reloadKey]);

    const stats = useMemo(() => {
        const byTopology = { direct: 0, one_relay: 0, two_relays: 0 };
        tests.forEach((t) => { if (t.topology_type in byTopology) byTopology[t.topology_type]++; });
        return {
            total: tests.length,
            completed: tests.filter((t) => t.status === 'completed').length,
            recording: tests.filter((t) => t.status === 'recording').length,
            byTopology,
        };
    }, [tests]);

    if (creating) {
        return (
            <main className="flex-1 p-6 bg-slate-100/70">
                <QosCreateForm
                    nodeOptions={nodes.map((n) => n.id)}
                    onCancel={() => setCreating(false)}
                    onCreated={() => { setCreating(false); setReloadKey((k) => k + 1); }}
                />
            </main>
        );
    }

    return (
        <main className="flex-1 p-6 bg-slate-100/70 space-y-6">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <StatCard label="Total sesi" value={stats.total} />
                <StatCard label="Selesai" value={stats.completed} />
                <StatCard label="Sedang merekam" value={stats.recording} />
                <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                    <p className="text-[11px] font-mono uppercase tracking-wider text-slate-500">Topologi</p>
                    <ul className="mt-1 text-xs font-mono text-slate-700 space-y-0.5">
                        {Object.entries(stats.byTopology).map(([k, v]) => (
                            <li key={k} className="flex justify-between">
                                <span>{TOPOLOGY_LABEL[k]}</span><span className="font-bold">{v}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-5 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                    <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">
                        Sesi Pengujian QoS
                    </h2>
                    <div className="flex gap-2">
                        <button
                            onClick={() => setCreating(true)}
                            className="px-3 py-1.5 rounded-lg bg-cyan-500 hover:bg-cyan-600 text-white text-xs font-mono font-bold active:scale-95 transition-all"
                        >
                            + SESI BARU
                        </button>
                        <button
                            onClick={() => setReloadKey((k) => k + 1)}
                            className="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-white text-xs font-mono font-bold active:scale-95 transition-all"
                        >
                            MUAT ULANG
                        </button>
                    </div>
                </div>

                {error && (
                    <div className="bg-red-50 text-red-700 text-xs font-mono px-5 py-2 border-b border-red-200">
                        Gagal memuat sesi ({error}).
                    </div>
                )}

                {loading && tests.length === 0 ? (
                    <div className="p-10 text-center text-sm font-mono text-slate-500">Memuat sesi...</div>
                ) : tests.length === 0 && !error ? (
                    <div className="p-10 text-center">
                        <p className="font-mono font-bold text-slate-600">Belum ada sesi pengujian</p>
                        <p className="text-xs text-slate-500 mt-1">Klik "+ SESI BARU" untuk membuat sesi pertama.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs font-mono">
                            <thead className="bg-slate-50 text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th className="px-4 py-2.5">Kode</th>
                                    <th className="px-4 py-2.5">Nama</th>
                                    <th className="px-4 py-2.5">Topologi</th>
                                    <th className="px-4 py-2.5">Jalur</th>
                                    <th className="px-4 py-2.5">Status</th>
                                    <th className="px-4 py-2.5">Dibuat</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tests.map((t) => (
                                    <tr key={t.id} className="border-b border-slate-100 hover:bg-slate-50">
                                        <td className="px-4 py-3 font-bold text-slate-800 whitespace-nowrap">{t.test_code}</td>
                                        <td className="px-4 py-3">{t.name}</td>
                                        <td className="px-4 py-3 whitespace-nowrap">{TOPOLOGY_LABEL[t.topology_type] ?? t.topology_type}</td>
                                        <td className="px-4 py-3 whitespace-nowrap text-slate-600">
                                            {(t.topology_config?.path ?? []).join(' → ')}
                                        </td>
                                        <td className="px-4 py-3"><StatusBadge status={t.status} /></td>
                                        <td className="px-4 py-3 whitespace-nowrap">{formatTime(t.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </main>
    );
}