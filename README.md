# 엑셀 목록 (CodeIgniter 4)

DB 테이블 목록 화면(검색, 페이지 이동, 테이블 직접 입력, 헤더 이름 수정)과 엑셀 다운로드.
기존 순수 PHP 버전은 `legacy/`, 이전 Laravel 버전은 `laravel/` 에 보관되어 있다.

## 실행

```bash
composer install
cp env .env               # database.default.* 값을 환경에 맞게 수정
php spark serve           # http://localhost:8080
```

CodeIgniter 4.4.x 기반이라 PHP 7.4 이상에서 동작한다 (운영 PHP 7.4.33 기준. 4.5 부터는 PHP 8.1 이 필요하므로 올리지 말 것).
`intl` / `mbstring` / `zip` / `mysqli` 확장이 필요하다.
웹서버를 쓸 때는 문서 루트를 `public/` 으로 지정하고, `.env` 의 `app.baseURL` 을 실제 주소로 맞춘다.

## 구조

| 위치 | 역할 |
|---|---|
| `app/Config/Routes.php` | 주소 목록 (`/`, `/excel`, `/excel/download`, `/excel/labels`) |
| `app/Config/ListView.php` | 목록 옵션 (테이블, 숨김 컬럼, 페이지당 행 수, 접근 제어 필터) |
| `app/Controllers/ListController.php` | 목록 / 다운로드 / 표시명 저장 |
| `app/Libraries/ListView/ListView.php` | 테이블 선택, 검색, 페이지, 엑셀용 행 공급 |
| `app/Libraries/ListView/LabelStore.php` | 헤더 표시명 저장 (`writable/listview/labels.json`) |
| `app/Libraries/Xlsx/` | 순수 PHP xlsx 작성기 |
| `app/Views/index.php`, `list.php` | 화면 |

## Laravel 버전과 달라진 점

- DB 접속 정보: `DB_*` → `.env` 의 `database.default.*`
- 접근 제어: 미들웨어 → `Config/ListView.php` 의 `filter` (`Config/Filters.php` 의 aliases 에 등록한 필터 이름)
- CSRF: `Config/Filters.php` 에서 전역 `csrf` 필터로 검사
- 쓰기 권한이 필요한 폴더: `storage/` → `writable/`

## 검색엔진 테스트 (`/search`)

MySQL `es_articles` 테이블을 Elasticsearch 7.x(nori 플러그인 필요)에 넣어 검색·등록·수정·삭제를 시험하는 화면.

```sql
CREATE TABLE es_articles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(200) NOT NULL,
    content     TEXT         NOT NULL,
    category    VARCHAR(50)  NOT NULL DEFAULT '',
    tags        VARCHAR(255) NOT NULL DEFAULT '',
    author      VARCHAR(50)  NOT NULL DEFAULT '',
    price       INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_category (category),
    KEY idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

```bash
php spark db:seed ArticleSeeder   # 샘플 50건
```

화면에서 **[전체 재색인]** 을 누르면 인덱스를 만들고 MySQL 데이터를 넣는다. ES 주소는 `.env` 의 `search.host` (PHP `curl` 확장 필요).

| 위치 | 역할 |
|---|---|
| `app/Config/Search.php` | ES 주소, 인덱스 이름, 페이지당 건수, 접근 제어 필터 |
| `app/Controllers/SearchController.php` | 검색 화면 / 1건 조회 / 저장 / 삭제 / 재색인 |
| `app/Models/ArticleModel.php` | MySQL `es_articles` (원본 데이터) |
| `app/Libraries/Search/EsClient.php` | ES REST API 호출 (외부 라이브러리 없음) |
| `app/Libraries/Search/ArticleIndex.php` | nori 매핑, 문서 변환, 검색 쿼리, 재색인 |
| `app/Views/search.php` | 화면 (목록 + 팝업 폼) |
