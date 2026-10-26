<?php
// 테이블 선택/입력 모드 예시.
// - 위쪽 입력창에 테이블명을 쓰면(자동완성 제공) 그 테이블의 컬럼에 맞게 목록, 검색, 엑셀이 자동으로 바뀐다.
// - [헤더 이름 편집] 버튼으로 컬럼 표시명을 바꾸면 서버에 저장되어 화면과 엑셀에 모두 반영된다.
require_once __DIR__ . '/../db/Db.php';
require_once __DIR__ . '/ListView.php';

use DbConn\Db;
use ListView\ListView;

try {
    $conn = Db::connect(); // 접속 정보는 db/config.php 에서 관리
} catch (RuntimeException $e) {
    http_response_code(500);
    exit(htmlspecialchars($e->getMessage()));
}

ListView::make($conn, [
    'title'        => '데이터 보기',
    // true: DB의 실제 테이블 어느 것이든 입력 가능 / ['_member','_order'] 처럼 배열이면 그 안에서만
    'tables'       => true,
    'tableInput'   => true,
    // 모든 테이블에서 숨길 컬럼명 (대소문자 무시). 비밀번호 등 민감 컬럼을 넣을 것
    'hideColumns'  => ['password', 'passwd', 'pwd', 'pw'],
    'perPage'      => 20,
    // 화면에서 헤더 이름 편집 허용. 저장 파일은 기본적으로 list_export/data/labels.json
    'editLabels'   => true,
    // 접근 제어: 모든 테이블이 열리므로 실제 서비스에서는 반드시 로그인 확인 등을 넣을 것
    // 'authorize' => function () { return !empty($_SESSION['is_admin']); },
    // (선택) 특정 테이블만 세부 지정. 없는 테이블은 구조를 읽어 자동 구성
    'tableOptions' => [
        '_member' => [
            'select'  => 'idx AS 회원번호, nickname AS 닉네임, name AS 이름',
            'search'  => ['nickname', 'name'],
            'orderBy' => 'idx',
        ],
    ],
])->run();
