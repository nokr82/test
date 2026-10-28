<?php

namespace App\Controllers;

use App\Libraries\ListView\LabelStore;
use App\Libraries\ListView\ListView;
use App\Libraries\Xlsx\ExcelExport;
use Config\Database;
use InvalidArgumentException;
use Throwable;

class ListController extends BaseController
{
    /** 목록 화면 (검색어 q, 페이지 page, 테이블 table) */
    public function index()
    {
        $list = $this->listView();
        $o = $list->resolve($this->request->getGet('table'));
        $keyword = $this->keyword();
        $data = $list->page($o, $keyword, (int)$this->request->getGet('page'));

        $columns = $list->columns($o, $data['rows']);
        $tbl = $o['currentTable'] !== null ? ['table' => $o['currentTable']] : [];
        // 비어 있는 값은 주소에서 뺀다
        $url = function (string $route, array $query) use ($tbl) {
            $query = array_filter($tbl + $query, function ($v) {
                return $v !== '' && $v !== null;
            });
            return url_to($route) . ($query ? '?' . http_build_query($query) : '');
        };

        return view('list', [
            'o' => $o,
            'keyword' => $keyword,
            'total' => $data['total'],
            'page' => $data['page'],
            'pages' => $data['pages'],
            'pageList' => ListView::pageList($data['page'], $data['pages']),
            'columns' => $columns,
            'cells' => $list->cells($o, $columns, $data['rows']),
            'canEdit' => (bool)$o['editLabels'] && $columns,
            'url' => $url,
            'downloadUrl' => $url('list.download', ['q' => $keyword]),
            'saveUrl' => $url('list.labels', []),
        ]);
    }

    /** 같은 검색 조건의 전체 결과를 엑셀로 내려준다 */
    public function download()
    {
        $list = $this->listView();
        $o = $list->resolve($this->request->getGet('table'));

        $export = ExcelExport::fromRows($list->exportRows($o, $this->keyword()))->sheet($o['sheet']);
        if ($o['labels']) {
            $export->columns($o['labels']);
        }
        if ($o['aliases']) {
            $export->labelOverrides($o['aliases']);
        }
        foreach ($o['formats'] as $column => $fn) {
            $export->format($column, $fn);
        }
        return $export->download($o['excelFile']);
    }

    /** 화면에서 수정한 헤더 표시명을 저장한다. CSRF 는 Config/Filters.php 의 csrf 필터(X-CSRF-TOKEN 헤더)가 검사한다 */
    public function saveLabels()
    {
        try {
            $list = $this->listView();
            $o = $list->resolve($this->request->getGet('table'));
            if (!$o['editLabels']) {
                throw new InvalidArgumentException('헤더 이름 편집이 꺼져 있습니다.');
            }
            $body = $this->request->getJSON(true);
            $labels = is_array($body) ? ($body['labels'] ?? null) : null;
            if (!is_array($labels)) {
                throw new InvalidArgumentException('잘못된 요청 내용입니다.');
            }
            // 저장 위치는 요청 본문이 아니라 서버가 확정한 테이블(from)로만 정한다
            $this->labelStore()->replace((string)$o['from'], LabelStore::clean($labels));
            return $this->response->setJSON(['ok' => true]);
        } catch (Throwable $e) {
            return $this->response
                ->setStatusCode($e instanceof InvalidArgumentException ? 400 : 500)
                ->setJSON(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function listView(): ListView
    {
        $config = config('ListView');
        return new ListView(get_object_vars($config), $this->labelStore(), Database::connect());
    }

    private function labelStore(): LabelStore
    {
        return new LabelStore(config('ListView')->labelFile);
    }

    private function keyword(): string
    {
        $q = $this->request->getGet('q');
        return is_string($q) ? trim($q) : '';
    }
}
