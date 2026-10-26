<?php
// 회원 목록 화면 + 엑셀 다운로드 버튼.
// 같은 검색 조건(q)으로 화면 목록과 엑셀을 만든다. 화면은 최대 $limit행, 엑셀은 전체.
require_once __DIR__ . '/ExcelExport.php';

use XlsxExport\ExcelExport;
use XlsxExport\QuerySource;

// ── DB 연결 (실제 사용 시 기존 연결로 교체) ──
$conn = new mysqli('192.168.17.128', 'ggulfile', 'test', 'ssfile');
if ($conn->connect_errno) {
    http_response_code(500);
    exit('DB 접속 실패: ' . htmlspecialchars($conn->connect_error));
}
$conn->set_charset('utf8mb4');

$limit = 100;
$keyword = trim($_GET['q'] ?? '');
// LIKE 와일드카드(% _)가 검색어에 있으면 문자 그대로 검색되도록 이스케이프
$params = ['%' . addcslashes($keyword, '%_\\') . '%'];
// 쿼리가 돌려주는 컬럼 수에 맞춰 목록 표와 엑셀이 자동으로 바뀐다.
// SELECT 컬럼을 늘리거나 줄이면 화면과 엑셀 모두 그대로 따라간다.
$sql = 'SELECT idx as 회원번호 , nickname as 닉네임,name as 이름 FROM _member WHERE nickname LIKE ? ORDER BY idx';
// (선택) 헤더 라벨. 비워두면 DB 컬럼명이 헤더가 된다.
// 값이 있으면 여기 적은 컬럼만, 적은 순서대로 표시된다. 예: ['id' => '번호', 'name' => '이름']
$labels = [];
// (선택) 컬럼별 값 가공. 화면과 엑셀에 똑같이 적용된다.
$formats = [
    'regdate' => function ($value, $row) {
        return substr((string)$value, 0, 10); // 날짜만 표시
    },
];

// 엑셀 다운로드 (download=1)
if (isset($_GET['download'])) {
    $export = ExcelExport::fromQuery($conn, $sql, $params)->sheet('회원목록');
    if ($labels) {
        $export->columns($labels);
    }
    foreach ($formats as $column => $fn) {
        $export->format($column, $fn);
    }
    $export->download('회원목록.xlsx');
    exit;
}

$total = (int)QuerySource::rows($conn, 'SELECT COUNT(*) AS c FROM _member WHERE nickname LIKE ?', $params)->current()['c'];
$rows = iterator_to_array(QuerySource::rows($conn, $sql . ' LIMIT ' . $limit, $params), false);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// 표시할 컬럼: $labels가 있으면 그것, 없으면 첫 행의 컬럼명 전부
$columns = $labels ?: ($rows ? array_combine(array_keys($rows[0]), array_keys($rows[0])) : []);

$downloadUrl = '?' . http_build_query(['q' => $keyword, 'download' => 1]);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>회원 목록</title>
<style>
    body { font-family: system-ui, "Malgun Gothic", sans-serif; margin: 0; background: #f5f6f8; color: #222; }
    .wrap { max-width: 860px; margin: 32px auto; padding: 0 16px; }
    h1 { font-size: 20px; margin: 0 0 16px; }
    .bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .bar form { display: flex; gap: 8px; flex: 1; min-width: 240px; }
    input[type=text] { flex: 1; padding: 8px 10px; border: 1px solid #c9ccd1; border-radius: 6px; font-size: 14px; }
    button, .btn { padding: 8px 14px; border: 0; border-radius: 6px; background: #2f6fed; color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; }
    .btn.excel { background: #1d8348; }
    .info { font-size: 13px; color: #555; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eceef1; font-size: 14px; }
    th { background: #eef0f4; }
    .empty { text-align: center; color: #888; padding: 32px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>회원 목록</h1>
    <div class="bar">
        <form method="get">
            <input type="text" name="q" value="<?= h($keyword) ?>" placeholder="이름 검색">
            <button type="submit">검색</button>
        </form>
        <a class="btn excel" href="<?= h($downloadUrl) ?>">엑셀 다운로드</a>
    </div>
    <div class="info">
        검색 결과 <?= number_format($total) ?>건
        <?php if ($total > $limit): ?>
            (화면에는 <?= $limit ?>건만 표시, 엑셀에는 전체 포함)
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr><?php foreach ($columns as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr>
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
                    <td><?= h($value) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
