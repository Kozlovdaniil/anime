<?php

namespace App\Twig;

use App\Repository\TitleRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * watching_count() — сколько тайтлов в разделе «Смотрю» (для ссылки в шапке).
 */
final class WatchingExtension extends AbstractExtension
{
    public function __construct(
        private readonly TitleRepository $titles,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('watching_count', $this->watchingCount(...)),
        ];
    }

    public function watchingCount(): int
    {
        try {
            return $this->titles->countWatching();
        } catch (\Throwable) {
            // БД может быть недоступна (например, страница ошибки) — не роняем рендер
            return 0;
        }
    }
}
