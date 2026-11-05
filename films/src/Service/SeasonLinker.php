<?php

namespace App\Service;

use App\Entity\Title;

/**
 * Связка сезонов внутри одной «франшизы».
 *
 * Разбирает название на нормализованный ключ франшизы (без номера сезона) и
 * номер сезона:
 *
 *   «Рон Камонохаси: Невменяемый детектив»     -> ["рон камонохаси: невменяемый детектив", 1]
 *   «Рон Камонохаси: Невменяемый детектив 2»   -> ["рон камонохаси: невменяемый детектив", 2]
 *   «Индекс волшебства III»                     -> ["индекс волшебства", 3]
 *   «Re:Zero. … миром 2. Часть 2»              -> ["re:zero. … миром", 2]
 *
 * Сами поля ничего не «склеивают»: связь между сезонами строится запросом по
 * одинаковому franchise. Номер сезона нужен только для сортировки «по
 * возрастанию» в карточке.
 */
final class SeasonLinker
{
    /**
     * Номера больше этого сезоном не считаем. Защита от чисел внутри названия:
     * «Айшилд 21», «АКБ0048» и т.п. не должны превратиться в 21-й сезон.
     */
    private const MAX_SEASON = 20;

    /**
     * @return array{key: string, season: int, part: int}
     */
    public function analyze(string $title): array
    {
        $base = trim($title);
        $season = 1;
        $part = 1;

        // Разбираем хвост по частям: «… 2. Часть 2», «… Часть II», «… III».
        for ($i = 0; $i < 5; ++$i) {
            if ($this->stripPart($base, $part, $rest)) {
                $base = $rest;
                continue;
            }
            if ($this->stripSeasonWord($base, $season, $rest)) {
                $base = $rest;
                continue;
            }
            if ($this->stripRoman($base, $season, $rest)) {
                $base = $rest;
                continue;
            }
            if ($this->stripNumber($base, $season, $rest)) {
                $base = $rest;
                continue;
            }
            break;
        }

        return ['key' => $this->normalizeKey($base), 'season' => $season, 'part' => $part];
    }

    /** Проставляет тайтлу франшизу и номер сезона по его названию. */
    public function apply(Title $title): void
    {
        $parsed = $this->analyze($title->getTitle());

        if ('' === $parsed['key']) {
            $title->setFranchise(null)->setSeasonNumber(null)->setSeasonPart(null);

            return;
        }

        $title
            ->setFranchise($parsed['key'])
            ->setSeasonNumber($parsed['season'])
            ->setSeasonPart($parsed['part']);
    }

    /** «… Часть 2» / «… Часть II» / «… Part 3» — номер внутри сезона, не сезон. */
    private function stripPart(string $base, int &$part, ?string &$rest): bool
    {
        if (!preg_match('/^(.+?)\s*[.:,\-—–]?\s*(?:часть|part)\s*[№#]?\s*([ivxlcdm]{1,6}|\d{1,2})$/iu', $base, $m)) {
            return false;
        }

        $token = $m[2];
        $number = ctype_digit($token) ? (int) $token : $this->romanToInt(mb_strtoupper($token));
        if (null === $number || $number < 1 || $number > 30) {
            return false;
        }

        $rest = $m[1];
        $part = $number;

        return true;
    }

    /** «… сезон 2» / «… Season 3». */
    private function stripSeasonWord(string $base, int &$season, ?string &$rest): bool
    {
        if (!preg_match('/^(.+?)\s*[.:,\-—–]?\s*(?:сезон|season)\s*[№#]?\s*(\d{1,2})$/iu', $base, $m)) {
            return false;
        }

        $number = (int) $m[2];
        if ($number < 1 || $number > self::MAX_SEASON) {
            return false;
        }

        $rest = $m[1];
        $season = $number;

        return true;
    }

    /** «… II» / «… III» — римский номер сезона (от II и выше). */
    private function stripRoman(string $base, int &$season, ?string &$rest): bool
    {
        if (!preg_match('/^(.+?)\s+([IVXLCDM]{2,})$/', $base, $m)) {
            return false;
        }

        $number = $this->romanToInt($m[2]);
        if (null === $number || $number < 2 || $number > self::MAX_SEASON) {
            return false;
        }

        $rest = $m[1];
        $season = $number;

        return true;
    }

    /** «… 2» / «… 10» — арабский номер сезона в конце названия. */
    private function stripNumber(string $base, int &$season, ?string &$rest): bool
    {
        if (!preg_match('/^(.+?)\s+(\d{1,2})$/', $base, $m)) {
            return false;
        }

        $number = (int) $m[2];
        if ($number < 1 || $number > self::MAX_SEASON) {
            return false;
        }

        $rest = $m[1];
        $season = $number;

        return true;
    }

    /** Ключ франшизы: нижний регистр, схлопнутые пробелы, ничего лишнего на концах. */
    private function normalizeKey(string $base): string
    {
        $base = preg_replace('/[\s.:,\-–—«»"\'()\[\]]+$/u', '', trim($base)) ?? $base;
        $base = preg_replace('/\s+/u', ' ', $base) ?? $base;

        return mb_strtolower(trim($base));
    }

    /** Строгий разбор римского числа: невалидные («MIX», «IIII») не принимаем. */
    private function romanToInt(string $roman): ?int
    {
        if (!preg_match('/^M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/', $roman)) {
            return null;
        }

        $map = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $sum = 0;
        $len = \strlen($roman);

        for ($i = 0; $i < $len; ++$i) {
            $current = $map[$roman[$i]];
            $next = $i + 1 < $len ? $map[$roman[$i + 1]] : 0;
            $sum += $current < $next ? -$current : $current;
        }

        return $sum;
    }
}
