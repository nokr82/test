<?php
namespace App\Libraries\Xlsx;

use CodeIgniter\HTTP\DownloadResponse;
use Config\Services;
use InvalidArgumentException;
use Throwable;

/**
 * 행(연관 배열)의 iterable을 엑셀(.xlsx)로 저장/다운로드하는 진입점.
 *
 * 사용 예:
 *   ExcelExport::fromRows($rows)
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

    /** 연관 배열 행의 iterable로 시작한다 (Generator 가능) */
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
     * 브라우저로 내려받는 응답을 만든다. 파일을 먼저 완성한 뒤 응답을 돌려주므로
     * 쿼리 오류가 나도 깨진 파일 대신 정상적인 오류가 발생한다. 전송 후 임시 파일은 삭제된다.
     */
    public function download(string $filename): DownloadResponse
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

        // 응답 전송이 끝난 뒤(스크립트 종료 시) 임시 파일을 지운다
        register_shutdown_function(static function () use ($tmp) {
            @unlink($tmp);
        });

        return Services::response()->download($tmp, null)
            ->setFileName($safe)
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
