<?php

namespace SuperAICore\Services;

use Symfony\Component\Process\Process;

/**
 * Detect/install OS-level tools (Tesseract, Poppler, ImageMagick) used by
 * document-processing agents. Ported from SuperTeam.
 */
class SystemToolManager
{
    protected static array $tools = [
        'tesseract' => [
            'name' => 'Tesseract OCR',
            'description' => 'Optical Character Recognition for scanned PDFs and images',
            'icon' => 'bi-eye',
            'color' => '#4285F4',
            'category' => 'document',
            'binary' => 'tesseract',
            'check_command' => 'tesseract --version',
            'version_pattern' => '/tesseract\s+([\d\.]+)/i',
            'languages' => [
                'eng' => 'English',
                'chi_sim' => 'Chinese Simplified',
                'chi_tra' => 'Chinese Traditional',
                'jpn' => 'Japanese',
                'kor' => 'Korean',
                'fra' => 'French',
                'deu' => 'German',
                'spa' => 'Spanish',
                'rus' => 'Russian',
                'ara' => 'Arabic',
            ],
            'install' => [
                'darwin' => [
                    'package_manager' => 'brew',
                    'commands' => [
                        'brew install tesseract',
                        'brew install tesseract-lang',
                    ],
                    'check_brew' => true,
                ],
                'linux' => [
                    'debian' => [
                        'package_manager' => 'apt',
                        'commands' => [
                            'sudo apt-get update',
                            'sudo apt-get install -y tesseract-ocr',
                            'sudo apt-get install -y tesseract-ocr-chi-sim tesseract-ocr-eng',
                        ],
                    ],
                    'redhat' => [
                        'package_manager' => 'yum',
                        'commands' => [
                            'sudo yum install -y tesseract',
                            'sudo yum install -y tesseract-langpack-chi_sim tesseract-langpack-eng',
                        ],
                    ],
                    'fedora' => [
                        'package_manager' => 'dnf',
                        'commands' => [
                            'sudo dnf install -y tesseract',
                            'sudo dnf install -y tesseract-langpack-chi_sim tesseract-langpack-eng',
                        ],
                    ],
                    'arch' => [
                        'package_manager' => 'pacman',
                        'commands' => [
                            'sudo pacman -S tesseract',
                            'sudo pacman -S tesseract-data-chi_sim tesseract-data-eng',
                        ],
                    ],
                ],
                'windows' => [
                    'package_manager' => 'choco',
                    'commands' => ['choco install tesseract'],
                    'manual_url' => 'https://github.com/UB-Mannheim/tesseract/wiki',
                ],
            ],
        ],
        'poppler' => [
            'name' => 'Poppler (pdftotext)',
            'description' => 'PDF text extraction and conversion utilities',
            'icon' => 'bi-file-pdf',
            'color' => '#DC143C',
            'category' => 'document',
            'binary' => 'pdftotext',
            'check_command' => 'pdftotext -v 2>&1',
            'version_pattern' => '/version\s+([\d\.]+)/i',
            'install' => [
                'darwin' => [
                    'package_manager' => 'brew',
                    'commands' => ['brew install poppler'],
                    'check_brew' => true,
                ],
                'linux' => [
                    'debian' => ['package_manager' => 'apt', 'commands' => ['sudo apt-get update', 'sudo apt-get install -y poppler-utils']],
                    'redhat' => ['package_manager' => 'yum', 'commands' => ['sudo yum install -y poppler-utils']],
                    'fedora' => ['package_manager' => 'dnf', 'commands' => ['sudo dnf install -y poppler-utils']],
                    'arch' => ['package_manager' => 'pacman', 'commands' => ['sudo pacman -S poppler']],
                ],
                'windows' => [
                    'package_manager' => 'choco',
                    'commands' => ['choco install poppler'],
                    'manual_url' => 'https://blog.alivate.com.au/poppler-windows/',
                ],
            ],
        ],
        'imagemagick' => [
            'name' => 'ImageMagick',
            'description' => 'Image processing and conversion for OCR preprocessing',
            'icon' => 'bi-image',
            'color' => '#1C5A8E',
            'category' => 'document',
            'binary' => 'magick',
            'binary_fallback' => 'convert',
            'check_command' => 'magick --version',
            'version_pattern' => '/Version: ImageMagick ([\d\.\-]+)/i',
            'install' => [
                'darwin' => [
                    'package_manager' => 'brew',
                    'commands' => ['brew install imagemagick'],
                    'check_brew' => true,
                ],
                'linux' => [
                    'debian' => ['package_manager' => 'apt', 'commands' => ['sudo apt-get update', 'sudo apt-get install -y imagemagick']],
                    'redhat' => ['package_manager' => 'yum', 'commands' => ['sudo yum install -y ImageMagick']],
                    'fedora' => ['package_manager' => 'dnf', 'commands' => ['sudo dnf install -y ImageMagick']],
                    'arch' => ['package_manager' => 'pacman', 'commands' => ['sudo pacman -S imagemagick']],
                ],
                'windows' => [
                    'package_manager' => 'choco',
                    'commands' => ['choco install imagemagick'],
                    'manual_url' => 'https://imagemagick.org/script/download.php#windows',
                ],
            ],
        ],
    ];

    public static function getAllTools(): array
    {
        $tools = [];
        foreach (self::$tools as $key => $tool) {
            $status = self::checkTool($key);
            $tools[$key] = array_merge($tool, [
                'key' => $key,
                'installed' => $status['installed'],
                'version' => $status['version'] ?? null,
                'languages' => $status['languages'] ?? [],
                'platform' => self::detectPlatform(),
            ]);
        }
        return $tools;
    }

    public static function checkTool(string $toolKey): array
    {
        if (!isset(self::$tools[$toolKey])) {
            return ['installed' => false, 'version' => null, 'languages' => [], 'error' => 'Unknown tool'];
        }

        $tool = self::$tools[$toolKey];
        $env = McpManager::enrichedEnv();

        $binaryPath = self::findBinary($tool['binary']);
        if (!$binaryPath && !empty($tool['binary_fallback'])) {
            $binaryPath = self::findBinary($tool['binary_fallback']);
        }
        if (!$binaryPath) {
            return ['installed' => false, 'version' => null, 'languages' => []];
        }

        $version = null;
        if (isset($tool['check_command'])) {
            $checkCmd = preg_replace('/^' . preg_quote($tool['binary'], '/') . '\b/', $binaryPath, $tool['check_command']);
            $process = Process::fromShellCommandline($checkCmd, null, $env);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();
            if (isset($tool['version_pattern']) && preg_match($tool['version_pattern'], $output, $matches)) {
                $version = $matches[1];
            }
        }

        $languages = [];
        if ($toolKey === 'tesseract') {
            $languages = self::checkTesseractLanguages();
        }

        return ['installed' => true, 'version' => $version, 'languages' => $languages];
    }

    protected static function findBinary(string $binary): ?string
    {
        return McpManager::which($binary);
    }

    protected static function checkTesseractLanguages(): array
    {
        $tesseract = self::findBinary('tesseract') ?? 'tesseract';
        // `tesseract --list-langs` writes both header + langs to stderr in
        // some 5.x builds. Symfony Process captures both streams separately
        // — merge them after the fact instead of using `2>&1` (cmd.exe
        // misparses `2>/dev/null` as an output filename on Windows).
        $process = Process::fromShellCommandline(escapeshellarg($tesseract) . ' --list-langs', null, McpManager::enrichedEnv());
        $process->run();
        if (!$process->isSuccessful() && $process->getOutput() === '' && $process->getErrorOutput() === '') {
            return [];
        }
        $output = $process->getOutput() . "\n" . $process->getErrorOutput();
        $languages = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, 'List of available languages') !== false) {
                continue;
            }
            if (isset(self::$tools['tesseract']['languages'][$line])) {
                $languages[$line] = self::$tools['tesseract']['languages'][$line];
            }
        }
        return $languages;
    }

    public static function getInstallCommands(string $toolKey): array
    {
        if (!isset(self::$tools[$toolKey])) return [];

        $tool = self::$tools[$toolKey];
        $platform = self::detectPlatform();
        $installInfo = null;

        if ($platform['os'] === 'darwin' && isset($tool['install']['darwin'])) {
            $installInfo = $tool['install']['darwin'];
        } elseif ($platform['os'] === 'linux' && isset($tool['install']['linux'])) {
            $distro = self::detectLinuxDistro();
            if ($distro && isset($tool['install']['linux'][$distro])) {
                $installInfo = $tool['install']['linux'][$distro];
            } else {
                $installInfo = $tool['install']['linux']['debian'] ?? null;
            }
        } elseif ($platform['os'] === 'windows' && isset($tool['install']['windows'])) {
            $installInfo = $tool['install']['windows'];
        }

        if (!$installInfo) return [];

        if (!empty($installInfo['check_brew'])) {
            $brewPath = self::findBrewPath();
            if (!$brewPath) {
                return ['error' => 'Homebrew not installed. Please install from https://brew.sh', 'commands' => []];
            }
            $installInfo['commands'] = array_map(
                fn ($cmd) => preg_replace('/^brew\s/', $brewPath . ' ', $cmd),
                $installInfo['commands']
            );
        }

        return [
            'package_manager' => $installInfo['package_manager'] ?? null,
            'commands' => $installInfo['commands'] ?? [],
            'manual_url' => $installInfo['manual_url'] ?? null,
        ];
    }

    public static function install(string $toolKey): array
    {
        $commands = self::getInstallCommands($toolKey);
        if (empty($commands['commands'])) {
            return [
                'success' => false,
                'message' => $commands['error'] ?? 'No installation commands available for this platform',
                'manual_url' => $commands['manual_url'] ?? null,
            ];
        }

        $output = [];
        $success = true;
        $env = McpManager::enrichedEnv();
        foreach ($commands['commands'] as $cmd) {
            $process = Process::fromShellCommandline($cmd, null, $env);
            $process->setTimeout(300);
            $process->run();
            $output[] = [
                'command' => $cmd,
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
                'success' => $process->isSuccessful(),
            ];
            if (!$process->isSuccessful()) {
                $success = false;
                break;
            }
        }

        if ($success) {
            $success = self::checkTool($toolKey)['installed'];
        }
        return ['success' => $success, 'output' => $output, 'message' => $success ? 'Installation completed successfully' : 'Installation failed'];
    }

    public static function installTesseractLanguage(string $langCode): array
    {
        $platform = self::detectPlatform();
        $commands = [];

        if ($platform['os'] === 'darwin') {
            $brewPath = self::findBrewPath();
            if (!$brewPath) {
                return ['success' => false, 'message' => 'Homebrew not installed. Please install from https://brew.sh'];
            }
            $commands = [$brewPath . ' install tesseract-lang'];
        } elseif ($platform['os'] === 'linux') {
            $distro = self::detectLinuxDistro();
            if (in_array($distro, ['debian', 'ubuntu'])) {
                $commands = ["sudo apt-get install -y tesseract-ocr-{$langCode}"];
            } elseif (in_array($distro, ['redhat', 'centos', 'fedora'])) {
                $commands = ["sudo yum install -y tesseract-langpack-{$langCode}"];
            }
        } elseif ($platform['os'] === 'windows') {
            return [
                'success' => false,
                'message' => 'Please download language packs manually from Tesseract GitHub',
                'manual_url' => 'https://github.com/tesseract-ocr/tessdata',
            ];
        }

        if (empty($commands)) {
            return ['success' => false, 'message' => 'Cannot determine installation command for this platform'];
        }

        $success = true;
        $output = [];
        $env = McpManager::enrichedEnv();
        foreach ($commands as $cmd) {
            $process = Process::fromShellCommandline($cmd, null, $env);
            $process->setTimeout(300);
            $process->run();
            $output[] = ['command' => $cmd, 'output' => $process->getOutput(), 'success' => $process->isSuccessful()];
            if (!$process->isSuccessful()) {
                $success = false;
                break;
            }
        }

        return ['success' => $success, 'output' => $output, 'message' => $success ? "Language pack {$langCode} installed" : 'Installation failed'];
    }

    protected static function detectPlatform(): array
    {
        $os = strtolower(PHP_OS_FAMILY);
        if ($os === 'windows') return ['os' => 'windows', 'arch' => PHP_INT_SIZE === 8 ? 'x64' : 'x86'];
        if ($os === 'darwin') return ['os' => 'darwin', 'arch' => php_uname('m')];
        if ($os === 'linux') return ['os' => 'linux', 'arch' => php_uname('m')];
        return ['os' => 'unknown', 'arch' => 'unknown'];
    }

    protected static function detectLinuxDistro(): ?string
    {
        if (file_exists('/etc/os-release')) {
            $content = file_get_contents('/etc/os-release');
            if (preg_match('/ID=["\']?([^"\'\n]+)/i', $content, $matches)) {
                $id = strtolower($matches[1]);
                if (in_array($id, ['ubuntu', 'debian'])) return 'debian';
                if (in_array($id, ['rhel', 'centos', 'rocky', 'almalinux'])) return 'redhat';
                if ($id === 'fedora') return 'fedora';
                if (in_array($id, ['arch', 'manjaro'])) return 'arch';
            }
        }
        if (McpManager::which('apt-get')) return 'debian';
        if (McpManager::which('yum')) return 'redhat';
        return null;
    }

    protected static function findBrewPath(): ?string
    {
        if (file_exists('/opt/homebrew/bin/brew')) return '/opt/homebrew/bin/brew';
        if (file_exists('/usr/local/bin/brew')) return '/usr/local/bin/brew';
        $process = Process::fromShellCommandline('which brew 2>/dev/null');
        $process->run();
        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
