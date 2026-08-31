<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Article;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    /** @var class-string<Article> */
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(4), '.'),
        ];
    }
}
