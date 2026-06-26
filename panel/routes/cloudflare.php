<?php

use App\Http\Controllers\CloudflareTokenController;
use App\Http\Controllers\DnsRecordController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Account-scoped Cloudflare token management.
    Route::get('settings/cloudflare', [CloudflareTokenController::class, 'index'])->name('cloudflare.index');
    Route::post('settings/cloudflare', [CloudflareTokenController::class, 'store'])->name('cloudflare.store');
    Route::delete('settings/cloudflare/{cloudflareToken}', [CloudflareTokenController::class, 'destroy'])->name('cloudflare.destroy');

    // Per-application DNS record management.
    Route::get('apps/{application}/dns', [DnsRecordController::class, 'index'])->name('dns.index');
    Route::post('apps/{application}/dns', [DnsRecordController::class, 'store'])->name('dns.store');
    Route::delete('apps/{application}/dns/{dnsRecord}', [DnsRecordController::class, 'destroy'])->name('dns.destroy');
});
