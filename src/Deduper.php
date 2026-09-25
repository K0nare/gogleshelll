<?php
declare(strict_types=1);

namespace Stratbook;

/**
 * Жёсткая дедупликация записей тактической книги в три прохода:
 *   1) exact-ключ (hardKey), 2) Левенштейн по названиям, 3) Жаккард по описаниям.
 * Порт hardDedupe()/mergeInto()/hardKey() из v7 (поведение идентично).
 */
final class Deduper
{
    public function __construct(
        private readonly int $levMaxDist = 2,
        private readonly float $jaccardHard = 0.85,
    ) {}

    /**
     * Дедуплицирует $store[$sheetName]['items'] на месте.
     *
     * @param array<string,array{tokens:array,items:array}> $store
     * @return array{removed:int, per_sheet:array<string,int>}
     */
    public function dedupe(array &$store): array
    {
        $stats = ['removed' => 0, 'per_sheet' => []];

        foreach ($store as $sheetName => &$data) {
            $items = $data['items'];
            if (empty($items)) {
                $data['items']  = [];
                $data['tokens'] = [];
                $stats['per_sheet'][$sheetName] = 0;
                continue;
            }

            $step1 = $this->dedupeExact($items);
            $step2 = $this->dedupeLevenshtein($step1);
            $step3 = $this->dedupeJaccard($step2);

            // снимаем служебное поле srcSet
            foreach ($step3 as &$it3) unset($it3['srcSet']);
            unset($it3);

            $removed = count($items) - count($step3);
            $data['items']  = $step3;
            $data['tokens'] = array_fill(0, count($step3), []);
            $stats['per_sheet'][$sheetName] = $removed;
            $stats['removed'] += $removed;
        }
        unset($data);

        return $stats;
    }

    /** Ключ жёсткой склейки по секции записи. */
    public static function hardKey(array $item): string
    {
        $sec  = $item['section'] ?? '';
        $name = TextUtil::normName((string)($item['name'] ?? ''));

        if ($sec === 'strategy') {
            $side = $item['side'] ?? 'ANY';
            $site = $item['site'] ?? 'ALL';
            return 'S||' . $name . '||' . $side . '||' . $site;
        }

        if ($sec === 'spawn') {
            return 'P||' . self::normalizeCoords($item['value'] ?? '');
        }

        if ($sec === 'wall') {
            return 'W||' . $name . '||' . self::normalizeCoords($item['value'] ?? '');
        }

        if ($sec === 'nade') {
            return 'N||' . $name . '||' . TextUtil::norm((string)($item['value'] ?? ''));
        }

        return $sec . '||' . $name;
    }

    /** Координаты → нормализованный текст с округлением чисел до 1 знака. */
    private static function normalizeCoords(string $value): string
    {
        $coord = TextUtil::norm($value);
        return preg_replace_callback('/-?\d+\.?\d*/', static function (array $m): string {
            $s = number_format((float)$m[0], 1, '.', '');
            $s = rtrim(rtrim($s, '0'), '.');
            return $s === '-0' ? '0' : $s;
        }, $coord);
    }

    /**
     * Слияние дубликата в существующий элемент (источники/описания/значения).
     */
    private static function mergeInto(array &$dst, array $srcItem): void
    {
        foreach (($srcItem['sources'] ?? []) as $src) {
            if (!isset($dst['srcSet'][$src])) {
                $dst['srcSet'][$src] = true;
                $dst['sources'][]    = $src;
            }
        }
        if (mb_strlen((string)($srcItem['desc'] ?? '')) > mb_strlen((string)($dst['desc'] ?? ''))) {
            $dst['desc'] = $srcItem['desc'];
        }
        if (mb_strlen((string)($srcItem['value'] ?? '')) > mb_strlen((string)($dst['value'] ?? ''))) {
            $dst['value'] = $srcItem['value'];
        }
    }

    /** ШАГ 1: точное совпадение hardKey. */
    private function dedupeExact(array $items): array
    {
        $seenExact = [];
        $step1 = [];
        foreach ($items as $it) {
            $k = self::hardKey($it);
            if (isset($seenExact[$k])) {
                self::mergeInto($step1[$seenExact[$k]], $it);
            } else {
                $it['srcSet'] = [];
                foreach (($it['sources'] ?? []) as $src) $it['srcSet'][$src] = true;
                $seenExact[$k] = count($step1);
                $step1[] = $it;
            }
        }
        return $step1;
    }

    /** ШАГ 2: склейка названий стратегий по Левенштейну внутри групп type|side|site. */
    private function dedupeLevenshtein(array $step1): array
    {
        $step2 = [];
        $index = [];
        foreach ($step1 as $it) {
            $sec  = $it['section'] ?? '';
            $name = TextUtil::normName((string)($it['name'] ?? ''));

            if ($sec !== 'strategy' || $name === '') {
                $step2[] = $it;
                continue;
            }

            $groupKey = ($it['type'] ?? 'ПРОЧЕЕ') . '|' . ($it['side'] ?? 'ANY') . '|' . ($it['site'] ?? 'ALL');
            $foundIdx = null;

            if (isset($index[$groupKey])) {
                foreach ($index[$groupKey] as $existingName => $idx) {
                    $dist   = TextUtil::mbLevenshtein($name, $existingName);
                    $maxLen = max(mb_strlen($name), mb_strlen($existingName));
                    if ($dist <= $this->levMaxDist || ($maxLen > 0 && $dist / $maxLen <= 0.15)) {
                        $foundIdx = $idx;
                        break;
                    }
                }
            }

            if ($foundIdx !== null) {
                self::absorb($step2[$foundIdx], $it);
            } else {
                $newIdx = count($step2);
                $step2[] = $it;
                $index[$groupKey][$name] = $newIdx;
            }
        }
        return $step2;
    }

    /**
     * ШАГ 3: склейка описаний стратегий по Жаккарду.
     * Множества-карты вместо list+array_intersect/merge — результат
     * идентичен, но без квадратичных аллокаций на сравнение.
     */
    private function dedupeJaccard(array $step2): array
    {
        $step3 = [];
        $jaccTokens = [];
        $jaccIndex = [];
        foreach ($step2 as $it) {
            $sec  = $it['section'] ?? '';
            $desc = (string)($it['desc'] ?? '');
            $tokens = TextUtil::rowTokens([$desc]);

            if ($sec !== 'strategy' || count($tokens) < 2) {
                $step3[] = $it;
                $jaccTokens[] = array_fill_keys($tokens, true);
                continue;
            }

            $groupKey = ($it['type'] ?? 'ПРОЧЕЕ') . '|' . ($it['side'] ?? 'ANY') . '|' . ($it['site'] ?? 'ALL');
            $foundIdx = null;
            $tokenMap = array_fill_keys($tokens, true);

            if (isset($jaccIndex[$groupKey])) {
                foreach ($jaccIndex[$groupKey] as $idx) {
                    $sim = TextUtil::jaccardSets(
                        $tokenMap, count($tokenMap),
                        $jaccTokens[$idx], count($jaccTokens[$idx])
                    );
                    if ($sim >= $this->jaccardHard) {
                        $foundIdx = $idx;
                        break;
                    }
                }
            }

            if ($foundIdx !== null) {
                self::absorb($step3[$foundIdx], $it);
                $jaccTokens[$foundIdx] += $tokenMap; // union
            } else {
                $newIdx = count($step3);
                $step3[] = $it;
                $jaccTokens[] = $tokenMap;
                $jaccIndex[$groupKey][] = $newIdx;
            }
        }
        return $step3;
    }

    /** Поглощение дубликата: источники + самое длинное описание. */
    private static function absorb(array &$dst, array $src): void
    {
        foreach (($src['sources'] ?? []) as $s) {
            if (!in_array($s, $dst['sources'], true)) {
                $dst['sources'][] = $s;
            }
        }
        if (mb_strlen($src['desc'] ?? '') > mb_strlen($dst['desc'] ?? '')) {
            $dst['desc'] = $src['desc'];
        }
    }
}
