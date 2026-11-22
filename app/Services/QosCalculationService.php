<?php

namespace App\Services;

use App\Models\QosEvent;
use App\Models\QosResult;
use App\Models\QosTest;

class QosCalculationService
{
    public const FORMULA_VERSION = 'v1';

    /** Hitung semua metrik dan simpan (update jika sudah ada, tanpa duplikat). */
    public function calculate(QosTest $test): array
    {
        $saved = [];
        $now = now();

        foreach ($this->compute($test) as $key => $m) {
            $saved[$key] = QosResult::updateOrCreate(
                ['qos_test_id' => $test->id, 'metric_key' => $key],
                [
                    'metric_value'  => $m['value'],
                    'unit'          => $m['unit'],
                    'sample_count'  => $m['samples'],
                    'metadata'      => $m['metadata'] + ['formula_version' => self::FORMULA_VERSION],
                    'calculated_at' => $now,
                ]
            );
        }

        return $saved;
    }

    /** Hitung tanpa menyimpan. Nilai null berarti N/A. */
    public function compute(QosTest $test): array
    {
        $source = $test->source_node_id;
        $dest   = $test->destination_node_id;

        // --- Paket DATA yang sampai di tujuan ---
        $rx = QosEvent::where('qos_test_id', $test->id)
            ->where('event_type', 'PACKET_RX')
            ->where('packet_type', 'DATA')
            ->where('node_id', $dest)
            ->where('source_id', $source)
            ->orderBy('id')
            ->get(['id', 'boot_id', 'sequence_number', 'hop_count']);

        $validRx   = $rx->filter(fn ($e) => $e->boot_id !== null && $e->sequence_number !== null);
        $delivered = $validRx->unique(fn ($e) => $e->boot_id . '|' . $e->sequence_number)->values();
        $deliveredCount = $delivered->count();

        $base = [
            'source'                 => $source,
            'destination'            => $dest,
            'rx_events_total'        => $rx->count(),
            'rx_events_without_identity' => $rx->count() - $validRx->count(),
            'duplicate_rx_events'    => $validRx->count() - $deliveredCount,
        ];

        $foreign = QosEvent::where('qos_test_id', $test->id)
            ->where('packet_type', 'DATA')
            ->whereNotNull('source_id')
            ->where('source_id', '!=', $source)
            ->count();
        $base['data_events_from_other_sources'] = $foreign;

        // --- Penyebut: paket yang dibuat sumber ---
        $tx = QosEvent::where('qos_test_id', $test->id)
            ->where('event_type', 'PACKET_TX')
            ->where('packet_type', 'DATA')
            ->where('node_id', $source)
            ->where('source_id', $source)
            ->get(['boot_id', 'sequence_number'])
            ->filter(fn ($e) => $e->boot_id !== null && $e->sequence_number !== null)
            ->unique(fn ($e) => $e->boot_id . '|' . $e->sequence_number);

        $generated = null;
        $method = null;
        $note = null;

        if ($tx->count() > 0) {
            $generated = $tx->count();
            $method = 'tx_events';
        } elseif ($deliveredCount > 0) {
            $generated = $delivered->groupBy('boot_id')
                ->sum(fn ($g) => $g->max('sequence_number') - $g->min('sequence_number') + 1);
            $method = 'sequence_gap_estimate';
            $note = 'Estimasi dari celah nomor urut. Paket hilang di awal/akhir urutan tidak terdeteksi, '
                  . 'sehingga PDR cenderung terlalu tinggi.';
        }

        $pdrMeta = $base + ['denominator_method' => $method, 'note' => $note];
        $na = fn (string $unit, string $reason, array $meta = []) => [
            'value' => null, 'unit' => $unit, 'samples' => 0,
            'metadata' => $meta + ['reason' => $reason],
        ];

        $results = [];

        if ($generated === null) {
            $reason = 'Tidak ada event DATA yang cukup untuk menentukan jumlah paket yang dibuat sumber.';
            if ($foreign > 0) {
                $reason .= " Ada {$foreign} event DATA dengan source_id selain {$source}; periksa node sumber pada sesi.";
            }
            
            $results['pdr']                = $na('%', $reason, $pdrMeta);
            $results['packet_loss']        = $na('%', $reason, $pdrMeta);
            $results['generated_packets']  = $na('packets', $reason, $pdrMeta);
            $results['lost_packets']       = $na('packets', $reason, $pdrMeta);
        } elseif ($deliveredCount > $generated) {
            $reason = 'Data tidak konsisten: paket terkirim melebihi paket yang tercatat dibuat sumber.';
            $results['pdr']                = $na('%', $reason, $pdrMeta);
            $results['packet_loss']        = $na('%', $reason, $pdrMeta);
            $results['generated_packets']  = [
                'value' => $generated, 'unit' => 'packets', 'samples' => $generated, 'metadata' => $pdrMeta,
            ];
            $results['lost_packets']       = $na('packets', $reason, $pdrMeta);
        } else {
            $pdr = $deliveredCount / $generated * 100;
            $results['pdr']               = ['value' => $pdr, 'unit' => '%', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['packet_loss']       = ['value' => 100 - $pdr, 'unit' => '%', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['generated_packets'] = ['value' => $generated, 'unit' => 'packets', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['lost_packets']      = ['value' => $generated - $deliveredCount, 'unit' => 'packets', 'samples' => $generated, 'metadata' => $pdrMeta];
        }

        // delivered_packets bisa dihitung selama ada event RX valid (bukan N/A jika 0 tapi ada TX)
        $results['delivered_packets'] = ($deliveredCount > 0 || $generated !== null)
            ? ['value' => $deliveredCount, 'unit' => 'packets', 'samples' => $deliveredCount, 'metadata' => $base]
            : $na('packets', 'Tidak ada event DATA terkirim maupun event TX dari sumber.', $base);

        // --- Hop count rata-rata dari paket unik yang terkirim ---
        $hops = $delivered->pluck('hop_count')->filter(fn ($h) => $h !== null);
        $results['avg_hop_count'] = $hops->count() > 0
            ? [
                'value' => $hops->avg(), 'unit' => 'hops', 'samples' => $hops->count(),
                'metadata' => $base + ['note' => 'Diambil dari hop_count pada event RX pertama tiap paket unik. '
                    . 'Makna pasti field ini menunggu konfirmasi firmware.'],
            ]
            : $na('hops', 'Tidak ada hop_count pada paket yang terkirim.', $base);

                    // --- RSSI / SNR (hanya RX di tujuan dengan radio metrics valid) ---
        $radio = QosEvent::where('qos_test_id', $test->id)
            ->where('event_type', 'PACKET_RX')
            ->where('node_id', $dest)
            ->where('has_radio_metrics', true)
            ->get(['rssi', 'snr']);

        $radioNote = 'Hanya event RX di node tujuan. Event relay belum diekspor ke collector.';
        foreach (['rssi' => ['dBm', 'rssi'], 'snr' => ['dB', 'snr']] as $name => [$unit, $col]) {
            $vals = $radio->pluck($col)->filter(fn ($v) => $v !== null)->values();
            if ($vals->count() > 0) {
                $meta = ['note' => $radioNote];
                $results["avg_{$name}"] = ['value' => $vals->avg(), 'unit' => $unit, 'samples' => $vals->count(), 'metadata' => $meta];
                $results["min_{$name}"] = ['value' => $vals->min(), 'unit' => $unit, 'samples' => $vals->count(), 'metadata' => $meta];
                $results["max_{$name}"] = ['value' => $vals->max(), 'unit' => $unit, 'samples' => $vals->count(), 'metadata' => $meta];
            } else {
                foreach (['avg', 'min', 'max'] as $p) {
                    $results["{$p}_{$name}"] = $na($unit, 'Tidak ada event RX dengan radio metrics valid.');
                }
            }
        }

        // --- Throughput aplikasi ---
        $payloadBytes = QosEvent::where('qos_test_id', $test->id)
            ->where('event_type', 'PACKET_RX')->where('packet_type', 'DATA')
            ->where('node_id', $dest)->where('source_id', $source)
            ->whereNotNull('boot_id')->whereNotNull('sequence_number')
            ->whereNotNull('payload_length')
            ->orderBy('id')
            ->get(['boot_id', 'sequence_number', 'payload_length'])
            ->unique(fn ($e) => $e->boot_id . '|' . $e->sequence_number)
            ->sum('payload_length');

        $duration = $test->duration_seconds;
        $tpMeta = [
            'duration_seconds' => $duration,
            'delivered_payload_bytes' => (int) $payloadBytes,
            'note' => 'Throughput aplikasi (payload terkirim / durasi rekaman), bukan bit rate PHY LoRa.',
        ];
        $results['throughput'] = ($duration && $duration > 0 && $payloadBytes > 0)
            ? ['value' => $payloadBytes * 8 / $duration, 'unit' => 'bps', 'samples' => $deliveredCount, 'metadata' => $tpMeta]
            : $na('bps', 'Durasi rekaman atau payload_length terkirim tidak tersedia.', $tpMeta);

        // --- Hitungan event ---
        $count = fn ($q) => $q->count();
        $evt = fn () => QosEvent::where('qos_test_id', $test->id);

        $results['data_tx_count'] = [
            'value' => $evt()->where('event_type', 'PACKET_TX')->where('packet_type', 'DATA')->count(),
            'unit' => 'events', 'samples' => 0, 'metadata' => ['note' => 'Hanya event TX yang sampai ke collector.'],
        ];
        $results['data_rx_count'] = [
            'value' => $evt()->where('event_type', 'PACKET_RX')->where('packet_type', 'DATA')->count(),
            'unit' => 'events', 'samples' => 0, 'metadata' => ['note' => 'Semua event RX DATA yang tercatat, termasuk duplikat.'],
        ];
        $results['forwarding_count'] = [
            'value' => $evt()->where('event_type', 'PACKET_TX')->where('packet_type', 'DATA')->where('node_id', '!=', $source)->count(),
            'unit' => 'events', 'samples' => 0, 'metadata' => ['note' => 'TX DATA oleh node selain sumber. 0 bisa berarti relay belum mengekspor event.'],
        ];
        $results['duplicate_count'] = [
            'value' => $base['duplicate_rx_events'],
            'unit' => 'events', 'samples' => 0, 'metadata' => ['note' => 'Event RX DATA di tujuan dengan identitas paket yang sama.'],
        ];
        $results['dropped_count'] = [
            'value' => $evt()->where(fn ($q) => $q->where('event_status', 'failed')->orWhereNotNull('drop_reason'))->count(),
            'unit' => 'events', 'samples' => 0, 'metadata' => ['note' => 'Event dengan status failed atau drop_reason terisi.'],
        ];
        $results['retransmission_count'] = $na('events', 'Firmware belum menyediakan penanda retransmisi pada event.');
        
                // --- Basis: TX bila ada (transmisi nyata), jika tidak RX (yang teramati) ---
        $hasTx = QosEvent::where('qos_test_id', $test->id)->where('event_type', 'PACKET_TX')->exists();
        $basis = $hasTx ? 'PACKET_TX' : 'PACKET_RX';
        $basisNote = $hasTx
            ? 'Dihitung dari event PACKET_TX yang sampai ke collector.'
            : 'Tidak ada event TX; dihitung dari event PACKET_RX yang teramati. Nilai cenderung lebih rendah dari transmisi sebenarnya.';

        $controlTypes = ['HELLO', 'RREQ', 'RREP', 'RERR', 'ACK'];
        $basisEvents = QosEvent::where('qos_test_id', $test->id)
            ->where('event_type', $basis)
            ->get(['node_id', 'packet_type', 'payload_length']);

        // --- Routing overhead ---
        $ohMeta = [
            'basis' => $basis,
            'note' => $basisNote,
            'control_types' => $controlTypes,
        ];

        if ($basisEvents->isEmpty()) {
            foreach (['control_packet_count' => 'events', 'control_packet_bytes' => 'bytes',
                      'control_to_data_packet_ratio' => 'ratio', 'control_to_data_byte_ratio' => 'ratio'] as $k => $u) {
                $results[$k] = $na($u, 'Tidak ada event paket yang tercatat.', $ohMeta);
            }
        } else {
            $ctrl = $basisEvents->whereIn('packet_type', $controlTypes);
            $data = $basisEvents->where('packet_type', 'DATA');
            $ctrlCount = $ctrl->count();
            $dataCount = $data->count();
            $ctrlWithLen = $ctrl->filter(fn ($e) => $e->payload_length !== null);
            $dataWithLen = $data->filter(fn ($e) => $e->payload_length !== null);
            $ctrlBytes = (int) $ctrlWithLen->sum('payload_length');
            $dataBytes = (int) $dataWithLen->sum('payload_length');
            $ohMeta += [
                'control_events_without_payload_length' => $ctrlCount - $ctrlWithLen->count(),
                'data_events_without_payload_length' => $dataCount - $dataWithLen->count(),
            ];

            $results['control_packet_count'] = ['value' => $ctrlCount, 'unit' => 'events', 'samples' => $ctrlCount, 'metadata' => $ohMeta];
            $results['control_packet_bytes'] = ($ctrlCount === 0 || $ctrlWithLen->count() > 0)
                ? ['value' => $ctrlBytes, 'unit' => 'bytes', 'samples' => $ctrlWithLen->count(), 'metadata' => $ohMeta]
                : $na('bytes', 'Event kontrol tidak memiliki payload_length.', $ohMeta);
            $results['control_to_data_packet_ratio'] = $dataCount > 0
                ? ['value' => $ctrlCount / $dataCount, 'unit' => 'ratio', 'samples' => $dataCount, 'metadata' => $ohMeta]
                : $na('ratio', 'Tidak ada paket DATA sebagai pembanding.', $ohMeta);
            $results['control_to_data_byte_ratio'] = $dataBytes > 0 && ($ctrlCount === 0 || $ctrlWithLen->count() > 0)
                ? ['value' => $ctrlBytes / $dataBytes, 'unit' => 'ratio', 'samples' => $dataWithLen->count(), 'metadata' => $ohMeta]
                : $na('ratio', 'Byte DATA atau byte kontrol tidak tersedia.', $ohMeta);
        }

        // --- Airtime teoretis LoRa ---
        $cfg = QosAirtimeCalculator::config($test->radio_config);
        $airKeys = ['airtime_data' => 'ms', 'airtime_control' => 'ms', 'airtime_total' => 'ms', 'airtime_avg_per_tx' => 'ms'];

        if ($cfg === null) {
            $reason = 'Konfigurasi radio (SF, BW, CR, preamble) belum lengkap pada sesi ini.';
            foreach ($airKeys as $k => $u) $results[$k] = $na($u, $reason);
        } else {
            $timed = $basisEvents->filter(fn ($e) => $e->payload_length !== null);
            $airMeta = [
                'kind' => 'theoretical',
                'basis' => $basis,
                'note' => $basisNote . ' Memakai payload_length sebagai ukuran payload yang dikirim radio; '
                        . 'perlu dikonfirmasi firmware. Ini airtime teoretis, bukan durasi radio terukur.',
                'radio' => $cfg,
                'events_without_payload_length' => $basisEvents->count() - $timed->count(),
            ];

            if ($timed->isEmpty()) {
                foreach ($airKeys as $k => $u) $results[$k] = $na($u, 'Tidak ada event dengan payload_length.', $airMeta);
            } else {
                $ms = fn ($e) => QosAirtimeCalculator::packetSeconds((int) $e->payload_length, $cfg) * 1000;
                $dataMs = $timed->where('packet_type', 'DATA')->sum($ms);
                $ctrlMs = $timed->whereIn('packet_type', $controlTypes)->sum($ms);
                $totalMs = $dataMs + $ctrlMs;
                $n = $timed->count();

                $byNode = $timed->groupBy('node_id')->map(fn ($g) => [
                    'airtime_ms' => round($g->sum($ms), 3),
                    'transmissions' => $g->count(),
                ])->all();

                $results['airtime_data']       = ['value' => $dataMs, 'unit' => 'ms', 'samples' => $timed->where('packet_type', 'DATA')->count(), 'metadata' => $airMeta];
                $results['airtime_control']    = ['value' => $ctrlMs, 'unit' => 'ms', 'samples' => $timed->whereIn('packet_type', $controlTypes)->count(), 'metadata' => $airMeta];
                $results['airtime_total']      = ['value' => $totalMs, 'unit' => 'ms', 'samples' => $n, 'metadata' => $airMeta + ['by_node' => $byNode]];
                $results['airtime_avg_per_tx'] = ['value' => $totalMs / $n, 'unit' => 'ms', 'samples' => $n, 'metadata' => $airMeta];
            }
        }

        // E2E delay: jam millis() antar-ESP32 tidak sinkron
        $results['e2e_delay'] = $na('ms', 'Tidak dihitung: jam millis() antar node tidak tersinkronisasi. '
            . 'Selisih timestamp lintas node tidak valid tanpa metode sinkronisasi yang tervalidasi.');

        return $results;
    }
}