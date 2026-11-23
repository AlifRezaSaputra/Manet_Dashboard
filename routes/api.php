<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Support\AdminToken;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Hash;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', function (Request $request) {

    $data = $request->validate([
        'password' => 'required|string|max:200',
    ]);

    $hash = config('admin.password_hash');

    // Belum dikonfigurasi: tolak semua, jangan pernah buka akses
    if (empty($hash)) {
        return response()->json([
            'success' => false,
            'message' => 'Login belum dikonfigurasi di server'
        ], 503);
    }

    if (!Hash::check($data['password'], $hash)) {
        return response()->json([
            'success' => false,
            'message' => 'Password salah'
        ], 401);
    }

    return response()->json([
        'success'    => true,
        'token'      => AdminToken::issue(),
        'expires_in' => config('admin.token_ttl'),
    ]);

})->middleware('throttle:5,1'); // maksimal 5 percobaan per menit per IP

/*
|--------------------------------------------------------------------------
| Dashboard API
|--------------------------------------------------------------------------
*/
Route::middleware([AdminAuth::class])->group(function () {

    Route::get('/dashboard/nodes', function () {
        return response()->json(
            DB::table('node')->get()
        );
    });

    Route::get('/dashboard/nodes/{id}', function ($id) {

        $node = DB::table('node')
            ->where('id', $id)
            ->first();

        if (!$node) {
            return response()->json([
                'success' => false,
                'message' => 'Node not found'
            ], 404);
        }

        $location = DB::table('location')
            ->where('node_id', $node->id)
            ->orderByDesc('recorded_at')
            ->first();

        $battery = DB::table('battery_status')
            ->where('node_id', $node->id)
            ->orderByDesc('recorded_at')
            ->first();

        $sos = DB::table('sos')
            ->where('node_id', $node->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'node' => $node,
                'location' => $location,
                'battery' => $battery,
                'sos' => $sos
            ]
        ]);
    });

    Route::post('/dashboard/nodes/{id}/resolve', function ($id) {

        $node = DB::table('node')->where('id', $id)->first();

        if (!$node) {
            return response()->json([
                'success' => false,
                'message' => 'Node not found'
            ], 404);
        }

        $resolved = DB::transaction(function () use ($node) {

            // Matikan hanya kalau memang sedang aktif
            $affected = DB::table('sos')
                ->where('node_id', $node->id)
                ->where('is_active', 1)
                ->update([
                    'is_active'   => 0,
                    'resolved_at' => now(),
                    'updated_at'  => now()
                ]);

            if ($affected === 0) {
                return false;
            }

            $location = DB::table('location')
                ->where('node_id', $node->id)
                ->orderByDesc('recorded_at')
                ->first();

            DB::table('sos_record')->insert([
                'node_id'    => $node->id,
                'event_type' => 'SOS_RESOLVED',
                'event_time' => now(),
                'latitude'   => $location?->latitude,
                'longitude'  => $location?->longitude,
                'payload'    => json_encode(['resolved_by' => 'dashboard']),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return true;
        });

        if (!$resolved) {
            return response()->json([
                'success' => false,
                'message' => 'No active SOS for this node'
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'SOS resolved'
        ]);
    });

    Route::get('/dashboard/sos-history', function (Request $request) {

        $order = $request->query('order') === 'asc' ? 'asc' : 'desc'; // default terbaru dulu

        $query = DB::table('sos_record')
            ->join('node', 'node.id', '=', 'sos_record.node_id')
            ->select(
                'sos_record.id',
                'sos_record.event_type',
                'sos_record.event_time',
                'sos_record.latitude',
                'sos_record.longitude',
                'sos_record.payload',
                'node.id as node_db_id',
                'node.node_id as node_code',
                'node.node_name'
            );

        if ($request->filled('node')) {
            $query->where('sos_record.node_id', (int) $request->query('node'));
        }

        $rows = $query
            ->orderBy('sos_record.event_time', $order)
            ->orderBy('sos_record.id', $order)   // pemecah seri kalau waktunya sama
            ->limit(500)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    });

    Route::get('/dashboard/battery-history', function (Request $request) {
        $order = $request->query('order') === 'asc' ? 'asc' : 'desc';

        $query = DB::table('battery_status')
            ->join('node', 'node.id', '=', 'battery_status.node_id')
            ->select(
                'battery_status.id',
                'battery_status.voltage',
                'battery_status.battery_percent',
                'battery_status.recorded_at',
                'node.id as node_db_id',
                'node.node_id as node_code',
                'node.node_name'
            );

        if ($request->filled('node')) {
            $query->where(
                'battery_status.node_id',
                (int) $request->query('node')
            );
        }

        $rows = $query
            ->orderBy('battery_status.recorded_at', $order)
            ->orderBy('battery_status.id', $order)
            ->limit(500)
            ->get();

        $nodes = DB::table('node')
            ->select('id', 'node_id', 'node_name', 'node_type')
            ->orderBy('node_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'nodes' => $nodes,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Telemetry API
|--------------------------------------------------------------------------
*/

Route::post('/telemetry', function (Request $request) {

    // Validate incoming data
    $data = $request->validate([
        'node_id'         => 'required|string|max:32',
        'latitude'        => 'required|numeric|between:-90,90',
        'longitude'       => 'required|numeric|between:-180,180',
        'voltage'         => 'required|numeric',
        'battery_percent' => 'nullable|integer|between:0,100',
        'sos'             => 'nullable|boolean',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Find the node
    |--------------------------------------------------------------------------
    */

    $node = DB::table('node')
        ->where('node_id', $data['node_id'])
        ->first();


    // Reject unknown node
    if (!$node) {
        return response()->json([
            'success' => false,
            'message' => 'Node not found'
        ], 404);
    }


    /*
    |--------------------------------------------------------------------------
    | Update last seen
    |--------------------------------------------------------------------------
    */

    DB::table('node')
        ->where('id', $node->id)
        ->update([
            'last_seen_at' => now(),
            'updated_at'   => now()
        ]);


    /*
    |--------------------------------------------------------------------------
    | Store location
    |--------------------------------------------------------------------------
    */

    DB::table('location')->insert([
        'node_id'     => $node->id,
        'latitude'    => $data['latitude'],
        'longitude'   => $data['longitude'],
        'recorded_at' => now(),
        'created_at'  => now(),
        'updated_at'  => now()
    ]);


    /*
    |--------------------------------------------------------------------------
    | Store battery status
    |--------------------------------------------------------------------------
    */

    DB::table('battery_status')->insert([
        'node_id'         => $node->id,
        'voltage'         => $data['voltage'],
        'battery_percent' => $data['battery_percent'] ?? null,
        'recorded_at'     => now(),
        'created_at'      => now(),
        'updated_at'      => now()
    ]);


    /*
    |--------------------------------------------------------------------------
    | Handle SOS status
    |--------------------------------------------------------------------------
    */

    if (!empty($data['sos'])) {

        DB::transaction(function () use ($node, $data) {

            $current = DB::table('sos')
                ->where('node_id', $node->id)
                ->lockForUpdate()
                ->first();

            // Sudah aktif: bukan kejadian baru, jangan catat ulang
            if ($current && $current->is_active) {
                return;
            }

            if ($current) {
                DB::table('sos')->where('node_id', $node->id)->update([
                    'is_active'    => 1,
                    'triggered_at' => now(),
                    'resolved_at'  => null,
                    'updated_at'   => now()
                ]);
            } else {
                DB::table('sos')->insert([
                    'node_id'      => $node->id,
                    'is_active'    => 1,
                    'triggered_at' => now(),
                    'resolved_at'  => null,
                    'created_at'   => now(),
                    'updated_at'   => now()
                ]);
            }

            DB::table('sos_record')->insert([
                'node_id'    => $node->id,
                'event_type' => 'SOS_TRIGGERED',
                'event_time' => now(),
                'latitude'   => $data['latitude'],
                'longitude'  => $data['longitude'],
                'payload'    => json_encode($data),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
    }


    return response()->json([
        'success' => true,
        'message' => 'Telemetry received successfully'
    ], 201);

});
require __DIR__ . '/qos.php';
