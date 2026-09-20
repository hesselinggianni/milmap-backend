<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatPrivacyTest extends TestCase
{
    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->withoutMiddleware([
            \App\Http\Middleware\TrackLastSeen::class,
            \App\Http\Middleware\EnsureEmailVerified::class,
            \App\Http\Middleware\EnsureNotViewOnly::class,
        ]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('public_key')->nullable(); $t->text('key_escrow')->nullable();
            $t->string('key_escrow_salt')->nullable(); $t->string('key_escrow_nonce')->nullable();
            $t->integer('key_escrow_ops')->nullable(); $t->integer('key_escrow_mem')->nullable();
            $t->string('key_escrow_alg')->nullable(); $t->text('key_escrow_unlock')->nullable();
            $t->timestamp('key_escrow_updated_at')->nullable(); $t->timestamps();
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('type')->default('direct'); $t->timestamps();
        });
        Schema::create('conversation_user', function (Blueprint $t) {
            $t->id(); $t->uuid('conversation_id'); $t->integer('user_id');
            $t->timestamp('last_read_at')->nullable(); $t->timestamp('muted_until')->nullable();
            $t->timestamp('favorited_at')->nullable(); $t->timestamp('cleared_at')->nullable();
            $t->timestamp('archived_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_06_11_040200_create_user_uploads_table.php'))->up();
        (require database_path('migrations/2026_06_12_030000_add_soft_deletes_to_user_uploads.php'))->up();
        (require database_path('migrations/2026_09_20_100000_scope_chat_uploads_to_conversations.php'))->up();
        DB::table('users')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $this->conversationId]);
        DB::table('conversation_user')->insert([
            ['conversation_id' => $this->conversationId, 'user_id' => 1],
            ['conversation_id' => $this->conversationId, 'user_id' => 2],
        ]);
        Storage::fake('local');
    }

    private function login(int $id): void
    {
        $user = User::findOrFail($id);
        $this->actingAs($user, 'sanctum');
    }

    public function test_encrypted_attachment_is_private_and_conversation_scoped(): void
    {
        $this->login(1);
        $response = $this->postJson('/api/v1/chat/attachments', [
            'conversation_id' => $this->conversationId,
            'encrypted' => true,
            'file' => UploadedFile::fake()->createWithContent('encrypted.bin', random_bytes(128)),
        ])->assertCreated()->assertJsonPath('encrypted', true)->assertJsonMissingPath('filename');

        $upload = DB::table('user_uploads')->first();
        $this->assertSame('local', $upload->disk);
        $this->assertSame($this->conversationId, $upload->conversation_id);
        Storage::disk('local')->assertExists($upload->path);

        $this->getJson($response->json('url'))->assertOk();
        $this->login(2);
        $this->getJson($response->json('url'))->assertOk();
        $this->login(3);
        $this->getJson($response->json('url'))->assertNotFound();
    }

    public function test_upload_rejects_non_participant_and_unencrypted_input(): void
    {
        $this->login(3);
        $payload = [
            'conversation_id' => $this->conversationId,
            'encrypted' => true,
            'file' => UploadedFile::fake()->createWithContent('encrypted.bin', random_bytes(32)),
        ];
        $this->postJson('/api/v1/chat/attachments', $payload)->assertForbidden();
        $this->login(1);
        unset($payload['encrypted']);
        $this->postJson('/api/v1/chat/attachments', $payload)->assertUnprocessable();
    }

    public function test_backend_never_returns_or_accepts_server_unlock_key(): void
    {
        $this->login(1);
        DB::table('users')->where('id', 1)->update([
            'public_key' => 'public', 'key_escrow' => 'ciphertext', 'key_escrow_nonce' => 'nonce',
            'key_escrow_alg' => 'secretbox-acct-v1', 'key_escrow_unlock' => encrypt('legacy-secret'),
        ]);
        $this->login(1);
        $this->getJson('/api/v1/chat/keys/escrow')->assertOk()
            ->assertJsonMissingPath('unlock_key')->assertJsonPath('legacy_server_unlock_present', true);
        $this->putJson('/api/v1/chat/keys/escrow', [
            'public_key' => 'public', 'escrow' => 'ciphertext', 'nonce' => 'nonce',
            'alg' => 'secretbox-acct-v1', 'unlock_key' => 'secret',
        ])->assertUnprocessable();
        $this->deleteJson('/api/v1/chat/keys/escrow/server-unlock')->assertOk();
        $this->assertNull(User::findOrFail(1)->key_escrow_unlock);
    }
}
