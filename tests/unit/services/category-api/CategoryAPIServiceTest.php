<?php

namespace ContAI\Tests\Unit\Services\CategoryAPI;

use PHPUnit\Framework\TestCase;
use ContaiCategoryAPIService;

class CategoryAPIServiceTest extends TestCase {

    /**
     * @dataProvider languageNormalizationProvider
     */
    public function test_normalize_language(string $input, string $expected): void {
        $this->assertSame($expected, ContaiCategoryAPIService::normalizeLanguage($input));
    }

    public static function languageNormalizationProvider(): array {
        return [
            'english to en'          => ['english', 'en'],
            'spanish to es'          => ['spanish', 'es'],
            'en stays en'            => ['en', 'en'],
            'es stays es'            => ['es', 'es'],
            'English uppercase'      => ['English', 'en'],
            'SPANISH all caps'       => ['SPANISH', 'es'],
            'with leading spaces'    => ['  spanish', 'es'],
            'with trailing spaces'   => ['english  ', 'en'],
            'unknown defaults to en' => ['french', 'en'],
            'empty defaults to en'   => ['', 'en'],
        ];
    }

    /**
     * @dataProvider categoryTitleProvider
     */
    public function test_get_category_title(array $category, string $language, string $expected): void {
        $this->assertSame($expected, ContaiCategoryAPIService::getCategoryTitle($category, $language));
    }

    public static function categoryTitleProvider(): array {
        return [
            'english title' => [
                ['title' => ['en' => 'Technology', 'es' => 'Tecnologia']],
                'en',
                'Technology',
            ],
            'spanish title' => [
                ['title' => ['en' => 'Technology', 'es' => 'Tecnologia']],
                'es',
                'Tecnologia',
            ],
            'fallback to english' => [
                ['title' => ['en' => 'Technology']],
                'es',
                'Technology',
            ],
            'fallback to first available' => [
                ['title' => ['fr' => 'Technologie']],
                'en',
                'Technologie',
            ],
            'missing title array' => [
                [],
                'en',
                'Unnamed Category',
            ],
        ];
    }
}
