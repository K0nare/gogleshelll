<?php
/**
 * STRATBOOK CONSOLIDATOR v7 - HARD DEDUPE (final)
 */

set_time_limit(0);
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ====================== НАСТРОЙКИ ======================
$files = [
    "22_06_2025Копия STRATBOOK EXCLUS1VE.xlsx",
    "Копия STRATBOOK EXCLUS1VE111.xlsx",
    "Копия STRATBOOK EXCLUS1VE 28 06 225.xlsx",
    "Копия STRATBOOK EXCLUS1VE19 07 2025.xlsx",
    "Копия STRATBOOK EXCLUS1VE100MAY.xlsx",
    "_STRATBOOK  konare.xlsx",
    "Копия STRATBOOK EXCLUS1VE 2__.xlsx",
    "Копия STRATBOOK EXCLUS1VE 3.xlsx",
    "Копия STRATBOOK EXCLUS1VE.xlsx",
    "Копия STRATBOOK EXCLUS1VE (1).xlsx",
];

$outputFile = "STRATBOOK_FINAL.xlsx";

$sheetOrder = [
    "NUKE","TRAIN","ANCIENT","MIRAGE","ANUBIS",
    "DUST 2","INFERNO","VERTIGO","OVERPASS",
    "NADS ANUBIS","NADS ANCIENT","NADS TRAIN","NADS Mirage","NADS DUST 2",
];

$tabColors = [
    "NUKE"=>"FF10B981","TRAIN"=>"FF4A6CF7","ANCIENT"=>"FFF59E0B",
    "MIRAGE"=>"FF8B5CF6","ANUBIS"=>"FF06B6D4","DUST 2"=>"FFEF4444",
    "INFERNO"=>"FFF97316","VERTIGO"=>"FFEC4899","OVERPASS"=>"FF14B8A6",
    "NADS ANUBIS"=>"FF64748B","NADS ANCIENT"=>"FF64748B",
    "NADS TRAIN"=>"FF64748B","NADS Mirage"=>"FF64748B","NADS DUST 2"=>"FF64748B",
];

$JACCARD_HARD = 0.85;
$LEV_MAX_DIST = 2;

// ====================== УТИЛИТЫ ======================
function colToIndex(string $col): int {
    $col = strtoupper($col); $n = 0;
    for ($i = 0; $i < strlen($col); $i++) $n = $n * 26 + (ord($col[$i]) - 64);
    return $n;
}
function indexToCol(int $idx): string {
    $s = '';
    while ($idx > 0) { $idx--; $s = chr(65 + ($idx % 26)) . $s; $idx = intdiv($idx, 26); }
    return $s;
}

function norm(string $s): string {
    $s = str_replace(["\xC2\xA0","\t","\r","\n"], ' ', $s);
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);
    $s = preg_replace('/\[[^\]]*\]/u', ' ', $s);
    $s = preg_replace('/\{[^}]*\}/u', ' ', $s);
    $s = preg_replace('/[!?*,;:\'"`~^<>|\\\\\/]+/u', ' ', $s);
    $s = preg_replace('/[_\-]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = str_replace(['ё','Ё'], ['е','Е'], $s);
    return mb_strtolower(trim($s), 'UTF-8');
}

function normName(string $s): string {
    $s = norm($s);
    $s = preg_replace('/[\.\,]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function rtrimRow(array $row): array {
    while (!empty($row) && trim((string)end($row)) === '') array_pop($row);
    return $row;
}
function isEmptyRow(array $row): bool {
    foreach ($row as $v) if (trim((string)$v) !== '') return false;
    return true;
}

function mbLevenshtein(string $a, string $b): int {
    $la = mb_strlen($a, 'UTF-8');
    $lb = mb_strlen($b, 'UTF-8');
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $lb; $j++) {
            $cost = (mb_substr($a, $i-1, 1, 'UTF-8') === mb_substr($b, $j-1, 1, 'UTF-8')) ? 0 : 1;
            $cur[$j] = min(
                $cur[$j-1] + 1,
                $prev[$j] + 1,
                $prev[$j-1] + $cost
            );
        }
        $prev = $cur;
    }
    return $prev[$lb];
}


function rowTokens(array $row): array {
    $longest = '';
    foreach ($row as $v) {
        $v = trim((string)$v);
        if (mb_strlen($v) > mb_strlen($longest)) $longest = $v;
    }
    if ($longest === '') return [];
    $s = norm($longest);
    $stop = ['и','в','на','с','по','из','от','до','или','the','and','for','with'];
    $shortOk = ['2d','jl','ef','gl','wp','kt','vp','м33','к9','б8','мм','ог','инс'];
    $out = [];
    foreach (explode(' ', $s) as $t) {
        if ($t === '') continue;
        if (mb_strlen($t) >= 3 || in_array($t, $shortOk, true)) {
            if (!in_array($t, $stop, true)) $out[$t] = true;
        }
    }
    return array_keys($out);
}

function jaccardSets(array $setA, int $sizeA, array $setB, int $sizeB): float {
    // $setA/$setB — flip-карты токенов (token => true)
    if ($sizeA < 2 || $sizeB < 2) return 0.0;
    $inter = 0;
    foreach ($setA as $t => $_) if (isset($setB[$t])) $inter++;
    $union = $sizeA + $sizeB - $inter;
    return $union > 0 ? $inter / $union : 0.0;
}

// ====================== КОНТЕКСТ ======================
function detectSide(string $text): string {
    $u = ' ' . mb_strtoupper($text, 'UTF-8') . ' ';
    $u = preg_replace('/\s+/u', ' ', $u);
    if (preg_match('/\b(РЕТЕЙК|RETAKE)\b/u', $u)) return 'CT';
    if (preg_match('/(?:^|\s)(?:ОТ\s*КТ|ЗА\s*КТ|КТ\s*СТАРТ|CT\s*SIDE|CT\s*START|ОБОРОН)/u', $u)) return 'CT';
    if (preg_match('/(?:^|\s)КТ(?:\s|$)/u', $u)) return 'CT';
    if (preg_match('/(?:^|\s)(?:ОТ\s*Т|ЗА\s*Т|Т\s*СТАРТ|T\s*SIDE|T\s*START|АТАК)/u', $u)) return 'T';
    if (preg_match('/(?:^|\s)Т(?:\s|$)/u', $u) && !preg_match('/КТ/u', $u)) return 'T';
    return 'ANY';
}
function detectSite(string $text): string {
    $u = ' ' . mb_strtoupper($text, 'UTF-8') . ' ';
    $u = preg_replace('/\s+/u', ' ', $u);
    $hasA   = (bool)preg_match('/(?:^|[\s\/\|,\(\)\-])А(?:$|[\s\/\|,\(\)\-])/u', $u);
    $hasB   = (bool)preg_match('/(?:^|[\s\/\|,\(\)\-])(?:Б|B)(?:$|[\s\/\|,\(\)\-])/u', $u);
    $hasMid = (bool)preg_match('/(?:^|\s)(?:МИД|MID|ЦЕНТР)(?:\s|$)/u', $u);
    if ($hasA && $hasB) return 'ALL';
    if ($hasA) return 'A';
    if ($hasB) return 'B';
    if ($hasMid) return 'MID';
    return 'ALL';
}

// ====================== КЛАССИФИКАЦИЯ ======================
function classify(array $row): array {
    // Кэш классификации: одни и те же строки массово повторяются между
    // файлами-копиями, а classifyRaw — это десятки regex на каждую строку.
    // Возвращается СВЕЖАЯ КОПИЯ результата (со своим массивом sources),
    // поэтому мутации элементов в дедупликации не влияют на кэш и на другие
    // экземпляры той же строки — поведение идентично отсутствию кэша.
    static $cache = [];
    $ck = sha1(implode("\x1f", $row));
    if (!isset($cache[$ck])) $cache[$ck] = classifyRaw($row);
    $res = $cache[$ck];
    if (($res['section'] ?? '') !== 'skip') $res['sources'] = [];
    return $res;
}

function classifyRaw(array $row): array {
    $ne = [];
    foreach ($row as $v) { $v = trim((string)$v); if ($v !== '') $ne[] = $v; }
    if (empty($ne)) return ['section' => 'skip'];
    $first = $ne[0];
    $joined = implode(' ', $ne);

    if (preg_match('/^\s*SPAWN\s*\d*/iu', $first) || preg_match('/setpos\s+[-0-9]/i', $joined)) {
        if (stripos($first, 'СТЕНКА') !== false || stripos($first, 'WALL') !== false)
            return ['section' => 'wall', 'name' => $first, 'value' => findCoord($ne)];
        return ['section' => 'spawn', 'name' => $first ?: 'SPAWN', 'value' => findCoord($ne)];
    }
    if (stripos($first, 'СТЕНКА') !== false)
        return ['section' => 'wall', 'name' => $first, 'value' => findCoord($ne)];
    if (preg_match('/^(смок|молик|моли|флеш|флешка|хае|граната|nade)/iu', $first))
        return ['section' => 'nade', 'name' => $first, 'value' => implode(' • ', array_slice($ne, 1))];

    $u = mb_strtoupper($joined, 'UTF-8');
    $type = 'ПРОЧЕЕ';
    if (preg_match('/ПИСТОЛЕТКА|ПИСТИКИ|PISTOL|ЭКО|ECO|АНТИЭКО/iu', $u))                $type = 'ПИСТОЛЕТКА';
    elseif (preg_match('/ФАСТ|FAST|РАШ|RUSH|ПУШ|PUSH|БЫСТР|БЫСТАР|2 ТЕМП|ТЕМП/iu', $u)) $type = 'ФАСТ';
    elseif (preg_match('/СПЛИТ|SPLIT/iu', $u))                                            $type = 'СПЛИТ';
    elseif (preg_match('/РЕТЕЙК|RETAKE/iu', $u))                                          $type = 'РЕТЕЙК';
    elseif (preg_match('/КОНТРОЛЬ|CONTROL/iu', $u))                                       $type = 'КОНТРОЛЬ';
    elseif (preg_match('/ДЕФОЛТ|DEFAULT/iu', $u))                                         $type = 'ДЕФОЛТ';
    elseif (preg_match('/ФЕЙК|FAKE/iu', $u))                                              $type = 'ФЕЙК';
    elseif (preg_match('/ЗАНЯТИЕ|ЗАХВАТ|TAKE/iu', $u))                                    $type = 'ЗАНЯТИЕ';
    elseif (preg_match('/КОНТАКТ/iu', $u))                                                $type = 'КОНТАКТ';
    elseif (preg_match('/БУСТ|BOOST/iu', $u))                                             $type = 'БУСТ';
    elseif (preg_match('/ОКНО|WINDOW|БАЛКОН/iu', $u))                                     $type = 'ОКНО';
    elseif (preg_match('/УЛИЦА|СТРИТ|STREET/iu', $u))                                     $type = 'УЛИЦА';
    elseif (preg_match('/МИД|MID/iu', $u))                                                $type = 'МИД';
    elseif (preg_match('/РАМП|RAMP/iu', $u))                                              $type = 'РАМП';

    return [
        'section' => 'strategy',
        'type'    => $type,
        'side'    => detectSide($joined),
        'site'    => detectSite($joined),
        'name'    => $first,
        'desc'    => implode(' • ', array_slice($ne, 1)),
    ];
}
function findCoord(array $ne): string {
    foreach ($ne as $v) if (preg_match('/setpos\s+[-0-9]/i', $v)) return $v;
    return implode(' ', array_slice($ne, 1));
}

// ====================== ЖЁСТКАЯ ДЕДУПЛИКАЦИЯ ======================
function hardKey(array $item): string {
    $sec = $item['section'] ?? '';
    $name = normName((string)($item['name'] ?? ''));

    if ($sec === 'strategy') {
        $side = $item['side'] ?? 'ANY';
        $site = $item['site'] ?? 'ALL';
        return 'S||' . $name . '||' . $side . '||' . $site;
    }

    if ($sec === 'spawn') {
        $coord = norm((string)($item['value'] ?? ''));
        $coord = preg_replace_callback('/-?\d+\.?\d*/', function ($m) {
            $n = (float)$m[0];
            $s = number_format($n, 1, '.', '');
            $s = rtrim(rtrim($s, '0'), '.');
            return $s === '-0' ? '0' : $s;
        }, $coord);
        return 'P||' . $coord;
    }

    if ($sec === 'wall') {
        $coord = norm((string)($item['value'] ?? ''));
        $coord = preg_replace_callback('/-?\d+\.?\d*/', function ($m) {
            $n = (float)$m[0];
            $s = number_format($n, 1, '.', '');
            $s = rtrim(rtrim($s, '0'), '.');
            return $s === '-0' ? '0' : $s;
        }, $coord);
        return 'W||' . $name . '||' . $coord;
    }

    if ($sec === 'nade') {
        $coord = norm((string)($item['value'] ?? ''));
        return 'N||' . $name . '||' . $coord;
    }

    return $sec . '||' . $name;
}

// Слияние дубликата в существующий элемент (источники/описания/значения)
function mergeInto(array &$dst, array $srcItem): void {
    foreach (($srcItem['sources'] ?? []) as $src) {
        if (!isset($dst['srcSet'][$src])) {
            $dst['srcSet'][$src] = true;
            $dst['sources'][] = $src;
        }
    }
    if (mb_strlen((string)($srcItem['desc'] ?? '')) > mb_strlen((string)($dst['desc'] ?? '')))
        $dst['desc'] = $srcItem['desc'];
    if (mb_strlen((string)($srcItem['value'] ?? '')) > mb_strlen((string)($dst['value'] ?? '')))
        $dst['value'] = $srcItem['value'];
}

function hardDedupe(array &$store): array {
    global $LEV_MAX_DIST, $JACCARD_HARD;

    $stats = ['removed' => 0, 'per_sheet' => []];

    foreach ($store as $sheetName => &$data) {
        $items = $data['items'];
        if (empty($items)) {
            $data['items'] = [];
            $data['tokens'] = [];
            $stats['per_sheet'][$sheetName] = 0;
            continue;
        }

        // ШАГ 1: exact key
        $seenExact = [];
        $step1 = [];
        foreach ($items as $it) {
            $k = hardKey($it);
            if (isset($seenExact[$k])) {
                mergeInto($step1[$seenExact[$k]], $it);
            } else {
                $it['srcSet'] = [];
                foreach (($it['sources'] ?? []) as $src) $it['srcSet'][$src] = true;
                $seenExact[$k] = count($step1);
                $step1[] = $it;
            }
        }

        // ШАГ 2: Левенштейн
        $step2 = [];
        $index = [];
        foreach ($step1 as $it) {
            $sec = $it['section'] ?? '';
            $name = normName((string)($it['name'] ?? ''));

            if ($sec !== 'strategy' || $name === '') {
                $step2[] = $it;
                continue;
            }

            $groupKey = ($it['type'] ?? 'ПРОЧЕЕ') . '|' . ($it['side'] ?? 'ANY') . '|' . ($it['site'] ?? 'ALL');
            $foundIdx = null;

            if (isset($index[$groupKey])) {
                foreach ($index[$groupKey] as $existingName => $idx) {
                    $dist = mbLevenshtein($name, $existingName);
                    $maxLen = max(mb_strlen($name), mb_strlen($existingName));
                    if ($dist <= $LEV_MAX_DIST || ($maxLen > 0 && $dist / $maxLen <= 0.15)) {
                        $foundIdx = $idx;
                        break;
                    }
                }
            }

            if ($foundIdx !== null) {
                foreach (($it['sources'] ?? []) as $src) {
                    if (!in_array($src, $step2[$foundIdx]['sources'], true)) {
                        $step2[$foundIdx]['sources'][] = $src;
                    }
                }
                if (mb_strlen($it['desc'] ?? '') > mb_strlen($step2[$foundIdx]['desc'] ?? ''))
                    $step2[$foundIdx]['desc'] = $it['desc'];
            } else {
                $newIdx = count($step2);
                $step2[] = $it;
                $index[$groupKey][$name] = $newIdx;
            }
        }

        // ШАГ 3: Jaccard (множества-карты вместо list+array_intersect/merge —
        // результат идентичен, но без квадратичных аллокаций на сравнение)
        $step3 = [];
        $jaccTokens = [];
        $jaccIndex = [];
        foreach ($step2 as $it) {
            $sec = $it['section'] ?? '';
            $desc = (string)($it['desc'] ?? '');
            $tokens = rowTokens([$desc]);

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
                    $sim = jaccardSets($tokenMap, count($tokenMap), $jaccTokens[$idx], count($jaccTokens[$idx]));
                    if ($sim >= $JACCARD_HARD) {
                        $foundIdx = $idx;
                        break;
                    }
                }
            }

            if ($foundIdx !== null) {
                foreach (($it['sources'] ?? []) as $src) {
                    if (!in_array($src, $step3[$foundIdx]['sources'], true)) {
                        $step3[$foundIdx]['sources'][] = $src;
                    }
                }
                if (mb_strlen($it['desc'] ?? '') > mb_strlen($step3[$foundIdx]['desc'] ?? ''))
                    $step3[$foundIdx]['desc'] = $it['desc'];
                $jaccTokens[$foundIdx] += $tokenMap; // union
            } else {
                $newIdx = count($step3);
                $step3[] = $it;
                $jaccTokens[] = $tokenMap;
                $jaccIndex[$groupKey][] = $newIdx;
            }
        }

        // снимаем служебное поле srcSet
        foreach ($step3 as &$it3) unset($it3['srcSet']);
        unset($it3);

        $removed = count($items) - count($step3);
        $data['items'] = $step3;
        $data['tokens'] = array_fill(0, count($step3), []);
        $stats['per_sheet'][$sheetName] = $removed;
        $stats['removed'] += $removed;
    }
    unset($data);
    return $stats;
}

// ====================== ЧТЕНИЕ XLSX ======================
function readXlsx(string $file): array {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException("Не открыть ZIP: $file");
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $sx = @simplexml_load_string($ss);
        if ($sx !== false) foreach ($sx->si as $si) {
            if (isset($si->t)) $shared[] = (string)$si->t;
            else { $t=''; foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
        }
    }
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml === false) throw new RuntimeException("Нет workbook.xml");
    $wb = @simplexml_load_string($wbXml);
    $relMap = [];
    $rx = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rx !== false) {
        $r = @simplexml_load_string($rx);
        if ($r !== false) foreach ($r->Relationship as $rel)
            $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    }
    $out = []; $idx = 0;
    foreach ($wb->sheets->sheet as $sheet) {
        $idx++;
        $name = (string)$sheet['name'];
        $a = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = isset($a['id']) ? (string)$a['id'] : '';
        $target = $relMap[$rid] ?? "worksheets/sheet{$idx}.xml";
        $target = ltrim($target, '/');
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
        $target = str_replace(['xl/../','xl//'], ['xl/','xl/'], $target);
        $sheetXml = $zip->getFromName($target) ?: $zip->getFromName("xl/worksheets/sheet{$idx}.xml");
        if ($sheetXml === false) continue;
        $sx = @simplexml_load_string($sheetXml);
        if ($sx === false) continue;
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                if (!preg_match('/^([A-Z]+)(\d+)$/i', (string)$cell['r'], $m)) continue;
                $ci = colToIndex($m[1]);
                $t = (string)$cell['t'];
                if ($t === 's') $val = $shared[(int)(string)$cell->v] ?? '';
                elseif ($t === 'inlineStr') {
                    $val = isset($cell->is->t) ? (string)$cell->is->t : '';
                    if ($val === '' && isset($cell->is->r)) foreach ($cell->is->r as $r) $val .= (string)$r->t;
                } else $val = (string)$cell->v;
                $rowData[$ci] = trim($val);
            }
            if (empty($rowData)) continue;
            $max = max(array_keys($rowData));
            $full = [];
            for ($i = 1; $i <= $max; $i++) $full[] = $rowData[$i] ?? '';
            if (isEmptyRow($full)) continue;
            $rows[] = rtrimRow($full);
        }
        $out[$name] = $rows;
    }
    $zip->close();
    return $out;
}

// ====================== СТИЛИ ======================
function buildStylesXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="8">'
        .   '<font><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="22"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .   '<font><i/><sz val="11"/><color rgb="FF94A3B8"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="10"/><color rgb="FF1B1F3B"/><name val="Calibri"/></font>'
        .   '<font><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
        .   '<font><sz val="9"/><color rgb="FF334155"/><name val="Consolas"/></font>'
        .   '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="16">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1B1F3B"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF2D3561"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEF4444"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF10B981"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF8B5CF6"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF64748B"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEC4899"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF06B6D4"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFAFBFC"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF9"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border>'
        .     '<left style="thin"><color rgb="FFE2E8F0"/></left>'
        .     '<right style="thin"><color rgb="FFE2E8F0"/></right>'
        .     '<top style="thin"><color rgb="FFE2E8F0"/></top>'
        .     '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>'
        .   '</border>'
        .   '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE2E8F0"/></bottom></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="27">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" horizontal="left" indent="1"/></xf>'
        .   '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" horizontal="left" indent="1"/></xf>'
        .   '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" horizontal="left" indent="1"/></xf>'
        .   '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" horizontal="left" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="5" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" horizontal="left" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="5" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" horizontal="left"/></xf>'
        .   '<xf numFmtId="0" fontId="6" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" horizontal="left"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="12" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="2" fillId="6" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center" horizontal="left" indent="1"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="left"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="7" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="4" fillId="15" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="left" indent="1"/></xf>'
        .   '<xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center"/></xf>'
        . '</cellXfs>'
        . '</styleSheet>';
}
function badgeStyle(string $type): int {
    switch ($type) {
        case 'ДЕФОЛТ':     return 11;
        case 'КОНТРОЛЬ':
        case 'РЕТЕЙК':     return 12;
        case 'ФАСТ':       return 13;
        case 'СПЛИТ':      return 14;
        case 'ПИСТОЛЕТКА': return 15;
        case 'ФЕЙК':       return 16;
        case 'БУСТ':       return 17;
        case 'ЗАНЯТИЕ':    return 18;
        default:           return 19;
    }
}

// ====================== ЗАПИСЬ XLSX ======================
function buildSheetXml(array $sheet): string {
    $cols = $sheet['cols'];
    $rows = $sheet['rows'];
    $tabColor = $sheet['tab'] ?? 'FF4A6CF7';
    $freeze = $sheet['freeze'] ?? 5;
    $maxCols = count($cols);

    $colXml = '<cols>';
    foreach ($cols as $ci => $w) {
        $colXml .= '<col min="'.($ci+1).'" max="'.($ci+1).'" width="'.round($w,2).'" customWidth="1"/>';
    }
    $colXml .= '</cols>';

    $merges = [];
    foreach ($rows as $ri => $row) {
        if (empty($row['merge'])) continue;
        $m = $row['merge'];
        if (strpos($m, ':') !== false) {
            [$from, $to] = explode(':', $m);
            $merges[] = $from . ($ri+1) . ':' . $to . ($ri+1);
        } else {
            $merges[] = $m . ($ri+1);
        }
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<sheetPr><tabColor rgb="'.$tabColor.'"/></sheetPr>'
         . '<sheetViews><sheetView workbookViewId="0">'
         .   '<pane ySplit="'.$freeze.'" topLeftCell="A'.($freeze+1).'" activePane="bottomLeft" state="frozen"/>'
         . '</sheetView></sheetViews>'
         . $colXml
         . '<sheetData>';

    $r = 1;
    foreach ($rows as $row) {
        $h = $row['h'] ?? null;
        $rowAttr = ' r="'.$r.'"';
        if ($h) $rowAttr .= ' ht="'.$h.'" customHeight="1"';

        $rowXml = '<row'.$rowAttr.'>';
        $cells = $row['cells'] ?? [];
        for ($c = 0; $c < $maxCols; $c++) {
            $cell = $cells[$c] ?? null;
            if ($cell === null) continue;
            $st  = $cell['s'] ?? 0;
            $val = $cell['v'] ?? '';
            $ref = indexToCol($c+1).$r;
            if ($val === '' && $st === 0) continue;
            if ($val === '') {
                $rowXml .= '<c r="'.$ref.'" s="'.$st.'"/>';
            } else {
                $safe = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $rowXml .= '<c r="'.$ref.'" s="'.$st.'" t="inlineStr"><is><t xml:space="preserve">'.$safe.'</t></is></c>';
            }
        }
        $rowXml .= '</row>';
        $xml .= $rowXml;
        $r++;
    }
    $xml .= '</sheetData>';
    if ($merges) {
        $xml .= '<mergeCells count="'.count($merges).'">';
        foreach ($merges as $m) $xml .= '<mergeCell ref="'.$m.'"/>';
        $xml .= '</mergeCells>';
    }
    $xml .= '</worksheet>';
    return $xml;
}

function writeXlsx(string $file, array $sheets): void {
    @unlink($file);
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
        throw new RuntimeException("Не создать $file");

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $i = 1;
    foreach ($sheets as $_) {
        $ct .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $i++;
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/styles.xml', buildStylesXml());

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $i = 1;
    foreach (array_keys($sheets) as $name) {
        $safe = htmlspecialchars(substr($name, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $wb .= '<sheet name="'.$safe.'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
        $i++;
    }
    $wb .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);

    $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i = 1;
    foreach (array_keys($sheets) as $_) {
        $wbr .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        $i++;
    }
    $wbr .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wbr .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);

    $i = 1;
    foreach ($sheets as $sheetInfo) {
        $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', buildSheetXml($sheetInfo));
        $i++;
    }
    $zip->close();
}

// ====================== СБОРКА ЛИСТА ======================
function cmpStrategy(array $a, array $b): int {
    static $typeOrder = [
        'ДЕФОЛТ'=>1, 'КОНТРОЛЬ'=>2, 'РЕТЕЙК'=>3, 'ФАСТ'=>4, 'СПЛИТ'=>5,
        'ПИСТОЛЕТКА'=>6, 'ЗАНЯТИЕ'=>7, 'КОНТАКТ'=>8, 'ФЕЙК'=>9,
        'БУСТ'=>10, 'ОКНО'=>11, 'УЛИЦА'=>12, 'МИД'=>13, 'РАМП'=>14, 'ПРОЧЕЕ'=>99,
    ];
    $ta = $typeOrder[$a['type'] ?? 'ПРОЧЕЕ'] ?? 50;
    $tb = $typeOrder[$b['type'] ?? 'ПРОЧЕЕ'] ?? 50;
    if ($ta !== $tb) return $ta - $tb;
    return strcmp($a['name'] ?? '', $b['name'] ?? '');
}

function buildNiceSheet(string $sheetName, array $strategies, array $spawns, array $walls, array $nades, string $tabColor, string $date): array {
    $rows = [];
    $N = 11;

    $grp1 = []; $grp2 = []; $grp3 = [];
    foreach ($strategies as $s) {
        $t = $s['type'] ?? 'ПРОЧЕЕ';
        if ($t === 'ПИСТОЛЕТКА') $grp3[] = $s;
        elseif (in_array($t, ['ДЕФОЛТ','КОНТРОЛЬ','РЕТЕЙК','ФАСТ','СПЛИТ'], true)) $grp1[] = $s;
        else $grp2[] = $s;
    }
    usort($grp1, 'cmpStrategy');
    usort($grp2, 'cmpStrategy');
    usort($grp3, 'cmpStrategy');

    $emptyCells = function(int $style) use ($N): array {
        $arr = [];
        for ($i = 0; $i < $N; $i++) $arr[$i] = ['v' => '', 's' => $style];
        return $arr;
    };

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

    $heads = $emptyCells(3);
    $heads[0] = ['v' => "  ⬢  РАУНДЫ",         's' => 3];
    $heads[4] = ['v' => "  ⬢  ДОПОЛНИТЕЛЬНЫЕ",  's' => 3];
    $heads[8] = ['v' => "  ⬢  ПИСТОЛЕТКИ",      's' => 3];
    $rows[] = ['h' => 28, 'cells' => $heads, 'merge' => 'A:K'];

    $hr = $emptyCells(4);
    $hr[0]  = ['v' => '#',         's' => 4];
    $hr[1]  = ['v' => 'НАЗВАНИЕ',  's' => 4];
    $hr[2]  = ['v' => 'ОПИСАНИЕ',  's' => 4];
    $hr[4]  = ['v' => '#',         's' => 4];
    $hr[5]  = ['v' => 'НАЗВАНИЕ',  's' => 4];
    $hr[6]  = ['v' => 'ОПИСАНИЕ',  's' => 4];
    $hr[8]  = ['v' => '#',         's' => 4];
    $hr[9]  = ['v' => 'НАЗВАНИЕ',  's' => 4];
    $hr[10] = ['v' => 'ОПИСАНИЕ',  's' => 4];
    $rows[] = ['h' => 26, 'cells' => $hr];

    $maxLen = max(count($grp1), count($grp2), count($grp3));
    for ($i = 0; $i < $maxLen; $i++) {
        $even = ($i % 2 === 0);
        $numS  = $even ? 7 : 8;
        $bodyS = $even ? 5 : 6;
        $cells = $emptyCells($bodyS);

        if (isset($grp1[$i])) {
            $s = $grp1[$i];
            $cells[0] = ['v' => (string)($i+1),   's' => $numS];
            $cells[1] = ['v' => $s['name'],       's' => badgeStyle($s['type'])];
            $cells[2] = ['v' => $s['desc'] ?? '', 's' => $bodyS];
        }
        if (isset($grp2[$i])) {
            $s = $grp2[$i];
            $cells[4] = ['v' => (string)($i+1),   's' => $numS];
            $cells[5] = ['v' => $s['name'],       's' => badgeStyle($s['type'])];
            $cells[6] = ['v' => $s['desc'] ?? '', 's' => $bodyS];
        }
        if (isset($grp3[$i])) {
            $s = $grp3[$i];
            $cells[8]  = ['v' => (string)($i+1),  's' => $numS];
            $cells[9]  = ['v' => $s['name'],      's' => badgeStyle('ПИСТОЛЕТКА')];
            $cells[10] = ['v' => $s['desc'] ?? '', 's' => $bodyS];
        }
        $rows[] = ['h' => 24, 'cells' => $cells];
    }

    $rows[] = ['h' => 10, 'cells' => []];

    if (!empty($spawns)) {
        $head = $emptyCells(3);
        $head[0] = ['v' => "  📍  СПАВНЫ И ПОЗИЦИИ  ·  " . count($spawns), 's' => 3];
        $rows[] = ['h' => 28, 'cells' => $head, 'merge' => 'A:K'];

        $hr = $emptyCells(4);
        $hr[0] = ['v' => '#',                   's' => 4];
        $hr[1] = ['v' => 'НАЗВАНИЕ',            's' => 4];
        $hr[2] = ['v' => 'КООРДИНАТЫ (setpos)', 's' => 4];
        $rows[] = ['h' => 26, 'cells' => $hr, 'merge' => 'C:K'];

        $n = 0;
        foreach ($spawns as $sp) {
            $n++;
            $even = ($n % 2 === 1);
            $numS  = $even ? 7 : 8;
            $bodyS = $even ? 5 : 6;
            $coordS = $even ? 9 : 10;
            $cells = $emptyCells($coordS);
            $cells[0] = ['v' => (string)$n,   's' => $numS];
            $cells[1] = ['v' => $sp['name'],  's' => $bodyS];
            $cells[2] = ['v' => $sp['value'], 's' => $coordS];
            $rows[] = ['h' => 22, 'cells' => $cells, 'merge' => 'C:K'];
        }
        $rows[] = ['h' => 10, 'cells' => []];
    }

    if (!empty($walls)) {
        $head = $emptyCells(3);
        $head[0] = ['v' => "  🧱  СТЕНКИ  ·  " . count($walls), 's' => 3];
        $rows[] = ['h' => 28, 'cells' => $head, 'merge' => 'A:K'];

        $hr = $emptyCells(4);
        $hr[0] = ['v' => '#',                   's' => 4];
        $hr[1] = ['v' => 'НАЗВАНИЕ',            's' => 4];
        $hr[2] = ['v' => 'КООРДИНАТЫ (setpos)', 's' => 4];
        $rows[] = ['h' => 26, 'cells' => $hr, 'merge' => 'C:K'];

        $n = 0;
        foreach ($walls as $w) {
            $n++;
            $even = ($n % 2 === 1);
            $numS  = $even ? 7 : 8;
            $bodyS = $even ? 5 : 6;
            $coordS = $even ? 9 : 10;
            $cells = $emptyCells($coordS);
            $cells[0] = ['v' => (string)$n,  's' => $numS];
            $cells[1] = ['v' => $w['name'],  's' => $bodyS];
            $cells[2] = ['v' => $w['value'], 's' => $coordS];
            $rows[] = ['h' => 22, 'cells' => $cells, 'merge' => 'C:K'];
        }
        $rows[] = ['h' => 10, 'cells' => []];
    }

    if (!empty($nades)) {
        $head = $emptyCells(3);
        $head[0] = ['v' => "  💣  ГРАНАТЫ  ·  " . count($nades), 's' => 3];
        $rows[] = ['h' => 28, 'cells' => $head, 'merge' => 'A:K'];

        $hr = $emptyCells(4);
        $hr[0] = ['v' => '#',         's' => 4];
        $hr[1] = ['v' => 'НАЗВАНИЕ',  's' => 4];
        $hr[2] = ['v' => 'ОПИСАНИЕ',  's' => 4];
        $rows[] = ['h' => 26, 'cells' => $hr, 'merge' => 'C:K'];

        $n = 0;
        foreach ($nades as $g) {
            $n++;
            $even = ($n % 2 === 1);
            $numS  = $even ? 7 : 8;
            $bodyS = $even ? 5 : 6;
            $cells = $emptyCells($bodyS);
            $cells[0] = ['v' => (string)$n,  's' => $numS];
            $cells[1] = ['v' => $g['name'],  's' => $bodyS];
            $cells[2] = ['v' => $g['value'], 's' => $bodyS];
            $rows[] = ['h' => 22, 'cells' => $cells, 'merge' => 'C:K'];
        }
    }

    return [
        'cols'   => [5, 26, 55, 3, 5, 26, 55, 3, 5, 26, 40],
        'rows'   => $rows,
        'tab'    => $tabColor,
        'freeze' => 5,
    ];
}

// ====================== СВОДКА ======================
function buildSummarySheet(array $store, string $date): array {
    $cols = [22, 11, 11, 11, 12, 11, 11, 13, 11, 11, 11];
    $N = count($cols);
    $rows = [];
    $emptyCells = function(int $style) use ($N): array {
        $arr = [];
        for ($i = 0; $i < $N; $i++) $arr[$i] = ['v' => '', 's' => $style];
        return $arr;
    };

    $t = $emptyCells(1); $t[0] = ['v' => 'STRATBOOK EXCLUSIVE', 's' => 1];
    $rows[] = ['h' => 56, 'cells' => $t, 'merge' => 'A:K'];

    $s = $emptyCells(2); $s[0] = ['v' => "Сводная таблица    •    Обновлено: $date", 's' => 2];
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

    $total = ['s'=>0,'t'=>0,'ct'=>0,'def'=>0,'ctrl'=>0,'fast'=>0,'split'=>0,'pistol'=>0,'other'=>0,'spawn'=>0,'wall'=>0];
    $n = 0;
    foreach ($store as $sheetName => $data) {
        $items = $data['items'];
        if (empty($items)) continue;
        $n++;
        $even = ($n % 2 === 1);
        $bodyS = $even ? 5 : 6;
        $numS  = $even ? 7 : 8;

        $c = ['s'=>0,'t'=>0,'ct'=>0,'def'=>0,'ctrl'=>0,'fast'=>0,'split'=>0,'pistol'=>0,'other'=>0,'spawn'=>0,'wall'=>0];
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
        $row[0] = ['v' => $sheetName, 's' => $bodyS];
        $row[1] = ['v' => (string)$c['s'],        's' => $numS];
        $row[2] = ['v' => $c['t'].' / '.$c['ct'], 's' => $numS];
        $row[3] = ['v' => $c['def']   ? (string)$c['def']    : '—', 's' => $numS];
        $row[4] = ['v' => $c['ctrl']  ? (string)$c['ctrl']   : '—', 's' => $numS];
        $row[5] = ['v' => $c['fast']  ? (string)$c['fast']   : '—', 's' => $numS];
        $row[6] = ['v' => $c['split'] ? (string)$c['split']  : '—', 's' => $numS];
        $row[7] = ['v' => $c['pistol']? (string)$c['pistol'] : '—', 's' => $numS];
        $row[8] = ['v' => $c['other'] ? (string)$c['other']  : '—', 's' => $numS];
        $row[9] = ['v' => $c['spawn'] ? (string)$c['spawn']  : '—', 's' => $numS];
        $row[10]= ['v' => $c['wall']  ? (string)$c['wall']   : '—', 's' => $numS];
        $rows[] = ['h' => 24, 'cells' => $row];
    }

    $rows[] = ['h' => 8, 'cells' => []];

    $row = $emptyCells(3);
    $row[0] = ['v' => 'ИТОГО', 's' => 3];
    $row[1] = ['v' => (string)$total['s'],            's' => 3];
    $row[2] = ['v' => $total['t'].' / '.$total['ct'], 's' => 3];
    $row[3] = ['v' => (string)$total['def'],          's' => 3];
    $row[4] = ['v' => (string)$total['ctrl'],         's' => 3];
    $row[5] = ['v' => (string)$total['fast'],         's' => 3];
    $row[6] = ['v' => (string)$total['split'],        's' => 3];
    $row[7] = ['v' => (string)$total['pistol'],       's' => 3];
    $row[8] = ['v' => (string)$total['other'],        's' => 3];
    $row[9] = ['v' => (string)$total['spawn'],        's' => 3];
    $row[10]= ['v' => (string)$total['wall'],         's' => 3];
    $rows[] = ['h' => 28, 'cells' => $row];

    $rows[] = ['h' => 8, 'cells' => []];

    $f = $emptyCells(20);
    $f[0] = ['v' => 'Файл сгенерирован автоматически.', 's' => 20];
    $rows[] = ['h' => 24, 'cells' => $f, 'merge' => 'A:K'];

    return ['cols' => $cols, 'rows' => $rows, 'tab' => 'FF1B1F3B', 'freeze' => 5];
}

// ====================== ОСНОВНАЯ ЛОГИКА ======================
echo "=== ЧТЕНИЕ ФАЙЛОВ ===\n\n";
$store = [];
$seenFileHash = [];

foreach ($files as $file) {
    if (!file_exists($file)) { echo "[SKIP] нет файла: $file\n\n"; continue; }
    echo "Читаю: $file\n";
    try { $data = readXlsx($file); }
    catch (Throwable $e) { echo "  [ERR] {$e->getMessage()}\n\n"; continue; }

    $sheetsInfo = [];
    foreach ($data as $sn => $rows) $sheetsInfo[] = $sn.':'.count($rows);
    $fh = sha1(implode('|', $sheetsInfo));
    if (isset($seenFileHash[$fh])) {
        echo "  [DUP FILE] структурно идентичен: {$seenFileHash[$fh]}\n\n";
        continue;
    }
    $seenFileHash[$fh] = $file;

    foreach ($data as $sheetName => $rows) {
        if (!isset($store[$sheetName])) $store[$sheetName] = ['tokens' => [], 'items' => []];
        $added = 0;
        foreach ($rows as $row) {
            if (isEmptyRow($row)) continue;
            $cand = classify($row);
            if (($cand['section'] ?? '') === 'skip') continue;
            $cand['sources'] = [$file];
            $store[$sheetName]['items'][] = $cand;
            $added++;
        }
        printf("  -> %-15s : +%d строк\n", $sheetName, $added);
    }
    echo "\n";
}

// ====================== ЖЁСТКАЯ ДЕДУПЛИКАЦИЯ ======================
echo "=== ЖЁСТКАЯ ДЕДУПЛИКАЦИЯ (exact + Levenshtein + Jaccard 0.85) ===\n";
$dedupStats = hardDedupe($store);

foreach ($dedupStats['per_sheet'] as $sheet => $n) {
    if ($n > 0) printf("  %-15s : удалено %d\n", $sheet, $n);
}
printf("  Всего удалено: %d\n\n", $dedupStats['removed']);

// ====================== СБОРКА ======================
echo "=== СБОРКА СТРУКТУРЫ ===\n\n";
$date = date('d.m.Y H:i');
$outputSheets = [];

$outputSheets['СВОДКА'] = buildSummarySheet($store, $date);

foreach ($store as $sheetName => $data) {
    $items = $data['items'];
    if (empty($items)) continue;

    $strategies = []; $spawns = []; $walls = []; $nades = [];
    foreach ($items as $it) {
        switch ($it['section'] ?? '') {
            case 'strategy': $strategies[] = $it; break;
            case 'spawn':    $spawns[] = $it; break;
            case 'wall':     $walls[] = $it; break;
            case 'nade':     $nades[] = $it; break;
        }
    }

    $tab = $tabColors[$sheetName] ?? 'FF4A6CF7';
    $outputSheets[$sheetName] = buildNiceSheet($sheetName, $strategies, $spawns, $walls, $nades, $tab, $date);
    printf("  %-15s : стратегий %d, спавнов %d, стенок %d, гранат %d\n",
        $sheetName, count($strategies), count($spawns), count($walls), count($nades));
}

$finalSheets = ['СВОДКА' => $outputSheets['СВОДКА']];
unset($outputSheets['СВОДКА']);
uksort($outputSheets, function (string $a, string $b) use ($sheetOrder): int {
    $ia = array_search($a, $sheetOrder);
    $ib = array_search($b, $sheetOrder);
    if ($ia === false && $ib === false) return strcmp($a, $b);
    if ($ia === false) return 1;
    if ($ib === false) return -1;
    return $ia <=> $ib;
});
foreach ($outputSheets as $k => $v) $finalSheets[$k] = $v;

// ====================== ЗАПИСЬ ======================
echo "\n=== ЗАПИСЬ ===\n";
try { writeXlsx($outputFile, $finalSheets); }
catch (Throwable $e) { echo "ОШИБКА: {$e->getMessage()}\n"; exit(1); }

echo "\n✓ Готово: $outputFile\n";
echo "  Листов: " . count($finalSheets) . "\n";