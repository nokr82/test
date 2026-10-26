<?php
namespace ListView;

use InvalidArgumentException;
use RuntimeException;
use XlsxExport\ExcelExport;
use XlsxExport\QuerySource;

require_once __DIR__ . '/ExcelExport.php';

/**
 * 검색 + 표 + 페이지 이동 + 엑셀 다운로드 버튼이 있는 목록 화면.
 * 요청 파라미터: q(검색어), page(페이지), download(1이면 엑셀 다운로드), table(테이블 선택 모드일 때)
 *
 * [고정 테이블 모드] 사용 예:
 *   ListView::make($conn, [
 *       'title'   => '회원 목록',
 *       'select'  => 'idx AS 회원번호, nickname AS 닉네임',
 *       'from'    => '_member',
 *       'search'  => ['nickname', 'name'],
 *       'orderBy' => 'idx',
 *   ])->run();
 *
 * [테이블 선택 모드] 사용 예: 화면에서 테이블을 고르면 컬럼이 그 테이블에 맞게 자동으로 바뀐다
 *   ListView::make($conn, [
 *       'title'       => '데이터 보기',
 *       'tables'      => ['_member', '_order'],   // 선택 가능한 테이블 (true면 DB의 모든 테이블)
 *       'hideColumns' => ['password', 'pw'],      // 숨길 컬럼명 (모든 테이블에 적용)
 *       'tableOptions' => [                       // (선택) 특정 테이블만 세부 설정
 *           '_member' => ['select' => 'idx AS 번호, nickname AS 닉네임', 'orderBy' => 'idx'],
 *       ],
 *   ])->run();
 *   - 테이블 이름은 DB의 실제 테이블 목록에 있는 것만 허용한다 (주소의 table 값을 그대로 쓰지 않음)
 *   - 컬럼은 테이블 구조(SHOW COLUMNS)에서 읽어 자동 구성: 숨김 컬럼 제외, 문자형 컬럼을 검색 대상,
 *     기본키(PRI)를 정렬 기준으로 사용. 빈 테이블도 헤더가 표시된다
 *   - 테이블 선택 모드에서는 where/select/search/orderBy/labels/excelFile/sheet 옵션은 무시되고
 *     tableOptions 로만 테이블별 지정이 가능하다. title, perPage, formats 는 모든 테이블에 공통 적용
 *
 * 옵션
 *   title      화면 제목
 *   select     SELECT 뒤에 올 컬럼 목록 (개발자가 쓰는 고정 SQL. 사용자 입력 금지). 기본 '*'
 *   from       테이블 이름 (고정 모드는 필수. 영문/숫자/_ 와 db.table 형태만 허용)
 *   where      항상 적용할 고정 조건 SQL (선택, 예: 'status = 1'. 사용자 입력 금지)
 *   search     검색어를 LIKE로 찾을 컬럼 목록 (비우면 검색창 숨김)
 *   orderBy    정렬 컬럼, orderDir ASC|DESC
 *   perPage    페이지당 행 수 (기본 20)
 *   labels     ['컬럼' => '헤더'] 지정하면 이 컬럼만 이 순서로 표시 (생략하면 쿼리 결과의 모든 컬럼)
 *   formats    ['컬럼' => fn($value, $row)] 값 가공 (화면과 엑셀 모두 적용)
 *   excelFile, sheet   엑셀 파일명, 시트 이름
 *   tables, tableOptions, hideColumns   테이블 선택 모드 (위 설명 참고)
 *   tableInput   true면 테이블을 드롭다운 대신 직접 입력 (tables 가 true면 DB의 실제 테이블 어느 것이든 입력 가능,
 *                배열이면 그 안의 테이블만 가능. 없는 이름이면 안내 문구를 보여주고 기본 테이블 유지)
 *   editLabels   true면 화면에서 헤더 이름을 수정하고 서버(labelFile, JSON)에 저장. 화면과 엑셀에 모두 반영.
 *                DB 컬럼은 바뀌지 않는다. 저장 폴더(기본 list_export/data/)에 웹서버 쓰기 권한이 필요
 *   labelFile    표시명 저장 파일 경로 (기본 list_export/data/labels.json. 웹에서 직접 열리지 않는 곳을 권장)
 *   authorize    function(): bool 접근 제어. false를 돌려주면 403으로 화면, 다운로드, 편집을 모두 막는다
 */
class ListView
{
    const DEFAULTS = [
        'title' => '목록', 'select' => '*', 'from' => '', 'where' => '', 'search' => [],
        'orderBy' => null, 'orderDir' => 'ASC', 'perPage' => 20,
        'labels' => [], 'formats' => [], 'excelFile' => '목록.xlsx', 'sheet' => 'Sheet1',
        'tables' => false, 'tableOptions' => [], 'hideColumns' => ['password', 'passwd', 'pwd', 'pw'],
        'tableInput' => false, 'editLabels' => false, 'labelFile' => null, 'authorize' => null,
    ];

    private $conn;
    private $base;
    private $o;
    private $tableList = [];
    private $currentTable = null;
    private $tableError = '';
    private $aliases = [];

    public static function make($conn, array $options): self
    {
        return new self($conn, $options);
    }

    private function __construct($conn, array $options)
    {
        $this->conn = $conn;
        $this->base = $options + self::DEFAULTS;
        $this->o = $this->base;
        // 고정 모드는 만드는 시점에 검증해 잘못된 설정이 SQL까지 가지 않게 한다
        if (!$this->base['tables']) {
            self::validate($this->base);
        }
    }

    /** 요청을 처리한다. 다운로드면 엑셀을 내려주고 종료, 아니면 화면을 출력한다 */
    public function run(): void
    {
        // 접근 제어 훅: false를 돌려주면 화면, 다운로드, 편집 모두 막는다
        if (is_callable($this->base['authorize']) && !call_user_func($this->base['authorize'])) {
            http_response_code(403);
            exit('접근 권한이 없습니다.');
        }
        $this->o = $this->resolveOptions();
        self::validate($this->o);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&($_GET['action'] ?? '') === 'saveLabels') {
            $this->handleSaveLabels();
            exit;
        }
        $this->aliases = $this->o['editLabels'] ? ($this->loadLabels()[$this->o['from']] ?? []) : [];

        $keyword = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        [$where, $params] = $this->buildWhere($keyword);
        $order = $this->o['orderBy'] !== null
            ? ' ORDER BY ' . self::ident($this->o['orderBy']) . ' ' . strtoupper($this->o['orderDir'])
            : '';
        $from = ' FROM ' . self::ident($this->o['from']) . $where;
        $baseSql = 'SELECT ' . $this->o['select'] . $from . $order;

        if (isset($_GET['download'])) {
            $this->download($baseSql, $params);
            exit;
        }

        $perPage = (int)$this->o['perPage'];
        $total = (int)iterator_to_array(QuerySource::rows($this->conn, 'SELECT COUNT(*) AS c' . $from, $params), false)[0]['c'];
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $rows = iterator_to_array(
            QuerySource::rows($this->conn, $baseSql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params),
            false
        );
        $this->render($keyword, $total, $page, $pages, $rows);
    }

    /** 이번 요청에 적용할 옵션. 테이블 선택 모드면 선택된 테이블의 구조로 자동 구성한다 */
    private function resolveOptions(): array
    {
        $b = $this->base;
        if (!$b['tables']) {
            return $b;
        }
        $tables = $this->allowedTables($b['tables']);
        if (!$tables) {
            throw new RuntimeException('사용할 수 있는 테이블이 없습니다.');
        }
        // 주소의 table 값은 실제 테이블 목록에 있을 때만 사용한다
        $req = isset($_GET['table']) && is_string($_GET['table']) ? trim($_GET['table']) : '';
        if ($req !== '' && !in_array($req, $tables, true)) {
            $this->tableError = '선택할 수 없는 테이블입니다: ' . $req;
        }
        $table = in_array($req, $tables, true) ? $req : (in_array($b['from'], $tables, true) ? $b['from'] : $tables[0]);
        $this->tableList = $tables;
        $this->currentTable = $table;

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
        return $o;
    }

    /** 선택 가능한 테이블 이름들 (DB의 실제 테이블 중 허용 목록에 있는 것) */
    private function allowedTables($config): array
    {
        $all = [];
        foreach (QuerySource::rows($this->conn, 'SHOW TABLES') as $row) {
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
        return iterator_to_array(QuerySource::rows($this->conn, 'SHOW COLUMNS FROM ' . self::ident($table)), false);
    }

    private function labelFile(): string
    {
        return $this->o['labelFile'] ?? (__DIR__ . '/data/labels.json');
    }

    /** @return array ['테이블' => ['컬럼' => '표시명']] */
    private function loadLabels(): array
    {
        $file = $this->labelFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** 테이블 하나의 표시명 전체를 교체 저장한다 (비어 있으면 삭제). 파일 잠금으로 동시 저장을 막는다 */
    private function storeLabels(string $key, array $labels): void
    {
        $file = $this->labelFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('표시명 저장 폴더를 만들 수 없습니다(쓰기 권한 확인): ' . $dir);
        }
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            throw new RuntimeException('표시명 파일을 열 수 없습니다(쓰기 권한 확인): ' . $file);
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($labels) {
            $data[$key] = $labels;
        } else {
            unset($data[$key]);
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** POST ?action=saveLabels : 화면에서 수정한 헤더 표시명을 서버에 저장하고 JSON으로 응답한다 */
    private function handleSaveLabels(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!$this->o['editLabels']) {
                throw new InvalidArgumentException('헤더 이름 편집이 꺼져 있습니다.');
            }
            // 다른 사이트의 폼/이미지 요청으로는 보낼 수 없는 헤더 + 출처 확인 (CSRF 방지)
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'ListView') {
                throw new InvalidArgumentException('잘못된 요청입니다.');
            }
            $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
            $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== $host) {
                throw new InvalidArgumentException('허용되지 않는 출처입니다.');
            }
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body) || !isset($body['labels']) || !is_array($body['labels'])) {
                throw new InvalidArgumentException('잘못된 요청 내용입니다.');
            }
            $clean = [];
            foreach ($body['labels'] as $col => $label) {
                $col = (string)$col;
                $label = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$label));
                $label = function_exists('mb_substr') ? mb_substr($label, 0, 100, 'UTF-8') : substr($label, 0, 100);
                if ($label === '' || $col === '' || strlen($col) > 128 || count($clean) >= 200) {
                    continue;
                }
                $clean[$col] = $label;
            }
            // 저장 위치는 요청 본문이 아니라 서버가 확정한 테이블(from)로만 정한다
            $this->storeLabels((string)$this->o['from'], $clean);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function download(string $sql, array $params): void
    {
        $export = ExcelExport::fromQuery($this->conn, $sql, $params)->sheet($this->o['sheet']);
        if ($this->o['labels']) {
            $export->columns($this->o['labels']);
        }
        if ($this->aliases) {
            $export->labelOverrides($this->aliases);
        }
        foreach ($this->o['formats'] as $column => $fn) {
            $export->format($column, $fn);
        }
        $export->download($this->o['excelFile']);
    }

    /** @return array [WHERE 절 문자열, 바인딩 파라미터] */
    private function buildWhere(string $keyword): array
    {
        $conds = [];
        $params = [];
        if ($this->o['where'] !== '') {
            $conds[] = '(' . $this->o['where'] . ')';
        }
        if ($keyword !== '' && $this->o['search']) {
            // LIKE 와일드카드(% _)가 검색어에 있으면 문자 그대로 검색되도록 이스케이프
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $parts = [];
            foreach ($this->o['search'] as $col) {
                $parts[] = self::ident($col) . ' LIKE ?';
                $params[] = $like;
            }
            $conds[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [$conds ? ' WHERE ' . implode(' AND ', $conds) : '', $params];
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

    private static function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    private static function url(array $query): string
    {
        $query = array_filter($query, function ($v) {
            return $v !== '' && $v !== null;
        });
        return '?' . http_build_query($query);
    }

    /** 현재 페이지 주변만 보여주는 페이지 번호 목록 (사이는 '…') */
    private static function pageList(int $page, int $pages): array
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

    private function render(string $keyword, int $total, int $page, int $pages, array $rows): void
    {
        $labels = $this->o['labels'];
        if ($labels) {
            $columns = $labels;
        } elseif ($rows) {
            $columns = array_combine(array_keys($rows[0]), array_keys($rows[0]));
        } elseif (!empty($this->o['columnNames'])) {
            $columns = array_combine($this->o['columnNames'], $this->o['columnNames']); // 빈 테이블도 헤더 표시
        } else {
            $columns = [];
        }
        $formats = $this->o['formats'];
        $tbl = $this->currentTable !== null ? ['table' => $this->currentTable] : [];
        $downloadUrl = self::url($tbl + ['q' => $keyword, 'download' => 1]);
        $saveUrl = self::url($tbl + ['action' => 'saveLabels']);
        $canEdit = (bool)$this->o['editLabels'] && $columns;
        ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= self::h($this->o['title']) ?></title>
<style>
    body { font-family: system-ui, "Malgun Gothic", sans-serif; margin: 0; background: #f5f6f8; color: #222; }
    .wrap { max-width: 960px; margin: 32px auto; padding: 0 16px; }
    h1 { font-size: 20px; margin: 0 0 16px; }
    .bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .bar form { display: flex; gap: 8px; }
    .bar form.search { flex: 1; min-width: 240px; }
    input[type=text], select { padding: 8px 10px; border: 1px solid #c9ccd1; border-radius: 6px; font-size: 14px; background: #fff; }
    input[type=text] { flex: 1; }
    button, .btn { padding: 8px 14px; border: 0; border-radius: 6px; background: #2f6fed; color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; }
    .btn.excel { background: #1d8348; margin-left: auto; }
    .info { font-size: 13px; color: #555; margin-bottom: 8px; }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eceef1; font-size: 14px; white-space: nowrap; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
    th { background: #eef0f4; }
    .empty { text-align: center; color: #888; padding: 32px; }
    .pager { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; margin-top: 16px; }
    .pager a, .pager span { padding: 6px 11px; border-radius: 6px; background: #fff; border: 1px solid #d5d8dd; color: #333; text-decoration: none; font-size: 13px; }
    .pager .cur { background: #2f6fed; border-color: #2f6fed; color: #fff; }
    .pager .gap { border: 0; background: transparent; }
    .err { background: #fdecea; color: #b3261e; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
    .btn.gray { background: #6b7280; }
    th input { width: 100%; min-width: 90px; box-sizing: border-box; padding: 4px 6px; border: 1px solid #2f6fed; border-radius: 4px; font: inherit; }
    #lv-msg { font-size: 13px; color: #555; }
</style>
</head>
<body>
<div class="wrap">
    <h1><?= self::h($this->o['title']) ?></h1>
    <div class="bar">
        <?php if ($this->tableList && $this->o['tableInput']): ?>
        <form method="get">
            <input type="text" name="table" list="lv-tables" value="<?= self::h($this->currentTable) ?>" placeholder="테이블명 입력" autocomplete="off">
            <datalist id="lv-tables">
                <?php foreach ($this->tableList as $t): ?><option value="<?= self::h($t) ?>"><?php endforeach; ?>
            </datalist>
            <button type="submit">이동</button>
        </form>
        <?php elseif ($this->tableList): ?>
        <form method="get">
            <select name="table" onchange="this.form.submit()">
                <?php foreach ($this->tableList as $t): ?>
                    <option value="<?= self::h($t) ?>"<?= $t === $this->currentTable ? ' selected' : '' ?>><?= self::h($t) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit">이동</button></noscript>
        </form>
        <?php endif; ?>
        <?php if ($this->o['search']): ?>
        <form method="get" class="search">
            <?php if ($this->currentTable !== null): ?>
                <input type="hidden" name="table" value="<?= self::h($this->currentTable) ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?= self::h($keyword) ?>" placeholder="검색">
            <button type="submit">검색</button>
        </form>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <button type="button" class="btn gray" id="lv-edit">헤더 이름 편집</button>
            <span id="lv-editbar" hidden>
                <button type="button" id="lv-save">저장</button>
                <button type="button" class="btn gray" id="lv-cancel">취소</button>
                <span id="lv-msg"></span>
            </span>
        <?php endif; ?>
        <a class="btn excel" href="<?= self::h($downloadUrl) ?>">엑셀 다운로드</a>
    </div>
    <?php if ($this->tableError): ?>
        <div class="err"><?= self::h($this->tableError) ?></div>
    <?php endif; ?>
    <div class="info">
        검색 결과 <?= number_format($total) ?>건 · <?= $page ?> / <?= $pages ?> 페이지 (엑셀에는 전체 <?= number_format($total) ?>건 포함)
    </div>
    <div class="scroll">
    <table>
        <thead>
            <tr>
            <?php foreach ($columns as $key => $label): ?>
                <th data-col="<?= self::h($key) ?>" data-orig="<?= self::h($label) ?>" data-label="<?= self::h($this->aliases[$key] ?? $label) ?>"><?= self::h($this->aliases[$key] ?? $label) ?></th>
            <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td class="empty" colspan="<?= max(1, count($columns)) ?>">검색 결과가 없습니다.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php foreach (array_keys($columns) as $key): ?>
                    <?php
                    $value = $row[$key] ?? null;
                    if (isset($formats[$key])) {
                        $value = $formats[$key]($value, $row);
                    }
                    ?>
                    <td><?= self::h($value) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?><a href="<?= self::h(self::url($tbl + ['q' => $keyword, 'page' => $page - 1])) ?>">이전</a><?php endif; ?>
        <?php foreach (self::pageList($page, $pages) as $n): ?>
            <?php if ($n === '…'): ?>
                <span class="gap">…</span>
            <?php elseif ($n === $page): ?>
                <span class="cur"><?= $n ?></span>
            <?php else: ?>
                <a href="<?= self::h(self::url($tbl + ['q' => $keyword, 'page' => $n])) ?>"><?= $n ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a href="<?= self::h(self::url($tbl + ['q' => $keyword, 'page' => $page + 1])) ?>">다음</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php if ($canEdit): ?>
<script>
(function () {
    var saveUrl = <?= json_encode($saveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var editBtn = document.getElementById('lv-edit');
    var bar = document.getElementById('lv-editbar');
    var msg = document.getElementById('lv-msg');
    var ths = [].slice.call(document.querySelectorAll('th[data-col]'));

    editBtn.addEventListener('click', function () {
        ths.forEach(function (th) {
            var input = document.createElement('input');
            input.type = 'text';
            input.value = th.getAttribute('data-label');
            input.maxLength = 100;
            th.textContent = '';
            th.appendChild(input);
        });
        editBtn.hidden = true;
        bar.hidden = false;
    });
    document.getElementById('lv-cancel').addEventListener('click', function () {
        location.reload();
    });
    document.getElementById('lv-save').addEventListener('click', function () {
        var labels = {};
        ths.forEach(function (th) {
            var v = th.querySelector('input').value.trim();
            // 원래 이름과 같거나 비우면 저장하지 않는다 (= 원래 이름으로 복원)
            labels[th.getAttribute('data-col')] = (v === th.getAttribute('data-orig')) ? '' : v;
        });
        msg.textContent = '저장 중...';
        fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'ListView'},
            body: JSON.stringify({labels: labels})
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.ok) { location.reload(); } else { msg.textContent = '저장 실패: ' + (res.error || ''); }
        }).catch(function () { msg.textContent = '저장 실패: 서버에 연결할 수 없습니다.'; });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
        <?php
    }
}
