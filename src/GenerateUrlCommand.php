<?php

declare(strict_types=1);

namespace Codewithkyrian\PlatformPackageInstaller;

use Composer\Command\BaseCommand;
use Composer\Console\Input\InputOption;
use Composer\Factory;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateUrlCommand extends BaseCommand
{
    private const DIST_TYPES = [
        'github' => 'https://github.com/{repo_path}/releases/download/{version}/dist-{platform}.{ext}',
        'gitlab' => 'https://gitlab.com/{repo_path}/-/releases/{version}/downloads/dist-{platform}.{ext}',
        'huggingface' => 'https://huggingface.co/{repo_path}/resolve/{version}/dist-{platform}.{ext}',
    ];

    protected function configure(): void
    {
        $this->setName('platform:generate-urls')
            ->setDescription('Generate platform-specific URLs from a platform list')
            ->addOption(
                'dist-type',
                'dist',
                InputOption::VALUE_REQUIRED,
                'Distribution type (github, gitlab, huggingface, or custom URL template)'
            )
            ->addOption(
                'platforms',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Platform identifiers (repeat option or use comma-separated values)'
            )
            ->addOption(
                'repo-path',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Repository path (vendor/repo-name, optional when using a custom URL template)'
            )
            ->addOption(
                'extension',
                'e',
                InputOption::VALUE_OPTIONAL,
                'File extension (optional, will be auto-determined)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var array<int, string> $platformOptions */
        $platformOptions = $input->getOption('platforms');
        $distType = $input->getOption('dist-type');
        $repoPath = $input->getOption('repo-path');
        $extensionOverride = $input->getOption('extension');

        $platforms = $this->parsePlatforms($platformOptions);

        if ($platforms === []) {
            $output->writeln('<error>No platforms provided. Use --platforms to define at least one platform.</error>');
            return self::FAILURE;
        }

        $urlTemplate = $this->resolveUrlTemplate($distType, $repoPath);
        $platformUrls = $this->generatePlatformUrls($platforms, $urlTemplate, $extensionOverride);

        $this->updateComposerJson($platformUrls, $output);

        $output->writeln("<info>Platform URLs generated successfully!</info>");
        foreach ($platformUrls as $platform => $url) {
            $output->writeln("  - $platform: $url");
        }
        return self::SUCCESS;
    }

    private function resolveUrlTemplate(string $distType, ?string $repoPath = null): string
    {
        if (isset(self::DIST_TYPES[$distType])) {
            $template = self::DIST_TYPES[$distType];
            return str_replace('{repo_path}', $repoPath, $template);
        }

        return $distType;
    }

    /**
     * @param array<int, string> $platforms
     *
     * @return array<string, string>
     */
    private function generatePlatformUrls(array $platforms, string $urlTemplate, ?string $extensionOverride = null): array
    {
        $platformUrls = [];

        foreach ($platforms as $platform) {
            $ext = $extensionOverride ?? (str_starts_with(strtolower($platform), 'windows') ? 'zip' : 'tar.gz');
            $ext = ltrim($ext, '.');

            $url = str_replace(['{platform}', '{ext}'], [$platform, $ext], $urlTemplate);

            $platformUrls[$platform] = $url;
        }

        return $platformUrls;
    }

    /**
     * @param array<int, string> $platformOptions
     *
     * @return array<int, string>
     */
    private function parsePlatforms(array $platformOptions): array
    {
        $platforms = [];
        foreach ($platformOptions as $entry) {
            foreach (explode(',', $entry) as $platform) {
                $platform = trim($platform);
                if ($platform === '') {
                    continue;
                }

                $platforms[] = $platform;
            }
        }

        return array_values(array_unique($platforms));
    }

    private function updateComposerJson(array $platformUrls, OutputInterface $output): void
    {
        $composerJsonPath = Factory::getComposerFile();
        $jsonFile = new JsonFile($composerJsonPath);
        $composerJson = $jsonFile->read();

        $composerJson['extra'] = $composerJson['extra'] ?? [];
        $existingArtifacts = $composerJson['extra']['artifacts'] ?? null;

        if (is_array($existingArtifacts) && (array_key_exists('urls', $existingArtifacts) || array_key_exists('vars', $existingArtifacts))) {
            $existingArtifacts['urls'] = $platformUrls;
            $composerJson['extra']['artifacts'] = $existingArtifacts;
        } else {
            // Prefer the simple artifacts format when no vars are in use.
            $composerJson['extra']['artifacts'] = $platformUrls;
        }

        $jsonFile->write($composerJson);

        $output->writeln("<info>Updated composer.json with platform URLs</info>");
    }
}
