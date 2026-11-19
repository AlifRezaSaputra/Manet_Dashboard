<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\QosModuleEnabled;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QosTestController;
use App\Http\Controllers\QosEventController;

Route::middleware([QosModuleEnabled::class])->group(function () {

    // Dipakai React untuk memutuskan apakah menu "Hitung QoS" tampil
    Route::get('/qos/status', fn () => response()->json(['success' => true, 'enabled' => true]));

    // Route dashboard QoS: di balik login admin
    Route::middleware([AdminAuth::class])->group(function () {
        Route::get('/qos/tests', [QosTestController::class, 'index']);
        Route::post('/qos/tests', [QosTestController::class, 'store']);
        Route::get('/qos/tests/{id}', [QosTestController::class, 'show']);
        Route::post('/qos/tests/{id}/start', [QosTestController::class, 'start']);
        Route::post('/qos/tests/{id}/stop', [QosTestController::class, 'stop']);
    });

    Route::post('/qos/tests/{id}/events', [QosEventController::class, 'store']);
});
