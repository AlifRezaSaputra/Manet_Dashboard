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

        return $results;
    }
}