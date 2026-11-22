<!DOCTYPE html>
<html lang="id">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Laporan QoS {{ $test->test_code }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1e293b; }
    h1 { font-size: 17px; margin: 0; }
    h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #94a3b8; }
    h3 { font-size: 10.5px; margin: 12px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; }
    .muted { color: #64748b; }
    .na { color: #94a3b8; }
    .est { color: #b45309; font-weight: bold; }
    .small { font-size: 9px; }
    .bar { background: #fca5a5; height: 9px; }
    .bar div { height: 9px; background: #10b981; }
    .bar2 { background: #e2e8f0; height: 8px; }
    .bar2 div { height: 8px; background: #06b6d4; }
    .box { border: 1px solid #fcd34d; background: #fffbeb; padding: 6px 10px; }
    ul { margin: 4px 0 0 14px; padding: 0; }
    li { margin-bottom: 2px; }
</style>
</head>
<body>

<h1>Laporan Pengukuran QoS MANET</h1>
<p class="muted">MANET Dashboard · Basecamp Pendakian Puncak Wangu · Dibuat {{ $generated_at }}</p>

<h2>1. Identifikasi pengujian</h2>
<table>
    <tr><th style="width:25%">Kode</th><td>{{ $test->test_code }}</td></tr>
    <tr><th>Nama</th><td>{{ $test->name }}</td></tr>
    <tr><th>Deskripsi</th><td>{{ $test->description ?: '-' }}</td></tr>
    <tr><th>Status</th><td>{{ $test->status }}</td></tr>
    <tr><th>Dibuat</th><td>{{ $created }}</td></tr>
    <tr><th>Mulai rekaman</th><td>{{ $started }}</td></tr>
    <tr><th>Selesai rekaman</th><td>{{ $ended }}</td></tr>
    <tr><th>Durasi rekaman</th><td>{{ $test->duration_seconds !== null ? $test->duration_seconds . ' detik' : '-' }}</td></tr>
    <tr><th>Metrik dihitung</th><td>{{ $calculated_at }}</td></tr>
</table>

<h2>2. Topologi dan node peserta</h2>
<p><b>Topologi:</b> {{ $test->topology_type }} · <b>Jalur rencana:</b> {{ $intended_path }}</p>
<table>
    <tr><th>Urutan</th><th>Node</th><th>Peran</th></tr>
    @foreach($test->nodes as $n)
        <tr><td>{{ $n->sequence_order }}</td><td>{{ $n->node_id }}</td><td>{{ $n->node_role }}</td></tr>
    @endforeach
</table>

<h3>Jalur aktual yang teramati di Basecamp</h3>
@if(count($observed_paths))
    <table>
        <tr><th>Jalur</th><th style="width:15%">Jumlah paket</th><th style="width:25%">Terhadap rencana</th></tr>
        @foreach($observed_paths as $p)
            <tr>
                <td>{{ str_replace('>', ' → ', $p['path']) }}</td>
                <td>{{ $p['count'] }}</td>
                <td>{{ $p['matches_plan'] ? 'Sesuai rencana' : 'Berbeda dari rencana' }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p class="muted">Tidak ada informasi jalur pada event yang tercatat (N/A).</p>
@endif

<h2>3. Konfigurasi radio LoRa</h2>
@if($radio_rows)
    <table>
        @foreach($radio_rows as $k => $v)
            <tr><th style="width:35%">{{ $k }}</th><td>{{ $v }}</td></tr>
        @endforeach
    </table>
    <p class="small muted">Mode header, CRC, dan LDRO memakai nilai bawaan perhitungan (header eksplisit, CRC aktif, LDRO otomatis) dan belum dikonfirmasi dari firmware.</p>
@else
    <p class="muted">Konfigurasi radio tidak diisi pada sesi ini, sehingga airtime N/A.</p>
@endif

<h2>4. Ringkasan metrik QoS</h2>
<p class="small muted">N/A berarti data tidak tersedia untuk dihitung, bukan bernilai nol. ESTIMASI berarti nilai diperkirakan dari data tidak langsung.</p>

@foreach($groups as $group => $metrics)
    <h3>{{ $group_titles[$group] ?? $group }}</h3>
    <table>
        <tr><th style="width:32%">Metrik</th><th style="width:20%">Nilai</th><th style="width:7%">n</th><th>Keterangan</th></tr>
        @foreach($metrics as $m)
            <tr>
                <td>{{ $m['label'] }}</td>
                <td class="{{ $m['available'] ? '' : 'na' }}"><b>{{ $m['display'] }}</b>@if($m['estimate']) <span class="est">ESTIMASI</span>@endif</td>
                <td>{{ $m['samples'] }}</td>
                <td class="small">{{ $m['available'] ? ($m['note'] ?? '') : ($m['reason'] ?? '') }}</td>
            </tr>
        @endforeach
    </table>

    @if($group === 'delivery' && $delivery)
        <div class="bar"><div style="width: {{ $delivery['pct'] }}%"></div></div>
        <p class="small muted">Hijau: terkirim {{ $delivery['delivered'] }} paket · Merah: hilang {{ $delivery['lost'] }} paket</p>
    @endif

    @if($group === 'airtime' && count($airtime_nodes))
        <p class="small"><b>Airtime teoretis per node</b></p>
        <table>
            <tr><th style="width:15%">Node</th><th style="width:15%">Transmisi</th><th style="width:20%">Airtime</th><th></th></tr>
            @foreach($airtime_nodes as $a)
                <tr>
                    <td>{{ $a['node'] }}</td>
                    <td>{{ $a['transmissions'] }}</td>
                    <td>{{ number_format($a['airtime_ms'], 2, ',', '.') }} ms</td>
                    <td><div class="bar2"><div style="width: {{ $a['pct'] }}%"></div></div></td>
                </tr>
            @endforeach
        </table>
    @endif
@endforeach

<h2>5. Definisi dan rumus</h2>
<ul>
    <li><b>PDR</b> = paket DATA unik terkirim ke Basecamp ÷ paket DATA unik dibuat sumber × 100%. Identitas paket: source_id + boot_id + sequence_number. Salinan forward tidak menambah hitungan.</li>
    <li><b>Packet Loss</b> = 100% − PDR.</li>
    <li><b>Throughput aplikasi</b> = Σ payload paket DATA unik terkirim × 8 ÷ durasi rekaman (bps). Bukan bit rate PHY LoRa.</li>
    <li><b>RSSI / SNR</b>: rata-rata, minimum, maksimum dari event RX di Basecamp yang memiliki radio metrics valid.</li>
    <li><b>Hop count</b>: rata-rata hop_count pada paket DATA unik yang terkirim.</li>
    <li><b>Routing overhead</b>: paket kontrol (HELLO, RREQ, RREP, RERR, ACK) dan rasionya terhadap DATA, dalam jumlah paket dan byte.</li>
    <li><b>Airtime teoretis (Semtech)</b>: T_sym = 2^SF ÷ BW; T_preamble = (n_preamble + 4,25) × T_sym; n_payload = 8 + max(ceil((8·PL − 4·SF + 28 + 16·CRC − 20·IH) ÷ (4·(SF − 2·DE))) × CR, 0); ToA = T_preamble + n_payload × T_sym, dengan CR bernilai 5 sampai 8.</li>
    <li><b>End-to-End Delay</b>: tidak dihitung (lihat batasan).</li>
</ul>

<h2>6. Statistik pengumpulan event</h2>
<p>Total event: <b>{{ $event_total }}</b> · Pertama: {{ $event_first }} · Terakhir: {{ $event_last }}</p>
@if($event_types->count())
    <table>
        <tr><th>Tipe event</th><th>Tipe paket</th><th style="width:15%">Jumlah</th></tr>
        @foreach($event_types as $t)
            <tr><td>{{ $t->event_type }}</td><td>{{ $t->packet_type }}</td><td>{{ $t->total }}</td></tr>
        @endforeach
    </table>
@endif

<h2>7. Batasan pengukuran</h2>
<div class="box">
    <ul>
        <li>Airtime adalah perhitungan teoretis, bukan durasi radio terukur.</li>
        <li>Event hanya yang teramati di Basecamp. Event lokal relay belum diekspor ke collector, sehingga forwarding dan RSSI/SNR relay tidak tersedia.</li>
        <li>Tanpa event TX dari sumber, jumlah paket yang dibuat diperkirakan dari celah nomor urut. Paket hilang di awal atau akhir urutan tidak terdeteksi, sehingga PDR cenderung terlalu tinggi.</li>
        <li>End-to-End Delay tidak dihitung karena jam millis() antar-node tidak tersinkronisasi.</li>
        <li>Retransmisi tidak dapat ditentukan karena firmware belum menandai retry.</li>
        <li>Jika event berasal dari bridge Python versi saat ini: boot_id adalah perkiraan bridge (bukan data firmware), dan payload_length adalah panjang string payload yang diterima Basecamp.</li>
        <li>Arti hop_count pada event RX mengikuti laporan Basecamp dan belum dikonfirmasi oleh tim firmware.</li>
    </ul>
</div>

<h2>8. Catatan eksperimen</h2>
<p>{{ $test->notes ?: '-' }}</p>

</body>
</html>