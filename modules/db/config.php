<?php
// DB 접속 설정. 접속 정보는 이 파일 한 곳에서만 관리한다.
// 운영 서버에서는 웹 루트 밖에 두거나 웹에서 직접 접근하지 못하게 막을 것.
// driver: 'mysqli' 또는 'pdo'
return [
    'driver'  => 'mysqli',
    'host'    => '192.168.17.128',
    'port'    => 3306,
    'user'    => 'ggulfile',
    'pass'    => 'test',
    'name'    => 'ssfile',
    'charset' => 'utf8mb4',
];
