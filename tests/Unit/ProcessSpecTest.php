<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\EngineCatalog;
use SuperAICore\Support\ProcessSpec;

final class ProcessSpecTest extends TestCase
{
    public function test_round_trips_through_to_array_from_array(): void
    {
        $spec = new ProcessSpec(
            binary: 'claude',
            versionArgs: ['--version'],
            authStatusArgs: ['auth', 'status'],
            promptFlag: '--print',
            outputFormatFlag: '--output-format=json',
            modelFlag: '--model',
            defaultFlags: ['--verbose'],
            defaultTimeoutSec: 120,
        );

        $restored = ProcessSpec::fromArray($spec->toArray());

        $this->assertSame($spec->binary,            $restored->binary);
        $this->assertSame($spec->versionArgs,       $restored->versionArgs);
        $this->assertSame($spec->authStatusArgs,    $restored->authStatusArgs);
        $this->assertSame($spec->promptFlag,        $restored->promptFlag);
        $this->assertSame($spec->outputFormatFlag,  $restored->outputFormatFlag);
        $this->assertSame($spec->modelFlag,         $restored->modelFlag);
        $this->assertSame($spec->defaultFlags,      $restored->defaultFlags);
        $this->assertSame($spec->defaultTimeoutSec, $restored->defaultTimeoutSec);
    }

    public function test_engine_catalog_seeds_process_spec_for_every_cli_engine(): void
    {
        $catalog = new EngineCatalog([]);

        foreach (['claude', 'codex', 'gemini', 'copilot'] as $key) {
            $engine = $catalog->get($key);
            $this->assertNotNull($engine->processSpec, "engine {$key} should seed a ProcessSpec");
            $this->assertSame($key, $engine->processSpec->binary);
        }

        $this->assertNull($catalog->get('superagent')->processSpec);
    }

    public function test_host_config_can_override_process_spec_via_array(): void
    {
        $catalog = new EngineCatalog([
            'copilot' => [
                'process_spec' => [
                    'binary'             => 'custom-copilot',
                    'version_args'       => ['-V'],
                    'prompt_flag'        => '--ask',
                    'output_format_flag' => null,
                    'default_flags'      => ['--fast'],
                ],
            ],
        ]);

        $spec = $catalog->get('copilot')->processSpec;
        $this->assertSame('custom-copilot', $spec->binary);
        $this->assertSame(['-V'],           $spec->versionArgs);
        $this->assertSame('--ask',          $spec->promptFlag);
        $this->assertNull($spec->outputFormatFlag);
        $this->assertSame(['--fast'],       $spec->defaultFlags);
    }

    public function test_engine_descriptor_exports_process_spec_in_to_array(): void
    {
        $catalog = new EngineCatalog([]);
        $array = $catalog->get('copilot')->toArray();

        $this->assertArrayHasKey('process_spec', $array);
        $this->assertSame('copilot', $array['process_spec']['binary']);
    }
}
