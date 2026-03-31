<?php

declare(strict_types=1);

namespace Codewithkyrian\PlatformPackageInstaller;

final class ArtifactUrlResolver
{
    /**
     * @param array<string, mixed> $defaultVars
     * @param array<string, mixed> $overrideVars
     *
     * @return array<string, string>
     */
    public function mergeVars(array $defaultVars, array $overrideVars, string $version): array
    {
        $vars = array_merge($defaultVars, $overrideVars);
        $vars['version'] = $version;

        $out = [];
        foreach ($vars as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $out[$key] = (string) $value;
                continue;
            }

            $encoded = json_encode($value);
            $out[$key] = $encoded === false ? '' : $encoded;
        }

        return $out;
    }

    /**
     * Apply `{name}` placeholders using provided vars. Unknown placeholders are left intact.
     *
     * @param array<string, string> $vars
     */
    public function applyTemplate(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', function (array $m) use ($vars) {
            $key = $m[1] ?? '';
            if ($key === '' || !array_key_exists($key, $vars)) {
                return $m[0];
            }

            return $vars[$key];
        }, $template);
    }

    /**
     * @return list<string> placeholder keys still present in the value
     */
    public function unresolvedPlaceholders(string $value): array
    {
        if (!preg_match_all('/\{([a-zA-Z0-9_-]+)\}/', $value, $matches)) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = array_values(array_unique($matches[1] ?? []));
        sort($keys);
        return $keys;
    }
}
