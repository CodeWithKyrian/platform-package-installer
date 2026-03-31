<?php

declare(strict_types=1);

it('installs platform package using root override vars', function () {
    if (!class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive extension is required for integration test.');
    }

    $repoRoot = realpath(__DIR__.'/../../');
    expect($repoRoot)->not->toBeFalse();
    $repoRoot = (string) $repoRoot;

    $tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'platform-package-installer-int-'.uniqid();
    $pluginCopyPath = $tmpRoot.DIRECTORY_SEPARATOR.'plugin-under-test';
    $distPath = $tmpRoot.DIRECTORY_SEPARATOR.'dist';
    $appPath = $tmpRoot.DIRECTORY_SEPARATOR.'app';

    mkdir($tmpRoot, 0777, true);
    mkdir($distPath, 0777, true);
    mkdir($appPath, 0777, true);

    $serverProcess = null;
    try {
        copyPluginForPathRepository($repoRoot, $pluginCopyPath);
        relaxPluginDependenciesForIntegration($pluginCopyPath, '2.0.0');

        createZipArtifact($distPath.DIRECTORY_SEPARATOR.'onyx-runtime-1.2.3-cuda-12.zip', '12');
        createZipArtifact($distPath.DIRECTORY_SEPARATOR.'onyx-runtime-1.2.3-cuda-13.zip', '13');

        $port = reserveFreePort();
        $serverProcess = startPhpServer($distPath, $port);

        waitForServerReady("http://127.0.0.1:$port/onyx-runtime-1.2.3-cuda-13.zip");

        $template = "http://127.0.0.1:$port/onyx-runtime-{version}-cuda-{cuda}.zip";
        $appComposerJson = [
            'name' => 'integration/test-app',
            'type' => 'project',
            'require' => [
                'codewithkyrian/platform-package-installer' => '2.0.0',
                'org/onyx-runtime' => '1.2.3',
            ],
            'repositories' => [
                ['packagist.org' => false],
                [
                    'type' => 'path',
                    'url' => $pluginCopyPath,
                    'options' => ['symlink' => false],
                ],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'org/onyx-runtime',
                        'version' => '1.2.3',
                        'type' => 'platform-package',
                        'dist' => [
                            'url' => $template,
                            'type' => 'zip',
                        ],
                        'extra' => [
                            'artifacts' => [
                                'urls' => [
                                    'all' => $template,
                                ],
                                'vars' => [
                                    'cuda' => '12',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'extra' => [
                'platform-packages' => [
                    'org/onyx-runtime' => [
                        'cuda' => '13',
                    ],
                ],
            ],
            'config' => [
                'allow-plugins' => [
                    'codewithkyrian/platform-package-installer' => true,
                ],
                'secure-http' => false,
            ],
        ];

        file_put_contents(
            $appPath.DIRECTORY_SEPARATOR.'composer.json',
            json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $composerBin = findComposerBinary($repoRoot);
        $install = runCommand(
            [PHP_BINARY, $composerBin, 'install', '--no-interaction', '--no-progress'],
            $appPath,
            [
                'COMPOSER_CACHE_DIR' => $tmpRoot.DIRECTORY_SEPARATOR.'cache',
                'COMPOSER_HOME' => $tmpRoot.DIRECTORY_SEPARATOR.'home',
            ],
            120
        );

        if ($install['exit_code'] !== 0) {
            throw new RuntimeException(
                "composer install failed\nSTDERR:\n".$install['stderr']."\nSTDOUT:\n".$install['stdout']
            );
        }

        $marker = $appPath.DIRECTORY_SEPARATOR.'vendor/org/onyx-runtime/cuda-version.txt';
        expect(file_exists($marker))->toBeTrue();
        expect(trim((string) file_get_contents($marker)))->toBe('13');
    } finally {
        if (is_array($serverProcess)) {
            stopProcess($serverProcess);
        }

        if (is_dir($tmpRoot)) {
            removeDirectoryRecursive($tmpRoot);
        }
    }
});

it('falls back to base dist URL when a required artifact variable is missing', function () {
    if (!class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive extension is required for integration test.');
    }

    $repoRoot = realpath(__DIR__.'/../../');
    expect($repoRoot)->not->toBeFalse();
    $repoRoot = (string) $repoRoot;

    $tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'platform-package-installer-int-'.uniqid();
    $pluginCopyPath = $tmpRoot.DIRECTORY_SEPARATOR.'plugin-under-test';
    $distPath = $tmpRoot.DIRECTORY_SEPARATOR.'dist';
    $appPath = $tmpRoot.DIRECTORY_SEPARATOR.'app';

    mkdir($tmpRoot, 0777, true);
    mkdir($distPath, 0777, true);
    mkdir($appPath, 0777, true);

    $serverProcess = null;
    try {
        copyPluginForPathRepository($repoRoot, $pluginCopyPath);
        relaxPluginDependenciesForIntegration($pluginCopyPath, '2.0.0');

        createZipArtifact($distPath.DIRECTORY_SEPARATOR.'fallback.zip', 'fallback');

        $port = reserveFreePort();
        $serverProcess = startPhpServer($distPath, $port);

        waitForServerReady("http://127.0.0.1:$port/fallback.zip");

        $artifactTemplate = "http://127.0.0.1:$port/onyx-runtime-{version}-cuda-{cuda}.zip";
        $fallbackUrl = "http://127.0.0.1:$port/fallback.zip";

        $appComposerJson = [
            'name' => 'integration/test-app-fallback',
            'type' => 'project',
            'require' => [
                'codewithkyrian/platform-package-installer' => '2.0.0',
                'org/onyx-runtime' => '1.2.3',
            ],
            'repositories' => [
                ['packagist.org' => false],
                [
                    'type' => 'path',
                    'url' => $pluginCopyPath,
                    'options' => ['symlink' => false],
                ],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'org/onyx-runtime',
                        'version' => '1.2.3',
                        'type' => 'platform-package',
                        'dist' => [
                            'url' => $fallbackUrl,
                            'type' => 'zip',
                        ],
                        'extra' => [
                            'artifacts' => [
                                'urls' => [
                                    'all' => $artifactTemplate,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'config' => [
                'allow-plugins' => [
                    'codewithkyrian/platform-package-installer' => true,
                ],
                'secure-http' => false,
            ],
        ];

        file_put_contents(
            $appPath.DIRECTORY_SEPARATOR.'composer.json',
            json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $composerBin = findComposerBinary($repoRoot);
        $install = runCommand(
            [PHP_BINARY, $composerBin, 'install', '--no-interaction', '--no-progress'],
            $appPath,
            [
                'COMPOSER_CACHE_DIR' => $tmpRoot.DIRECTORY_SEPARATOR.'cache',
                'COMPOSER_HOME' => $tmpRoot.DIRECTORY_SEPARATOR.'home',
            ],
            120
        );

        if ($install['exit_code'] !== 0) {
            throw new RuntimeException(
                "composer install failed\nSTDERR:\n".$install['stderr']."\nSTDOUT:\n".$install['stdout']
            );
        }

        $marker = $appPath.DIRECTORY_SEPARATOR.'vendor/org/onyx-runtime/cuda-version.txt';
        expect(file_exists($marker))->toBeTrue();
        expect(trim((string) file_get_contents($marker)))->toBe('fallback');
    } finally {
        if (is_array($serverProcess)) {
            stopProcess($serverProcess);
        }

        if (is_dir($tmpRoot)) {
            removeDirectoryRecursive($tmpRoot);
        }
    }
});

it('installs platform package from simple artifacts format without vars', function () {
    if (!class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive extension is required for integration test.');
    }

    $repoRoot = realpath(__DIR__.'/../../');
    expect($repoRoot)->not->toBeFalse();
    $repoRoot = (string) $repoRoot;

    $tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'platform-package-installer-int-'.uniqid();
    $pluginCopyPath = $tmpRoot.DIRECTORY_SEPARATOR.'plugin-under-test';
    $distPath = $tmpRoot.DIRECTORY_SEPARATOR.'dist';
    $appPath = $tmpRoot.DIRECTORY_SEPARATOR.'app';

    mkdir($tmpRoot, 0777, true);
    mkdir($distPath, 0777, true);
    mkdir($appPath, 0777, true);

    $serverProcess = null;
    try {
        copyPluginForPathRepository($repoRoot, $pluginCopyPath);
        relaxPluginDependenciesForIntegration($pluginCopyPath, '2.0.0');

        createZipArtifact($distPath.DIRECTORY_SEPARATOR.'onyx-runtime-1.2.3.zip', 'simple');

        $port = reserveFreePort();
        $serverProcess = startPhpServer($distPath, $port);

        waitForServerReady("http://127.0.0.1:$port/onyx-runtime-1.2.3.zip");

        $artifactTemplate = "http://127.0.0.1:$port/onyx-runtime-{version}.zip";

        $appComposerJson = [
            'name' => 'integration/test-app-simple-format',
            'type' => 'project',
            'require' => [
                'codewithkyrian/platform-package-installer' => '2.0.0',
                'org/onyx-runtime' => '1.2.3',
            ],
            'repositories' => [
                ['packagist.org' => false],
                [
                    'type' => 'path',
                    'url' => $pluginCopyPath,
                    'options' => ['symlink' => false],
                ],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'org/onyx-runtime',
                        'version' => '1.2.3',
                        'type' => 'platform-package',
                        'dist' => [
                            'url' => "http://127.0.0.1:$port/should-not-be-used.zip",
                            'type' => 'zip',
                        ],
                        'extra' => [
                            'artifacts' => [
                                'all' => $artifactTemplate,
                            ],
                        ],
                    ],
                ],
            ],
            'config' => [
                'allow-plugins' => [
                    'codewithkyrian/platform-package-installer' => true,
                ],
                'secure-http' => false,
            ],
        ];

        file_put_contents(
            $appPath.DIRECTORY_SEPARATOR.'composer.json',
            json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $composerBin = findComposerBinary($repoRoot);
        $install = runCommand(
            [PHP_BINARY, $composerBin, 'install', '--no-interaction', '--no-progress'],
            $appPath,
            [
                'COMPOSER_CACHE_DIR' => $tmpRoot.DIRECTORY_SEPARATOR.'cache',
                'COMPOSER_HOME' => $tmpRoot.DIRECTORY_SEPARATOR.'home',
            ],
            120
        );

        if ($install['exit_code'] !== 0) {
            throw new RuntimeException(
                "composer install failed\nSTDERR:\n".$install['stderr']."\nSTDOUT:\n".$install['stdout']
            );
        }

        $marker = $appPath.DIRECTORY_SEPARATOR.'vendor/org/onyx-runtime/cuda-version.txt';
        expect(file_exists($marker))->toBeTrue();
        expect(trim((string) file_get_contents($marker)))->toBe('simple');
    } finally {
        if (is_array($serverProcess)) {
            stopProcess($serverProcess);
        }

        if (is_dir($tmpRoot)) {
            removeDirectoryRecursive($tmpRoot);
        }
    }
});

/**
 * @return array{process: resource, stdout: resource, stderr: resource}
 */
function startPhpServer(string $documentRoot, int $port): array
{
    $cmd = sprintf(
        '%s -S %s -t %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg("127.0.0.1:$port"),
        escapeshellarg($documentRoot)
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start local PHP server.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return [
        'process' => $process,
        'stdout' => $pipes[1],
        'stderr' => $pipes[2],
    ];
}

/**
 * @param array<int, string> $command
 * @param array<string, string> $env
 *
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runCommand(array $command, string $cwd, array $env = [], int $timeoutSeconds = 60): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $command));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
    if (!is_resource($process)) {
        throw new RuntimeException("Unable to run command: $cmd");
    }

    fclose($pipes[0]);

    $start = time();
    while (true) {
        $status = proc_get_status($process);
        if ($status['running'] === false) {
            break;
        }

        if ((time() - $start) > $timeoutSeconds) {
            proc_terminate($process);
            throw new RuntimeException("Command timed out after {$timeoutSeconds}s: $cmd");
        }

        usleep(100_000);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * @param array{process: resource, stdout: resource, stderr: resource} $processData
 */
function stopProcess(array $processData): void
{
    if (is_resource($processData['stdout'])) {
        fclose($processData['stdout']);
    }
    if (is_resource($processData['stderr'])) {
        fclose($processData['stderr']);
    }
    if (is_resource($processData['process'])) {
        proc_terminate($processData['process']);
        proc_close($processData['process']);
    }
}

function copyPluginForPathRepository(string $sourceRoot, string $targetRoot): void
{
    mkdir($targetRoot, 0777, true);

    $skip = [
        '.git',
        '.idea',
        '.cursor',
        'vendor',
        'tests',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
        $firstPart = explode(DIRECTORY_SEPARATOR, $relative)[0];

        if (in_array($firstPart, $skip, true)) {
            continue;
        }

        $dest = $targetRoot.DIRECTORY_SEPARATOR.$relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0777, true);
            }

            continue;
        }

        copy($item->getPathname(), $dest);
    }
}

function relaxPluginDependenciesForIntegration(string $pluginRoot, string $version): void
{
    $composerPath = $pluginRoot.DIRECTORY_SEPARATOR.'composer.json';
    $composer = json_decode((string) file_get_contents($composerPath), true);

    $composer['version'] = $version;
    $composer['require'] = [
        'php' => '^8.1',
        'composer-plugin-api' => '^1.1 || ^2.0',
        'composer-runtime-api' => '*',
    ];
    unset($composer['require-dev']);

    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function createZipArtifact(string $zipPath, string $cudaVersion): void
{
    $zip = new ZipArchive();
    $ok = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($ok !== true) {
        throw new RuntimeException("Unable to create archive: $zipPath");
    }

    $zip->addFromString('cuda-version.txt', $cudaVersion.PHP_EOL);
    $zip->addFromString('README.txt', 'integration artifact');
    $zip->close();
}

function reserveFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Unable to reserve a free port: $error");
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('Unable to read reserved port.');
    }

    return (int) substr($name, strrpos($name, ':') + 1);
}

function waitForServerReady(string $url): void
{
    $attempts = 20;
    for ($i = 0; $i < $attempts; $i++) {
        usleep(100_000);
        $headers = @get_headers($url);
        if (is_array($headers) && isset($headers[0]) && str_contains($headers[0], '200')) {
            return;
        }
    }

    throw new RuntimeException("Local artifact server did not become ready for URL: $url");
}

function findComposerBinary(string $repoRoot): string
{
    $localComposer = $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'composer';
    if (file_exists($localComposer)) {
        return $localComposer;
    }

    $whichComposer = trim((string) shell_exec('which composer'));
    if ($whichComposer !== '' && file_exists($whichComposer)) {
        return $whichComposer;
    }

    throw new RuntimeException('Composer binary not found. Ensure vendor/bin/composer or global composer is available.');
}

function removeDirectoryRecursive(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}

