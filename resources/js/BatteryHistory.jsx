
import React, { useState, useEffect } from 'react';
import { fetchBatteryHistory } from './api';

function formatTime(str) {
    if (!str) return '-';

    const d = new Date(str.replace(' ', 'T') + 'Z');

    if (Number.isNaN(d.getTime())) return str;

    return d.toLocaleString('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }) + ' WIB';
}

function BatteryRow({ row }) {
    return (
        <tr className="border-b border-slate-100 hover:bg-slate-50">
            <td className="px-4 py-3">
                <div className="font-bold text-slate-800">
                    {row.node_name}
                </div>
                <div className="text-[11px] text-slate-500">
                    {row.node_code}
                </div>
            </td>

            <td className="px-4 py-3 font-bold text-cyan-700">
                {Number(row.voltage).toFixed(3)} V
            </td>

            <td className="px-4 py-3">
                {row.battery_percent != null
                    ? `${row.battery_percent}%`
                    : <span className="text-slate-400 italic">
                        Belum tersedia
                    </span>
                }
            </td>

            <td className="px-4 py-3 whitespace-nowrap">
                {formatTime(row.recorded_at)}
            </td>
        </tr>
    );
}

export default function BatteryHistory() {
    const [order, setOrder] = useState('desc');
    const [nodeFilter, setNodeFilter] = useState('');
    const [rows, setRows] = useState([]);
    const [nodes, setNodes] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        let cancelled = false;

        setLoading(true);

        fetchBatteryHistory({
            order,
            node: nodeFilter,
        })
            .then((data) => {
                if (cancelled) return;

                setRows(data.rows);
                setNodes(data.nodes);
                setError(null);
            })
            .catch((err) => {
                if (cancelled) return;
                setError(err.message);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [order, nodeFilter, reloadKey]);

    const selectClass =
        'bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-700';

    return (
        <main className="flex-1 p-6 bg-slate-100/70">
            <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

                <div className="px-5 py-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">
                        Battery History
                    </h2>

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={order}
                            onChange={(e) => setOrder(e.target.value)}
                            className={selectClass}
                        >
                            <option value="desc">Terbaru ke terlama</option>
                            <option value="asc">Terlama ke terbaru</option>
                        </select>

                        <select
                            value={nodeFilter}
                            onChange={(e) => setNodeFilter(e.target.value)}
                            className={selectClass}
                        >
                            <option value="">Semua node</option>

                            {nodes.map((node) => (
                                <option key={node.id} value={node.id}>
                                    {node.node_name} ({node.node_id})
                                </option>
                            ))}
                        </select>

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
                        Gagal memuat riwayat ({error}).
                    </div>
                )}

                {loading && rows.length === 0 ? (
                    <div className="p-10 text-center text-sm font-mono text-slate-500">
                        Memuat riwayat baterai...
                    </div>
                ) : rows.length === 0 && !error ? (
                    <div className="p-10 text-center">
                        <p className="font-mono font-bold text-slate-600">
                            Belum ada riwayat baterai
                        </p>
                        <p className="text-xs text-slate-500 mt-1">
                            Belum ada sampel tegangan yang tersimpan.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs font-mono">
                            <thead className="bg-slate-50 text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th className="px-4 py-2.5">Node</th>
                                    <th className="px-4 py-2.5">Tegangan</th>
                                    <th className="px-4 py-2.5">Persentase</th>
                                    <th className="px-4 py-2.5">Waktu</th>
                                </tr>
                            </thead>

                            <tbody>
                                {rows.map((row) => (
                                    <BatteryRow
                                        key={row.id}
                                        row={row}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="px-5 py-3 border-t border-slate-200 text-xs font-mono text-slate-500">
                    Menampilkan {rows.length} catatan (maksimal 500).
                </div>
            </section>
        </main>
    );
}
