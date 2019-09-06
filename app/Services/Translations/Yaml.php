<?php

namespace App\Services\Translations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\Cache\Repository;
use Symfony\Component\Yaml\Yaml as YamlParser;
use Spatie\TranslationLoader\TranslationLoaders\TranslationLoader;

class Yaml implements TranslationLoader
{
    /** @var Filesystem */
    protected $files;

    /** @var Repository */
    protected $cache;

    public function __construct(Filesystem $files, Repository $cache)
    {
        $this->files = $files;
        $this->cache = $cache;
    }

    public function loadTranslations(string $locale, string $group): array
    {
        $path = resource_path("lang/$locale/$group.yml");

        return file_exists($path) ? $this->loadYaml($path) : [];
    }

    public function loadYaml(string $path): array
    {
        $key = 'yaml-trans-'.md5($path).'-'.filemtime($path);
        return $this->cache->rememberForever($key, function () use ($path) {
            return YamlParser::parseFile($path);
        });
    }
}