import React, { useState, useEffect, useRef } from 'react';
import { MapContainer, TileLayer, Marker, Popup, useMap } from 'react-leaflet';
import { fetchDashboardNodes} from './api';

const DEFAULT_CENTER = [-6.235045, 106.871906]; 

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

    const [sosAlert, setSosAlert] = useState(null);
    const acknowledgedSos = useRef(new Set()); // "daftar centang"

    useEffect(() => {
        const activeIds = new Set(nodes.filter((n) => n.isSos).map((n) => n.id));

        // Hapus centang untuk node yang SOS-nya sudah mati
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
                setError(err.message); // data lama tetap tampil, hanya banner error muncul
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

    return (
        <div className="min-h-screen bg-white text-slate-800 flex flex-col font-sans selection:bg-cyan-500 selection:text-white">
            {/* Top Navigation Bar */}
            <header className="bg-slate-900 border-b border-slate-800 px-6 py-4 flex items-center justify-start sticky top-0 z-50 shadow-md">
                <h1 className="text-xl font-black tracking-wider text-white uppercase font-mono">
                    BASECAMP PENDAKIAN PUNCAK WANGU
                </h1>
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
                            {nodes.filter((node) => node.hasLocation).map((node) => (
                                <Marker key={node.id} position={[node.lat, node.lng]}>
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
                                </div>
                            );
                        })}
                    </div>
                </section>
            </main>

            {/* High Priority Pure Red SOS Pop-Up Modal Alert */}
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


