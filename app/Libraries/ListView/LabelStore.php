<?php

namespace App\Libraries\ListView;

use RuntimeException;

/** 헤더 표시명을 JSON 파일에 저장/조회한다. 형식: ['테이블' => ['컬럼' => '표시명']] */
class LabelStore
{
    private $file;

    public function __construct(string $file = '')
    {
        $this->file = $file !== '' ? $file : WRITEPATH . 'listview/labels.json';
    }

    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    public function forTable(string $table): array
    {
        return $this->all()[$table] ?? [];
    }

    /** 테이블 하나의 표시명 전체를 교체 저장한다 (비어 있으면 삭제). 파일 잠금으로 동시 저장을 막는다 */
    public function replace(string $table, array $labels): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('표시명 저장 폴더를 만들 수 없습니다(쓰기 권한 확인): ' . $dir);
        }
        $fh = @fopen($this->file, 'c+');
        if (!$fh) {
            throw new RuntimeException('표시명 파일을 열 수 없습니다(쓰기 권한 확인): ' . $this->file);
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($labels) {
            $data[$table] = $labels;
        } else {
            unset($data[$table]);
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** 요청으로 받은 표시명을 정리한다 (제어문자 제거, 100자 제한, 빈 값/과다 항목 제외) */
    public static function clean(array $labels): array
    {
        $clean = [];
        foreach ($labels as $col => $label) {
            $col = (string)$col;
            $label = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$label));
            $label = mb_substr($label, 0, 100, 'UTF-8');
            if ($label === '' || $col === '' || strlen($col) > 128 || count($clean) >= 200) {
                continue;
            }
            $clean[$col] = $label;
        }
        return $clean;
    }
}
