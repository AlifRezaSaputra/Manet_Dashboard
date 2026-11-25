import React, { useState, useMemo } from 'react';
import { createQosTest } from './api';

const TOPOLOGIES = {
    dynamic: { label: 'Flexible / Auto-Discovery', relays: 0 },
    direct: { label: 'Direct (Fixed)', relays: 0 },
    one_relay: { label: 'One Relay (Fixed)', relays: 1 },
    two_relays: { label: 'Two Relays (Fixed)', relays: 2 },
};

const DEFAULT_PATH = {
    dynamic: ['AUTO', 'BASE'],
    direct: ['NODE01', 'BASE'],
    one_relay: ['NODE01', 'NODE02', 'BASE'],
    two_relays: ['NODE01', 'NODE02', 'NODE03', 'BASE'],
};

function roleOf(i, total) {
    if (i === 0) return 'source';
    if (i === total - 1) return 'destination';
    return 'relay';
}

export default function QosCreateForm({ nodeOptions, onCreated, onCancel }) {
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [notes, setNotes] = useState('');
    const [topology, setTopology] = useState('dynamic');
    const [destination, setDestination] = useState('BASE');
    const [path, setPath] = useState(DEFAULT_PATH.direct);
    const [sf, setSf] = useState('7');
    const [bw, setBw] = useState('125');
    const [cr, setCr] = useState('5');
    const [preamble, setPreamble] = useState('8');
    const [confirmed, setConfirmed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const options = useMemo(() => {
        const set = new Set([...nodeOptions, 'BASE']);
        return [...set];
    }, [nodeOptions]);

    const isDynamic = topology === 'dynamic';

    const changeTopology = (t) => {
        setTopology(t);
        if (t !== 'dynamic') {
            setPath(DEFAULT_PATH[t]);
        }
        setConfirmed(false);
    };

    const changeNode = (i, value) => {
        setPath((p) => p.map((n, idx) => (idx === i ? value : n)));
        setConfirmed(false);
    };

    const effectiveNodes = isDynamic ? ['AUTO', destination] : path;
    const hasDuplicate = !isDynamic && new Set(path).size !== path.length;
    const canSubmit = name.trim() && !hasDuplicate && confirmed && !saving;

    const submit = async (e) => {
        e.preventDefault();
        if (!canSubmit) return;
        setSaving(true);
        setError(null);

        const radio = {};
        if (sf !== '') radio.sf = Number(sf);
        if (bw !== '') radio.bw = Number(bw);
        if (cr !== '') radio.cr = Number(cr);
        if (preamble !== '') radio.preamble = Number(preamble);

        try {
            const created = await createQosTest({
                name: name.trim(),
                description: description.trim() || null,
                topology_type: topology,
                nodes: effectiveNodes,
                radio_config: Object.keys(radio).length ? radio : null,
                notes: notes.trim() || null,
            });
            onCreated(created);
        } catch (err) {
            setError(err.message);
            setSaving(false);
        }
    };

    const field = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono text-slate-900 bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500';
    const label = 'block text-[11px] font-mono font-bold text-slate-600 uppercase tracking-wider mb-1';

    return (
        <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            <div className="px-5 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">Buat Sesi Pengujian</h2>
                <button type="button" onClick={onCancel} className="text-xs font-mono font-bold text-slate-500 hover:text-slate-800">
                    BATAL
                </button>
            </div>

            <form onSubmit={submit} className="p-5 space-y-5">
                <div>
                    <label className={label}>Nama pengujian</label>
                    <input className={field} value={name} onChange={(e) => setName(e.target.value)} maxLength={150} placeholder="Contoh: Uji Multi-hop & Switching Node 2 Mati" />
                </div>

                <div>
                    <label className={label}>Deskripsi</label>
                    <textarea className={field} rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
                </div>

                <div>
                    <label className={label}>Mode Topologi</label>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(TOPOLOGIES).map(([key, t]) => (
                            <button
                                type="button"
                                key={key}
                                onClick={() => changeTopology(key)}
                                className={`px-4 py-2 rounded-lg text-xs font-mono font-bold border transition-all ${
                                    topology === key
                                        ? 'bg-cyan-500 border-cyan-500 text-white'
                                        : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>

                {isDynamic ? (
                    <div className="rounded-xl bg-cyan-50/70 border border-cyan-200 p-4 space-y-3">
                        <p className="text-xs font-mono text-cyan-900">
                            Semua node yang mengirim/meneruskan paket selama sesi akan direkam otomatis dan bisa difilter per-node pada halaman hasil.
                        </p>
                        <div className="max-w-xs">
                            <label className={label}>Node Tujuan (Collector / Basecamp)</label>
                            <select className={field} value={destination} onChange={(e) => { setDestination(e.target.value); setConfirmed(false); }}>
                                {options.map((o) => <option key={o} value={o}>{o}</option>)}
                            </select>
                        </div>
                    </div>
                ) : (
                    <div>
                        <label className={label}>Jalur node (peran disarankan otomatis)</label>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            {path.map((nodeId, i) => (
                                <div key={i}>
                                    <p className="text-[10px] font-mono uppercase text-slate-500 mb-1">
                                        {i + 1}. {roleOf(i, path.length)}
                                    </p>
                                    <select className={field} value={nodeId} onChange={(e) => changeNode(i, e.target.value)}>
                                        {options.map((o) => <option key={o} value={o}>{o}</option>)}
                                    </select>
                                </div>
                            ))}
                        </div>
                        {hasDuplicate && (
                            <p className="mt-2 text-xs font-mono text-red-600">Node tidak boleh dipakai dua kali dalam satu jalur.</p>
                        )}
                    </div>
                )}

                <div>
                    <label className={label}>Konfigurasi radio LoRa (opsional)</label>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <input className={field} placeholder="SF (6-12)" value={sf} onChange={(e) => setSf(e.target.value)} inputMode="numeric" />
                        <select className={field} value={bw} onChange={(e) => setBw(e.target.value)}>
                            {['7.8', '10.4', '15.6', '20.8', '31.25', '41.7', '62.5', '125', '250', '500'].map((v) => (
                                <option key={v} value={v}>BW {v} kHz</option>
                            ))}
                        </select>
                        <input className={field} placeholder="CR (5-8)" value={cr} onChange={(e) => setCr(e.target.value)} inputMode="numeric" />
                        <input className={field} placeholder="Preamble" value={preamble} onChange={(e) => setPreamble(e.target.value)} inputMode="numeric" />
                    </div>
                </div>

                <div>
                    <label className={label}>Catatan eksperimen</label>
                    <textarea className={field} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
                </div>

                <div className="rounded-xl bg-slate-50 border border-slate-200 p-4">
                    <p className="text-[11px] font-mono font-bold uppercase text-slate-600 tracking-wider">Tinjau konfigurasi</p>
                    <p className="mt-1 text-sm font-mono font-bold text-slate-800">
                        {isDynamic ? `Semua Node (Auto) → ${destination}` : path.join(' → ')}
                    </p>
                    <label className="mt-3 flex items-center gap-2 text-xs font-mono text-slate-700 cursor-pointer">
                        <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
                        Saya sudah memeriksa dan mengonfirmasi pengaturan sesi ini
                    </label>
                </div>

                {error && (
                    <div role="alert" className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-mono px-3 py-2">
                        {error}
                    </div>
                )}

                <button
                    type="submit"
                    disabled={!canSubmit}
                    className="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-700 text-white font-mono font-bold text-sm transition-all disabled:opacity-50 active:scale-95"
                >
                    {saving ? 'MEMBUAT...' : 'BUAT SESI'}
                </button>
            </form>
        </section>
    );
}