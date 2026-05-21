<?php

/**
 * AI Core package routes.
 *
 * Auto-registered under prefix config('super-ai-core.route.prefix') and
 * wrapped in middleware config('super-ai-core.route.middleware').
 * All route names are prefixed with 'super-ai-core.' via the ServiceProvider group.
 */

use SuperAICore\Http\Controllers\AiServiceController;
use SuperAICore\Http\Controllers\CostDashboardController;
use SuperAICore\Http\Controllers\HarnessResumeController;
use SuperAICore\Http\Controllers\IntegrationController;
use SuperAICore\Http\Controllers\LocaleController;
use SuperAICore\Http\Controllers\ProcessController;
use SuperAICore\Http\Controllers\ProviderController;
use SuperAICore\Http\Controllers\PtyController;
use SuperAICore\Http\Controllers\QuestionController;
use SuperAICore\Http\Controllers\RevertController;
use SuperAICore\Http\Controllers\ShareController;
use SuperAICore\Http\Controllers\UsageApiController;
use SuperAICore\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// ─── Locale switcher ───
Route::get('locale/{code}', [LocaleController::class, 'switch'])->name('locale.switch');

// ─── Providers (Execution Engine) ───
Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
Route::get('providers/cli-status', [ProviderController::class, 'cliStatus'])->name('providers.cli-status');
Route::post('providers/default-backend', [ProviderController::class, 'saveDefaultBackend'])->name('providers.default-backend');
Route::post('providers/toggle-backend', [ProviderController::class, 'toggleBackend'])->name('providers.toggle-backend');
Route::post('providers/activate-builtin', [ProviderController::class, 'activateBuiltin'])->name('providers.activate-builtin');
Route::post('providers/test-builtin', [ProviderController::class, 'testBuiltin'])->name('providers.test-builtin');
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
Route::get('integrations/status', [IntegrationController::class, 'status'])->name('integrations.status');
Route::post('integrations/batch-check', [IntegrationController::class, 'batchCheck'])->name('integrations.batchCheck');
Route::post('integrations/batch-install', [IntegrationController::class, 'batchInstall'])->name('integrations.batchInstall');

// System tools (OS-level dependencies)
Route::get('integrations/system-tools', [IntegrationController::class, 'systemToolsStatus'])->name('integrations.systemTools');
Route::get('integrations/system-tools/{key}/commands', [IntegrationController::class, 'systemToolCommands'])->name('integrations.systemToolCommands');
Route::post('integrations/system-tools/{key}/install', [IntegrationController::class, 'installSystemTool'])->name('integrations.installSystemTool');
Route::post('integrations/system-tools/tesseract/language/{lang}', [IntegrationController::class, 'installTesseractLanguage'])->name('integrations.installTesseractLanguage');

// MCP server ops (order matters: specific paths first so {key} doesn't swallow them)
Route::post('integrations/{key}/install', [IntegrationController::class, 'install'])->name('integrations.install');
Route::post('integrations/{key}/uninstall', [IntegrationController::class, 'uninstall'])->name('integrations.uninstall');
Route::delete('integrations/{key}/uninstall', [IntegrationController::class, 'uninstall']);
Route::get('integrations/{key}/test', [IntegrationController::class, 'test'])->name('integrations.test');
Route::post('integrations/{key}/auth/start', [IntegrationController::class, 'startAuth'])->name('integrations.auth');
Route::post('integrations/{key}/auth/clear', [IntegrationController::class, 'clearAuth'])->name('integrations.clearAuth');
Route::delete('integrations/{key}/auth', [IntegrationController::class, 'clearAuth']);

// ─── Usage ───
Route::get('usage', [UsageController::class, 'index'])->name('usage.index');

// ─── Cost Analytics ───
Route::get('costs', [CostDashboardController::class, 'index'])->name('costs.index');

// ─── Task Model Settings ───

// ─── Process Monitor ───
// Aggregates rows from every registered ProcessSource (built-in ai_processes
// + host-contributed sources like TaskResult). IDs follow "{sourceKey}.{localId}".
Route::get('processes', [ProcessController::class, 'index'])->name('processes.index');
Route::post('processes/register', [ProcessController::class, 'register'])->name('processes.register');
Route::post('processes/kill', [ProcessController::class, 'kill'])->name('processes.kill');
Route::get('processes/{process}/log', [ProcessController::class, 'log'])
    ->where('process', '[A-Za-z0-9_.-]+')
    ->name('processes.log');

// ─── Mid-run HITL question endpoint (P0-2 — opencode question.ts) ───
// AskUserTool inserts pending rows; the UI polls /questions and posts
// the answer back. Status flips unblock the AskUserTool's polling loop.
Route::get('processes/questions',                  [QuestionController::class, 'index'])->name('processes.questions.index');
Route::post('processes/questions/{id}/answer',     [QuestionController::class, 'answer'])->where('id', '[0-9]+')->name('processes.questions.answer');
Route::post('processes/questions/{id}/cancel',     [QuestionController::class, 'cancel'])->where('id', '[0-9]+')->name('processes.questions.cancel');

// ─── Revert worktree to pre-dispatch snapshot (P1-5) ───
// Reads `pre_snapshot` off the UsageLog row and calls
// `GitShadowStore::restore()`. Disabled when
// super-ai-core.snapshot.revert_enabled = false.
Route::post('usage/{id}/revert', [RevertController::class, 'revert'])->where('id', '[0-9]+')->name('usage.revert');

// ─── PTY long-lived shell stream (P3-9) ───
// Phase 1: long-poll endpoints (no WebSocket). Phase 2 may upgrade to WS
// via Laravel Reverb when streaming UX demands it.
Route::post('pty/sessions',                   [PtyController::class, 'create'])->name('pty.create');
Route::get('pty/sessions/{id}',               [PtyController::class, 'show'])->where('id', '[0-9]+')->name('pty.show');
Route::post('pty/sessions/{id}/write',        [PtyController::class, 'write'])->where('id', '[0-9]+')->name('pty.write');
Route::get('pty/sessions/{id}/poll',          [PtyController::class, 'poll'])->where('id', '[0-9]+')->name('pty.poll');
Route::post('pty/sessions/{id}/kill',         [PtyController::class, 'kill'])->where('id', '[0-9]+')->name('pty.kill');

// ─── Share session (P3-10) ───
// Pushes session events to a remote sharer (configured per
// `super-ai-core.share.remote_url`). Returns the share id + url.
Route::post('share/sessions/{sessionId}/create',  [ShareController::class, 'create'])->name('share.create');
Route::post('share/sessions/{sessionId}/destroy', [ShareController::class, 'destroy'])->name('share.destroy');
Route::get('share/sessions/{sessionId}',          [ShareController::class, 'show'])->name('share.show');

// ─── Headless usage API (v1) ───
// JSON aggregate endpoint mirroring codex's app-server `/v1/usage`.
// Same group_by axes (day | model | provider | thread | backend |
// task_type), same shape, designed for dashboards and billing
// automation. Auth is the host's responsibility — wrap the surrounding
// route group's middleware with whatever your app uses.
Route::get('v1/usage', [UsageApiController::class, 'aggregate'])->name('v1.usage');

// ─── Cross-harness session resume (0.9.7) ───
// Backed by SuperAgent SDK 0.9.7's HarnessImporter SPI. Gated by
// `super-ai-core.resume.enabled` so on shared machines an operator's
// ~/.claude or ~/.codex history isn't exposed to the dashboard by default.
Route::get('resume', [HarnessResumeController::class, 'index'])->name('resume.index');
Route::get('resume/{harness}', [HarnessResumeController::class, 'listSessions'])
    ->where('harness', '[a-z][a-z0-9_-]*')
    ->name('resume.list');
Route::post('resume/{harness}/load', [HarnessResumeController::class, 'load'])
    ->where('harness', '[a-z][a-z0-9_-]*')
    ->name('resume.load');
