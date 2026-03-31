<?php

declare(strict_types=1);

namespace Codewithkyrian\PlatformPackageInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\PartialComposer;
use React\Promise\PromiseInterface;

class PlatformInstaller extends LibraryInstaller
{
    private ArtifactUrlResolver $artifactUrlResolver;

    public function __construct(IOInterface $io, PartialComposer $composer)
    {
        parent::__construct($io, $composer, "platform-package");
        $this->artifactUrlResolver = new ArtifactUrlResolver();
    }

    public function download(PackageInterface $package, ?PackageInterface $prevPackage = null): ?PromiseInterface
    {
        if ($url = $this->resolveDistUrl($package)) {
            $package->setDistUrl($url);
            $package->setDistType($this->inferArchiveType($url));
        }

        return parent::download($package, $prevPackage);
    }

    private function resolveDistUrl(PackageInterface $package): string|false
    {
        $artifacts = $package->getExtra()['artifacts'] ?? [];
        if (!is_array($artifacts)) {
            $this->io->writeError("{$package->getName()}: Invalid extra.artifacts config (expected object)");
            return false;
        }

        $normalized = $this->normalizeArtifactsConfig($package, $artifacts);
        if ($normalized === false) {
            return false;
        }

        $validatedUrls = $this->validateArtifactUrls($package, $normalized['urls']);
        if ($matchingTemplate = Platform::findBestMatch($validatedUrls)) {
            $vars = $this->resolveTemplateVars($package, $normalized['vars']);
            $resolvedUrl = $this->artifactUrlResolver->applyTemplate($matchingTemplate, $vars);

            $unresolved = $this->artifactUrlResolver->unresolvedPlaceholders($resolvedUrl);
            if ($unresolved !== []) {
                $missing = implode(', ', array_map(fn($k) => '{'.$k.'}', $unresolved));
                $this->io->writeError("{$package->getName()}: Unresolved URL placeholders: {$missing}");
                return false;
            }

            if (!filter_var($resolvedUrl, FILTER_VALIDATE_URL)) {
                $this->io->writeError("{$package->getName()}: Invalid resolved URL: $resolvedUrl");
                return false;
            }

            if ($this->urlExists($resolvedUrl)) {
                return $resolvedUrl;
            }

            $this->io->writeError("{$package->getName()}: URL found for current platform but it doesn't exist: $resolvedUrl");
            return false;
        }

        $this->io->writeError("{$package->getName()}: No download URL found for current platform");
        return false;
    }

    /**
     * Supports two artifact formats:
     * 1) Simple:   artifacts = { "darwin-arm64": "https://..." }
     * 2) Extended: artifacts = { "urls": {...}, "vars": {...} }
     *
     * @param array<string, mixed> $artifacts
     *
     * @return array{urls: array<string, mixed>, vars: array<string, mixed>}|false
     */
    private function normalizeArtifactsConfig(PackageInterface $package, array $artifacts): array|false
    {
        if (array_key_exists('urls', $artifacts)) {
            $urls = $artifacts['urls'];
            if (!is_array($urls)) {
                $this->io->writeError("{$package->getName()}: Invalid extra.artifacts.urls config (expected object)");
                return false;
            }

            $vars = $artifacts['vars'] ?? [];
            if (!is_array($vars)) {
                $this->io->writeError("{$package->getName()}: Invalid extra.artifacts.vars config (expected object)");
                return false;
            }

            return [
                'urls' => $urls,
                'vars' => $vars,
            ];
        }

        if (array_key_exists('vars', $artifacts)) {
            $this->io->writeError("{$package->getName()}: Invalid extra.artifacts config. Use extra.artifacts.urls when defining extra.artifacts.vars");
            return false;
        }

        return [
            'urls' => $artifacts,
            'vars' => [],
        ];
    }

    /**
     * Check if a URL exists by sending a HEAD request
     */
    private function urlExists(string $url): bool
    {
        try {
            $headers = @get_headers($url);

            if ($headers === false) {
                return false;
            }

            return str_contains($headers[0], '200') || str_contains($headers[0], '302');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param PackageInterface $package
     * @param array<string, mixed> $platformUrls
     *
     * @return array<string, string>
     */
    private function validateArtifactUrls(PackageInterface $package, array $platformUrls): array
    {
        $validatedPlatforms = [];
        foreach ($platformUrls as $platform => $url) {
            if (!is_string($platform) || $platform === '') {
                continue;
            }

            if (!is_string($url) || $url === '') {
                $this->io->writeError("{$package->getName()}: Invalid artifact URL for platform '$platform' (expected non-empty string). Skipping...");
                continue;
            }

            $validatedPlatforms[strtolower($platform)] = $url;
        }

        return $validatedPlatforms;
    }

    /**
     * @param array<string, mixed> $defaultVars
     *
     * @return array<string, string>
     */
    private function resolveTemplateVars(PackageInterface $package, array $defaultVars): array
    {
        $rootExtra = $this->composer->getPackage()->getExtra();
        $platformPackages = $rootExtra['platform-packages'] ?? [];
        if (!is_array($platformPackages)) {
            $platformPackages = [];
        }

        $overrideVars = $platformPackages[$package->getName()] ?? [];
        if (!is_array($overrideVars)) {
            $overrideVars = [];
        }

        return $this->artifactUrlResolver->mergeVars($defaultVars, $overrideVars, $package->getPrettyVersion());
    }

    private function inferArchiveType(string $url): string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

        $archiveTypes = [
            // Compressed archives
            'zip' => 'zip',
            'tar' => 'tar',
            'gz' => 'tar',
            'tgz' => 'tar',
            'tbz2' => 'tar',
            'bz2' => 'tar',
            '7z' => '7z',
            'rar' => 'rar',

            // Less common but still valid
            'xz' => 'tar',
            'lz' => 'tar',
            'lzma' => 'tar',
        ];

        if (isset($archiveTypes[$extension])) {
            return $archiveTypes[$extension];
        }

        try {
            $headers = get_headers($url, true);

            if (is_array($headers)) {
                $contentType = strtolower($headers['Content-Type'] ?? '');

                // Common content type mappings
                $contentTypeMap = [
                    'application/zip' => 'zip',
                    'application/x-zip-compressed' => 'zip',
                    'application/x-tar' => 'tar',
                    'application/x-gzip' => 'tar',
                    'application/gzip' => 'tar',
                    'application/x-bzip2' => 'tar',
                ];

                foreach ($contentTypeMap as $type => $archiveType) {
                    if (strpos($contentType, $type) !== false) {
                        return $archiveType;
                    }
                }
            }
        } catch (\Exception) {
        }

        // Fallback to ZIP if no other type could be determined
        return 'zip';
    }
}
