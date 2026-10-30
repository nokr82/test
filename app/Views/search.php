<?php
/**
 * @var array    $cond
 * @var array    $status
 * @var array    $result
 * @var ?string  $error
 * @var string[] $categories
 * @var int      $page
 * @var int      $pages
 * @var array    $pageList
 * @var callable $url
 * @var string   $host
 */
$base = rtrim(url_to('search.index'), '/');
$js = static function ($v) {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
};
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
<title>검색엔진 테스트</title>
<style>
    body { font-family: system-ui, "Malgun Gothic", sans-serif; margin: 0; background: #f5f6f8; color: #222; }
    .wrap { max-width: 1100px; margin: 32px auto; padding: 0 16px; }
    h1 { font-size: 20px; margin: 0 0 16px; }
    .bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .bar form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; flex: 1; }
    input[type=text], input[type=number], select, textarea { padding: 8px 10px; border: 1px solid #c9ccd1; border-radius: 6px; font-size: 14px; background: #fff; font-family: inherit; }
    form input[name=q] { flex: 1; min-width: 200px; }
    form input[type=number] { width: 90px; }
    button, .btn { padding: 8px 14px; border: 0; border-radius: 6px; background: #2f6fed; color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; }
    .btn.gray { background: #6b7280; }
    .btn.green { background: #1d8348; }
    .btn.red { background: #c0392b; }
    .btn.sm { padding: 4px 10px; font-size: 13px; }
    .status { font-size: 13px; color: #555; margin-bottom: 8px; }
    .status .ok { color: #1d8348; } .status .bad { color: #b3261e; }
    .info { font-size: 13px; color: #555; margin-bottom: 8px; }
    .facet a { display: inline-block; padding: 2px 10px; margin: 0 4px 4px 0; border-radius: 12px; background: #e3e8f4; color: #333; text-decoration: none; font-size: 13px; }
    .facet a.cur { background: #2f6fed; color: #fff; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eceef1; font-size: 14px; vertical-align: top; }
    th { background: #eef0f4; white-space: nowrap; }
    td.num { text-align: right; white-space: nowrap; }
    td.act { white-space: nowrap; }
    .snip { color: #666; font-size: 13px; margin-top: 4px; }
    .meta { color: #888; font-size: 12px; margin-top: 4px; }
    mark { background: #fde68a; padding: 0 1px; }
    .empty { text-align: center; color: #888; padding: 32px; }
    .pager { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; margin-top: 16px; }
    .pager a, .pager span { padding: 6px 11px; border-radius: 6px; background: #fff; border: 1px solid #d5d8dd; color: #333; text-decoration: none; font-size: 13px; }
    .pager .cur { background: #2f6fed; border-color: #2f6fed; color: #fff; }
    .pager .gap { border: 0; background: transparent; }
    .err { background: #fdecea; color: #b3261e; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
    dialog { border: 0; border-radius: 10px; padding: 20px; width: min(640px, 92vw); box-shadow: 0 12px 40px rgba(0,0,0,.25); }
    dialog::backdrop { background: rgba(0,0,0,.35); }
    dialog h2 { font-size: 17px; margin: 0 0 12px; }
    .field { margin-bottom: 10px; }
    .field label { display: block; font-size: 12px; color: #666; margin-bottom: 4px; }
    .field input, .field textarea { width: 100%; box-sizing: border-box; }
    .row { display: flex; gap: 10px; } .row .field { flex: 1; }
    .dlg-actions { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
    #dlg-msg { font-size: 13px; color: #b3261e; }
</style>
</head>
<body>
<div class="wrap">
    <h1>검색엔진 테스트 <small style="font-size:13px;color:#888;font-weight:normal">MySQL es_articles ↔ Elasticsearch</small></h1>

    <div class="status">
        <?php if ($status['version'] !== null): ?>
            <span class="ok">● Elasticsearch <?= esc($status['version']) ?> 연결됨</span>
        <?php else: ?>
            <span class="bad">● Elasticsearch 연결 안 됨</span>
        <?php endif ?>
        (<?= esc($host) ?>)
        · MySQL <?= $status['mysqlCount'] === null ? '-' : number_format($status['mysqlCount']) ?>건
        / ES <?= $status['esCount'] === null ? '-' : number_format($status['esCount']) ?>건
        <?php if ($status['mysqlCount'] !== null && $status['esCount'] !== null && $status['mysqlCount'] !== $status['esCount']): ?>
            <span class="bad">← 건수가 다릅니다. 재색인하세요.</span>
        <?php endif ?>
    </div>

    <div class="bar">
        <form method="get">
            <input type="text" name="q" value="<?= esc($cond['q'], 'attr') ?>" placeholder="검색어 (예: 검색엔진을)">
            <select name="category">
                <option value="">분류 전체</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= esc($c, 'attr') ?>"<?= $c === $cond['category'] ? ' selected' : '' ?>><?= esc($c) ?></option>
                <?php endforeach ?>
            </select>
            <input type="number" name="min" value="<?= esc((string)$cond['min'], 'attr') ?>" placeholder="가격 최소">
            ~
            <input type="number" name="max" value="<?= esc((string)$cond['max'], 'attr') ?>" placeholder="최대">
            <button type="submit">검색</button>
        </form>
        <button type="button" class="btn green" id="btn-new">+ 새 글</button>
        <button type="button" class="btn gray" id="btn-reindex">전체 재색인</button>
    </div>

    <?php if ($error): ?>
        <div class="err"><?= esc($error) ?></div>
    <?php endif ?>

    <?php if ($result['categories']): ?>
    <div class="facet">
        <a href="<?= esc($url(['category' => '']), 'attr') ?>"<?= $cond['category'] === '' ? ' class="cur"' : '' ?>>전체 <?= number_format(array_sum($result['categories'])) ?></a>
        <?php foreach ($result['categories'] as $name => $n): ?>
            <a href="<?= esc($url(['category' => (string)$name]), 'attr') ?>"<?= (string)$name === $cond['category'] ? ' class="cur"' : '' ?>><?= esc($name === '' ? '(없음)' : (string)$name) ?> <?= number_format($n) ?></a>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <div class="info">
        검색 결과 <?= number_format($result['total']) ?>건 · <?= $result['took'] ?>ms · <?= $page ?> / <?= $pages ?> 페이지
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>제목 / 본문</th><th>분류</th><th>가격</th><th>점수</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$result['hits']): ?>
            <tr><td class="empty" colspan="6">검색 결과가 없습니다.</td></tr>
        <?php endif ?>
        <?php foreach ($result['hits'] as $h): ?>
            <?php $s = $h['source'] ?>
            <tr>
                <td class="num"><?= $h['id'] ?></td>
                <td>
                    <!-- 하이라이트는 ES 가 html 인코딩해서 돌려주므로 그대로 출력한다 -->
                    <b><?= $h['titleHtml'] ?? esc($s['title'] ?? '') ?></b>
                    <div class="snip"><?= $h['contentHtml'] ?? esc(mb_substr((string)($s['content'] ?? ''), 0, 80)) ?></div>
                    <div class="meta">
                        <?= esc($s['author'] ?? '') ?>
                        <?php if (!empty($s['tags'])): ?> · #<?= esc(implode(' #', (array)$s['tags'])) ?><?php endif ?>
                        · <?= esc($s['updated_at'] ?? '') ?>
                    </div>
                </td>
                <td><?= esc($s['category'] ?? '') ?></td>
                <td class="num"><?= number_format((int)($s['price'] ?? 0)) ?></td>
                <td class="num"><?= $h['score'] === null ? '-' : number_format((float)$h['score'], 2) ?></td>
                <td class="act">
                    <button type="button" class="btn sm gray" data-edit="<?= $h['id'] ?>">수정</button>
                    <button type="button" class="btn sm red" data-delete="<?= $h['id'] ?>">삭제</button>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?><a href="<?= esc($url(['page' => $page - 1]), 'attr') ?>">이전</a><?php endif ?>
        <?php foreach ($pageList as $n): ?>
            <?php if ($n === '…'): ?>
                <span class="gap">…</span>
            <?php elseif ($n === $page): ?>
                <span class="cur"><?= $n ?></span>
            <?php else: ?>
                <a href="<?= esc($url(['page' => $n]), 'attr') ?>"><?= $n ?></a>
            <?php endif ?>
        <?php endforeach ?>
        <?php if ($page < $pages): ?><a href="<?= esc($url(['page' => $page + 1]), 'attr') ?>">다음</a><?php endif ?>
    </div>
    <?php endif ?>
</div>

<dialog id="dlg">
    <form id="dlg-form" method="dialog">
        <h2 id="dlg-title">새 글</h2>
        <input type="hidden" name="id">
        <div class="field"><label>제목 *</label><input type="text" name="title" maxlength="200" required></div>
        <div class="field"><label>본문 *</label><textarea name="content" rows="6" required></textarea></div>
        <div class="row">
            <div class="field"><label>분류</label><input type="text" name="category" maxlength="50" list="dlg-categories"></div>
            <div class="field"><label>작성자</label><input type="text" name="author" maxlength="50"></div>
            <div class="field"><label>가격</label><input type="number" name="price" step="1"></div>
        </div>
        <datalist id="dlg-categories">
            <?php foreach ($categories as $c): ?><option value="<?= esc($c, 'attr') ?>"><?php endforeach ?>
        </datalist>
        <div class="field"><label>태그 (쉼표로 구분)</label><input type="text" name="tags" maxlength="255"></div>
        <div class="dlg-actions">
            <button type="submit" id="dlg-save">저장</button>
            <button type="button" class="btn gray" id="dlg-cancel">취소</button>
            <span id="dlg-msg"></span>
        </div>
    </form>
</dialog>

<script>
(function () {
    var base = <?= $js($base) ?>;
    var csrfHeader = <?= $js(csrf_header()) ?>;
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var dlg = document.getElementById('dlg');
    var form = document.getElementById('dlg-form');
    var msg = document.getElementById('dlg-msg');
    var fields = ['id', 'title', 'content', 'category', 'author', 'price', 'tags'];

    function request(method, url, body) {
        var headers = {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'};
        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            headers[csrfHeader] = csrf;
        }
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body === undefined ? undefined : JSON.stringify(body)
        }).then(function (r) {
            // CSRF 토큰 불일치 등은 JSON 이 아닌 오류 페이지로 온다
            return r.json().catch(function () { return {ok: false, error: '서버 오류 (' + r.status + '). 새로고침 후 다시 시도하세요.'}; });
        }, function () {
            return {ok: false, error: '서버에 연결할 수 없습니다.'};
        });
    }

    // ES 반영 실패 경고가 있으면 알려준 뒤 새로고침
    function done(res) {
        if (res.warning) { alert(res.warning); }
        location.reload();
    }

    function open(item) {
        fields.forEach(function (f) { form.elements[f].value = item && item[f] != null ? item[f] : ''; });
        document.getElementById('dlg-title').textContent = item ? '글 수정 #' + item.id : '새 글';
        msg.textContent = '';
        dlg.showModal();
        form.elements.title.focus();
    }

    document.getElementById('btn-new').addEventListener('click', function () { open(null); });
    document.getElementById('dlg-cancel').addEventListener('click', function () { dlg.close(); });

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-edit')) {
            request('GET', base + '/item/' + t.getAttribute('data-edit')).then(function (res) {
                if (res.ok) { open(res.item); } else { alert(res.error); }
            });
        } else if (t.hasAttribute('data-delete')) {
            var id = t.getAttribute('data-delete');
            if (!confirm('#' + id + ' 글을 삭제할까요? (MySQL 과 ES 모두에서 지워집니다)')) { return; }
            request('POST', base + '/delete/' + id, {}).then(function (res) {
                if (res.ok) { done(res); } else { alert('삭제 실패: ' + res.error); }
            });
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = {};
        fields.forEach(function (f) { body[f] = form.elements[f].value; });
        msg.textContent = '저장 중...';
        request('POST', base + '/save', body).then(function (res) {
            if (res.ok) { done(res); } else { msg.textContent = '저장 실패: ' + res.error; }
        });
    });

    document.getElementById('btn-reindex').addEventListener('click', function () {
        if (!confirm('ES 인덱스를 지우고 MySQL 데이터로 다시 만듭니다. 계속할까요?')) { return; }
        this.disabled = true;
        this.textContent = '재색인 중...';
        request('POST', base + '/reindex', {}).then(function (res) {
            if (res.ok) { alert(res.count + '건을 다시 넣었습니다.'); location.reload(); } else { alert('재색인 실패: ' + res.error); location.reload(); }
        });
    });
})();
</script>
</body>
</html>
