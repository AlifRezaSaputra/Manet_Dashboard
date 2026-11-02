<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


/*
|--------------------------------------------------------------------------
| Dashboard API
|--------------------------------------------------------------------------
*/

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

        DB::table('sos')->updateOrInsert(
            ['node_id' => $node->id],
            [
                'is_active'    => true,
                'triggered_at' => now(),
                'resolved_at'  => null,
                'created_at'   => now(),
                'updated_at'   => now()
            ]
        );


        // Save permanent SOS record
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
    }


    return response()->json([
        'success' => true,
        'message' => 'Telemetry received successfully'
    ], 201);

});