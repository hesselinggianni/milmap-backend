<?php

namespace Tests\Feature;

use App\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_post_preserves_platforms_and_publication_statuses(): void
    {
        $post = SocialPost::create([
            'title' => 'New Post',
            'description' => 'Content of the new post',
            'platforms' => ['pinterest', 'instagram'],
            'statuses' => ['pinterest' => ['state' => 'manual']],
        ])->fresh();

        $this->assertSame('New Post', $post->title);
        $this->assertSame(['pinterest', 'instagram'], $post->platforms);
        $this->assertSame(['pinterest' => ['state' => 'manual']], $post->statuses);
    }
}
