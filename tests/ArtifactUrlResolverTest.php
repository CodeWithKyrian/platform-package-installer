<?php

declare(strict_types=1);

use Codewithkyrian\PlatformPackageInstaller\ArtifactUrlResolver;

it('merges vars with built-in version and prefers overrides', function () {
    $resolver = new ArtifactUrlResolver();

    $vars = $resolver->mergeVars(
        ['cuda' => 12, 'channel' => 'stable', 'version' => 'nope'],
        ['cuda' => 13],
        '2.1.0'
    );

    expect($vars)->toMatchArray([
        'cuda' => '13',
        'channel' => 'stable',
        'version' => '2.1.0',
    ]);
});

it('applies placeholders and leaves unknown ones intact', function () {
    $resolver = new ArtifactUrlResolver();

    $out = $resolver->applyTemplate(
        'https://cdn.example.com/{version}/cuda-{cuda}/dist-{platform}.tar.gz',
        ['version' => '1.0.0', 'cuda' => '12']
    );

    expect($out)->toBe('https://cdn.example.com/1.0.0/cuda-12/dist-{platform}.tar.gz');
    expect($resolver->unresolvedPlaceholders($out))->toBe(['platform']);
});

it('reports no unresolved placeholders when fully substituted', function () {
    $resolver = new ArtifactUrlResolver();

    $out = $resolver->applyTemplate(
        'https://example.com/{version}/cuda-{cuda}.zip',
        ['version' => '1.0.0', 'cuda' => '12']
    );

    expect($resolver->unresolvedPlaceholders($out))->toBe([]);
});

