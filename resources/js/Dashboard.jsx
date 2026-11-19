import React, { useState, useEffect, useRef } from 'react';
import { MapContainer, TileLayer, Marker, Popup, Tooltip, useMap } from 'react-leaflet';
import L from 'leaflet';
import { fetchDashboardNodes, resolveSos, fetchQosEnabled } from './api';
import SosHistory from './SosHistory';
import QosPage from './QosPage';

const DEFAULT_CENTER = [-6.372108269939045, 106.82424173608405];

// Ikon pin merah dengan lingkaran target di ujung bawahnya
const redPinIcon = L.divIcon({
    className: 'custom-red-pin',
    html: `
      <div style="position: relative; width: 32px; height: 42px; display: flex; justify-content: center;">
        <div style="position: absolute; bottom: -4px; width: 16px; height: 8px; background: rgba(239, 68, 68, 0.35); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
          <div style="width: 8px; height: 4px; background: #ef4444; border: 1.5px solid #ffffff; border-radius: 50%;"></div>
        </div>
        <svg viewBox="0 0 24 36" width="32" height="42" style="position: relative; z-index: 2; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.3));">
          <path fill="#FF2A2A" d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24c0-6.6-5.4-12-12-12z"/>
          <circle cx="12" cy="12" r="5" fill="#7A0019"/>
        </svg>
      </div>
    `,
    iconSize: [32, 42],
    iconAnchor: [16, 42], // Ujung bawah pin menempel pas di titik koordinat
    tooltipAnchor: [0, -42], // Posisi label tepat di atas kepala pin
});

// Komponen penyesuai ukuran kanvas Leaflet secara otomatis saat dimuat
function MapResizeHandler() {
    const map = useMap();
    useEffect(() => {
        setTimeout(() => {
            map.invalidateSize();
        }, 200);
    }, [map]);
    return null;
}

export default function Dashboard() {
    // State Jam Real-Time
    const [currentTime, setCurrentTime] = useState(new Date().toLocaleTimeString('id-ID'));

    // Update jam setiap 1 detik
    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentTime(new Date().toLocaleTimeString('id-ID'));
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    // Data node pendaki
    const [nodes, setNodes] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [view, setView] = useState('monitor'); // 'monitor' | 'history'
    const [qosEnabled, setQosEnabled] = useState(false);

    useEffect(() => {
        let cancelled = false;
        fetchQosEnabled().then((ok) => { if (!cancelled) setQosEnabled(ok); });
        return () => { cancelled = true; };
    }, []);

    const [sosAlert, setSosAlert] = useState(null);
    const acknowledgedSos = useRef(new Set()); // "daftar centang" modal popup

    useEffect(() => {
        const activeIds = new Set(nodes.filter((n) => n.isSos).map((n) => n.id));

        // Hapus centang untuk node yang SOS-nya sudah mati di server
        acknowledgedSos.current.forEach((id) => {
            if (!activeIds.has(id)) acknowledgedSos.current.delete(id);
        });

        if (sosAlert) return; // modal sedang tampil, jangan ditimpa

        const fresh = nodes.find((n) => n.isSos && !acknowledgedSos.current.has(n.id));
        if (fresh) {
            acknowledgedSos.current.add(fresh.id);
            setSosAlert(fresh);
        }
    }, [nodes, sosAlert]);

    // Polling: ambil data dari server setiap 5 detik
    useEffect(() => {
        let cancelled = false;

        async function load() {
            try {
                const data = await fetchDashboardNodes();
                if (cancelled) return;
                setNodes(data);
                setError(null);
            } catch (err) {
                if (cancelled) return;
                setError(err.message);
            } finally {
                if (!cancelled) setLoading(false);
            }
        }

        load();
        const timer = setInterval(load, 5000);

        return () => {
            cancelled = true;
            clearInterval(timer);
        };
    }, []);

    // Fungsi saat petugas menekan tombol "Selesaikan Penanganan" pada kartu
    const handleResolveSos = async (node) => {
        if (!window.confirm(`Selesaikan SOS ${node.name}? Pastikan pendaki sudah aman.`)) return;
        try {
            await resolveSos(node.dbId);
            // Tampilan langsung berubah; polling berikutnya mengonfirmasi dari server
            setNodes((prev) => prev.map((n) => (n.id === node.id ? { ...n, isSos: false } : n)));
            if (sosAlert && sosAlert.id === node.id) setSosAlert(null);
        } catch (err) {
            window.alert(`Gagal menyelesaikan SOS: ${err.message}`);
        }
    };

    return (
        <div className="min-h-screen bg-white text-slate-800 flex flex-col font-sans selection:bg-cyan-500 selection:text-white">
            {/* Top Navigation Bar */}
            <header className="bg-slate-900 border-b border-slate-800 px-6 py-4 flex items-center justify-start sticky top-0 z-50 shadow-md">
                <h1 className="text-xl font-black tracking-wider text-white uppercase font-mono">
                    BASECAMP PENDAKIAN PUNCAK WANGU
                </h1>
                <nav className="ml-8 flex gap-2">
                    {[
                        ['monitor', 'MONITORING'],
                        ['history', 'RIWAYAT SOS'],
                        ...(qosEnabled ? [['qos', 'HITUNG QOS']] : []),
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setView(key)}
                            className={`px-4 py-1.5 rounded-lg text-xs font-mono font-bold tracking-wider transition-all ${
                                view === key
                                    ? 'bg-cyan-500 text-white'
                                    : 'text-slate-300 hover:bg-slate-800'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
            </header>

            {error && (
                <div className="bg-red-600 text-white text-xs font-mono px-6 py-2 text-center">
                    KONEKSI KE SERVER TERPUTUS, data di layar mungkin sudah tidak terbaru
                </div>
            )}
            {loading && (
                <div className="bg-slate-200 text-slate-600 text-xs font-mono px-6 py-2 text-center">
                    Memuat data pendaki...
                </div>
            )}

            {/* Main Operational Grid */}
            {view === 'history' ? (
                <SosHistory nodes={nodes} />
            ) : view === 'qos' && qosEnabled ? (
                <QosPage nodes={nodes} />
            ) : (
                <main className="flex-1 grid grid-cols-1 lg:grid-cols-4 gap-6 p-6 bg-slate-100/70">
                    
                    {/* Left/Center Panel: Map Visualization */}
                    <section className="lg:col-span-3 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm flex flex-col min-h-[550px]">
                        <div className="px-5 py-3.5 bg-slate-50 border-b border-slate-200 flex justify-start items-center">
                            <h2 className="font-mono font-bold text-xs uppercase text-slate-700 tracking-wider">
                                PETA LOKASI PENDAKI
                            </h2>
                        </div>
                        
                        <div className="flex-1 relative z-0 w-full h-[500px]">
                            <MapContainer 
                                center={DEFAULT_CENTER} 
                                zoom={14} 
                                minZoom={10}
                                maxZoom={18}
                                maxBounds={[[-90, -180], [90, 180]]}
                                maxBoundsViscosity={1.0}
                                scrollWheelZoom={true} 
                                className="h-full w-full absolute inset-0 rounded-b-2xl"
                            >
                                <MapResizeHandler />
                                <TileLayer
                                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                                    noWrap={true}
                                />
                                {nodes.filter((node) => node.hasLocation).map((node, index) => (
                                    <Marker 
                                        key={node.id} 
                                        position={[node.lat, node.lng]}
                                        icon={redPinIcon}
                                    >
                                        {/* Label permanen Node 1, Node 2, Node 3 di atas pin */}
                                        <Tooltip permanent direction="top" offset={[0, 0]}>
                                            <span className="font-mono font-bold text-xs text-slate-900">
                                                {`Node ${index + 1}`}
                                            </span>
                                        </Tooltip>

                                        <Popup>
                                            <div className="p-1 font-sans">
                                                <div className="flex items-center justify-between gap-2">
                                                    <h3 className="font-bold text-slate-900 text-sm">{node.name}</h3>
                                                    {node.isSos && (
                                                        <span className="bg-red-600 text-white text-[9px] font-mono px-1.5 py-0.5 rounded font-bold uppercase tracking-wider">
                                                            SOS AKTIF
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-xs text-slate-500 font-mono">ID: {node.id}</p>
                                                <div className="mt-2 text-xs flex justify-between gap-4 font-semibold">
                                                    <span>Baterai:</span>
                                                    <span className={node.isSos ? 'text-red-600 font-bold' : node.battery > 50 ? 'text-emerald-600' : 'text-amber-600'}>
                                                        {node.battery !== null ? `${node.battery}%` : 'TIDAK ADA DATA'}
                                                    </span>
                                                </div>
                                            </div>
                                        </Popup>
                                    </Marker>
                                ))}
                            </MapContainer>
                        </div>
                    </section>

                    {/* Right Panel: Status Pendaki & Jam Real-Time */}
                    <section className="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm flex flex-col">
                        <div className="flex flex-col items-center justify-center mb-4 border-b border-slate-200 pb-3">
                            <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider text-center">
                                STATUS PENDAKI
                            </h2>
                            <div className="mt-1 font-mono text-xs font-semibold text-cyan-600 bg-cyan-50 px-3 py-1 rounded-full border border-cyan-200 shadow-sm flex items-center gap-1.5">
                                <span className="w-2 h-2 rounded-full bg-cyan-500 animate-pulse"></span>
                                <span>{currentTime} WIB</span>
                            </div>
                        </div>

                        <div className="space-y-3.5 flex-1 overflow-y-auto">
                            {nodes.map((node) => {
                                const isMoreThan50 = node.battery > 50;

                                const batteryTheme = node.isSos
                                    ? {
                                        bg: 'bg-red-500',
                                        text: 'text-red-600',
                                        cardBg: 'bg-red-100/70 border-red-500 ring-2 ring-red-500/40 shadow-red-200'
                                    }
                                    : isMoreThan50
                                    ? {
                                        bg: 'bg-emerald-500',
                                        text: 'text-emerald-600',
                                        cardBg: 'bg-emerald-50/60 border-emerald-200'
                                    }
                                    : {
                                        bg: 'bg-amber-400',
                                        text: 'text-amber-600',
                                        cardBg: 'bg-amber-50/60 border-amber-200'
                                    };

                                return (
                                    <div 
                                        key={node.id}
                                        className={`p-4 rounded-xl border transition-all duration-300 relative overflow-hidden shadow-sm ${batteryTheme.cardBg}`}
                                    >
                                        {node.isSos && (
                                            <div className="absolute top-0 right-0 bg-red-600 text-[10px] font-mono font-black text-white px-2.5 py-0.5 rounded-bl-lg uppercase tracking-wider shadow-sm">
                                                SOS AKTIF
                                            </div>
                                        )}

                                        <div className="flex items-start justify-between mb-2">
                                            <div>
                                                <h4 className="font-bold text-sm text-slate-800">{node.name}</h4>
                                                <p className="text-[11px] font-mono text-slate-500">{node.id}</p>
                                            </div>
                                        </div>

                                        <div className="space-y-2 mt-3">
                                            <div className="flex items-center justify-between text-xs font-mono">
                                                <span className="text-slate-500 font-medium">ENERGY LEVEL</span>
                                                <span className={`font-bold ${batteryTheme.text}`}>
                                                    {node.battery !== null ? `${node.battery}%` : 'TIDAK ADA DATA'}
                                                </span>
                                            </div>

                                            <div className="w-full bg-slate-200 h-2.5 rounded-full overflow-hidden p-0.5 border border-slate-300/60">
                                                <div 
                                                    className={`h-full rounded-full transition-all duration-500 ${batteryTheme.bg}`}
                                                    style={{ width: `${node.battery ?? 0}%` }}
                                                ></div>
                                            </div>
                                        </div>

                                        {/* Tombol Matikan Notifikasi Merah / Selesaikan Penanganan */}
                                        {node.isSos && (
                                            <button
                                                onClick={() => handleResolveSos(node)}
                                                className="mt-3.5 w-full bg-red-600 hover:bg-red-700 text-white font-mono font-bold py-2 rounded-lg text-xs transition-all shadow-md active:scale-95 flex items-center justify-center gap-1"
                                            >
                                                SELESAIKAN PENANGANAN
                                            </button>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                </main>
            )}

            {/* High Priority Pure Red SOS Pop-Up Modal Alert (Tombol Simpel OK) */}
            {sosAlert && (
                <div className="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50">
                    <div className="bg-red-600 border-4 border-red-700 rounded-2xl max-w-sm w-full p-6 shadow-2xl text-white text-center font-sans">
                        <h2 className="text-5xl font-black tracking-widest mb-4 font-mono uppercase">
                            SOS
                        </h2>

                        <div className="bg-red-700/90 p-4 rounded-xl space-y-3 mb-6 text-left font-mono text-sm border border-red-500 shadow-inner">
                            <div className="flex justify-between border-b border-red-500/60 pb-2">
                                <span className="text-red-100">PENDAKI:</span>
                                <span className="font-bold text-white">{sosAlert.name}</span>
                            </div>
                            <div className="flex justify-between border-b border-red-500/60 pb-2">
                                <span className="text-red-100">LOKASI:</span>
                                <span className="font-bold text-white">{sosAlert.lat}, {sosAlert.lng}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-red-100">BATERAI:</span>
                                <span className="font-bold text-white">{sosAlert.battery !== null ? `${sosAlert.battery}%` : 'TIDAK ADA DATA'}</span>
                            </div>
                        </div>

                        <button
                            onClick={() => setSosAlert(null)}
                            className="w-full bg-white hover:bg-gray-100 text-red-700 font-black py-3 rounded-xl transition-all text-lg font-mono uppercase shadow-lg active:scale-95"
                        >
                            OK
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}