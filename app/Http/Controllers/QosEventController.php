<?php

namespace App\Http\Controllers;

use App\Models\QosEvent;
use App\Models\QosTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class QosEventController extends Controller
{
    // KONTRAK SEMENTARA: berdasarkan spesifikasi, belum dikonfirmasi firmware.
    private const RULES = [
        'event_uuid'        => 'required|string|max:36',
        'node_id'           => 'required|string|max:30',
        'event_type'        => 'required|string|max:40',
        'packet_type'       => 'required|in:DATA,ACK,HELLO,RREQ,RREP,RERR',
        'source_id'         => 'nullable|string|max:30',
        'destination_id'    => 'nullable|string|max:30',
        'boot_id'           => 'nullable|integer|min:0',
        'sequence_number'   => 'nullable|integer|min:0',
        'origin_timestamp'  => 'nullable|integer|min:0',
        'event_timestamp'   => 'nullable|integer|min:0',
        'hop_count'         => 'nullable|integer|min:0|max:65535',
        'next_hop'          => 'nullable|string|max:30',
        'payload_length'    => 'nullable|integer|min:0|max:65535',
        'has_radio_metrics' => 'nullable|boolean',
        'rssi'              => 'nullable|numeric',
        'snr'               => 'nullable|numeric',
        'event_status'      => 'nullable|in:success,failed,attempt,unknown',
        'drop_reason'       => 'nullable|string|max:40',
        'collected_at'      => 'required|date',
        'raw_event'         => 'required|string',
    ];

    public function store(Request $request, $id)
    {
        $request->validate(['events' => 'required|array|min:1|max:500']);

        $test = QosTest::find($id);

        if (!$test) {
            return response()->json(['success' => false, 'message' => 'Test not found'], 404);
        }

        if ($test->status !== 'recording') {
            return response()->json(['success' => false, 'message' => 'Sesi tidak sedang merekam'], 409);
        }

        $accepted   = 0;
        $duplicates = 0;
        $rejected   = [];

        foreach ($request->input('events') as $i => $raw) {
            $v = Validator::make(is_array($raw) ? $raw : [], self::RULES);

            if ($v->fails()) {
                $rejected[] = ['index' => $i, 'errors' => $v->errors()->all()];
                continue;
            }

            $e        = $v->validated();
            $hasRadio = (bool) ($e['has_radio_metrics'] ?? false);

            $result = DB::transaction(function () use ($test, $e, $hasRadio) {
                // Kunci sesi: stop yang datang bersamaan tidak boleh disalip
                $fresh = QosTest::lockForUpdate()->find($test->id);

                if ($fresh->status !== 'recording') {
                    return 'closed';
                }

                if (QosEvent::where('event_uuid', $e['event_uuid'])->exists()) {
                    return 'duplicate';
                }

                $e['has_radio_metrics'] = $hasRadio;

                if (!$hasRadio) {
                    $e['rssi'] = null;
                    $e['snr']  = null;
                }

                QosEvent::create($e + ['qos_test_id' => $test->id]);

                return 'ok';
            });

            if ($result === 'ok') {
                $accepted++;
            } elseif ($result === 'duplicate') {
                $duplicates++;
            } else {
                $rejected[] = ['index' => $i, 'errors' => ['Sesi berhenti merekam']];
            }
        }

        return response()->json([
            'success'    => true,
            'accepted'   => $accepted,
            'duplicates' => $duplicates,
            'rejected'   => $rejected,
        ], 201);
    }

        public function index(Request $request, $id)
    {
        $test = QosTest::find($id);

        if (!$test) {
            return response()->json(['success' => false, 'message' => 'Test not found'], 404);
        }

        $limit = min(max((int) $request->query('limit', 50), 1), 200);

        $byType = QosEvent::where('qos_test_id', $test->id)
            ->selectRaw('event_type, packet_type, COUNT(*) as total')
            ->groupBy('event_type', 'packet_type')
            ->get();

        $latest = QosEvent::where('qos_test_id', $test->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'   => QosEvent::where('qos_test_id', $test->id)->count(),
                'by_type' => $byType,
                'latest'  => $latest,
            ],
        ]);
    }
}