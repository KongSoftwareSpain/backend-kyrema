<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Payments\RedsysController;
use App\Http\Controllers\Payments\RedsysBridgeController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::get ('/payments/redsys/insite/iframe/{pago}', [RedsysController::class, 'insiteIframe'])->name('redsys.insite.iframe');

Route::get('/payments/redsys/bridge/ok/{uuid}',    [RedsysBridgeController::class, 'ok'])->name('redsys.bridge.ok');
Route::get('/payments/redsys/bridge/ko/{uuid}',    [RedsysBridgeController::class, 'ko'])->name('redsys.bridge.ko');
