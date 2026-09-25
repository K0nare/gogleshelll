<?php
declare(strict_types=1);

namespace Stratbook;

/**
 * Сборка листов итогового файла: карта (buildNiceSheet) и «СВОДКА»
 * (buildSummarySheet). Порт соответствующих функций v7; структура rows/cols
 * совпадает с форматом, который потребляет XlsxWriter.
 */
final class SheetBuilder
{
    /** Фиксированный порядок типов стратегий при сортировке. */
    private const TYPE_ORDER = [
        'ДЕФОЛТ' => 1, 'КОНТРОЛЬ' => 2, 'РЕТЕЙК' => 3, 'ФАСТ' => 4, 'СПЛИТ' => 5,
        'ПИСТОЛЕТКА' => 6, 'ЗАНЯТИЕ' => 7, 'КОНТАКТ' => 8, 'ФЕЙК' => 9,
        'БУСТ' => 10, 'ОКНО' => 11, 'УЛИЦА' => 12, 'МИД' => 13, 'РАМП' => 14, 'ПРОЧЕЕ' => 99,
    ];

    /** Сравнение стратегий: сначала по типу, затем по названию. */
    public static function cmpStrategy(array $a, array $b): int
    {
        $ta = self::TYPE_ORDER[$a['type'] ?? 'ПРОЧЕЕ'] ?? 50;
        $tb = self::TYPE_ORDER[$b['type'] ?? 'ПРОЧЕЕ'] ?? 50;
        if ($ta !== $tb) return $ta - $tb;
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    }

    /**
     * Лист карты: три колонки-группы (раунды / дополнительные / пистолетки),
     * ниже — блоки спавнов, стенок и гранат.
     */
    public static function buildMapSheet(
        string $sheetName,
        array $strategies,
        array $spawns,
        array $walls,
        array $nades,
        string $tabColor,
        string $date
    ): array {
        $rows = [];
        $N = 11;

        [$grp1, $grp2, $grp3] = self::splitByGroup($strategies);
        usort($grp1, [self::class, 'cmpStrategy']);
        usort($grp2, [self::class, 'cmpStrategy']);
        usort($grp3, [self::class, 'cmpStrategy']);

        $emptyCells = static fn(int $style): array => self::blankCells($N, $style);

        // Заголовок и подзаголовок со статистикой
        $t = $emptyCells(1);
        $t[0] = ['v' => $sheetName, 's' => 1];
        $rows[] = ['h' => 44, 'cells' => $t, 'merge' => 'A:K'];

        $sub = $emptyCells(2);
        $sub[0] = ['v' => sprintf(
            "Раунды: %d    •    Пистолетки: %d    •    Спавнов: %d    •    Стенок: %d    •    Гранат: %d    •    %s",
            count($grp1) + count($grp2), count($grp3),
            count($spawns), count($walls), count($nades), $date
        ), 's' => 2];
        $rows[] = ['h' => 24, 'cells' => $sub, 'merge' => 'A:K'];
        $rows[] = ['h' => 10, 'cells' => []];

        // Шапки трёх колонок-групп
        $heads = $emptyCells(3);
        $heads[0] = ['v' => "  ⬢  РАУНДЫ",        's' => 3];
        $heads[4] = ['v' => "  ⬢  ДОПОЛНИТЕЛЬНЫЕ", 's' => 3];
        $heads[8] = ['v' => "  ⬢  ПИСТОЛЕТКИ",     's' => 3];
        $rows[] = ['h' => 28, 'cells' => $heads, 'merge' => 'A:K'];

        $hr = $emptyCells(4);
        $hr[0]  = ['v' => '#',        's' => 4];
        $hr[1]  = ['v' => 'НАЗВАНИЕ', 's' => 4];
        $hr[2]  = ['v' => 'ОПИСАНИЕ', 's' => 4];
        $hr[4]  = ['v' => '#',        's' => 4];
        $hr[5]  = ['v' => 'НАЗВАНИЕ', 's' => 4];
        $hr[6]  = ['v' => 'ОПИСАНИЕ', 's' => 4];
        $hr[8]  = ['v' => '#',        's' => 4];
        $hr[9]  = ['v' => 'НАЗВАНИЕ', 's' => 4];
        $hr[10] = ['v' => 'ОПИСАНИЕ', 's' => 4];
        $rows[] = ['h' => 26, 'cells' => $hr];

        // Тело: три группы в параллельных колонках, зебра по чётности
        $maxLen = max(count($grp1), count($grp2), count($grp3));
        for ($i = 0; $i < $maxLen; $i++) {
            $even  = ($i % 2 === 0);
            $numS  = $even ? 7 : 8;
            $bodyS = $even ? 5 : 6;
            $cells = $emptyCells($bodyS);

            foreach ([[0, $grp1], [4, $grp2]] as [$base, $group]) {
                if (!isset($group[$i])) continue;
                $s = $group[$i];
                $cells[$base]     = ['v' => (string)($i + 1), 's' => $numS];
                $cells[$base + 1] = ['v' => $s['name'],       's' => Styles::badgeStyle($s['type'])];
                $cells[$base + 2] = ['v' => $s['desc'] ?? '', 's' => $bodyS];
            }
            if (isset($grp3[$i])) {
                $s = $grp3[$i];
                $cells[8]  = ['v' => (string)($i + 1),  's' => $numS];
                $cells[9]  = ['v' => $s['name'],        's' => Styles::badgeStyle('ПИСТОЛЕТКА')];
                $cells[10] = ['v' => $s['desc'] ?? '',  's' => $bodyS];
            }
            $rows[] = ['h' => 24, 'cells' => $cells];
        }

        $rows[] = ['h' => 10, 'cells' => []];

        // Однотипные секции-списки под картой
        $rows = array_merge($rows, self::buildCoordSection('  📍  СПАВНЫ И ПОЗИЦИИ', 'КООРДИНАТЫ (setpos)', $spawns, $emptyCells));
        $rows = array_merge($rows, self::buildCoordSection('  🧱  СТЕНКИ',          'КООРДИНАТЫ (setpos)', $walls,  $emptyCells));
        $rows = array_merge($rows, self::buildNadeSection('  💣  ГРАНАТЫ',           $nades, $emptyCells));

        return [
            'cols'   => [5, 26, 55, 3, 5, 26, 55, 3, 5, 26, 40],
            'rows'   => $rows,
            'tab'    => $tabColor,
            'freeze' => 5,
        ];
    }

    /** @return array{0:array,1:array,2:array} [раунды, дополнительные, пистолетки] */
    private static function splitByGroup(array $strategies): array
    {
        $grp1 = []; $grp2 = []; $grp3 = [];
        foreach ($strategies as $s) {
            $t = $s['type'] ?? 'ПРОЧЕЕ';
            if ($t === 'ПИСТОЛЕТКА') $grp3[] = $s;
            elseif (in_array($t, ['ДЕФОЛТ', 'КОНТРОЛЬ', 'РЕТЕЙК', 'ФАСТ', 'СПЛИТ'], true)) $grp1[] = $s;
            else $grp2[] = $s;
        }
        return [$grp1, $grp2, $grp3];
    }

    /** Секция спавнов/стенок (name + setpos-координата, C:K слиты). */
    private static function buildCoordSection(string $title, string $coordHeader, array $items, callable $emptyCells): array
    {
        if (empty($items)) return [];
        $rows = [];
        $head = $emptyCells(3);
        $head[0] = ['v' => $title . '  ·  ' . count($items), 's' => 3];
        $rows[] = ['h' => 28, 'cells' => $head, 'merge' => 'A:K'];

        $hr = $emptyCells(4);
        $hr[0] = ['v' => '#',            's' => 4];
        $hr[1] = ['v' => 'НАЗВАНИЕ',     's' => 4];
        $hr[2] = ['v' => $coordHeader,   's' => 4];
        $rows[] = ['h' => 26, 'cells' => $hr, 'merge' => 'C:K'];

        $n = 0;
        foreach ($items as $item) {
            $n++;
            $even   = ($n % 2 === 1);
            $numS   = $even ? 7 : 8;
            $bodyS  = $even ? 5 : 6;
            $coordS = $even ? 9 : 10;
            $cells  = $emptyCells($coordS);
            $cells[0] = ['v' => (string)$n,         's' => $numS];
            $cells[1] = ['v' => $item['name'],      's' => $bodyS];
            $cells[2] = ['v' => $item['value'],     's' => $coordS];
            $rows[] = ['h' => 22, 'cells' => $cells, 'merge' => 'C:K'];
        }
        $rows[] = ['h' => 10, 'cells' => []];
        return $rows;
    }

    /** Секция гранат (name + описание, C:K слиты). */
    private static function buildNadeSection(string $title, array $items, callable $emptyCells): array
    {
        if (empty($items)) return [];
        $rows = [];
        $head = $emptyCells(3);
        $head[0] = ['v' => $title . '  ·  ' . count($items), 's' => 3];
        $rows[] = ['h' => 28, 'cells' => $head, 'merge' => 'A:K'];

        $hr = $emptyCells(4);
        $hr[0] = ['v' => '#',        's' => 4];
        $hr[1] = ['v' => 'НАЗВАНИЕ', 's' => 4];
        $hr[2] = ['v' => 'ОПИСАНИЕ', 's' => 4];
        $rows[] = ['h' => 26, 'cells' => $hr, 'merge' => 'C:K'];

        $n = 0;
        foreach ($items as $g) {
            $n++;
            $even  = ($n % 2 === 1);
            $numS  = $even ? 7 : 8;
            $bodyS = $even ? 5 : 6;
            $cells = $emptyCells($bodyS);
            $cells[0] = ['v' => (string)$n,     's' => $numS];
            $cells[1] = ['v' => $g['name'],     's' => $bodyS];
            $cells[2] = ['v' => $g['value'],    's' => $bodyS];
            $rows[] = ['h' => 22, 'cells' => $cells, 'merge' => 'C:K'];
        }
        return $rows;
    }

    /** Лист «СВОДКА»: статистика по картам и итоговая строка. */
    public static function buildSummarySheet(array $store, string $date): array
    {
        $cols = [22, 11, 11, 11, 12, 11, 11, 13, 11, 11, 11];
        $N = count($cols);
        $rows = [];
        $emptyCells = static fn(int $style): array => self::blankCells($N, $style);

        $t = $emptyCells(1);
        $t[0] = ['v' => 'STRATBOOK EXCLUSIVE', 's' => 1];
        $rows[] = ['h' => 56, 'cells' => $t, 'merge' => 'A:K'];

        $s = $emptyCells(2);
        $s[0] = ['v' => "Сводная таблица    •    Обновлено: $date", 's' => 2];
        $rows[] = ['h' => 28, 'cells' => $s, 'merge' => 'A:K'];
        $rows[] = ['h' => 12, 'cells' => []];

        $hr = $emptyCells(4);
        $hr[0]  = ['v' => 'КАРТА',      's' => 4];
        $hr[1]  = ['v' => 'СТРАТЕГИЙ',  's' => 4];
        $hr[2]  = ['v' => 'T / CT',     's' => 4];
        $hr[3]  = ['v' => 'ДЕФОЛТ',     's' => 4];
        $hr[4]  = ['v' => 'КОНТРОЛЬ',   's' => 4];
        $hr[5]  = ['v' => 'ФАСТ',       's' => 4];
        $hr[6]  = ['v' => 'СПЛИТ',      's' => 4];
        $hr[7]  = ['v' => 'ПИСТОЛЕТКА', 's' => 4];
        $hr[8]  = ['v' => 'ПРОЧЕЕ',     's' => 4];
        $hr[9]  = ['v' => 'СПАВНЫ',     's' => 4];
        $hr[10] = ['v' => 'СТЕНКИ',     's' => 4];
        $rows[] = ['h' => 40, 'cells' => $hr];

        $zeroCounters = static fn(): array =>
            ['s'=>0,'t'=>0,'ct'=>0,'def'=>0,'ctrl'=>0,'fast'=>0,'split'=>0,'pistol'=>0,'other'=>0,'spawn'=>0,'wall'=>0];
        $total = $zeroCounters();
        $n = 0;
        foreach ($store as $sheetName => $data) {
            $items = $data['items'];
            if (empty($items)) continue;
            $n++;
            $even  = ($n % 2 === 1);
            $bodyS = $even ? 5 : 6;
            $numS  = $even ? 7 : 8;

            $c = $zeroCounters();
            foreach ($items as $it) {
                switch ($it['section'] ?? '') {
                    case 'strategy':
                        $c['s']++;
                        if (($it['side'] ?? 'ANY') === 'T')  $c['t']++;
                        if (($it['side'] ?? 'ANY') === 'CT') $c['ct']++;
                        switch ($it['type']) {
                            case 'ДЕФОЛТ':     $c['def']++; break;
                            case 'КОНТРОЛЬ':
                            case 'РЕТЕЙК':     $c['ctrl']++; break;
                            case 'ФАСТ':       $c['fast']++; break;
                            case 'СПЛИТ':      $c['split']++; break;
                            case 'ПИСТОЛЕТКА': $c['pistol']++; break;
                            default:           $c['other']++; break;
                        }
                        break;
                    case 'spawn': $c['spawn']++; break;
                    case 'wall':  $c['wall']++; break;
                }
            }
            foreach ($c as $k => $v) if (isset($total[$k])) $total[$k] += $v;

            $row = $emptyCells($bodyS);
            $row[0]  = ['v' => $sheetName, 's' => $bodyS];
            $row[1]  = ['v' => (string)$c['s'],        's' => $numS];
            $row[2]  = ['v' => $c['t'].' / '.$c['ct'], 's' => $numS];
            $row[3]  = ['v' => $c['def']    ? (string)$c['def']    : '—', 's' => $numS];
            $row[4]  = ['v' => $c['ctrl']   ? (string)$c['ctrl']   : '—', 's' => $numS];
            $row[5]  = ['v' => $c['fast']   ? (string)$c['fast']   : '—', 's' => $numS];
            $row[6]  = ['v' => $c['split']  ? (string)$c['split']  : '—', 's' => $numS];
            $row[7]  = ['v' => $c['pistol'] ? (string)$c['pistol'] : '—', 's' => $numS];
            $row[8]  = ['v' => $c['other']  ? (string)$c['other']  : '—', 's' => $numS];
            $row[9]  = ['v' => $c['spawn']  ? (string)$c['spawn']  : '—', 's' => $numS];
            $row[10] = ['v' => $c['wall']   ? (string)$c['wall']   : '—', 's' => $numS];
            $rows[] = ['h' => 24, 'cells' => $row];
        }

        $rows[] = ['h' => 8, 'cells' => []];

        $row = $emptyCells(3);
        $row[0]  = ['v' => 'ИТОГО',                  's' => 3];
        $row[1]  = ['v' => (string)$total['s'],      's' => 3];
        $row[2]  = ['v' => $total['t'].' / '.$total['ct'], 's' => 3];
        $row[3]  = ['v' => (string)$total['def'],    's' => 3];
        $row[4]  = ['v' => (string)$total['ctrl'],   's' => 3];
        $row[5]  = ['v' => (string)$total['fast'],   's' => 3];
        $row[6]  = ['v' => (string)$total['split'],  's' => 3];
        $row[7]  = ['v' => (string)$total['pistol'], 's' => 3];
        $row[8]  = ['v' => (string)$total['other'],  's' => 3];
        $row[9]  = ['v' => (string)$total['spawn'],  's' => 3];
        $row[10] = ['v' => (string)$total['wall'],   's' => 3];
        $rows[] = ['h' => 28, 'cells' => $row];

        $rows[] = ['h' => 8, 'cells' => []];

        $f = $emptyCells(20);
        $f[0] = ['v' => 'Файл сгенерирован автоматически.', 's' => 20];
        $rows[] = ['h' => 24, 'cells' => $f, 'merge' => 'A:K'];

        return ['cols' => $cols, 'rows' => $rows, 'tab' => 'FF1B1F3B', 'freeze' => 5];
    }

    /** @return array<int,array{v:string,s:int}> N пустых ячеек со стилем. */
    private static function blankCells(int $n, int $style): array
    {
        $arr = [];
        for ($i = 0; $i < $n; $i++) $arr[$i] = ['v' => '', 's' => $style];
        return $arr;
    }
}
