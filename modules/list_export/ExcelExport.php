<?php
namespace XlsxExport;

use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/XlsxWriter.php';
require_once __DIR__ . '/QuerySource.php';

/**
 * SQL 결과를 엑셀(.xlsx)로 저장/다운로드하는 진입점.
 *
 * 사용 예:
 *   ExcelExport::fromQuery($conn, 'SELECT id, name FROM users WHERE name LIKE ?', ['%kim%'])
 *       ->columns(['id' => '번호', 'name' => '이름'])
 *       ->sheet('회원목록')
 *       ->download('회원목록.xlsx');
 */
class ExcelExport
{
    private $rows;
    private $columns = null;
    private $formats = [];
    private $sheet = 'Sheet1';
    private $labelOverrides = [];

    private function __construct($rows)
    {
        $this->rows = $rows;
    }

    /** PDO 또는 mysqli 연결과 SQL로 시작한다. 사용자 입력은 반드시 $params로 넘긴다. */
    public static function fromQuery($conn, string $sql, array $params = []): self
    {
        return new self(QuerySource::rows($conn, $sql, $params));
    }

    /** 연관 배열 행의 iterable로 시작한다 (DB 없이도 사용 가능) */
    public static function fromRows($rows): self
    {
        return new self($rows);
    }

    /** 출력할 컬럼, 순서, 헤더 라벨. ['col' => '라벨'] 또는 ['col1', 'col2'] */
    public function columns(array $map): self
    {
        $normalized = [];
        foreach ($map as $key => $label) {
            if (is_int($key)) {
                $key = $label;
            }
            if (!is_string($key) && !is_int($key)) {
                throw new InvalidArgumentException('columns 키는 문자열이어야 합니다.');
            }
            if (!is_scalar($label)) {
                throw new InvalidArgumentException('columns 헤더 라벨은 문자열이어야 합니다: ' . $key);
            }
            $normalized[$key] = (string)$label;
        }
        $this->columns = $normalized;
        return $this;
    }

    /** 컬럼 값 가공. $fn($value, array $row)가 셀에 넣을 값을 돌려준다 */
    public function format(string $column, callable $fn): self
    {
        $this->formats[$column] = $fn;
        return $this;
    }

    public function sheet(string $name): self
    {
        $this->sheet = $name;
        return $this;
    }

    /** 헤더 표시명만 덮어쓴다 ['컬럼' => '표시명']. 컬럼 선택/순서는 바꾸지 않는다 */
    public function labelOverrides(array $map): self
    {
        $this->labelOverrides = $map;
        return $this;
    }

    /** 파일로 저장한다. 실패하면 예외가 발생하고 대상 파일은 만들어지지 않는다 */
    public function save(string $path): void
    {
        $writer = new XlsxWriter($this->sheet);
        $keys = null;
        if ($this->columns !== null) {
            $keys = array_keys($this->columns);
            $header = [];
            foreach ($this->columns as $key => $label) {
                $header[] = $this->labelOverrides[$key] ?? $label;
            }
            $writer->addRow($header, true);
        }
        foreach ($this->rows as $row) {
            if ($keys === null) {
                $keys = array_keys($row);
                $header = [];
                foreach ($keys as $key) {
                    $header[] = $this->labelOverrides[$key] ?? $key;
                }
                $writer->addRow($header, true);
            }
            $cells = [];
            foreach ($keys as $key) {
                $value = $row[$key] ?? null;
                if (isset($this->formats[$key])) {
                    $value = ($this->formats[$key])($value, $row);
                }
                $cells[] = $value;
            }
            $writer->addRow($cells);
        }
        $writer->save($path);
    }

    /**
     * 브라우저로 내려받게 한다. 파일을 먼저 완성한 뒤 헤더를 보내므로
     * 쿼리 오류가 나도 깨진 파일 대신 정상적인 오류가 발생한다.
     * 호출 후 이어서 다른 출력이 나가지 않도록 호출자가 exit 해야 한다.
     */
    public function download(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        try {
            $this->save($tmp);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        if (!preg_match('/\.xlsx$/i', $filename)) {
            $filename .= '.xlsx';
        }
        $safe = preg_replace('/[\r\n"\\\\\/]+/', '_', $filename);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="export.xlsx"; filename*=UTF-8\'\'' . rawurlencode($safe));
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        unlink($tmp);
    }
}
