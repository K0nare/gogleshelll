<?php
declare(strict_types=1);

namespace Stratbook;

/**
 * Стили итогового XLSX (порт buildStylesXml()/badgeStyle() из v7).
 * Индексы cellXfs зафиксированы: на них ссылается сборка листов.
 */
final class Styles
{
    private function __construct() {}

    public static function xml(): string
    {
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

    /** Стиль бейджа типа стратегии (индекс cellXfs). */
    public static function badgeStyle(string $type): int
    {
        return match ($type) {
            'ДЕФОЛТ'     => 11,
            'КОНТРОЛЬ',
            'РЕТЕЙК'     => 12,
            'ФАСТ'       => 13,
            'СПЛИТ'      => 14,
            'ПИСТОЛЕТКА' => 15,
            'ФЕЙК'       => 16,
            'БУСТ'       => 17,
            'ЗАНЯТИЕ'    => 18,
            default      => 19,
        };
    }
}
