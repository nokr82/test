<?php

namespace App\Models;

use CodeIgniter\Model;

/** 검색 테스트용 게시글 (MySQL es_articles). 원본 데이터는 항상 여기이고, ES 는 복사본이다 */
class ArticleModel extends Model
{
    protected $table = 'es_articles';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['title', 'content', 'category', 'tags', 'author', 'price'];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'title' => 'required|max_length[200]',
        'content' => 'required',
        'category' => 'permit_empty|max_length[50]',
        'tags' => 'permit_empty|max_length[255]',
        'author' => 'permit_empty|max_length[50]',
        'price' => 'permit_empty|integer',
    ];

    protected $validationMessages = [
        'title' => ['required' => '제목을 입력하세요.', 'max_length' => '제목은 200자까지입니다.'],
        'content' => ['required' => '본문을 입력하세요.'],
        'category' => ['max_length' => '분류는 50자까지입니다.'],
        'tags' => ['max_length' => '태그는 255자까지입니다.'],
        'author' => ['max_length' => '작성자는 50자까지입니다.'],
        'price' => ['integer' => '가격은 정수로 입력하세요.'],
    ];

    /** 분류 선택 목록 */
    public function categories(): array
    {
        $rows = $this->builder()->select('category')->distinct()->where('category !=', '')->orderBy('category')->get()->getResultArray();
        return array_column($rows, 'category');
    }
}
