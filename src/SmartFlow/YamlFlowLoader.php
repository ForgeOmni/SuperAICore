<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Symfony\Component\Yaml\Yaml;

/**
 * Compiles a declarative YAML flow into a {@see FlowDefinition} whose body runs
 * on the same {@see FlowEngine} as a hand-written PHP flow ("static flow 固定步骤、
 * 可重放、可演练"). One execution path serves both authoring styles.
 *
 * YAML shape (cross-CLI: each step/agent may pin a `backend`):
 *
 *   name: cross-cli-review
 *   description: ...
 *   phases: [{title: Plan}, {title: Review}]
 *   defaults: {backend: claude_cli, budget_usd: 1.0}
 *   schemas:
 *     plan: {type: object, required: [steps], properties: {...}}
 *   steps:
 *     - name: plan
 *       role: planner
 *       backend: claude_cli        # which CLI runs this step
 *       phase: Plan
 *       prompt: "Plan: {{args.goal}}"
 *       schema: plan               # named ref or inline object
 *     - name: reviews
 *       strategy: parallel         # run `agents` concurrently across CLIs
 *       agents:
 *         - {role: reviewer, backend: codex_cli,  prompt: "Review:\n{{steps.plan.output}}"}
 *         - {role: reviewer, backend: gemini_cli, prompt: "Review:\n{{steps.plan.output}}"}
 *     - name: per-item
 *       strategy: pipeline
 *       over: "{{args.items}}"
 *       stages:
 *         - {role: writer, backend: claude_cli, prompt: "Draft for {{item}}"}
 *     - name: accept
 *       strategy: gate
 *       check: "nonempty:{{steps.plan.output}}"
 *       required: true
 *
 * Templating: {{args.x}}, {{steps.name.output}}, {{item}} (pipeline), dotted
 * paths into structured outputs (e.g. {{steps.plan.output.title}}).
 */
final class YamlFlowLoader
{
    public const STRATEGIES = ['solo', 'parallel', 'pipeline', 'gate', 'delegate'];

    public function loadFile(string $path): FlowDefinition
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Flow file not found: {$path}");
        }
        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed)) {
            throw new \InvalidArgumentException("Flow file is not a YAML map: {$path}");
        }
        return $this->compile($parsed, $path);
    }

    public function loadString(string $yaml, string $source = '<string>'): FlowDefinition
    {
        $parsed = Yaml::parse($yaml);
        if (!is_array($parsed)) {
            throw new \InvalidArgumentException("Flow YAML is not a map: {$source}");
        }
        return $this->compile($parsed, $source);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function compile(array $spec, ?string $source = null): FlowDefinition
    {
        $name = (string) ($spec['name'] ?? 'unnamed');
        $description = (string) ($spec['description'] ?? '');
        $phases = [];
        foreach ((array) ($spec['phases'] ?? []) as $p) {
            if (is_array($p) && isset($p['title'])) {
                $phases[] = ['title' => (string) $p['title'], 'detail' => (string) ($p['detail'] ?? '')];
            } elseif (is_string($p)) {
                $phases[] = ['title' => $p];
            }
        }
        $defaults = is_array($spec['defaults'] ?? null) ? $spec['defaults'] : [];
        $schemas = is_array($spec['schemas'] ?? null) ? $spec['schemas'] : [];
        $steps = is_array($spec['steps'] ?? null) ? array_values($spec['steps']) : [];
        $returnStep = $spec['return'] ?? null;

        $self = $this;
        $body = function (Flow $flow) use ($steps, $schemas, $phases, $returnStep, $self) {
            $ctx = ['args' => $flow->args, 'steps' => []];
            $lastPhase = '';

            foreach ($steps as $rawStep) {
                if (!is_array($rawStep)) {
                    continue;
                }
                $stepName = (string) ($rawStep['name'] ?? ('step-' . (count($ctx['steps']) + 1)));
                $phase = (string) ($rawStep['phase'] ?? '');
                if ($phase !== '' && $phase !== $lastPhase) {
                    $flow->phase($phase);
                    $lastPhase = $phase;
                }

                $output = $self->runStep($flow, $rawStep, $stepName, $ctx, $schemas);
                $ctx['steps'][$stepName] = ['output' => $output];
            }

            if (is_string($returnStep) && isset($ctx['steps'][$returnStep])) {
                return $ctx['steps'][$returnStep]['output'];
            }
            // Default return: a map of every step's output.
            return array_map(static fn ($s) => $s['output'], $ctx['steps']);
        };

        return new FlowDefinition($name, $description, $body, $phases, $defaults, $source);
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $schemas
     */
    private function runStep(Flow $flow, array $step, string $name, array $ctx, array $schemas): mixed
    {
        $strategy = (string) ($step['strategy'] ?? 'solo');

        return match ($strategy) {
            'parallel' => $this->runParallel($flow, $step, $name, $ctx, $schemas),
            'pipeline' => $this->runPipeline($flow, $step, $name, $ctx, $schemas),
            'gate' => $this->runGate($flow, $step, $name, $ctx),
            'delegate' => $this->runDelegate($flow, $step, $name, $ctx),
            default => $this->runSolo($flow, $step, $name, $ctx, $schemas),
        };
    }

    private function runSolo(Flow $flow, array $step, string $name, array $ctx, array $schemas): mixed
    {
        $prompt = $this->render((string) ($step['prompt'] ?? ''), $ctx);
        $opts = $this->agentOpts($step, $name, $schemas, $ctx);
        return $flow->agent($prompt, $opts);
    }

    private function runParallel(Flow $flow, array $step, string $name, array $ctx, array $schemas): array
    {
        $calls = [];
        foreach ((array) ($step['agents'] ?? []) as $i => $agent) {
            if (!is_array($agent)) {
                continue;
            }
            $prompt = $this->render((string) ($agent['prompt'] ?? ''), $ctx);
            $opts = $this->agentOpts($agent, $name . '-' . ($i + 1), $schemas, $ctx);
            $calls[] = $flow->call($prompt, $opts);
        }
        return $flow->parallel($calls);
    }

    private function runPipeline(Flow $flow, array $step, string $name, array $ctx, array $schemas): array
    {
        $items = $this->resolveArray($step['over'] ?? [], $ctx);
        $stageSpecs = array_values((array) ($step['stages'] ?? []));
        $self = $this;

        $stages = [];
        foreach ($stageSpecs as $si => $stageSpec) {
            if (!is_array($stageSpec)) {
                continue;
            }
            $stages[] = function ($prev, $item, $idx) use ($flow, $stageSpec, $name, $si, $schemas, $ctx, $self) {
                $local = $ctx;
                $local['item'] = $item;
                $local['prev'] = $prev;
                $prompt = $self->render((string) ($stageSpec['prompt'] ?? ''), $local);
                $opts = $self->agentOpts($stageSpec, $name . '-s' . ($si + 1), $schemas, $local);
                return $flow->call($prompt, $opts);
            };
        }

        if ($stages === []) {
            return [];
        }
        return $flow->pipeline($items, ...$stages);
    }

    private function runGate(Flow $flow, array $step, string $name, array $ctx): GateResult
    {
        $check = (string) ($step['check'] ?? '');
        $self = $this;
        $opts = [];
        if (isset($step['fallback'])) {
            $fallback = $step['fallback'];
            $opts['fallback'] = fn () => is_string($fallback) ? $self->render($fallback, $ctx) : $fallback;
        }
        if (!empty($step['required'])) {
            $opts['required'] = true;
        }
        if (isset($step['fail_reason'])) {
            $opts['fail_reason'] = (string) $step['fail_reason'];
        }

        return $flow->gate($name, fn () => $self->evalCondition($check, $ctx), $opts);
    }

    /**
     * `strategy: delegate` — hand a sub-flow to superagent's SmartFlow. The step
     * names either a SDK flow (`delegate: research-trio`) or an inline spec
     * (`spec: {...}` authored here). `flow_args` values are templated.
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $ctx
     */
    private function runDelegate(Flow $flow, array $step, string $name, array $ctx): mixed
    {
        $opts = ['label' => (string) ($step['label'] ?? ('delegate-' . $name))];

        if (isset($step['delegate'])) {
            $opts['delegate'] = (string) $step['delegate'];
        }
        if (isset($step['spec'])) {
            $opts['spec'] = is_array($step['spec']) ? $step['spec'] : $this->render((string) $step['spec'], $ctx);
        }

        $args = [];
        foreach ((array) ($step['flow_args'] ?? []) as $k => $v) {
            $args[$k] = is_string($v) ? $this->render($v, $ctx) : $v;
        }
        $opts['flow_args'] = $args;

        foreach (['provider' => 'delegate_provider', 'model' => 'delegate_model', 'concurrency' => 'delegate_concurrency'] as $src => $dst) {
            if (isset($step[$src])) {
                $opts[$dst] = $step[$src];
            }
        }

        return $flow->delegate('', $opts);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $schemas
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function agentOpts(array $spec, string $label, array $schemas, array $ctx): array
    {
        $opts = ['label' => (string) ($spec['label'] ?? $label)];
        // `backend` is the cross-CLI knob; `provider` is accepted as an alias.
        foreach (['role', 'backend', 'provider', 'model'] as $k) {
            if (isset($spec[$k])) {
                $opts[$k] = (string) $spec[$k];
            }
        }
        if (isset($spec['system'])) {
            $opts['system'] = $this->render((string) $spec['system'], $ctx);
        }
        if (isset($spec['temperature'])) {
            $opts['temperature'] = (float) $spec['temperature'];
        }
        if (isset($spec['max_tokens'])) {
            $opts['max_tokens'] = (int) $spec['max_tokens'];
        }
        if (isset($spec['schema'])) {
            $schema = $spec['schema'];
            if (is_string($schema) && isset($schemas[$schema]) && is_array($schemas[$schema])) {
                $opts['schema'] = $schemas[$schema];
            } elseif (is_array($schema)) {
                $opts['schema'] = $schema;
            }
        }
        return $opts;
    }

    // ── templating + conditions ───────────────────────────────────

    /**
     * @param array<string, mixed> $ctx
     */
    public function render(string $template, array $ctx): string
    {
        return preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function ($m) use ($ctx) {
            $value = $this->resolvePath(trim($m[1]), $ctx);
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            return $value === null ? '' : (string) $value;
        }, $template) ?? $template;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function resolvePath(string $path, array $ctx): mixed
    {
        $cur = $ctx;
        foreach (explode('.', $path) as $seg) {
            $seg = trim($seg);
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<mixed>
     */
    private function resolveArray(mixed $over, array $ctx): array
    {
        if (is_array($over)) {
            return array_values($over);
        }
        if (is_string($over)) {
            // A template referencing an array, or a comma list.
            if (preg_match('/^\{\{\s*([^}]+?)\s*\}\}$/', trim($over), $m)) {
                $resolved = $this->resolvePath(trim($m[1]), $ctx);
                if (is_array($resolved)) {
                    return array_values($resolved);
                }
                return $resolved === null || $resolved === '' ? [] : [$resolved];
            }
            $rendered = $this->render($over, $ctx);
            return $rendered === '' ? [] : array_map('trim', explode(',', $rendered));
        }
        return [];
    }

    /**
     * Tiny condition evaluator for gates. Supported forms:
     *   nonempty:{{...}}        truthy if the rendered value is non-empty
     *   equals:{{a}}|{{b}}      truthy if both sides render equal
     *   contains:{{a}}|needle   truthy if a contains needle
     * Anything else: truthy unless it renders to "", "0", or "false".
     *
     * @param array<string, mixed> $ctx
     */
    public function evalCondition(string $condition, array $ctx): bool
    {
        $condition = trim($condition);
        if ($condition === '') {
            return true;
        }
        if (str_starts_with($condition, 'nonempty:')) {
            return trim($this->render(substr($condition, 9), $ctx)) !== '';
        }
        if (str_starts_with($condition, 'equals:')) {
            [$a, $b] = array_pad(explode('|', substr($condition, 7), 2), 2, '');
            return trim($this->render($a, $ctx)) === trim($this->render($b, $ctx));
        }
        if (str_starts_with($condition, 'contains:')) {
            [$a, $needle] = array_pad(explode('|', substr($condition, 9), 2), 2, '');
            return str_contains($this->render($a, $ctx), trim($needle));
        }
        $rendered = strtolower(trim($this->render($condition, $ctx)));
        return !in_array($rendered, ['', '0', 'false', 'null'], true);
    }
}
