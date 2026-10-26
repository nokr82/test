<?php
namespace DbConn;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * 설정 파일(config.php)로 MariaDB 연결을 만든다.
 *
 * 사용 예:
 *   require_once '/경로/db/Db.php';
 *   $conn = \DbConn\Db::connect();                 // 기본: db/config.php
 *   $conn = \DbConn\Db::connect('/다른/설정.php'); // 설정 파일 지정
 */
class Db
{
    /** @return \mysqli|PDO 설정의 driver에 따라 mysqli 또는 PDO */
    public static function connect(?string $configFile = null)
    {
        $file = $configFile ?? __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('DB 설정 파일을 찾을 수 없습니다: ' . $file);
        }
        $c = require $file;
        if (!is_array($c)) {
            throw new RuntimeException('DB 설정 파일은 배열을 return 해야 합니다.');
        }
        $c += ['driver' => 'mysqli', 'host' => '127.0.0.1', 'port' => 3306, 'user' => '', 'pass' => '', 'name' => '', 'charset' => 'utf8mb4'];

        if ($c['driver'] === 'pdo') {
            return self::pdo($c);
        }
        if ($c['driver'] === 'mysqli') {
            return self::mysqli($c);
        }
        throw new RuntimeException("지원하지 않는 driver 입니다: {$c['driver']} (mysqli 또는 pdo)");
    }

    private static function mysqli(array $c): \mysqli
    {
        try {
            $db = @new \mysqli($c['host'], $c['user'], $c['pass'], $c['name'], (int)$c['port']);
        } catch (Throwable $e) {
            throw new RuntimeException('DB 접속 실패: ' . $e->getMessage());
        }
        if ($db->connect_errno) {
            throw new RuntimeException('DB 접속 실패: ' . $db->connect_error);
        }
        $db->set_charset($c['charset']);
        return $db;
    }

    private static function pdo(array $c): PDO
    {
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
        try {
            return new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB 접속 실패: ' . $e->getMessage());
        }
    }
}
