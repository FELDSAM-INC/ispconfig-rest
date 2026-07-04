<?php

use App\Http\Controllers\Api\V1\DnsRecordController;
use App\Http\Controllers\Api\V1\DnsSlaveController;
use App\Http\Controllers\Api\V1\DnsSoaController;
use App\Http\Controllers\Api\V1\DnsTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DNS module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): register specific/static
| segments before parameterized ones.
*/

// DNS Zones (SOA) — api/modules/dns/soa.yaml
Route::get('dns/soa', [DnsSoaController::class, 'index']);
Route::post('dns/soa', [DnsSoaController::class, 'store']);
Route::get('dns/soa/{dnsSoa}', [DnsSoaController::class, 'show'])->whereNumber('dnsSoa');
Route::put('dns/soa/{dnsSoa}', [DnsSoaController::class, 'update'])->whereNumber('dnsSoa');
Route::delete('dns/soa/{dnsSoa}', [DnsSoaController::class, 'destroy'])->whereNumber('dnsSoa');

// DNS Resource Records — api/modules/dns/records.yaml
Route::get('dns/records', [DnsRecordController::class, 'index']);
Route::post('dns/records', [DnsRecordController::class, 'store']);
Route::get('dns/records/{dnsRecord}', [DnsRecordController::class, 'show'])->whereNumber('dnsRecord');
Route::put('dns/records/{dnsRecord}', [DnsRecordController::class, 'update'])->whereNumber('dnsRecord');
Route::delete('dns/records/{dnsRecord}', [DnsRecordController::class, 'destroy'])->whereNumber('dnsRecord');

// DNS Slave Zones — api/modules/dns/slave.yaml
Route::get('dns/slaves', [DnsSlaveController::class, 'index']);
Route::post('dns/slaves', [DnsSlaveController::class, 'store']);
Route::get('dns/slaves/{dnsSlave}', [DnsSlaveController::class, 'show'])->whereNumber('dnsSlave');
Route::put('dns/slaves/{dnsSlave}', [DnsSlaveController::class, 'update'])->whereNumber('dnsSlave');
Route::delete('dns/slaves/{dnsSlave}', [DnsSlaveController::class, 'destroy'])->whereNumber('dnsSlave');

// DNS Templates — api/modules/dns/template.yaml
// (reads row-scoped; writes admin-only — legacy exposes template editing
// only in the admin menu, dns/lib/module.conf.php:23-28; spec 011 FR-017)
Route::get('dns/templates', [DnsTemplateController::class, 'index']);
Route::post('dns/templates', [DnsTemplateController::class, 'store'])->middleware('scope.admin');
Route::get('dns/templates/{dnsTemplate}', [DnsTemplateController::class, 'show'])->whereNumber('dnsTemplate');
Route::put('dns/templates/{dnsTemplate}', [DnsTemplateController::class, 'update'])->whereNumber('dnsTemplate')->middleware('scope.admin');
Route::delete('dns/templates/{dnsTemplate}', [DnsTemplateController::class, 'destroy'])->whereNumber('dnsTemplate')->middleware('scope.admin');
