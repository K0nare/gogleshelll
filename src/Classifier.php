<?php
declare(strict_types=1);

namespace Stratbook;

/**
 * Классификация строк тактической книги: секции (strategy/spawn/wall/nade),
 * тип раунда, сторона (T/CT) и сайт (A/B/MID). Порт classify()/classifyRaw()
 * из v7 с сохранением кэша классификации.
 */
final class Classifier
{
    /** @var array<string,array> Кэш: sha1(строки) → результат classifyRaw */
    private array $cache = [];

    /**
     * Классифицирует строку. Кэш нужен потому, что одни и те же строки
     * массово повторяются между файлами-копиями, а raw-классификация —
     * это десятки regex на каждую строку.
     *
     * Возвращается СВЕЖАЯ КОПИЯ результата (со своим массивом sources),
     * поэтому мутации элементов в дедупликации не влияют на кэш и на
     * другие экземпляры той же строки — поведение идентично отсутствию кэша.
     *
     * @param array<int,string> $row
     */
    public function classify(array $row): array
    {
        $ck = sha1(implode("\x1f", $row));
        if (!isset($this->cache[$ck])) $this->cache[$ck] = self::classifyRaw($row);
        $res = $this->cache[$ck];
        if (($res['section'] ?? '') !== 'skip') $res['sources'] = [];
        return $res;
    }

    /** @param array<int,string> $row */
    public static function classifyRaw(array $row): array
    {
        $ne = [];
        foreach ($row as $v) {
            $v = trim((string)$v);
            if ($v !== '') $ne[] = $v;
        }
        if (empty($ne)) return ['section' => 'skip'];
        $first  = $ne[0];
        $joined = implode(' ', $ne);

        if (preg_match('/^\s*SPAWN\s*\d*/iu', $first) || preg_match('/setpos\s+[-0-9]/i', $joined)) {
            if (stripos($first, 'СТЕНКА') !== false || stripos($first, 'WALL') !== false) {
                return ['section' => 'wall', 'name' => $first, 'value' => self::findCoord($ne)];
            }
            return ['section' => 'spawn', 'name' => $first ?: 'SPAWN', 'value' => self::findCoord($ne)];
        }
        if (stripos($first, 'СТЕНКА') !== false) {
            return ['section' => 'wall', 'name' => $first, 'value' => self::findCoord($ne)];
        }
        if (preg_match('/^(смок|молик|моли|флеш|флешка|хае|граната|nade)/iu', $first)) {
            return ['section' => 'nade', 'name' => $first, 'value' => implode(' • ', array_slice($ne, 1))];
        }

        $u    = mb_strtoupper($joined, 'UTF-8');
        $type = 'ПРОЧЕЕ';
        if (preg_match('/ПИСТОЛЕТКА|ПИСТИКИ|PISTOL|ЭКО|ECO|АНТИЭКО/iu', $u))                $type = 'ПИСТОЛЕТКА';
        elseif (preg_match('/ФАСТ|FAST|РАШ|RUSH|ПУШ|PUSH|БЫСТР|БЫСТАР|2 ТЕМП|ТЕМП/iu', $u)) $type = 'ФАСТ';
        elseif (preg_match('/СПЛИТ|SPLIT/iu', $u))                                           $type = 'СПЛИТ';
        elseif (preg_match('/РЕТЕЙК|RETAKE/iu', $u))                                         $type = 'РЕТЕЙК';
        elseif (preg_match('/КОНТРОЛЬ|CONTROL/iu', $u))                                      $type = 'КОНТРОЛЬ';
        elseif (preg_match('/ДЕФОЛТ|DEFAULT/iu', $u))                                        $type = 'ДЕФОЛТ';
        elseif (preg_match('/ФЕЙК|FAKE/iu', $u))                                             $type = 'ФЕЙК';
        elseif (preg_match('/ЗАНЯТИЕ|ЗАХВАТ|TAKE/iu', $u))                                   $type = 'ЗАНЯТИЕ';
        elseif (preg_match('/КОНТАКТ/iu', $u))                                               $type = 'КОНТАКТ';
        elseif (preg_match('/БУСТ|BOOST/iu', $u))                                            $type = 'БУСТ';
        elseif (preg_match('/ОКНО|WINDOW|БАЛКОН/iu', $u))                                    $type = 'ОКНО';
        elseif (preg_match('/УЛИЦА|СТРИТ|STREET/iu', $u))                                    $type = 'УЛИЦА';
        elseif (preg_match('/МИД|MID/iu', $u))                                               $type = 'МИД';
        elseif (preg_match('/РАМП|RAMP/iu', $u))                                             $type = 'РАМП';

        return [
            'section' => 'strategy',
            'type'    => $type,
            'side'    => self::detectSide($joined),
            'site'    => self::detectSite($joined),
            'name'    => $first,
            'desc'    => implode(' • ', array_slice($ne, 1)),
        ];
    }

    /** Определяет сторону: CT / T / ANY. */
    public static function detectSide(string $text): string
    {
        $u = ' ' . mb_strtoupper($text, 'UTF-8') . ' ';
        $u = preg_replace('/\s+/u', ' ', $u);
        if (preg_match('/\b(РЕТЕЙК|RETAKE)\b/u', $u)) return 'CT';
        if (preg_match('/(?:^|\s)(?:ОТ\s*КТ|ЗА\s*КТ|КТ\s*СТАРТ|CT\s*SIDE|CT\s*START|ОБОРОН)/u', $u)) return 'CT';
        if (preg_match('/(?:^|\s)КТ(?:\s|$)/u', $u)) return 'CT';
        if (preg_match('/(?:^|\s)(?:ОТ\s*Т|ЗА\s*Т|Т\s*СТАРТ|T\s*SIDE|T\s*START|АТАК)/u', $u)) return 'T';
        if (preg_match('/(?:^|\s)Т(?:\s|$)/u', $u) && !preg_match('/КТ/u', $u)) return 'T';
        return 'ANY';
    }

    /** Определяет сайт: A / B / MID / ALL. */
    public static function detectSite(string $text): string
    {
        $u = ' ' . mb_strtoupper($text, 'UTF-8') . ' ';
        $u = preg_replace('/\s+/u', ' ', $u);
        $hasA   = (bool)preg_match('/(?:^|[\s\/\|,\(\)\-])А(?:$|[\s\/\|,\(\)\-])/u', $u);
        $hasB   = (bool)preg_match('/(?:^|[\s\/\|,\(\)\-])(?:Б|B)(?:$|[\s\/\|,\(\)\-])/u', $u);
        $hasMid = (bool)preg_match('/(?:^|\s)(?:МИД|MID|ЦЕНТР)(?:\s|$)/u', $u);
        if ($hasA && $hasB)  return 'ALL';
        if ($hasA)           return 'A';
        if ($hasB)           return 'B';
        if ($hasMid)         return 'MID';
        return 'ALL';
    }

    /** Координата setpos из непустых ячеек строки (или остаток строки). */
    public static function findCoord(array $ne): string
    {
        foreach ($ne as $v) {
            if (preg_match('/setpos\s+[-0-9]/i', $v)) return $v;
        }
        return implode(' ', array_slice($ne, 1));
    }
}
