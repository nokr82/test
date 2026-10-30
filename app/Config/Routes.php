<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// 초기 화면: app/Views/index.php
$routes->view('/', 'index', ['as' => 'home']);

// 접근 제어가 필요하면 Config/ListView.php 의 filter 에 필터 이름을 넣는다
$filter = config('ListView')->filter;
$routes->group('excel', $filter !== '' ? ['filter' => $filter] : [], static function ($routes) {
    $routes->get('/', 'ListController::index', ['as' => 'list.index']);
    $routes->get('download', 'ListController::download', ['as' => 'list.download']);
    $routes->post('labels', 'ListController::saveLabels', ['as' => 'list.labels']);
});

// 검색엔진(Elasticsearch) 테스트. 접근 제어는 Config/Search.php 의 filter
$searchFilter = config('Search')->filter;
$routes->group('search', $searchFilter !== '' ? ['filter' => $searchFilter] : [], static function ($routes) {
    $routes->get('/', 'SearchController::index', ['as' => 'search.index']);
    $routes->get('item/(:num)', 'SearchController::item/$1', ['as' => 'search.item']);
    $routes->post('save', 'SearchController::save', ['as' => 'search.save']);
    $routes->post('delete/(:num)', 'SearchController::delete/$1', ['as' => 'search.delete']);
    $routes->post('reindex', 'SearchController::reindex', ['as' => 'search.reindex']);
});
