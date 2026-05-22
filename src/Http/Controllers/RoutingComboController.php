<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SuperAICore\Models\AiRoutingCombo;

/**
 * CRUD + listing for named routing combos.
 *
 *   GET    /super-ai-core/routing/combos        – list (HTML)
 *   GET    /super-ai-core/routing/combos.json   – list (JSON)
 *   POST   /super-ai-core/routing/combos        – create
 *   PUT    /super-ai-core/routing/combos/{name} – update
 *   DELETE /super-ai-core/routing/combos/{name} – destroy
 */
final class RoutingComboController extends Controller
{
    public function index(Request $request)
    {
        $combos = AiRoutingCombo::query()->orderBy('name')->get();
        if ($request->wantsJson() || $request->is('*.json')) {
            return response()->json($combos->map(fn ($c) => $this->toArray($c))->all());
        }
        return view('super-ai-core::routing.combos', ['combos' => $combos]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9-]*$/'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'entries'      => ['required', 'array', 'min:1'],
            'entries.*.provider' => ['required', 'string', 'max:80'],
            'entries.*.model'    => ['nullable', 'string', 'max:120'],
        ]);

        $combo = AiRoutingCombo::create([
            'name'         => $data['name'],
            'display_name' => $data['display_name'] ?? null,
            'description'  => $data['description'] ?? null,
            'entries'      => array_values($data['entries']),
            'is_active'    => true,
        ]);

        return response()->json($this->toArray($combo), 201);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        $combo = AiRoutingCombo::query()->where('name', $name)->firstOrFail();
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'entries'      => ['nullable', 'array', 'min:1'],
            'entries.*.provider' => ['required_with:entries', 'string', 'max:80'],
            'entries.*.model'    => ['nullable', 'string', 'max:120'],
            'is_active'    => ['nullable', 'boolean'],
        ]);

        foreach (['display_name', 'description', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) $combo->{$f} = $data[$f];
        }
        if (isset($data['entries'])) {
            $combo->entries = array_values($data['entries']);
        }
        $combo->save();

        return response()->json($this->toArray($combo));
    }

    public function destroy(string $name): JsonResponse
    {
        AiRoutingCombo::query()->where('name', $name)->delete();
        return response()->json(['ok' => true]);
    }

    private function toArray(AiRoutingCombo $combo): array
    {
        return [
            'name'         => $combo->name,
            'display_name' => $combo->display_name,
            'description'  => $combo->description,
            'entries'      => $combo->entries,
            'is_active'    => $combo->is_active,
            'created_at'   => $combo->created_at?->toIso8601String(),
            'updated_at'   => $combo->updated_at?->toIso8601String(),
        ];
    }
}
