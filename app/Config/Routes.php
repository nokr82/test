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
