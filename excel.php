<?php
require_once __DIR__ . '/modules/db/Db.php';
require_once __DIR__ . '/modules/list_export/ListView.php';

use DbConn\Db;
use ListView\ListView;

ListView::make(Db::connect(), [
    'title'     => '엑셀',
    'tables' => true,
    'tableInput' => true,
    'editLabels' => true,
    'select'    => 'idx AS 회원번호, nickname AS 닉네임, name AS 이름',
    'from'      => '_member',
    'search'    => ['nickname', 'name'],   // 검색어를 찾을 컬럼 (여러 개면 OR)
    'orderBy'   => 'idx',
    'perPage'   => 20,
    'excelFile' => '회원목록.xlsx',
])->run();
