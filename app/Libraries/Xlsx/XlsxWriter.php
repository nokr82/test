<?php
namespace App\Libraries\Xlsx;

use DateTimeInterface;
use OverflowException;
use RuntimeException;
use ZipArchive;

/**
 * 순수 PHP .xlsx 작성기 (ZipArchive만 필요).
 * 행을 임시 파일로 스트리밍하므로 큰 결과도 메모리를 적게 쓴다.
 */
class XlsxWriter
{
    const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const MAX_COLS = 16384;

    /** 시트당 최대 행 수 (엑셀 한계) */
    public $maxRows = 1048576;
    /** "123", "1.5" 같은 숫자형 문자열을 숫자 셀로 저장 (앞자리 0, 15자리 초과는 텍스트 유지) */
    public $numericStrings = true;

    private $sheetName;
    private $tmpPath;
    private $tmp;
    private $rowCount = 0;
    private $widths = [];
    private $saved = false;

    public function __construct(string $sheetName = 'Sheet1')
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP zip 확장이 필요합니다. 설치 예: sudo apt install php-zip 후 웹서버 재시작');
        }
        $this->sheetName = self::cleanSheetName($sheetName);
        $this->tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->tmp = fopen($this->tmpPath, 'wb');
    }

    public function __destruct()
    {
        if (is_resource($this->tmp)) {
            fclose($this->tmp);
        }
        @unlink($this->tmpPath);
        @unlink($this->tmpPath . '.sheet');
    }

    /** 한 행을 추가한다. $header가 true면 굵은 글씨, 숫자 변환 없음 */
    public function addRow(array $cells, bool $header = false): void
    {
        if ($this->saved) {
            throw new RuntimeException('이미 저장된 파일입니다.');
        }
        if ($this->rowCount >= $this->maxRows) {
            throw new OverflowException('엑셀 시트 최대 행 수(' . $this->maxRows . ')를 초과했습니다.');
        }
        $r = ++$this->rowCount;
        $style = $header ? ' s="1"' : '';
        $xml = '<row r="' . $r . '">';
        $col = 0;
        foreach ($cells as $value) {
            if ($col >= self::MAX_COLS) {
                throw new OverflowException('엑셀 최대 열 수(' . self::MAX_COLS . ')를 초과했습니다.');
            }
            $ref = self::colName($col) . $r;
            $value = self::normalize($value);
            if (is_int($value) || is_float($value)) {
                $text = is_float($value) ? json_encode($value) : (string)$value;
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $text . '</v></c>';
                $this->trackWidth($col, strlen($text));
            } elseif ($value !== null && $value !== '') {
                if (!$header && $this->numericStrings && self::isSafeNumber($value)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                    $this->trackWidth($col, strlen($value));
                } else {
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                        . self::esc($value) . '</t></is></c>';
                    $this->trackWidth($col, self::textWidth($value));
                }
            }
            $col++;
        }
        fwrite($this->tmp, $xml . '</row>');
    }

    /** .xlsx 파일로 저장한다. 한 번만 호출할 수 있다. */
    public function save(string $path): void
    {
        if ($this->saved) {
            throw new RuntimeException('이미 저장된 파일입니다.');
        }
        $this->saved = true;
        fclose($this->tmp);

        $sheetPath = $this->tmpPath . '.sheet';
        $out = fopen($sheetPath, 'wb');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::NS . '">' . $this->colsXml() . '<sheetData>');
        $in = fopen($this->tmpPath, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fwrite($out, '</sheetData></worksheet>');
        fclose($out);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('xlsx 파일을 만들 수 없습니다: ' . $path);
        }
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $zip->addFromString('[Content_Types].xml', $head
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', $head
            . '<Relationships xmlns="' . $pkg . '">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $head
            . '<workbook xmlns="' . self::NS . '" xmlns:r="' . $rel . '"><sheets>'
            . '<sheet name="' . self::esc($this->sheetName) . '" sheetId="1" r:id="rId1"/>'
            . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head
            . '<Relationships xmlns="' . $pkg . '">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $head
            . '<styleSheet xmlns="' . self::NS . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $ok = $zip->close();
        @unlink($sheetPath);
        if (!$ok) {
            throw new RuntimeException('xlsx 저장에 실패했습니다: ' . $path);
        }
    }

    /** 0부터 시작하는 열 번호를 A, B, ..., AA 형태로 바꾼다 */
    public static function colName(int $i): string
    {
        $name = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    private function colsXml(): string
    {
        if (!$this->widths) {
            return '';
        }
        ksort($this->widths);
        $xml = '<cols>';
        foreach ($this->widths as $c => $w) {
            $n = $c + 1;
            $width = min(60, max(8, $w + 2));
            $xml .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
        }
        return $xml . '</cols>';
    }

    private function trackWidth(int $col, int $len): void
    {
        if (!isset($this->widths[$col]) || $len > $this->widths[$col]) {
            $this->widths[$col] = $len;
        }
    }

    private static function textWidth(string $s): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);
    }

    /** 15자리 이하 10진수 문자열만 안전하게 숫자로 저장한다 (앞자리 0 제외) */
    private static function isSafeNumber($value): bool
    {
        return is_string($value)
            && strlen(ltrim($value, '-')) <= 15
            && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value) === 1;
    }

    /** 셀에 넣을 수 있는 int|float|string|null 로 맞춘다 */
    private static function normalize($value)
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value) && !is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function cleanSheetName(string $name): string
    {
        $name = preg_replace('/[\[\]:*?\/\\\\]/', '', $name);
        $name = trim($name, " '");
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 31, 'UTF-8') : substr($name, 0, 31);
        return $name === '' ? 'Sheet1' : $name;
    }

    /** XML에 넣을 수 있게 이스케이프하고 허용되지 않는 문자는 제거한다 */
    private static function esc(string $s): string
    {
        if (function_exists('mb_scrub')) {
            $s = mb_scrub($s, 'UTF-8');
        }
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
