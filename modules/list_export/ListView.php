<?php
namespace ListView;

use InvalidArgumentException;
use XlsxExport\ExcelExport;
use XlsxExport\QuerySource;

require_once __DIR__ . '/ExcelExport.php';

/**
 * 검색 + 표 + 페이지 이동 + 엑셀 다운로드 버튼이 있는 목록 화면.
 * 요청 파라미터: q(검색어), page(페이지), download(1이면 엑셀 다운로드)
 *
 * 사용 예:
 *   ListView::make($conn, [
 *       'title'   => '회원 목록',
 *       'select'  => 'idx AS 회원번호, nickname AS 닉네임',
 *       'from'    => '_member',
 *       'search'  => ['nickname', 'name'],
 *       'orderBy' => 'idx',
 *   ])->run();
 *
 * 옵션
 *   title      화면 제목
 *   select     SELECT 뒤에 올 컬럼 목록 (개발자가 쓰는 고정 SQL. 사용자 입력 금지). 기본 '*'
 *   from       테이블 이름 (필수. 영문/숫자/_ 와 db.table 형태만 허용)
 *   where      항상 적용할 고정 조건 SQL (선택, 예: 'status = 1'. 사용자 입력 금지)
 *   search     검색어를 LIKE로 찾을 컬럼 목록 (비우면 검색창 숨김)
 *   orderBy    정렬 컬럼, orderDir ASC|DESC
 *   perPage    페이지당 행 수 (기본 20)
 *   labels     ['컬럼' => '헤더'] 지정하면 이 컬럼만 이 순서로 표시 (생략하면 쿼리 결과의 모든 컬럼)
 *   formats    ['컬럼' => fn($value, $row)] 값 가공 (화면과 엑셀 모두 적용)
 *   excelFile, sheet   엑셀 파일명, 시트 이름
 */
class ListView
{
    private $conn;
    private $o;

    public static function make($conn, array $options): self
    {
        return new self($conn, $options);
    }

    private function __construct($conn, array $options)
    {
        $this->conn = $conn;
        $this->o = $options + [
            'title' => '목록', 'select' => '*', 'from' => '', 'where' => '', 'search' => [],
            'orderBy' => null, 'orderDir' => 'ASC', 'perPage' => 20,
            'labels' => [], 'formats' => [], 'excelFile' => '목록.xlsx', 'sheet' => 'Sheet1',
        ];
        // 식별자는 만드는 시점에 검증해 잘못된 설정이 SQL까지 가지 않게 한다
        self::ident((string)$this->o['from']);
        foreach ($this->o['search'] as $col) {
            self::ident((string)$col);
        }
        if ($this->o['orderBy'] !== null) {
            self::ident((string)$this->o['orderBy']);
        }
        if (!in_array(strtoupper((string)$this->o['orderDir']), ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('orderDir 는 ASC 또는 DESC 여야 합니다.');
        }
        if ((int)$this->o['perPage'] < 1) {
            throw new InvalidArgumentException('perPage 는 1 이상이어야 합니다.');
        }
    }

    /** 요청을 처리한다. 다운로드면 엑셀을 내려주고 종료, 아니면 화면을 출력한다 */
    public function run(): void
    {
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

    private function download(string $sql, array $params): void
    {
        $export = ExcelExport::fromQuery($this->conn, $sql, $params)->sheet($this->o['sheet']);
        if ($this->o['labels']) {
            $export->columns($this->o['labels']);
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
        $columns = $labels ?: ($rows ? array_combine(array_keys($rows[0]), array_keys($rows[0])) : []);
        $formats = $this->o['formats'];
        $downloadUrl = self::url(['q' => $keyword, 'download' => 1]);
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
    .bar form { display: flex; gap: 8px; flex: 1; min-width: 240px; }
    input[type=text] { flex: 1; padding: 8px 10px; border: 1px solid #c9ccd1; border-radius: 6px; font-size: 14px; }
    button, .btn { padding: 8px 14px; border: 0; border-radius: 6px; background: #2f6fed; color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; }
    .btn.excel { background: #1d8348; margin-left: auto; }
    .info { font-size: 13px; color: #555; margin-bottom: 8px; }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eceef1; font-size: 14px; white-space: nowrap; }
    th { background: #eef0f4; }
    .empty { text-align: center; color: #888; padding: 32px; }
    .pager { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; margin-top: 16px; }
    .pager a, .pager span { padding: 6px 11px; border-radius: 6px; background: #fff; border: 1px solid #d5d8dd; color: #333; text-decoration: none; font-size: 13px; }
    .pager .cur { background: #2f6fed; border-color: #2f6fed; color: #fff; }
    .pager .gap { border: 0; background: transparent; }
</style>
</head>
<body>
<div class="wrap">
    <h1><?= self::h($this->o['title']) ?></h1>
    <div class="bar">
        <?php if ($this->o['search']): ?>
        <form method="get">
            <input type="text" name="q" value="<?= self::h($keyword) ?>" placeholder="검색">
            <button type="submit">검색</button>
        </form>
        <?php endif; ?>
        <a class="btn excel" href="<?= self::h($downloadUrl) ?>">엑셀 다운로드</a>
    </div>
    <div class="info">
        검색 결과 <?= number_format($total) ?>건 · <?= $page ?> / <?= $pages ?> 페이지 (엑셀에는 전체 <?= number_format($total) ?>건 포함)
    </div>
    <div class="scroll">
    <table>
        <thead>
            <tr><?php foreach ($columns as $label): ?><th><?= self::h($label) ?></th><?php endforeach; ?></tr>
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
        <?php if ($page > 1): ?><a href="<?= self::h(self::url(['q' => $keyword, 'page' => $page - 1])) ?>">이전</a><?php endif; ?>
        <?php foreach (self::pageList($page, $pages) as $n): ?>
            <?php if ($n === '…'): ?>
                <span class="gap">…</span>
            <?php elseif ($n === $page): ?>
                <span class="cur"><?= $n ?></span>
            <?php else: ?>
                <a href="<?= self::h(self::url(['q' => $keyword, 'page' => $n])) ?>"><?= $n ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $pages): ?><a href="<?= self::h(self::url(['q' => $keyword, 'page' => $page + 1])) ?>">다음</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
        <?php
    }
}
