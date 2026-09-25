<?php
declare(strict_types=1);

namespace Stratbook;

/**
 * Текстовые утилиты: координаты ячеек, нормализация, токенизация,
 * метрики похожести (Левенштейн, Жаккар). Поведение идентично v7.
 */
final class TextUtil
{
    private function __construct() {}

    /** «BC» → числовой индекс колонки (1-based), как в XLSX. */
    public static function colToIndex(string $col): int
    {
        $col = strtoupper($col);
        $n = 0;
        for ($i = 0, $len = strlen($col); $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n;
    }

    /** Числовой индекс (1-based) → имя колонки («BC»). */
    public static function indexToCol(int $idx): string
    {
        $s = '';
        while ($idx > 0) {
            $idx--;
            $s = chr(65 + ($idx % 26)) . $s;
            $idx = intdiv($idx, 26);
        }
        return $s;
    }

    /** Глубокая нормализация строки для сравнения (см. v7 norm()). */
    public static function norm(string $s): string
    {
        $s = str_replace(["\xC2\xA0", "\t", "\r", "\n"], ' ', $s);
        $s = preg_replace('/\([^)]*\)/u', ' ', $s);
        $s = preg_replace('/\[[^\]]*\]/u', ' ', $s);
        $s = preg_replace('/\{[^}]*\}/u', ' ', $s);
        $s = preg_replace('/[!?*,;:\'"`~^<>|\\\\\/]+/u', ' ', $s);
        $s = preg_replace('/[_\-]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_replace(['ё', 'Ё'], ['е', 'Е'], $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    /** Нормализация названия (доп. снятие точек/запятых). */
    public static function normName(string $s): string
    {
        $s = self::norm($s);
        $s = preg_replace('/[\.\,]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Отрезает пустые ячейки с конца строки. */
    public static function rtrimRow(array $row): array
    {
        while (!empty($row) && trim((string)end($row)) === '') {
            array_pop($row);
        }
        return $row;
    }

    public static function isEmptyRow(array $row): bool
    {
        foreach ($row as $v) {
            if (trim((string)$v) !== '') return false;
        }
        return true;
    }

    /** Многоязычное расстояние Левенштейна (DP по символам UTF-8). */
    public static function mbLevenshtein(string $a, string $b): int
    {
        $la = mb_strlen($a, 'UTF-8');
        $lb = mb_strlen($b, 'UTF-8');
        if ($la === 0) return $lb;
        if ($lb === 0) return $la;
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = (mb_substr($a, $i - 1, 1, 'UTF-8') === mb_substr($b, $j - 1, 1, 'UTF-8')) ? 0 : 1;
                $cur[$j] = min(
                    $cur[$j - 1] + 1,
                    $prev[$j] + 1,
                    $prev[$j - 1] + $cost
                );
            }
            $prev = $cur;
        }
        return $prev[$lb];
    }

    /**
     * Токены строки для Жаккарда: берётся самое длинное значение строки,
     * нормализуется, отбрасываются стоп-слова и короткие токены
     * (кроме белого списка коротких тегов).
     *
     * @return string[]
     */
    public static function rowTokens(array $row): array
    {
        $longest = '';
        foreach ($row as $v) {
            $v = trim((string)$v);
            if (mb_strlen($v) > mb_strlen($longest)) $longest = $v;
        }
        if ($longest === '') return [];
        $s = self::norm($longest);
        $stop = ['и', 'в', 'на', 'с', 'по', 'из', 'от', 'до', 'или', 'the', 'and', 'for', 'with'];
        $shortOk = ['2d', 'jl', 'ef', 'gl', 'wp', 'kt', 'vp', 'м33', 'к9', 'б8', 'мм', 'ог', 'инс'];
        $out = [];
        foreach (explode(' ', $s) as $t) {
            if ($t === '') continue;
            if (mb_strlen($t) >= 3 || in_array($t, $shortOk, true)) {
                if (!in_array($t, $stop, true)) $out[$t] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Коэффициент Жаккарда для flip-карт токенов (token => true).
     */
    public static function jaccardSets(array $setA, int $sizeA, array $setB, int $sizeB): float
    {
        if ($sizeA < 2 || $sizeB < 2) return 0.0;
        $inter = 0;
        foreach ($setA as $t => $_) {
            if (isset($setB[$t])) $inter++;
        }
        $union = $sizeA + $sizeB - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }
}
