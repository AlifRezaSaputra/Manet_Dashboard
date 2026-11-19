<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQosTestRequest;
use App\Models\QosTest;
use Illuminate\Support\Facades\DB;

class QosTestController extends Controller
{
    public function store(StoreQosTestRequest $request)
    {
        $data  = $request->validated();
        $nodes = $data['nodes'];
        $last  = count($nodes) - 1;

        $test = DB::transaction(function () use ($data, $nodes, $last) {
            $today = now()->format('Ymd');
            $count = QosTest::where('test_code', 'like', "QOS-{$today}-%")->lockForUpdate()->count();
            $code  = sprintf('QOS-%s-%03d', $today, $count + 1);

            $test = QosTest::create([
                'test_code'           => $code,
                'name'                => $data['name'],
                'description'         => $data['description'] ?? null,
                'topology_type'       => $data['topology_type'],
                'source_node_id'      => $nodes[0],
                'destination_node_id' => $nodes[$last],
                'topology_config'     => ['path' => $nodes],
                'radio_config'        => $data['radio_config'] ?? null,
                'status'              => 'pending',
                'notes'               => $data['notes'] ?? null,
            ]);

            foreach ($nodes as $i => $nodeId) {
                $test->nodes()->create([
                    'node_id'        => $nodeId,
                    'node_role'      => $i === 0 ? 'source' : ($i === $last ? 'destination' : 'relay'),
                    'sequence_order' => $i + 1,
                ]);
            }

            return $test;
        });

        return response()->json([
            'success' => true,
            'data'    => $test->load('nodes'),
        ], 201);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => QosTest::orderByDesc('id')->get(),
        ]);
    }

    public function show($id)
    {
        $test = QosTest::with('nodes')->find($id);

        if (!$test) {
            return response()->json(['success' => false, 'message' => 'Test not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $test]);
    }

        public function start($id)
    {
        return DB::transaction(function () use ($id) {
            $test = QosTest::lockForUpdate()->find($id);

            if (!$test) {
                return response()->json(['success' => false, 'message' => 'Test not found'], 404);
            }

            if ($test->status !== 'pending') {
                return response()->json(['success' => false, 'message' => 'Hanya sesi pending yang bisa dimulai'], 409);
            }

            if (QosTest::where('status', 'recording')->exists()) {
                return response()->json(['success' => false, 'message' => 'Masih ada sesi lain yang sedang merekam'], 409);
            }

            $test->update(['status' => 'recording', 'started_at' => now()]);

            return response()->json(['success' => true, 'data' => $test->fresh('nodes')]);
        });
    }

    public function stop($id)
    {
        return DB::transaction(function () use ($id) {
            $test = QosTest::lockForUpdate()->find($id);

            if (!$test) {
                return response()->json(['success' => false, 'message' => 'Test not found'], 404);
            }

            if ($test->status !== 'recording') {
                return response()->json(['success' => false, 'message' => 'Hanya sesi recording yang bisa dihentikan'], 409);
            }

            $ended = now();
            $test->update([
                'status'           => 'completed',
                'ended_at'         => $ended,
                'duration_seconds' => $test->started_at->diffInSeconds($ended),
            ]);

            return response()->json(['success' => true, 'data' => $test->fresh('nodes')]);
        });
    }
}