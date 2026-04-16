<?php

/**
 * AI Core package routes.
 *
 * Auto-registered under prefix config('ai-core.route.prefix') and
 * wrapped in middleware config('ai-core.route.middleware').
 * All route names are prefixed with 'ai-core.' via the ServiceProvider group.
 */

use ForgeOmni\AiCore\Http\Controllers\AiServiceController;
use ForgeOmni\AiCore\Http\Controllers\CostDashboardController;
use ForgeOmni\AiCore\Http\Controllers\IntegrationController;
use ForgeOmni\AiCore\Http\Controllers\ProcessController;
use ForgeOmni\AiCore\Http\Controllers\ProviderController;
use ForgeOmni\AiCore\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// ─── Providers (Execution Engine) ───
Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
Route::post('providers/{provider}/activate', [ProviderController::class, 'activate'])->name('providers.activate');
Route::post('providers/{provider}/deactivate', [ProviderController::class, 'deactivate'])->name('providers.deactivate');
Route::get('providers/{provider}/models', [ProviderController::class, 'models'])->name('providers.models');
Route::post('providers/{provider}/test', [ProviderController::class, 'test'])->name('providers.test');

// ─── AI Services + Capabilities + Routing ───
Route::get('services', [AiServiceController::class, 'index'])->name('services.index');
Route::get('services/routing', [AiServiceController::class, 'routingIndex'])->name('services.routing');

Route::post('capabilities', [AiServiceController::class, 'storeCapability'])->name('capabilities.store');
Route::put('capabilities/{capability}', [AiServiceController::class, 'updateCapability'])->name('capabilities.update');
Route::delete('capabilities/{capability}', [AiServiceController::class, 'destroyCapability'])->name('capabilities.destroy');
Route::post('capabilities/{capability}/toggle', [AiServiceController::class, 'toggleCapability'])->name('capabilities.toggle');

Route::post('ai-service', [AiServiceController::class, 'storeService'])->name('ai-service.store');
Route::put('ai-service/{service}', [AiServiceController::class, 'updateService'])->name('ai-service.update');
Route::delete('ai-service/{service}', [AiServiceController::class, 'destroyService'])->name('ai-service.destroy');
Route::post('ai-service/{service}/toggle', [AiServiceController::class, 'toggleService'])->name('ai-service.toggle');
Route::post('ai-service/{service}/test', [AiServiceController::class, 'testService'])->name('ai-service.test');

Route::post('routings', [AiServiceController::class, 'storeRouting'])->name('routings.store');
Route::put('routings/{routing}', [AiServiceController::class, 'updateRouting'])->name('routings.update');
Route::delete('routings/{routing}', [AiServiceController::class, 'destroyRouting'])->name('routings.destroy');
Route::post('routings/{routing}/toggle', [AiServiceController::class, 'toggleRouting'])->name('routings.toggle');

// ─── MCP / Integrations ───
Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
Route::post('integrations/{key}/install', [IntegrationController::class, 'install'])->name('integrations.install');
Route::post('integrations/{key}/uninstall', [IntegrationController::class, 'uninstall'])->name('integrations.uninstall');
Route::get('integrations/{key}/test', [IntegrationController::class, 'test'])->name('integrations.test');
Route::get('integrations/status', [IntegrationController::class, 'status'])->name('integrations.status');

// ─── Usage ───
Route::get('usage', [UsageController::class, 'index'])->name('usage.index');

// ─── Cost Analytics ───
Route::get('costs', [CostDashboardController::class, 'index'])->name('costs.index');

// ─── Process Monitor ───
Route::get('processes', [ProcessController::class, 'index'])->name('processes.index');
Route::post('processes/kill', [ProcessController::class, 'kill'])->name('processes.kill');
