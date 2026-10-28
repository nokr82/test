<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 목록 화면 + 엑셀 다운로드 설정. 옵션 설명은 app/Libraries/ListView/ListView.php 상단 참고.
 * .env 에서 덮어쓸 수도 있다 (예: listView.perPage = 50)
 */
class ListView extends BaseConfig
{
    /** 접근 제어: /excel 주소에 적용할 필터 이름 (Config/Filters.php 의 aliases 에 등록된 것). 비우면 없음 */
    public string $filter = '';

    /** 헤더 표시명 저장 파일. 비우면 writable/listview/labels.json */
    public string $labelFile = '';

    public string $title = '엑셀';

    /** @var bool|list<string> true면 DB의 모든 테이블, 배열이면 그 안의 테이블만 */
    public $tables = true;

    /** 테이블을 드롭다운 대신 직접 입력 */
    public bool $tableInput = true;

    /** 화면에서 헤더 이름 수정 (DB 컬럼은 바뀌지 않음) */
    public bool $editLabels = true;

    public array $hideColumns = ['password', 'passwd', 'pwd', 'pw'];

    /** 기본으로 보여줄 테이블 */
    public string $from = '_member';

    public int $perPage = 20;

    /**
     * (선택) 특정 테이블만 세부 설정. 예:
     * '_member' => ['select' => 'idx AS 회원번호, nickname AS 닉네임', 'orderBy' => 'idx'],
     */
    public array $tableOptions = [];

    /**
     * (선택) 값 가공 ['컬럼' => fn($value, $row)]. 화면과 엑셀에 모두 적용된다.
     * 속성 기본값에는 함수를 쓸 수 없으므로 생성자에서 넣는다. 예:
     *   public function __construct() { parent::__construct(); $this->formats['regdate'] = fn ($v) => substr($v, 0, 10); }
     */
    public array $formats = [];
}
