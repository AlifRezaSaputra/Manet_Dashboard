<?php

namespace App\Http\Controllers;

use App\Models\QosResult;
use App\Models\QosTest;
use App\Services\QosCalculationService;

class QosResultController extends Controller
{
    private const GROUPS = [
        'delivery'    => ['pdr', 'packet_loss', 'generated_packets', 'delivered_packets', 'lost_packets'],
        'performance' => ['throughput', 'e2e_delay'],
        'radio'       => ['avg_rssi', 'min_rssi', 'max_rssi', 'avg_snr', 'min_snr', 'max_snr'],
        'routing'     => ['avg_hop_count', 'forwarding_count', 'retransmission_count',
                          'control_packet_count', 'control_packet_bytes',
                          'control_to_data_packet_ratio', 'control_to_data_byte_ratio'],
        'airtime'     => ['airtime_data', 'airtime_control', 'airtime_total', 'airtime_avg_per_tx'],
        'events'      => ['data_tx_count', 'data_rx_count', 'duplicate_count', 'dropped_count'],
    ];

    public function show($id)
    {
        $test = QosTest::find($id);

        if (!$test) {
            return response()->json(['success' => false, 'message' => 'Test not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->payload($test)]);
    }

    public function calculate($id, QosCalculationService $calculator)
    {
        $test = QosTest::find($id);

        if (!$test) {
            return response()->json(['success' => false, 'message' => 'Test not found'], 404);
        }

        if ($test->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Hanya sesi completed yang bisa dihitung'], 409);
        }

        $calculator->calculate($test);

        return response()->json(['success' => true, 'data' => $this->payload($test)]);
    }

    private function payload(QosTest $test): array
    {
        $rows = QosResult::where('qos_test_id', $test->id)->get()->keyBy('metric_key');

        $groups = [];
        foreach (self::GROUPS as $group => $keys) {
            $groups[$group] = [];
            foreach ($keys as $key) {
                if (isset($rows[$key])) {
                    $groups[$group][] = $this->format($rows[$key]);
                }
            }
        }

        $known = collect(self::GROUPS)->flatten()->all();
        $groups['other'] = $rows->reject(fn ($r, $key) => in_array($key, $known, true))->map(fn ($r) => $this->format($r))->values()->all();

        return [
            'test' => [
                'id'               => $test->id,
                'test_code'        => $test->test_code,
                'name'             => $test->name,
                'status'           => $test->status,
                'topology_type'    => $test->topology_type,
                'path'             => $test->topology_config['path'] ?? [],
                'duration_seconds' => $test->duration_seconds,
                'radio_config'     => $test->radio_config,
            ],
            'calculated'    => $rows->isNotEmpty(),
            'calculated_at' => $rows->max('calculated_at'),
            'groups'        => $groups,
        ];
    }

    private function format(QosResult $r): array
    {
        return [
            'key'         => $r->metric_key,
            'value'       => $r->metric_value,          // null = N/A, bukan 0
            'unit'        => $r->unit,
            'samples'     => $r->sample_count,
            'available'   => $r->metric_value !== null,
            'reason'      => $r->metadata['reason'] ?? null,
            'note'        => $r->metadata['note'] ?? null,
            'metadata'    => $r->metadata,
        ];
    }
}