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
