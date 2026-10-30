<?php

namespace App\Libraries\Search;

use App\Models\ArticleModel;
use stdClass;

/**
 * 게시글(es_articles) 인덱스: 매핑 정의, MySQL 행 → ES 문서 변환, 검색 쿼리 작성, 재색인
 */
class ArticleIndex
{
    private EsClient $es;
    private string $index;

    public function __construct(EsClient $es, string $index)
    {
        $this->es = $es;
        $this->index = $index;
    }

    public function name(): string
    {
        return $this->index;
    }

    public function exists(): bool
    {
        return $this->es->indexExists($this->index);
    }

    public function count(): int
    {
        return $this->es->count($this->index);
    }

    /** 인덱스 설정과 매핑. 한국어 필드는 nori 형태소 분석기를 쓴다 */
    public static function definition(): array
    {
        $korean = ['type' => 'text', 'analyzer' => 'korean'];
        return [
            'settings' => [
                'number_of_shards' => 1,
                // 노드가 1대라 복제본을 두면 상태가 yellow 가 된다
                'number_of_replicas' => 0,
                'analysis' => [
                    'tokenizer' => [
                        // mixed: "검색엔진" → 검색엔진 + 검색 + 엔진 (복합명사를 원형과 분해형 모두 색인)
                        'nori_mixed' => ['type' => 'nori_tokenizer', 'decompound_mode' => 'mixed'],
                    ],
                    'analyzer' => [
                        // nori_part_of_speech: 조사·어미 등을 버려 "검색엔진을" 으로도 "검색엔진" 이 찾아지게 한다
                        'korean' => ['type' => 'custom', 'tokenizer' => 'nori_mixed', 'filter' => ['nori_part_of_speech', 'lowercase']],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => $korean,
                    'content' => $korean,
                    'tags' => $korean,
                    'category' => ['type' => 'keyword'],
                    'author' => ['type' => 'keyword'],
                    'price' => ['type' => 'integer'],
                    'created_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
                    'updated_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
                ],
            ],
        ];
    }

    /** MySQL 행 → ES 문서. 태그는 쉼표로 나눠 배열로 넣는다 */
    public static function toDoc(array $row): array
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string)$row['tags'])), 'strlen'));
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'content' => (string)$row['content'],
            'tags' => $tags,
            'category' => (string)$row['category'],
            'author' => (string)$row['author'],
            'price' => (int)$row['price'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** 문서 1건 저장. 인덱스가 없으면 먼저 만든다 (자동 생성되면 nori 매핑이 빠지므로) */
    public function save(array $row): void
    {
        if (!$this->exists()) {
            $this->es->createIndex($this->index, self::definition());
        }
        $this->es->put($this->index, $row['id'], self::toDoc($row));
    }

    public function delete(int $id): void
    {
        $this->es->remove($this->index, $id);
    }

    /** 인덱스를 지우고 새로 만든 뒤 MySQL 의 전체 행을 넣는다. 넣은 건수를 돌려준다 */
    public function rebuild(ArticleModel $model, int $chunk = 500): int
    {
        $this->es->deleteIndex($this->index);
        $this->es->createIndex($this->index, self::definition());

        $docs = [];
        $total = 0;
        $model->orderBy('id')->chunk($chunk, function (array $row) use (&$docs, &$total, $chunk) {
            $docs[$row['id']] = self::toDoc($row);
            if (count($docs) >= $chunk) {
                $this->es->bulkPut($this->index, $docs);
                $total += count($docs);
                $docs = [];
            }
        });
        if ($docs) {
            $this->es->bulkPut($this->index, $docs);
            $total += count($docs);
        }
        $this->es->refresh($this->index);
        return $total;
    }

    /**
     * 검색
     *
     * @param array{q:string,category:string,min:?int,max:?int} $cond
     * @return array{total:int,took:int,hits:array,categories:array}
     */
    public function search(array $cond, int $page, int $perPage): array
    {
        $filter = [];
        if ($cond['min'] !== null || $cond['max'] !== null) {
            $range = [];
            if ($cond['min'] !== null) {
                $range['gte'] = $cond['min'];
            }
            if ($cond['max'] !== null) {
                $range['lte'] = $cond['max'];
            }
            $filter[] = ['range' => ['price' => $range]];
        }

        $must = $cond['q'] !== ''
            ? ['multi_match' => ['query' => $cond['q'], 'fields' => ['title^2', 'content', 'tags']]]
            : ['match_all' => new stdClass()];

        $body = [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
            'track_total_hits' => true,
            'query' => ['bool' => ['must' => [$must], 'filter' => $filter]],
            'sort' => $cond['q'] !== '' ? ['_score', ['id' => 'desc']] : [['id' => 'desc']],
            // 분류별 개수는 분류 필터를 걸기 전 기준으로 센다 (그래서 분류는 post_filter 로 거른다)
            'aggs' => ['categories' => ['terms' => ['field' => 'category', 'size' => 50]]],
            'highlight' => [
                // html 인코더: 본문의 HTML 은 이스케이프하고 <mark> 만 남긴다
                'encoder' => 'html',
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fields' => [
                    'title' => ['number_of_fragments' => 0],
                    'content' => ['fragment_size' => 80, 'number_of_fragments' => 1],
                ],
            ],
        ];
        if ($cond['category'] !== '') {
            $body['post_filter'] = ['term' => ['category' => $cond['category']]];
        }

        $res = $this->es->search($this->index, $body);

        $categories = [];
        foreach ($res['aggregations']['categories']['buckets'] ?? [] as $b) {
            $categories[$b['key']] = $b['doc_count'];
        }
        $hits = [];
        foreach ($res['hits']['hits'] ?? [] as $h) {
            $hits[] = [
                'id' => (int)$h['_id'],
                'score' => $h['_score'],
                'source' => $h['_source'],
                'titleHtml' => $h['highlight']['title'][0] ?? null,
                'contentHtml' => $h['highlight']['content'][0] ?? null,
            ];
        }
        return [
            'total' => (int)($res['hits']['total']['value'] ?? 0),
            'took' => (int)($res['took'] ?? 0),
            'hits' => $hits,
            'categories' => $categories,
        ];
    }
}
