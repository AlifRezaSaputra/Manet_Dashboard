<?php

namespace App\Services;

use App\Models\QosEvent;
use App\Models\QosResult;
use App\Models\QosTest;
use Illuminate\Support\Collection;

class QosCalculationService
{
    public const FORMULA_VERSION = 'v2-flexible';

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
        $dest = $test->destination_node_id ?: 'BASE';
        $isDynamic = $test->topology_type === 'dynamic' || empty($test->source_node_id);
        $allEvents = QosEvent::where('qos_test_id', $test->id)->orderBy('id')->get();

        // Kumpulkan semua node aktif yang muncul di sesi ini (kecuali destination/BASE)
        $discoveredNodes = $allEvents
            ->flatMap(fn ($e) => [$e->node_id, $e->source_id])
            ->filter(fn ($id) => $id !== null && $id !== '' && $id !== $dest)
            ->unique()
            ->values()
            ->all();

        natsort($discoveredNodes);
        $discoveredNodes = array_values($discoveredNodes);

        // Metrik utama:
        // - Dynamic: semua sumber + overhead/airtime se-jaringan
        // - Fixed: sumber sesuai source_node_id, namun overhead/airtime/event tetap se-jaringan
        $primary = $isDynamic
            ? $this->computeScope($test, $allEvents, null, $dest, true)
            : $this->computeScope($test, $allEvents, $test->source_node_id, $dest, true);

        // Agregat ALL & breakdown per-node murni untuk tab filter di UI
        $allAggregate = $isDynamic ? $primary : $this->computeScope($test, $allEvents, null, $dest, true);
        $perNode = [];
        foreach ($discoveredNodes as $nodeId) {
            $perNode[$nodeId] = $this->computeScope($test, $allEvents, $nodeId, $dest, false);
        }

        // Gabungkan breakdown ke dalam metadata['by_source'] tiap metrik
        foreach ($primary as $metricKey => &$metric) {
            $bySource = [];

            if (!$isDynamic && isset($allAggregate[$metricKey])) {
                $bySource['ALL'] = [
                    'value'    => $allAggregate[$metricKey]['value'],
                    'unit'     => $allAggregate[$metricKey]['unit'],
                    'samples'  => $allAggregate[$metricKey]['samples'],
                    'metadata' => $allAggregate[$metricKey]['metadata'],
                ];
            }

            foreach ($discoveredNodes as $nodeId) {
                if (isset($perNode[$nodeId][$metricKey])) {
                    $sub = $perNode[$nodeId][$metricKey];
                    $bySource[$nodeId] = [
                        'value'    => $sub['value'],
                        'unit'     => $sub['unit'],
                        'samples'  => $sub['samples'],
                        'metadata' => $sub['metadata'],
                    ];
                }
            }

            $metric['metadata']['available_nodes'] = $discoveredNodes;
            $metric['metadata']['by_source'] = $bySource;
        }
        unset($metric);

        return $primary;
    }

    /**
     * Hitung seluruh metrik untuk scope tertentu:
     * - $targetNode === null -> Semua traffic (agregat seluruh source menuju $dest)
     * - $targetNode !== null -> Delivery/Throughput khusus $targetNode
     * - $networkWideOverhead -> Jika true, event counts, overhead, dan airtime dihitung se-jaringan
     */
    private function computeScope(
        QosTest $test,
        Collection $allEvents,
        ?string $targetNode,
        string $dest,
        bool $networkWideOverhead = false
    ): array {
        $na = fn (string $unit, string $reason, array $meta = []) => [
            'value' => null, 'unit' => $unit, 'samples' => 0,
            'metadata' => $meta + ['reason' => $reason],
        ];

        $packetKey = fn ($e) => ($e->source_id ?? 'UNKNOWN') . '|' . $e->boot_id . '|' . $e->sequence_number;

        // --- Paket DATA yang sampai di tujuan ($dest) ---
        $rxQuery = $allEvents->filter(fn ($e) =>
            $e->event_type === 'PACKET_RX' &&
            $e->packet_type === 'DATA' &&
            $e->node_id === $dest &&
            ($targetNode === null ? ($e->source_id !== null && $e->source_id !== $dest) : $e->source_id === $targetNode)
        );

        $validRx        = $rxQuery->filter(fn ($e) => $e->boot_id !== null && $e->sequence_number !== null);
        $delivered      = $validRx->unique($packetKey)->values();
        $deliveredCount = $delivered->count();

        $base = [
            'scope'                      => $targetNode ?? 'ALL',
            'destination'                => $dest,
            'rx_events_total'            => $rxQuery->count(),
            'rx_events_without_identity' => $rxQuery->count() - $validRx->count(),
            'duplicate_rx_events'        => $validRx->count() - $deliveredCount,
        ];

        // --- Penyebut: paket DATA yang dibuat sumber ---
        $txQuery = $allEvents->filter(fn ($e) =>
            $e->event_type === 'PACKET_TX' &&
            $e->packet_type === 'DATA' &&
            ($targetNode === null
                ? ($e->source_id !== null && $e->node_id === $e->source_id)
                : ($e->node_id === $targetNode && $e->source_id === $targetNode))
        );

        $txUnique = $txQuery
            ->filter(fn ($e) => $e->boot_id !== null && $e->sequence_number !== null)
            ->unique($packetKey);

        $generated = null;
        $method = null;
        $note = null;

        if ($txUnique->count() > 0) {
            $generated = $txUnique->count();
            $method = 'tx_events';
        } elseif ($deliveredCount > 0) {
            $generated = $delivered
                ->groupBy(fn ($e) => ($e->source_id ?? 'UNKNOWN') . '|' . $e->boot_id)
                ->sum(fn ($g) => $g->max('sequence_number') - $g->min('sequence_number') + 1);
            $method = 'sequence_gap_estimate';
            $note = 'Estimasi dari celah nomor urut per sumber. Paket hilang di awal/akhir urutan tidak terdeteksi.';
        }

        $foreign = $targetNode !== null
            ? $allEvents->filter(fn ($e) =>
                $e->packet_type === 'DATA' &&
                $e->source_id !== null &&
                $e->source_id !== $targetNode &&
                $e->source_id !== $dest
            )->count()
            : 0;

        $pdrMeta = $base + [
            'denominator_method'             => $method,
            'note'                           => $note,
            'data_events_from_other_sources' => $foreign,
        ];
        $results = [];

        if ($generated === null) {
            if ($targetNode !== null && $foreign > 0) {
                $reason = "Tidak ada event DATA yang cukup untuk menentukan jumlah paket yang dibuat sumber. "
                        . "Ada {$foreign} event DATA dengan source_id selain {$targetNode}; periksa node sumber pada sesi.";
            } elseif ($targetNode !== null) {
                $reason = "{$targetNode} tidak mengirim paket DATA sebagai sumber pada sesi ini (bisa jadi hanya berperan sebagai relay/kontrol).";
            } else {
                $reason = 'Tidak ada event DATA yang cukup untuk menentukan jumlah paket yang dibuat sumber.';
            }

            $results['pdr']               = $na('%', $reason, $pdrMeta);
            $results['packet_loss']       = $na('%', $reason, $pdrMeta);
            $results['generated_packets'] = $na('packets', $reason, $pdrMeta);
            $results['lost_packets']      = $na('packets', $reason, $pdrMeta);
        } elseif ($deliveredCount > $generated) {
            $reason = 'Data tidak konsisten: paket terkirim melebihi paket yang tercatat dibuat sumber.';
            $results['pdr']               = $na('%', $reason, $pdrMeta);
            $results['packet_loss']       = $na('%', $reason, $pdrMeta);
            $results['generated_packets'] = ['value' => $generated, 'unit' => 'packets', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['lost_packets']      = $na('packets', $reason, $pdrMeta);
        } else {
            $pdr = ($deliveredCount / $generated) * 100;
            $results['pdr']               = ['value' => $pdr, 'unit' => '%', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['packet_loss']       = ['value' => 100 - $pdr, 'unit' => '%', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['generated_packets'] = ['value' => $generated, 'unit' => 'packets', 'samples' => $generated, 'metadata' => $pdrMeta];
            $results['lost_packets']      = ['value' => $generated - $deliveredCount, 'unit' => 'packets', 'samples' => $generated, 'metadata' => $pdrMeta];
        }

        $results['delivered_packets'] = ($deliveredCount > 0 || $generated !== null)
            ? ['value' => $deliveredCount, 'unit' => 'packets', 'samples' => $deliveredCount, 'metadata' => $base]
            : $na('packets', 'Tidak ada paket DATA terkirim dari scope ini.', $base);

        // --- Hop count rata-rata ---
        $hops = $delivered->pluck('hop_count')->filter(fn ($h) => $h !== null);
        $results['avg_hop_count'] = $hops->count() > 0
            ? [
                'value' => $hops->avg(), 'unit' => 'hops', 'samples' => $hops->count(),
                'metadata' => $base + ['note' => 'Rata-rata hop_count dari paket unik yang tiba di tujuan.'],
            ]
            : $na('hops', 'Tidak ada hop_count pada paket yang terkirim.', $base);

        // --- RSSI / SNR ---
        $radio = $allEvents->filter(function ($e) use ($targetNode, $dest, $networkWideOverhead) {
            if ($e->event_type !== 'PACKET_RX' || !$e->has_radio_metrics) {
                return false;
            }
            if ($targetNode === null || $networkWideOverhead) {
                return $e->node_id === $dest;
            }
            return $e->node_id === $targetNode || ($e->node_id === $dest && $e->source_id === $targetNode);
        });

        $radioNote = ($targetNode && !$networkWideOverhead)
            ? "Event RX dengan metrik radio yang melibatkan {$targetNode}."
            : "Event RX di {$dest} dengan metrik radio valid.";

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
        $payloadBytes = $validRx
            ->filter(fn ($e) => $e->payload_length !== null)
            ->unique($packetKey)
            ->sum('payload_length');

        $duration = $test->duration_seconds;
        $tpMeta = [
            'duration_seconds'        => $duration,
            'delivered_payload_bytes' => (int) $payloadBytes,
            'note'                    => 'Throughput aplikasi (payload unik terkirim / durasi rekaman).',
        ];
        $results['throughput'] = ($duration && $duration > 0 && $payloadBytes > 0)
            ? ['value' => ($payloadBytes * 8) / $duration, 'unit' => 'bps', 'samples' => $deliveredCount, 'metadata' => $tpMeta]
            : $na('bps', 'Durasi rekaman atau payload_length terkirim tidak tersedia.', $tpMeta);

        // --- Hitungan event ---
        $isNodeOnly = $targetNode !== null && !$networkWideOverhead;
        $scopedEvents = $isNodeOnly
            ? $allEvents->filter(fn ($e) => $e->node_id === $targetNode || $e->source_id === $targetNode)
            : $allEvents;

        $results['data_tx_count'] = [
            'value' => $allEvents->filter(fn ($e) =>
                $e->event_type === 'PACKET_TX' && $e->packet_type === 'DATA' &&
                (!$isNodeOnly || $e->node_id === $targetNode)
            )->count(),
            'unit' => 'events', 'samples' => 0,
            'metadata' => ['note' => $isNodeOnly ? "Total TX DATA oleh {$targetNode} (sumber + relay)." : 'Semua event TX DATA yang tercatat.'],
        ];

        $results['data_rx_count'] = [
            'value' => $isNodeOnly
                ? $rxQuery->count()
                : $allEvents->filter(fn ($e) => $e->event_type === 'PACKET_RX' && $e->packet_type === 'DATA')->count(),
            'unit' => 'events', 'samples' => 0,
            'metadata' => ['note' => 'Semua event RX DATA yang tercatat, termasuk duplikat.'],
        ];

        $results['forwarding_count'] = [
            'value' => $allEvents->filter(function ($e) use ($targetNode, $isNodeOnly) {
                if ($e->event_type !== 'PACKET_TX' || $e->packet_type !== 'DATA') {
                    return false;
                }
                if ($isNodeOnly) {
                    return $e->source_id !== null && $e->node_id !== $e->source_id && $e->node_id === $targetNode;
                }
                return $targetNode !== null
                    ? $e->node_id !== $targetNode
                    : ($e->source_id !== null && $e->node_id !== $e->source_id);
            })->count(),
            'unit' => 'events', 'samples' => 0,
            'metadata' => ['note' => $isNodeOnly
                ? "Paket DATA milik node lain yang di-forward/relay oleh {$targetNode}."
                : 'Total paket DATA yang diteruskan oleh node selain sumber.'],
        ];

        $results['duplicate_count'] = [
            'value' => $base['duplicate_rx_events'],
            'unit' => 'events', 'samples' => 0,
            'metadata' => ['note' => 'Event RX DATA di tujuan dengan identitas paket yang sama.'],
        ];

        $results['dropped_count'] = [
            'value' => $scopedEvents->filter(fn ($e) => $e->event_status === 'failed' || $e->drop_reason !== null)->count(),
            'unit' => 'events', 'samples' => 0,
            'metadata' => ['note' => 'Event dengan status failed atau drop_reason terisi.'],
        ];

        $results['retransmission_count'] = $na('events', 'Firmware belum menyediakan penanda retransmisi pada event.');

        // --- Routing overhead & Airtime ---
        $nodeBasisAll = $isNodeOnly
            ? $allEvents->filter(fn ($e) => $e->node_id === $targetNode)
            : $allEvents;

        $hasTx = $nodeBasisAll->contains(fn ($e) => $e->event_type === 'PACKET_TX');
        $basis = $hasTx ? 'PACKET_TX' : 'PACKET_RX';
        $basisNote = $hasTx
            ? 'Dihitung dari event PACKET_TX yang tercatat.'
            : 'Tidak ada event TX; dihitung dari event PACKET_RX yang teramati.';

        $controlTypes = ['HELLO', 'RREQ', 'RREP', 'RERR', 'ACK'];
        $basisEvents = $nodeBasisAll->filter(fn ($e) => $e->event_type === $basis);

        $ohMeta = [
            'basis'         => $basis,
            'note'          => $basisNote,
            'control_types' => $controlTypes,
        ];

        if ($basisEvents->isEmpty()) {
            foreach ([
                'control_packet_count'         => 'events',
                'control_packet_bytes'         => 'bytes',
                'control_to_data_packet_ratio' => 'ratio',
                'control_to_data_byte_ratio'   => 'ratio',
            ] as $k => $u) {
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
                'data_events_without_payload_length'    => $dataCount - $dataWithLen->count(),
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
            foreach ($airKeys as $k => $u) {
                $results[$k] = $na($u, $reason);
            }
        } else {
            $timed = $basisEvents->filter(fn ($e) => $e->payload_length !== null);
            $airMeta = [
                'kind'                          => 'theoretical',
                'basis'                         => $basis,
                'note'                          => $basisNote . ' Airtime teoretis rumus Semtech.',
                'radio'                         => $cfg,
                'events_without_payload_length' => $basisEvents->count() - $timed->count(),
            ];

            if ($timed->isEmpty()) {
                foreach ($airKeys as $k => $u) {
                    $results[$k] = $na($u, 'Tidak ada event dengan payload_length.', $airMeta);
                }
            } else {
                $ms = fn ($e) => QosAirtimeCalculator::packetSeconds((int) $e->payload_length, $cfg) * 1000;
                $dataMs = $timed->where('packet_type', 'DATA')->sum($ms);
                $ctrlMs = $timed->whereIn('packet_type', $controlTypes)->sum($ms);
                $totalMs = $dataMs + $ctrlMs;
                $n = $timed->count();

                $byNode = $timed->groupBy('node_id')->map(fn ($g) => [
                    'airtime_ms'    => round($g->sum($ms), 3),
                    'transmissions' => $g->count(),
                ])->all();

                $results['airtime_data']       = ['value' => $dataMs, 'unit' => 'ms', 'samples' => $timed->where('packet_type', 'DATA')->count(), 'metadata' => $airMeta];
                $results['airtime_control']    = ['value' => $ctrlMs, 'unit' => 'ms', 'samples' => $timed->whereIn('packet_type', $controlTypes)->count(), 'metadata' => $airMeta];
                $results['airtime_total']      = ['value' => $totalMs, 'unit' => 'ms', 'samples' => $n, 'metadata' => $airMeta + ['by_node' => $byNode]];
                $results['airtime_avg_per_tx'] = ['value' => $totalMs / $n, 'unit' => 'ms', 'samples' => $n, 'metadata' => $airMeta];
            }
        }

        // E2E delay: jam millis() antar-ESP32 tidak sinkron
        $results['e2e_delay'] = $na('ms', 'Tidak dihitung: jam millis() antar node tidak tersinkronisasi.');

        return $results;
    }
}