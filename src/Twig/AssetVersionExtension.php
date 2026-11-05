<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * asset_v('js/player.js') → /js/player.js?v=<mtime файла>.
 *
 * Нужно, чтобы статику не залипало в кеше браузера (у нас нет сборки
 * с хешами в имени): после правки файла mtime меняется и URL другой.
 */
final class AssetVersionExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_v', $this->assetVersioned(...)),
        ];
    }

    /** @param string $path путь относительно public/ */
    public function assetVersioned(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $file = $this->projectDir.'/public'.$path;
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return $path.'?v='.$version;
    }
}
