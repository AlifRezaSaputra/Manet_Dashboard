import React, { useState, useEffect, useMemo } from 'react';
import { fetchSosHistory } from './api';

// DB menyimpan UTC tanpa penanda zona -> tambahkan 'Z', tampilkan sebagai WIB
function formatTime(str) {
    if (!str) return '-';
    const d = new Date(str.replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return str;
    return d.toLocaleString('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false,
    }) + ' WIB';
}

// 0,0 berarti GPS belum dapat sinyal, bukan lokasi sungguhan
function formatLocation(lat, lng) {
    const la = Number(lat);
    const lo = Number(lng);
    if (!Number.isFinite(la) || !Number.isFinite(lo) || (la === 0 && lo === 0)) return null;
    return `${la.toFixed(6)}, ${lo.toFixed(6)}`;
}

function parsePayload(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    try { return JSON.parse(raw); } catch { return null; }
}

function extraInfo(row) {
    if (row.event_type === 'SOS_RESOLVED') return 'Diselesaikan oleh operator';
    const p = parsePayload(row.payload);
    if (!p) return '-';
    const parts = [];
    if (p.battery_percent != null) parts.push(`Baterai ${p.battery_percent}%`);
    if (p.voltage != null) parts.push(`${p.voltage} V`);
    return parts.length ? parts.join(' · ') : '-';
}

function EventBadge({ type }) {
    if (type === 'SOS_TRIGGERED') {
        return <span className="bg-red-600 text-white text-[10px] font-mono font-bold px-2 py-0.5 rounded uppercase tracking-wider">SOS MENYALA</span>;
    }
    if (type === 'SOS_RESOLVED') {
        return <span className="bg-emerald-600 text-white text-[10px] font-mono font-bold px-2 py-0.5 rounded uppercase tracking-wider">SELESAI</span>;
    }
    return <span className="bg-slate-500 text-white text-[10px] font-mono font-bold px-2 py-0.5 rounded">{type}</span>;
}

function Row({ row }) {
    const loc = formatLocation(row.latitude, row.longitude);
    return (
        <tr className="border-b border-slate-100 hover:bg-slate-50">
            <td className="px-4 py-3">
                <div className="font-bold text-slate-800">{row.node_name}</div>
                <div className="text-[11px] text-slate-500">{row.node_code}</div>
            </td>
            <td className="px-4 py-3"><EventBadge type={row.event_type} /></td>
            <td className="px-4 py-3 whitespace-nowrap">{formatTime(row.event_time)}</td>
            <td className="px-4 py-3 whitespace-nowrap">
                {loc ?? <span className="text-slate-400 italic">Lokasi tidak tersedia</span>}
            </td>
            <td className="px-4 py-3 text-slate-600">{extraInfo(row)}</td>
        </tr>
    );
}

export default function SosHistory({ nodes }) {
    const [order, setOrder] = useState('desc');   // default: terbaru dulu
    const [nodeFilter, setNodeFilter] = useState('');
    const [grouped, setGrouped] = useState(false);
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        fetchSosHistory({ order, node: nodeFilter })
            .then((data) => {
                if (cancelled) return;
                setRows(data);
                setError(null);
            })
            .catch((err) => {
                if (cancelled) return;
                setError(err.message);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
    }, [order, nodeFilter, reloadKey]);

    // Kelompok per node, urutan kelompok mengikuti kemunculan pertama
    const groups = useMemo(() => {
        const map = new Map();
        rows.forEach((r) => {
            if (!map.has(r.node_code)) {
                map.set(r.node_code, { code: r.node_code, name: r.node_name, items: [] });
            }
            map.get(r.node_code).items.push(r);
        });
        return [...map.values()];
    }, [rows]);

    const selectClass = 'bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-700';

    return (
        <main className="flex-1 p-6 bg-slate-100/70">
            <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-5 py-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">
                        SOS History
                    </h2>

                    <div className="flex flex-wrap items-center gap-2">
                        <select value={order} onChange={(e) => setOrder(e.target.value)} className={selectClass}>
                            <option value="desc">Terbaru ke terlama</option>
                            <option value="asc">Terlama ke terbaru</option>
                        </select>

                        <select value={nodeFilter} onChange={(e) => setNodeFilter(e.target.value)} className={selectClass}>
                            <option value="">Semua node</option>
                            {nodes.map((n) => (
                                <option key={n.dbId} value={n.dbId}>{n.name} ({n.id})</option>
                            ))}
                        </select>

                        <label className="flex items-center gap-1.5 text-xs font-mono text-slate-700 cursor-pointer">
                            <input type="checkbox" checked={grouped} onChange={(e) => setGrouped(e.target.checked)} />
                            Kelompokkan per node
                        </label>

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
                        Gagal memuat riwayat ({error}). Data di layar mungkin tidak terbaru.
                    </div>
                )}

                {loading && rows.length === 0 ? (
                    <div className="p-10 text-center text-sm font-mono text-slate-500">Memuat riwayat SOS...</div>
                ) : rows.length === 0 && !error ? (
                    <div className="p-10 text-center">
                        <p className="font-mono font-bold text-slate-600">Belum ada riwayat SOS</p>
                        <p className="text-xs text-slate-500 mt-1">
                            {nodeFilter ? 'Node ini belum pernah mengirim SOS.' : 'Belum ada node yang pernah mengirim SOS.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs font-mono">
                            <thead className="bg-slate-50 text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th className="px-4 py-2.5">Node</th>
                                    <th className="px-4 py-2.5">Peristiwa</th>
                                    <th className="px-4 py-2.5">Waktu</th>
                                    <th className="px-4 py-2.5">Lokasi</th>
                                    <th className="px-4 py-2.5">Informasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {grouped
                                    ? groups.map((g) => (
                                          <React.Fragment key={g.code}>
                                              <tr className="bg-slate-100 border-y border-slate-200">
                                                  <td colSpan={5} className="px-4 py-2 font-bold text-slate-700">
                                                      {g.name} ({g.code}) · {g.items.length} peristiwa
                                                  </td>
                                              </tr>
                                              {g.items.map((r) => <Row key={r.id} row={r} />)}
                                          </React.Fragment>
                                      ))
                                    : rows.map((r) => <Row key={r.id} row={r} />)}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </main>
    );
}