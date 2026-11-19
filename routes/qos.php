<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\QosModuleEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware([QosModuleEnabled::class])->group(function () {

    // Dipakai React untuk memutuskan apakah menu "Hitung QoS" tampil
    Route::get('/qos/status', fn () => response()->json(['success' => true, 'enabled' => true]));

    // Route dashboard QoS: di balik login admin
    Route::middleware([AdminAuth::class])->group(function () {
        // controller sesi menyusul
    });
});
