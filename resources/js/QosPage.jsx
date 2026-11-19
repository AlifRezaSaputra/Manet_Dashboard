import React from 'react';

export default function QosPage() {
    return (
        <main className="flex-1 p-6 bg-slate-100/70">
            <section className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-5 py-4 bg-slate-50 border-b border-slate-200">
                    <h2 className="font-mono font-bold text-sm uppercase text-slate-700 tracking-wider">
                        Hitung QoS
                    </h2>
                </div>
                <div className="p-10 text-center text-sm font-mono text-slate-500">
                    Modul QoS aktif (mode pengembangan).
                </div>
            </section>
        </main>
    );
}