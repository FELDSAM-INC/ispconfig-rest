<?php

use App\Http\Controllers\Api\V1\DnsRecordController;
use App\Http\Controllers\Api\V1\DnsSlaveController;
use App\Http\Controllers\Api\V1\DnsSoaController;
use App\Http\Controllers\Api\V1\DnsTemplateController;
use App\Http\Controllers\Api\V1\DnsZoneTemplateController;
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
// Zone wizard: expands a dns_template into a zone with its records (spec 029).
// Registered before the {dnsSoa} routes (constitution Principle IV).
Route::post('dns/soa/from-template', [DnsSoaController::class, 'storeFromTemplate']);
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

// DNS zone wizard templates — api/modules/dns/zone-templates.yaml
// (read-only projection of the visible templates, offered to every key as
// the legacy wizard does, dns_wizard.php:73; spec 029)
Route::get('dns/zone-templates', [DnsZoneTemplateController::class, 'index']);

// DNS Templates — api/modules/dns/template.yaml
// (reads row-scoped; writes admin-only — legacy exposes template editing
// only in the admin menu, dns/lib/module.conf.php:23-28; spec 011 FR-017)
Route::get('dns/templates', [DnsTemplateController::class, 'index']);
Route::post('dns/templates', [DnsTemplateController::class, 'store'])->middleware('scope.admin');
Route::get('dns/templates/{dnsTemplate}', [DnsTemplateController::class, 'show'])->whereNumber('dnsTemplate');
Route::put('dns/templates/{dnsTemplate}', [DnsTemplateController::class, 'update'])->whereNumber('dnsTemplate')->middleware('scope.admin');
Route::delete('dns/templates/{dnsTemplate}', [DnsTemplateController::class, 'destroy'])->whereNumber('dnsTemplate')->middleware('scope.admin');
