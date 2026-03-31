<?php

declare(strict_types=1);

use Codewithkyrian\PlatformPackageInstaller\GenerateUrlCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Application;

beforeEach(function () {
    $application = new Application();
    $urlGeneratorCommand = new GenerateUrlCommand();
    $application->add($urlGeneratorCommand);
    $application->setAutoExit(false);
    $this->command = $application->find('platform:generate-urls');

    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'platform-package-installer-test-'.uniqid();
    mkdir($this->tempDir);

    $baseComposerJson = [
        'name' => 'test/platform-package',
        'type' => 'library',
        'description' => 'Test Package',
        'version' => '1.0.0',
    ];
    file_put_contents($this->tempDir.DIRECTORY_SEPARATOR.'composer.json', json_encode($baseComposerJson));

    chdir($this->tempDir);
});

afterEach(function () {
    array_map('unlink', glob($this->tempDir.DIRECTORY_SEPARATOR.'*'));
    rmdir($this->tempDir);
});

it('generates platform URLs for GitHub', function () {
    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--platforms' => ['linux-x86_64', 'darwin-arm64', 'windows-x86_64'],
        '--dist-type' => 'github',
        '--repo-path' => 'vendor/repo',
    ]);

    $output = new BufferedOutput();

    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(0);

    $composerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);

    expect($composerJson)->toHaveKey('extra')
        ->and($composerJson['extra'])->toHaveKey('artifacts');

    $platformUrls = $composerJson['extra']['artifacts'];

    expect($platformUrls)->toHaveCount(3)
        ->and($platformUrls['linux-x86_64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-linux-x86_64.tar.gz')
        ->and($platformUrls['darwin-arm64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-darwin-arm64.tar.gz')
        ->and($platformUrls['windows-x86_64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-windows-x86_64.zip');

});

it('supports comma-separated platforms', function () {
    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--platforms' => ['linux-x86_64,darwin-arm64,windows-x86_64'],
        '--dist-type' => 'github',
        '--repo-path' => 'vendor/repo',
    ]);

    $output = new BufferedOutput();

    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(0);

    $composerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);

    expect($composerJson)->toHaveKey('extra')
        ->and($composerJson['extra'])->toHaveKey('artifacts');

    $platformUrls = $composerJson['extra']['artifacts'];

    expect($platformUrls)->toHaveCount(3)
        ->and($platformUrls['linux-x86_64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-linux-x86_64.tar.gz')
        ->and($platformUrls['darwin-arm64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-darwin-arm64.tar.gz')
        ->and($platformUrls['windows-x86_64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-windows-x86_64.zip');


});

it('handles custom URL template', function () {
    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--platforms' => ['linux-x86_64', 'darwin-arm64'],
        '--dist-type' => 'https://custom-cdn.com/releases/{version}/dist-{platform}.{ext}',
        '--extension' => 'tar.xz',
    ]);

    $output = new BufferedOutput();

    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(0);

    $composerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);

    $platformUrls = $composerJson['extra']['artifacts'];

    expect($platformUrls)->toHaveCount(2)
        ->and($platformUrls['linux-x86_64'])->toBe('https://custom-cdn.com/releases/{version}/dist-linux-x86_64.tar.xz')
        ->and($platformUrls['darwin-arm64'])->toBe('https://custom-cdn.com/releases/{version}/dist-darwin-arm64.tar.xz');
});

it('fails with no platforms provided', function () {
    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--dist-type' => 'github',
        '--repo-path' => 'vendor/repo',
    ]);

    $output = new BufferedOutput();
    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(1);
});

it('deduplicates repeated platforms', function () {
    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--platforms' => ['linux-x86_64,darwin-arm64', 'darwin-arm64'],
        '--dist-type' => 'github',
        '--repo-path' => 'vendor/repo',
    ]);

    $output = new BufferedOutput();
    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(0);

    $composerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);

    expect($composerJson['extra']['artifacts'])->toBeArray()
        ->and($composerJson['extra']['artifacts'])->toHaveCount(2)
        ->and($composerJson['extra']['artifacts'])->toHaveKeys(['linux-x86_64', 'darwin-arm64']);
});

it('merges with existing extra configuration', function () {
    $composerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);
    $composerJson['extra'] = [
        'existing-config' => 'test-value'
    ];
    file_put_contents($this->tempDir.'/composer.json', json_encode($composerJson));

    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--platforms' => ['linux-x86_64'],
        '--dist-type' => 'github',
        '--repo-path' => 'vendor/repo',
    ]);

    $output = new BufferedOutput();
    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(0);

    $updatedComposerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);

    expect($updatedComposerJson['extra']['existing-config'])->toBe('test-value')
        ->and($updatedComposerJson['extra']['artifacts'])->toHaveCount(1)
        ->and($updatedComposerJson['extra']['artifacts']['linux-x86_64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-linux-x86_64.tar.gz');
});

it('keeps extended artifacts format when vars are already defined', function () {
    $composerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);
    $composerJson['extra'] = [
        'artifacts' => [
            'vars' => [
                'cuda' => '12',
            ],
        ],
    ];
    file_put_contents($this->tempDir.'/composer.json', json_encode($composerJson));

    $input = new ArrayInput([
        'command' => 'platform:generate-urls',
        '--platforms' => ['linux-x86_64'],
        '--dist-type' => 'github',
        '--repo-path' => 'vendor/repo',
    ]);

    $output = new BufferedOutput();
    $resultCode = $this->command->run($input, $output);

    expect($resultCode)->toBe(0);

    $updatedComposerJson = json_decode(file_get_contents($this->tempDir.'/composer.json'), true);

    expect($updatedComposerJson['extra']['artifacts']['vars']['cuda'])->toBe('12')
        ->and($updatedComposerJson['extra']['artifacts']['urls']['linux-x86_64'])->toBe('https://github.com/vendor/repo/releases/download/{version}/dist-linux-x86_64.tar.gz');
});
