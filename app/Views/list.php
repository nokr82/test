<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
<title><?= esc($o['title']) ?></title>
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
    <h1><?= esc($o['title']) ?></h1>
    <div class="bar">
        <?php if ($o['tableList'] && $o['tableInput']): ?>
        <form method="get">
            <input type="text" name="table" list="lv-tables" value="<?= esc((string)$o['currentTable'], 'attr') ?>" placeholder="테이블명 입력" autocomplete="off">
            <datalist id="lv-tables">
                <?php foreach ($o['tableList'] as $t): ?><option value="<?= esc($t, 'attr') ?>"><?php endforeach ?>
            </datalist>
            <button type="submit">이동</button>
        </form>
        <?php elseif ($o['tableList']): ?>
        <form method="get">
            <select name="table" onchange="this.form.submit()">
                <?php foreach ($o['tableList'] as $t): ?>
                    <option value="<?= esc($t, 'attr') ?>"<?= $t === $o['currentTable'] ? ' selected' : '' ?>><?= esc($t) ?></option>
                <?php endforeach ?>
            </select>
            <noscript><button type="submit">이동</button></noscript>
        </form>
        <?php endif ?>
        <?php if ($o['search']): ?>
        <form method="get" class="search">
            <?php if ($o['currentTable'] !== null): ?>
                <input type="hidden" name="table" value="<?= esc($o['currentTable'], 'attr') ?>">
            <?php endif ?>
            <input type="text" name="q" value="<?= esc($keyword, 'attr') ?>" placeholder="검색">
            <button type="submit">검색</button>
        </form>
        <?php endif ?>
        <?php if ($canEdit): ?>
            <button type="button" class="btn gray" id="lv-edit">헤더 이름 편집</button>
            <span id="lv-editbar" hidden>
                <button type="button" id="lv-save">저장</button>
                <button type="button" class="btn gray" id="lv-cancel">취소</button>
                <span id="lv-msg"></span>
            </span>
        <?php endif ?>
        <a class="btn excel" href="<?= esc($downloadUrl, 'attr') ?>">엑셀 다운로드</a>
    </div>
    <?php if ($o['tableError']): ?>
        <div class="err"><?= esc($o['tableError']) ?></div>
    <?php endif ?>
    <div class="info">
        검색 결과 <?= number_format($total) ?>건 · <?= $page ?> / <?= $pages ?> 페이지 (엑셀에는 전체 <?= number_format($total) ?>건 포함)
    </div>
    <div class="scroll">
    <table>
        <thead>
            <tr>
            <?php foreach ($columns as $key => $label): ?>
                <?php $shown = $o['aliases'][$key] ?? $label ?>
                <th data-col="<?= esc((string)$key, 'attr') ?>" data-orig="<?= esc((string)$label, 'attr') ?>" data-label="<?= esc((string)$shown, 'attr') ?>"><?= esc((string)$shown) ?></th>
            <?php endforeach ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$cells): ?>
            <tr><td class="empty" colspan="<?= max(1, count($columns)) ?>">검색 결과가 없습니다.</td></tr>
        <?php endif ?>
        <?php foreach ($cells as $line): ?>
            <tr>
                <?php foreach ($line as $value): ?>
                    <td><?= esc((string)$value) ?></td>
                <?php endforeach ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?><a href="<?= esc($url('list.index', ['q' => $keyword, 'page' => $page - 1]), 'attr') ?>">이전</a><?php endif ?>
        <?php foreach ($pageList as $n): ?>
            <?php if ($n === '…'): ?>
                <span class="gap">…</span>
            <?php elseif ($n === $page): ?>
                <span class="cur"><?= $n ?></span>
            <?php else: ?>
                <a href="<?= esc($url('list.index', ['q' => $keyword, 'page' => $n]), 'attr') ?>"><?= $n ?></a>
            <?php endif ?>
        <?php endforeach ?>
        <?php if ($page < $pages): ?><a href="<?= esc($url('list.index', ['q' => $keyword, 'page' => $page + 1]), 'attr') ?>">다음</a><?php endif ?>
    </div>
    <?php endif ?>
</div>
<?php if ($canEdit): ?>
<script>
(function () {
    var saveUrl = <?= json_encode($saveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var csrfHeader = <?= json_encode(csrf_header(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
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
        var headers = {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'};
        headers[csrfHeader] = csrf;
        msg.textContent = '저장 중...';
        fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify({labels: labels})
        }).then(function (r) {
            // CSRF 토큰 불일치 등은 JSON 이 아닌 오류 페이지로 온다
            return r.json().catch(function () { return {ok: false, error: '서버 오류 (' + r.status + '). 새로고침 후 다시 시도하세요.'}; });
        }).then(function (res) {
            if (res.ok) { location.reload(); } else { msg.textContent = '저장 실패: ' + (res.error || res.message || ''); }
        }).catch(function () { msg.textContent = '저장 실패: 서버에 연결할 수 없습니다.'; });
    });
})();
</script>
<?php endif ?>
</body>
</html>
