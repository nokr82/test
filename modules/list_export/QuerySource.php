<?php
namespace XlsxExport;

use Generator;
use InvalidArgumentException;
use mysqli;
use PDO;
use RuntimeException;

/** PDO 또는 mysqli 연결에서 SQL 결과를 연관 배열 행으로 하나씩 공급한다 */
class QuerySource
{
    /**
     * @param PDO|mysqli $conn   기존에 쓰고 있는 DB 연결
     * @param array      $params 바인딩 파라미터 (사용자 입력은 반드시 여기로 넘길 것)
     */
    public static function rows($conn, string $sql, array $params = []): Generator
    {
        if ($conn instanceof PDO) {
            yield from self::pdoRows($conn, $sql, $params);
        } elseif ($conn instanceof mysqli) {
            yield from self::mysqliRows($conn, $sql, $params);
        } else {
            throw new InvalidArgumentException('PDO 또는 mysqli 연결만 지원합니다.');
        }
    }

    private static function pdoRows(PDO $conn, string $sql, array $params): Generator
    {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('쿼리 준비 실패: ' . implode(' ', $conn->errorInfo()));
        }
        if (!$stmt->execute($params)) {
            throw new RuntimeException('쿼리 실행 실패: ' . implode(' ', $stmt->errorInfo()));
        }
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
        $stmt->closeCursor();
    }

    private static function mysqliRows(mysqli $conn, string $sql, array $params): Generator
    {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('쿼리 준비 실패: ' . $conn->error);
        }
        if ($params) {
            $types = '';
            $values = array_values($params);
            foreach ($values as $p) {
                $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
            }
            $stmt->bind_param($types, ...$values);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('쿼리 실행 실패: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result === false) {
            throw new RuntimeException('결과를 가져올 수 없습니다. SELECT 쿼리인지, mysqlnd가 설치되어 있는지 확인하세요.');
        }
        while (($row = $result->fetch_assoc()) !== null) {
            yield $row;
        }
        $result->free();
        $stmt->close();
    }
}
