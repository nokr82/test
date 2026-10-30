<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 검색엔진(Elasticsearch) 테스트 화면 설정.
 * .env 에서 덮어쓸 수 있다 (예: search.host = http://127.0.0.1:9200)
 */
class Search extends BaseConfig
{
    /** 접근 제어: /search 주소에 적용할 필터 이름 (Config/Filters.php 의 aliases). 비우면 없음 */
    public string $filter = '';

    /** Elasticsearch 주소. PHP 가 컨테이너 안에서 돌기 때문에 localhost 가 아니라 VM 주소를 쓴다 */
    public string $host = 'http://192.168.17.128:9200';

    /** 인덱스 이름 (MySQL 테이블 이름과 같게 둔다) */
    public string $index = 'es_articles';

    /** 요청 제한 시간(초) */
    public int $timeout = 5;

    public int $perPage = 20;
}
