<?php

namespace App\Libraries\Search;

use CodeIgniter\HTTP\CURLRequest;
use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Elasticsearch REST API 를 직접 호출하는 얇은 클라이언트 (ES 7.x 기준, 외부 라이브러리 없음).
 * 연결 실패나 4xx/5xx 응답은 RuntimeException 으로 던진다.
 */
class EsClient
{
    private string $host;
    private int $timeout;

    public function __construct(string $host, int $timeout = 5)
    {
        $this->host = rtrim($host, '/') . '/';
        $this->timeout = $timeout;
    }

    /** 서버 버전. 연결이 안 되면 null */
    public function version(): ?string
    {
        try {
            return $this->call('GET', '')['version']['number'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function indexExists(string $index): bool
    {
        return $this->send('HEAD', $index)[0] === 200;
    }

    public function createIndex(string $index, array $definition): void
    {
        $this->call('PUT', $index, $definition);
    }

    /** 없는 인덱스면 아무 일도 하지 않는다 */
    public function deleteIndex(string $index): void
    {
        $this->call('DELETE', $index, null, [404]);
    }

    /** 문서 저장(없으면 추가, 있으면 덮어쓰기). 검색에 바로 보이도록 반영될 때까지 기다린다 */
    public function put(string $index, $id, array $doc): void
    {
        $this->call('PUT', $index . '/_doc/' . rawurlencode((string)$id) . '?refresh=wait_for', $doc);
    }

    /** 문서 삭제. 이미 없는 문서면 아무 일도 하지 않는다 */
    public function remove(string $index, $id): void
    {
        $this->call('DELETE', $index . '/_doc/' . rawurlencode((string)$id) . '?refresh=wait_for', null, [404]);
    }

    public function count(string $index): int
    {
        return (int)($this->call('GET', $index . '/_count')['count'] ?? 0);
    }

    public function search(string $index, array $body): array
    {
        return $this->call('POST', $index . '/_search', $body);
    }

    /**
     * 여러 문서를 한 번에 저장한다
     *
     * @param array<int|string, array> $docs [id => 문서]
     */
    public function bulkPut(string $index, array $docs): void
    {
        if (!$docs) {
            return;
        }
        $lines = '';
        foreach ($docs as $id => $doc) {
            $lines .= json_encode(['index' => ['_index' => $index, '_id' => (string)$id]]) . "\n";
            $lines .= json_encode($doc, JSON_UNESCAPED_UNICODE) . "\n";
        }
        $res = $this->call('POST', '_bulk', $lines, [], 'application/x-ndjson');
        if (!empty($res['errors'])) {
            foreach ($res['items'] ?? [] as $item) {
                if (isset($item['index']['error'])) {
                    throw new RuntimeException('일괄 저장 실패 (id ' . $item['index']['_id'] . '): ' . ($item['index']['error']['reason'] ?? ''));
                }
            }
            throw new RuntimeException('일괄 저장 중 일부 문서가 실패했습니다.');
        }
    }

    public function refresh(string $index): void
    {
        $this->call('POST', $index . '/_refresh');
    }

    /**
     * 요청을 보내고 JSON 응답을 배열로 돌려준다
     *
     * @param array|string|null $body 배열이면 JSON 으로 바꿔 보낸다
     * @param int[]             $okStatus 오류로 보지 않을 추가 상태 코드
     */
    private function call(string $method, string $path, $body = null, array $okStatus = [], string $contentType = 'application/json'): array
    {
        [$status, $raw] = $this->send($method, $path, $body, $contentType);
        $data = json_decode($raw, true);
        if ($status >= 400 && !in_array($status, $okStatus, true)) {
            $reason = $data['error']['reason'] ?? $data['error']['type'] ?? (is_string($data['error'] ?? null) ? $data['error'] : $raw);
            throw new RuntimeException("Elasticsearch 오류 ({$status}): {$reason}");
        }
        return is_array($data) ? $data : [];
    }

    /** @return array{0:int,1:string} [상태 코드, 응답 본문] */
    private function send(string $method, string $path, $body = null, string $contentType = 'application/json'): array
    {
        // 요청마다 새 인스턴스를 만들어 이전 요청의 본문/헤더가 섞이지 않게 한다
        /** @var CURLRequest $http */
        $http = Services::curlrequest([
            'base_uri' => $this->host,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'http_errors' => false,
        ], null, null, false);

        $options = ['headers' => ['Accept' => 'application/json']];
        if ($body !== null) {
            $options['headers']['Content-Type'] = $contentType;
            $options['body'] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        try {
            $res = $http->request($method, $path, $options);
        } catch (Throwable $e) {
            throw new RuntimeException('Elasticsearch 에 연결할 수 없습니다 (' . $this->host . '): ' . $e->getMessage(), 0, $e);
        }
        return [$res->getStatusCode(), (string)$res->getBody()];
    }
}
