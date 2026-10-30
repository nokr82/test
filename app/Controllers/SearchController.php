<?php

namespace App\Controllers;

use App\Libraries\ListView\ListView;
use App\Libraries\Search\ArticleIndex;
use App\Libraries\Search\EsClient;
use App\Models\ArticleModel;
use InvalidArgumentException;
use Throwable;

/**
 * 검색엔진(Elasticsearch) 테스트 화면.
 * 등록/수정/삭제는 MySQL 에 먼저 반영한 뒤 ES 에 반영한다. ES 반영만 실패하면 MySQL 은 되돌리지 않고 경고를 준다.
 * CSRF 는 Config/Filters.php 의 csrf 필터(X-CSRF-TOKEN 헤더)가 검사한다.
 */
class SearchController extends BaseController
{
    /** 검색 화면 (검색어 q, 분류 category, 가격 min~max, 페이지 page) */
    public function index()
    {
        $config = config('Search');
        $model = new ArticleModel();
        $index = $this->articleIndex();
        $cond = $this->condition();
        $page = max(1, (int)$this->request->getGet('page'));

        $status = ['version' => null, 'indexExists' => false, 'mysqlCount' => null, 'esCount' => null];
        $result = ['total' => 0, 'took' => 0, 'hits' => [], 'categories' => []];
        $error = null;
        $categories = [];

        try {
            $status['mysqlCount'] = $model->countAllResults();
            $categories = $model->categories();
        } catch (Throwable $e) {
            $error = 'MySQL 오류: ' . $e->getMessage() . ' (es_articles 테이블을 만들었는지 확인하세요)';
        }

        $status['version'] = (new EsClient($config->host, $config->timeout))->version();
        if ($status['version'] === null) {
            $error = $error ?? 'Elasticsearch(' . $config->host . ')에 연결할 수 없습니다.';
        } else {
            try {
                $status['indexExists'] = $index->exists();
                if ($status['indexExists']) {
                    $status['esCount'] = $index->count();
                    $result = $index->search($cond, $page, $config->perPage);
                } else {
                    $error = $error ?? '인덱스 ' . $index->name() . ' 이(가) 없습니다. [전체 재색인] 을 눌러 만드세요.';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $pages = max(1, (int)ceil($result['total'] / $config->perPage));
        // 비어 있는 값은 주소에서 뺀다
        $url = static function (array $query) use ($cond) {
            $query = array_filter($query + $cond, static function ($v) {
                return $v !== '' && $v !== null;
            });
            return url_to('search.index') . ($query ? '?' . http_build_query($query) : '');
        };

        return view('search', [
            'cond' => $cond,
            'status' => $status,
            'result' => $result,
            'error' => $error,
            'categories' => $categories,
            'page' => $page,
            'pages' => $pages,
            'pageList' => ListView::pageList($page, $pages),
            'url' => $url,
            'host' => $config->host,
        ]);
    }

    /** 수정 팝업용: MySQL 원본 1건 */
    public function item(int $id)
    {
        $row = (new ArticleModel())->find($id);
        if (!$row) {
            return $this->fail('없는 글입니다.', 404);
        }
        return $this->response->setJSON(['ok' => true, 'item' => $row]);
    }

    /** 등록(id 없음) / 수정(id 있음) */
    public function save()
    {
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body)) {
                throw new InvalidArgumentException('잘못된 요청 내용입니다.');
            }
            $data = [];
            foreach (['title', 'content', 'category', 'tags', 'author', 'price'] as $field) {
                $data[$field] = trim((string)($body[$field] ?? ''));
            }
            $data['price'] = $data['price'] === '' ? '0' : $data['price'];

            $model = new ArticleModel();
            $id = (int)($body['id'] ?? 0);
            if ($id > 0) {
                if (!$model->find($id)) {
                    return $this->fail('없는 글입니다.', 404);
                }
                $saved = $model->update($id, $data);
            } else {
                $saved = $model->insert($data);
                $id = (int)$model->getInsertID();
            }
            if ($saved === false) {
                return $this->fail(implode(' ', $model->errors()), 400);
            }

            return $this->response->setJSON(['ok' => true, 'id' => $id] + $this->syncEs(function (ArticleIndex $index) use ($model, $id) {
                $index->save($model->find($id));
            }));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), $e instanceof InvalidArgumentException ? 400 : 500);
        }
    }

    public function delete(int $id)
    {
        try {
            $model = new ArticleModel();
            if (!$model->find($id)) {
                return $this->fail('없는 글입니다.', 404);
            }
            $model->delete($id);
            return $this->response->setJSON(['ok' => true] + $this->syncEs(function (ArticleIndex $index) use ($id) {
                $index->delete($id);
            }));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /** 인덱스를 새로 만들고 MySQL 전체를 넣는다 */
    public function reindex()
    {
        try {
            $count = $this->articleIndex()->rebuild(new ArticleModel());
            return $this->response->setJSON(['ok' => true, 'count' => $count]);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /** MySQL 반영이 끝난 뒤 ES 에 반영한다. 실패해도 예외를 던지지 않고 경고로 돌려준다 */
    private function syncEs(callable $fn): array
    {
        try {
            $fn($this->articleIndex());
            return ['esOk' => true];
        } catch (Throwable $e) {
            return ['esOk' => false, 'warning' => 'MySQL 에는 저장됐지만 Elasticsearch 반영에 실패했습니다. [전체 재색인] 이 필요합니다. (' . $e->getMessage() . ')'];
        }
    }

    private function articleIndex(): ArticleIndex
    {
        $config = config('Search');
        return new ArticleIndex(new EsClient($config->host, $config->timeout), $config->index);
    }

    /** @return array{q:string,category:string,min:?int,max:?int} */
    private function condition(): array
    {
        $str = function (string $key): string {
            $v = $this->request->getGet($key);
            return is_string($v) ? trim($v) : '';
        };
        $int = static function (string $v): ?int {
            return preg_match('/^-?\d+$/', $v) ? (int)$v : null;
        };
        return [
            'q' => $str('q'),
            'category' => $str('category'),
            'min' => $int($str('min')),
            'max' => $int($str('max')),
        ];
    }

    private function fail(string $message, int $status)
    {
        return $this->response->setStatusCode($status)->setJSON(['ok' => false, 'error' => $message]);
    }
}
