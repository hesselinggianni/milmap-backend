<?php

namespace Tests\Unit\Services;

use App\Models\Post;
use App\Services\PostService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PostServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $postService;

    public function setUp(): void
    {
        parent::setUp();
        $this->postService = new PostService();
    }

    public function testStorePost()
    {
        $data = ['title' => 'New Post', 'content' => 'Content of the new post'];
        $post = $this->postService->storePost($data);

        $this->assertInstanceOf(Post::class, $post);
        $this->assertEquals('New Post', $post->title);
    }
}
