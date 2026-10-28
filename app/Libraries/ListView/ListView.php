<?php

namespace App\Libraries\ListView;

use CodeIgniter\Database\BaseConnection;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * 검색 + 표 + 페이지 이동 + 엑셀 다운로드를 위한 목록 로직. 옵션은 Config/ListView.php 에서 읽는다.
 *
 * [고정 테이블 모드] tables 가 false 이고 from/select/search/orderBy 를 직접 지정한다.
 * [테이블 선택 모드] tables 가 true(DB의 모든 테이블) 또는 배열(허용 테이블)이면
 *   화면에서 고른 테이블의 구조(SHOW COLUMNS)로 컬럼을 자동 구성한다.
 *   - 테이블 이름은 DB의 실제 테이블 목록에 있는 것만 허용한다 (주소의 table 값을 그대로 쓰지 않음)
 *   - 숨김 컬럼 제외, 문자형 컬럼을 검색 대상, 기본키(PRI)를 정렬 기준으로 사용
 *   - where/select/search/orderBy/labels/excelFile/sheet 옵션은 무시되고 tableOptions 로만 테이블별 지정 가능.
 *     title, perPage, formats 는 모든 테이블에 공통 적용
 *
 * 옵션
 *   title, select(기본 '*'), from, where, search, orderBy, orderDir, perPage, labels, formats,
 *   excelFile, sheet, tables, tableOptions, hideColumns, tableInput, editLabels
 *   select/where 는 개발자가 쓰는 고정 SQL 이다 (사용자 입력 금지).
 *   formats 는 ['컬럼' => fn($value, $row)] 이며 화면과 엑셀에 모두 적용된다.
 */
class ListView
{
    const DEFAULTS = [
        'title' => '목록', 'select' => '*', 'from' => '', 'where' => '', 'search' => [],
        'orderBy' => null, 'orderDir' => 'ASC', 'perPage' => 20,
        'labels' => [], 'formats' => [], 'excelFile' => '목록.xlsx', 'sheet' => 'Sheet1',
        'tables' => false, 'tableOptions' => [], 'hideColumns' => ['password', 'passwd', 'pwd', 'pw'],
        'tableInput' => false, 'editLabels' => false,
    ];

    private $base;
    private $store;
    private $db;

    public function __construct(array $options, LabelStore $store, BaseConnection $db)
    {
        $this->base = $options + self::DEFAULTS;
        $this->store = $store;
        $this->db = $db;
        // 고정 모드는 만드는 시점에 검증해 잘못된 설정이 SQL까지 가지 않게 한다
        if (!$this->base['tables']) {
            self::validate($this->base);
        }
    }

    /**
     * 이번 요청에 적용할 옵션. 테이블 선택 모드면 선택된 테이블($table, 주소의 table 값)의 구조로 자동 구성한다.
     * 결과에는 tableList, currentTable, tableError, aliases(저장된 헤더 표시명)가 추가된다.
     */
    public function resolve($table): array
    {
        $o = $this->resolveOptions(is_string($table) ? trim($table) : '');
        self::validate($o);
        $o['aliases'] = $o['editLabels'] ? $this->store->forTable($o['from']) : [];
        return $o;
    }

    private function resolveOptions(string $req): array
    {
        $b = $this->base;
        $b += ['tableList' => [], 'currentTable' => null, 'tableError' => ''];
        if (!$b['tables']) {
            return $b;
        }
        $tables = $this->allowedTables($b['tables']);
        if (!$tables) {
            throw new RuntimeException('사용할 수 있는 테이블이 없습니다.');
        }
        // 주소의 table 값은 실제 테이블 목록에 있을 때만 사용한다
        $tableError = '';
        if ($req !== '' && !in_array($req, $tables, true)) {
            $tableError = '선택할 수 없는 테이블입니다: ' . $req;
        }
        $table = in_array($req, $tables, true) ? $req : (in_array($b['from'], $tables, true) ? $b['from'] : $tables[0]);

        $hidden = array_map('strtolower', (array)$b['hideColumns']);
        $visible = [];
        $search = [];
        $pk = null;
        foreach ($this->describe($table) as $f) {
            $name = (string)$f['Field'];
            if (in_array(strtolower($name), $hidden, true)) {
                continue;
            }
            $visible[] = $name;
            $plain = preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
            if ($plain && count($search) < 10 && preg_match('/^(var)?char|text$|^enum|^set\(/i', (string)$f['Type'])) {
                $search[] = $name;
            }
            if ($plain && $pk === null && ($f['Key'] ?? '') === 'PRI') {
                $pk = $name;
            }
        }
        if (!$visible) {
            throw new RuntimeException('표시할 수 있는 컬럼이 없습니다: ' . $table);
        }
        $auto = [
            'from' => $table,
            'select' => implode(', ', array_map([self::class, 'quote'], $visible)),
            'search' => $search,
            'orderBy' => $pk,
            'title' => $b['title'] . ' · ' . $table,
            'excelFile' => $table . '.xlsx',
            'sheet' => $table,
            'columnNames' => $visible,
        ];
        $tableOpts = is_array($b['tableOptions'][$table] ?? null) ? $b['tableOptions'][$table] : [];
        $shared = array_diff_key($b, array_flip(['from', 'select', 'where', 'search', 'orderBy', 'orderDir', 'labels', 'excelFile', 'sheet']));
        $o = $tableOpts + $auto + $shared + self::DEFAULTS;
        if (isset($tableOpts['select'])) {
            $o['columnNames'] = []; // select를 직접 지정했으면 자동 컬럼명을 쓰지 않는다
        }
        $o['tableList'] = $tables;
        $o['currentTable'] = $table;
        $o['tableError'] = $tableError;
        return $o;
    }

    /** 선택 가능한 테이블 이름들 (DB의 실제 테이블 중 허용 목록에 있는 것) */
    private function allowedTables($config): array
    {
        $all = [];
        foreach ($this->db->query('SHOW TABLES')->getResultArray() as $row) {
            $name = (string)array_values($row)[0];
            if (preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $all[] = $name;
            }
        }
        return is_array($config) ? array_values(array_intersect($config, $all)) : $all;
    }

    /** @return array 컬럼 정보 목록 (Field, Type, Key ...) */
    private function describe(string $table): array
    {
        return $this->db->query('SHOW COLUMNS FROM ' . self::ident($table))->getResultArray();
    }

    /** 화면에 보여줄 한 페이지: ['rows', 'total', 'page', 'pages'] */
    public function page(array $o, string $keyword, int $requestedPage): array
    {
        [$where, $params] = $this->buildWhere($o, $keyword);
        $from = ' FROM ' . self::ident($o['from']) . $where;
        $perPage = (int)$o['perPage'];

        $total = (int)$this->db->query('SELECT COUNT(*) AS c' . $from, $params)->getRow()->c;
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, $requestedPage));
        $sql = $this->baseSql($o, $from) . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        $rows = $this->db->query($sql, $params)->getResultArray();

        return compact('rows', 'total', 'page', 'pages');
    }

    /** 엑셀용 전체 행 (같은 검색 조건). 한 행씩 공급한다 */
    public function exportRows(array $o, string $keyword): Generator
    {
        [$where, $params] = $this->buildWhere($o, $keyword);
        $sql = $this->baseSql($o, ' FROM ' . self::ident($o['from']) . $where);
        $result = $this->db->query($sql, $params);
        while ($row = $result->getUnbufferedRow('array')) {
            yield $row;
        }
        $result->freeResult();
    }

    private function baseSql(array $o, string $from): string
    {
        $order = $o['orderBy'] !== null
            ? ' ORDER BY ' . self::ident($o['orderBy']) . ' ' . strtoupper($o['orderDir'])
            : '';
        return 'SELECT ' . $o['select'] . $from . $order;
    }

    /** @return array [WHERE 절 문자열, 바인딩 파라미터] */
    private function buildWhere(array $o, string $keyword): array
    {
        $conds = [];
        $params = [];
        if ($o['where'] !== '') {
            $conds[] = '(' . $o['where'] . ')';
        }
        if ($keyword !== '' && $o['search']) {
            // LIKE 와일드카드(% _)가 검색어에 있으면 문자 그대로 검색되도록 이스케이프
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $parts = [];
            foreach ($o['search'] as $col) {
                $parts[] = self::ident($col) . ' LIKE ?';
                $params[] = $like;
            }
            $conds[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [$conds ? ' WHERE ' . implode(' AND ', $conds) : '', $params];
    }

    /** 표에 그릴 컬럼 ['키' => '원래 이름'] */
    public function columns(array $o, array $rows): array
    {
        if ($o['labels']) {
            return $o['labels'];
        }
        if ($rows) {
            $keys = array_keys($rows[0]);
            return array_combine($keys, $keys);
        }
        if (!empty($o['columnNames'])) {
            return array_combine($o['columnNames'], $o['columnNames']); // 빈 테이블도 헤더 표시
        }
        return [];
    }

    /** formats 를 적용한 표시용 값 목록 (행마다 컬럼 순서대로) */
    public function cells(array $o, array $columns, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $value = $row[$key] ?? null;
                if (isset($o['formats'][$key])) {
                    $value = $o['formats'][$key]($value, $row);
                }
                $line[] = $value;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** 현재 페이지 주변만 보여주는 페이지 번호 목록 (사이는 '…') */
    public static function pageList(int $page, int $pages): array
    {
        $set = [1, $pages];
        for ($i = $page - 2; $i <= $page + 2; $i++) {
            if ($i >= 1 && $i <= $pages) {
                $set[] = $i;
            }
        }
        $set = array_values(array_unique($set));
        sort($set);
        $out = [];
        $prev = 0;
        foreach ($set as $n) {
            if ($n - $prev > 1) {
                $out[] = '…';
            }
            $out[] = $n;
            $prev = $n;
        }
        return $out;
    }

    private static function validate(array $o): void
    {
        self::ident((string)$o['from']);
        foreach ($o['search'] as $col) {
            self::ident((string)$col);
        }
        if ($o['orderBy'] !== null) {
            self::ident((string)$o['orderBy']);
        }
        if (!in_array(strtoupper((string)$o['orderDir']), ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('orderDir 는 ASC 또는 DESC 여야 합니다.');
        }
        if ((int)$o['perPage'] < 1) {
            throw new InvalidArgumentException('perPage 는 1 이상이어야 합니다.');
        }
    }

    /** 테이블/컬럼 이름 검증 후 백틱으로 감싼다 (영문, 숫자, _ 와 . 만 허용) */
    private static function ident(string $name): string
    {
        $parts = explode('.', $name);
        foreach ($parts as $p) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $p)) {
                throw new InvalidArgumentException('허용되지 않는 이름입니다: ' . $name);
            }
        }
        return '`' . implode('`.`', $parts) . '`';
    }

    /** DB가 알려준 컬럼명을 SELECT 목록에 안전하게 넣는다 (백틱 이스케이프) */
    private static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
