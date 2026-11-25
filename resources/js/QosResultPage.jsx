import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { fetchQosResults, recalculateQos, downloadQosReport } from './api';

const TOPOLOGY_LABEL = {
    dynamic: 'Flexible / Auto-Discovery',
    direct: 'Direct',
    one_relay: 'One Relay',
    two_relays: 'Two Relays',
};

const GROUP_TITLES = {
    delivery: 'Delivery Performance',
    performance: 'Performance',
    radio: 'Radio Quality',
    routing: 'Routing Performance',
    airtime: 'LoRa Airtime (teoretis)',
    events: 'Pengumpulan Event',
    other: 'Lainnya',
};

const LABELS = {
    pdr: 'Packet Delivery Ratio (PDR)',
    packet_loss: 'Packet Loss',
    generated_packets: 'Paket dibuat sumber',
    delivered_packets: 'Paket terkirim (unik)',
    lost_packets: 'Paket hilang',
    throughput: 'Throughput aplikasi',
    e2e_delay: 'End-to-End Delay',
    avg_rssi: 'RSSI rata-rata', min_rssi: 'RSSI minimum', max_rssi: 'RSSI maksimum',
    avg_snr: 'SNR rata-rata', min_snr: 'SNR minimum', max_snr: 'SNR maksimum',
    avg_hop_count: 'Hop count rata-rata',
    forwarding_count: 'Transmisi forwarding',
    retransmission_count: 'Retransmisi',
    control_packet_count: 'Paket kontrol',
    control_packet_bytes: 'Byte paket kontrol',
    control_to_data_packet_ratio: 'Rasio kontrol:DATA (paket)',
    control_to_data_byte_ratio: 'Rasio kontrol:DATA (byte)',
    airtime_data: 'Airtime DATA',
    airtime_control: 'Airtime kontrol',
    airtime_total: 'Airtime total',
    airtime_avg_per_tx: 'Airtime rata-rata per transmisi',
    data_tx_count: 'Event TX DATA',
    data_rx_count: 'Event RX DATA',
    duplicate_count: 'Duplikat RX',
    dropped_count: 'Event drop/gagal',
};

const UNIT_LABEL = { ratio: '', packets: 'paket', events: 'event' };

function formatValue(m) {
    if (!m.available) return 'N/A';
    const v = Number(m.value);
    const text = Number.isInteger(v) ? String(v) : v.toFixed(2);
    const unit = m.unit in UNIT_LABEL ? UNIT_LABEL[m.unit] : m.unit;
    return unit ? `${text} ${unit}` : text;
}

function formatTime(value) {
    if (!value) return '-';
    const d = new Date(value.length === 19 ? value.replace(' ', 'T') + 'Z' : value);
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleString('id-ID', {
        timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: false,
    }) + ' WIB';
}

function resolveMetricForNode(m, selectedNode) {
    if (selectedNode === 'ALL') return m;
    const sub = m.metadata?.by_source?.[selectedNode];
    if (!sub) return m;

    const available = sub.value !== null && sub.value !== undefined;
    return {
        ...m,
        value: sub.value,
        unit: sub.unit ?? m.unit,
        samples: sub.samples ?? 0,
        available,
        reason: sub.metadata?.reason ?? null,
        note: sub.metadata?.note ?? null,
        metadata: sub.metadata ?? {},
    };
}

function MetricCard({ m }) {
    const estimate = m.metadata?.denominator_method === 'sequence_gap_estimate';
    return (
        <div className={`rounded-xl border p-4 ${m.available ? 'bg-white border-slate-200' : 'bg-slate-50 border-slate-200 border-dashed'}`}>
            <div className="flex items-start justify-between gap-2">
                <p className="text-[11px] font-mono uppercase tracking-wider text-slate-500">{LABELS[m.key] ?? m.key}</p>
                {estimate && (
                    <span className="bg-amber-500 text-white text-[9px] font-mono font-bold px-1.5 py-0.5 rounded uppercase tracking-wider">
                        Estimasi
                    </span>
                )}
            </div>
            <p className={`mt-1 text-2xl font-black font-mono ${m.available ? 'text-slate-800' : 'text-slate-400'}`}>
                {formatValue(m)}
            </p>
            {m.available && m.samples > 0 && (
                <p className="mt-1 text-[10px] font-mono text-slate-400">n = {m.samples}</p>
            )}
            {!m.available && m.reason && (
                <p className="mt-2 text-[11px] text-slate-500 leading-snug">{m.reason}</p>
            )}
            {m.available && m.note && (
                <p className="mt-2 text-[11px] text-amber-700 leading-snug">{m.note}</p>
            )}
        </div>
    );
}

function AirtimeByNode({ metric }) {
    const byNode = metric?.metadata?.by_node;
    if (!byNode || Object.keys(byNode).length === 0) return null;

    const rows = Object.entries(byNode);
    const max = Math.max(...rows.map(([, r]) => r.airtime_ms), 1);

    return (
        <div className="mt-4 rounded-xl border border-slate-200 bg-white overflow-hidden">
            <div className="px-4 py-2.5 bg-slate-50 border-b border-slate-200">
                <h4 className="font-mono font-bold text-[11px] uppercase text-slate-700 tracking-wider">Airtime per node (teoretis)</h4>
            </div>
            <table className="w-full text-left text-xs font-mono">
                <thead className="text-slate-500 uppercase tracking-wider">
                    <tr>
                        <th className="px-4 py-2">Node</th>
                        <th className="px-4 py-2">Transmisi</th>
                        <th className="px-4 py-2">Airtime</th>
                        <th className="px-4 py-2 w-1/3"></th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map(([node, r]) => (
                        <tr key={node} className="border-t border-slate-100">
                            <td className="px-4 py-2 font-bold text-slate-800">{node}</td>
                            <td className="px-4 py-2">{r.transmissions}</td>
                            <td className="px-4 py-2">{Number(r.airtime_ms).toFixed(2)} ms</td>
                            <td className="px-4 py-2">
                                <div className="h-2 rounded-full bg-slate-100 overflow-hidden">
                                    <div className="h-full bg-cyan-500" style={{ width: `${(r.airtime_ms / max) * 100}%` }} />
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function DeliveryBar({ metrics }) {
    const get = (k) => metrics.find((m) => m.key === k);
    const delivered = get('delivered_packets');
    const lost = get('lost_packets');
    if (!delivered?.available || !lost?.available) return null;

    const d = Number(delivered.value);
    const l = Number(lost.value);
    const total = d + l;
    if (total === 0) return null;

    return (
        <div className="mt-4">
            <div className="flex h-3 rounded-full overflow-hidden bg-slate-100">
                <div className="bg-emerald-500" style={{ width: `${(d / total) * 100}%` }} />
                <div className="bg-red-500" style={{ width: `${(l / total) * 100}%` }} />
            </div>
            <div className="mt-1 flex justify-between text-[10px] font-mono text-slate-500">
                <span>Terkirim {d}</span>
                <span>Hilang {l}</span>
            </div>
        </div>
    );
}

export default function QosResultPage({ testId, onBack, onOpenRecord }) {
    const [data, setData] = useState(null);
    const [selectedNode, setSelectedNode] = useState('ALL');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [pdfBusy, setPdfBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            setData(await fetchQosResults(testId));
            setError(null);
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    }, [testId]);

    useEffect(() => { load(); }, [load]);

    // Ambil daftar node dinamis dari metadata hasil kalkulasi
    const availableNodes = useMemo(() => {
        if (!data?.groups) return [];
        const nodeSet = new Set();
        Object.values(data.groups).forEach((metrics) => {
            metrics.forEach((m) => {
                (m.metadata?.available_nodes ?? []).forEach((n) => nodeSet.add(n));
                Object.keys(m.metadata?.by_source ?? {}).forEach((n) => nodeSet.add(n));
            });
        });
        return [...nodeSet];
    }, [data]);

    const recalc = async () => {
        setBusy(true);
        setError(null);
        try {
            setData(await recalculateQos(testId));
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    };

    const exportPdf = async () => {
        setPdfBusy(true);
        setError(null);
        try {
            await downloadQosReport(testId, `${data.test.test_code}-report.pdf`);
        } catch (err) {
            setError(err.message);
        } finally {
            setPdfBusy(false);
        }
    };

    if (loading) {
        return <main className="flex-1 p-10 text-center text-sm font-mono text-slate-500">Memuat hasil...</main>;
    }

    if (!data) {
        return (
            <main className="flex-1 p-6">
                <div className="bg-red-50 text-red-700 text-xs font-mono px-4 py-3 rounded-lg border border-red-200">
                    Gagal memuat hasil ({error}).
                </div>
                <button onClick={onBack} className="mt-4 text-xs font-mono font-bold text-slate-600 hover:text-slate-900">&larr; KEMBALI</button>
            </main>
        );
    }

    const t = data.test;
    const rc = t.radio_config;

    // Resolve semua grup metrik sesuai node yang dipilih
    const resolvedGroups = Object.fromEntries(
        Object.entries(data.groups).map(([group, metrics]) => [
            group,
            metrics.map((m) => resolveMetricForNode(m, selectedNode)),
        ])
    );
    const airtimeTotal = resolvedGroups.airtime?.find((m) => m.key === 'airtime_total');

    return (
        <main className="flex-1 p-6 bg-slate-100/70 space-y-6">
            <button onClick={onBack} className="text-xs font-mono font-bold text-slate-600 hover:text-slate-900">&larr; KEMBALI KE DAFTAR</button>

            <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-5 py-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">Hasil QoS · {t.test_code}</h2>
                        <p className="text-xs text-slate-500 mt-0.5">{t.name}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            onClick={onOpenRecord}
                            className="px-3 py-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-mono font-bold active:scale-95 transition-all"
                        >
                            LIHAT EVENT MENTAH
                        </button>
                        <button
                            onClick={recalc}
                            disabled={busy || t.status !== 'completed'}
                            className="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-white text-xs font-mono font-bold disabled:opacity-50 active:scale-95 transition-all"
                        >
                            {busy ? 'MENGHITUNG...' : 'HITUNG ULANG'}
                        </button>
                        <button
                            onClick={exportPdf}
                            disabled={pdfBusy || t.status !== 'completed'}
                            className="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-white text-xs font-mono font-bold disabled:opacity-50 active:scale-95 transition-all"
                        >
                            {pdfBusy ? 'MENGUNDUH...' : 'UNDUH LAPORAN'}
                        </button>
                    </div>
                </div>

                <div className="p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-mono">
                    <div>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider">Mode & Tujuan</p>
                        <p className="font-bold text-slate-800 mt-1">{TOPOLOGY_LABEL[t.topology_type] ?? t.topology_type}</p>
                        <p className="text-slate-600 mt-1">
                            {t.topology_type === 'dynamic'
                                ? `Semua Node → ${t.path?.[t.path.length - 1] ?? 'BASE'}`
                                : t.path.join(' → ')}
                        </p>
                    </div>
                    <div>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider">Durasi rekaman</p>
                        <p className="font-bold text-slate-800 mt-1">{t.duration_seconds != null ? `${t.duration_seconds} detik` : '-'}</p>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider mt-3">Dihitung</p>
                        <p className="font-bold text-slate-800 mt-1">{formatTime(data.calculated_at)}</p>
                    </div>
                    <div>
                        <p className="text-slate-500 uppercase text-[10px] tracking-wider">Konfigurasi radio</p>
                        <p className="font-bold text-slate-800 mt-1">
                            {rc ? `SF${rc.sf} · BW ${rc.bw} kHz · CR 4/${rc.cr} · preamble ${rc.preamble}` : 'Tidak diisi (airtime N/A)'}
                        </p>
                    </div>
                </div>
            </section>

            {error && (
                <div role="alert" className="bg-red-50 text-red-700 text-xs font-mono px-4 py-2 rounded-lg border border-red-200">
                    {error}
                </div>
            )}

            {!data.calculated ? (
                <div className="bg-white border border-slate-200 rounded-2xl p-10 text-center">
                    <p className="font-mono font-bold text-slate-600">Hasil belum dihitung</p>
                    <p className="text-xs text-slate-500 mt-1">Klik HITUNG ULANG untuk menghitung dari event yang tersimpan.</p>
                </div>
            ) : (
                <>
                    {/* Bar Filter Node */}
                    <section className="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-[10px] font-mono font-bold uppercase tracking-wider text-slate-500">Filter Tampilan Hasil</p>
                            <p className="text-xs font-mono text-slate-700 mt-0.5">
                                {selectedNode === 'ALL'
                                    ? `Agregat seluruh traffic yang terekam (${availableNodes.length} node terdeteksi)`
                                    : `Traffic & aktivitas relay spesifik untuk ${selectedNode}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setSelectedNode('ALL')}
                                className={`px-3.5 py-1.5 rounded-lg text-xs font-mono font-bold border transition-all ${
                                    selectedNode === 'ALL'
                                        ? 'bg-cyan-500 border-cyan-500 text-white shadow-sm'
                                        : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                SEMUA
                            </button>
                            {availableNodes.map((node) => (
                                <button
                                    key={node}
                                    type="button"
                                    onClick={() => setSelectedNode(node)}
                                    className={`px-3.5 py-1.5 rounded-lg text-xs font-mono font-bold border transition-all ${
                                        selectedNode === node
                                            ? 'bg-cyan-500 border-cyan-500 text-white shadow-sm'
                                            : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    {node}
                                </button>
                            ))}
                        </div>
                    </section>

                    {Object.entries(resolvedGroups).map(([group, metrics]) => (
                        metrics.length > 0 && (
                            <section key={group}>
                                <h3 className="font-mono font-bold text-xs uppercase text-slate-700 tracking-wider mb-3">
                                    {GROUP_TITLES[group] ?? group}
                                </h3>
                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                    {metrics.map((m) => <MetricCard key={m.key} m={m} />)}
                                </div>
                                {group === 'delivery' && <DeliveryBar metrics={metrics} />}
                                {group === 'airtime' && <AirtimeByNode metric={airtimeTotal} />}
                            </section>
                        )
                    ))}

                    <section className="bg-amber-50 border border-amber-200 rounded-2xl p-5">
                        <h3 className="font-mono font-bold text-xs uppercase text-amber-800 tracking-wider">Batasan pengukuran</h3>
                        <ul className="mt-2 list-disc pl-5 space-y-1 text-xs text-amber-900">
                            <li>Airtime adalah perhitungan teoretis (rumus Semtech), bukan durasi radio terukur.</li>
                            <li>Event hanya dari yang teramati di Basecamp. Event lokal relay hanya muncul jika log relay ikut diunggah.</li>
                            <li>Tanpa event TX dari sumber, PDR diperkirakan dari celah nomor urut per sumber.</li>
                            <li>E2E delay tidak dihitung karena jam antar-node tidak tersinkronisasi.</li>
                            <li>Throughput adalah throughput aplikasi, bukan bit rate PHY LoRa.</li>
                            <li>Nilai N/A berarti data tidak tersedia, bukan nol.</li>
                        </ul>
                    </section>
                </>
            )}
        </main>
    );
}