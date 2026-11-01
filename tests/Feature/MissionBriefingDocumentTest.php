<?php
namespace Tests\Feature;
use App\Models\Mission;
use App\Models\MissionCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
class MissionBriefingDocumentTest extends TestCase {
    use RefreshDatabase;
    private User $owner;
    private Mission $mission;
    protected function setUp(): void {
        parent::setUp(); config(['briefing_builder.enabled' => true]);
        $this->owner = $this->user();
        $this->mission = Mission::create(['owner_id' => $this->owner->id, 'name' => 'Testmissie', 'ogroup' => ['situation' => ['enemy' => 'Vijand'], 'presentation' => ['custom_pages' => [['id' => 'a', 'text' => 'Origineel']]]]]);
    }
    private function user(): User {
        return User::create(['first_name' => 'Briefing', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDays(3)]);
    }
    private function as(User $user): static {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }
    private function url(): string { return '/api/v1/missions/'.$this->mission->id.'/briefing-documents'; }
    private function payload(int $revision = 0): array {
        return ['title' => 'Geheime briefing', 'revision' => $revision, 'document' => ['version' => 1, 'sections' => [['id' => 's1','title' => 'Situatie','notes' => 'Notities','blocks' => [['id' => 'b1','type' => 'text','text' => '<b>Geheime inhoud</b>']]]]]];
    }
    public function test_roundtrip_encryption_conflicts_and_original_preservation(): void {
        $url = $this->url().'/'.Str::uuid(); $og = $this->mission->ogroup;
        $this->as($this->owner)->putJson($url,$this->payload())->assertOk()->assertJsonPath('data.revision',1)->assertJsonPath('data.document.sections.0.blocks.0.text','Geheime inhoud');
        $raw = DB::table('mission_briefing_documents')->first();
        $this->assertStringNotContainsString('Geheime', $raw->document);
        $this->assertStringNotContainsString('Geheime', $raw->title);
        $this->as($this->owner)->putJson($url,$this->payload())->assertStatus(409)->assertJsonPath('current.revision',1);
        $this->as($this->owner)->putJson($url,$this->payload(1))->assertOk()->assertJsonPath('data.revision',2);
        $this->as($this->owner)->getJson($url)->assertOk()->assertJsonPath('data.title','Geheime briefing');
        $this->assertSame($og,$this->mission->fresh()->ogroup);
    }
    public function test_feature_flag_permissions_and_lock(): void {
        $url = $this->url().'/'.Str::uuid(); $viewer = $this->user();
        MissionCollaborator::create(['mission_id'=>$this->mission->id,'user_id'=>$viewer->id,'role'=>'viewer','status'=>'accepted','added_by'=>$this->owner->id]);
        $this->as($viewer)->putJson($url,$this->payload())->assertForbidden();
        $this->as($this->user())->getJson($this->url())->assertForbidden();
        $this->mission->update(['locked'=>true]);
        $this->as($this->owner)->putJson($url,$this->payload())->assertStatus(423);
        config(['briefing_builder.enabled'=>false]);
        $this->as($this->owner)->getJson($this->url())->assertNotFound();
    }
    public function test_invalid_blocks_and_active_media_urls_are_rejected(): void {
        $payload=$this->payload(); $url=$this->url().'/'.Str::uuid();
        $payload['document']['sections'][0]['blocks'][0]['type']='html';
        $this->as($this->owner)->putJson($url,$payload)->assertUnprocessable();
        $payload=$this->payload();
        foreach (['javascript:alert(1)','data:image/svg+xml;base64,PHN2Zz4='] as $bad) {
            $payload['document']['sections'][0]['blocks'][0]['url']=$bad;
            $this->as($this->owner)->putJson($url,$payload)->assertUnprocessable();
        }
    }
    public function test_sources_read_real_smeac_and_custom_phases(): void {
        $og=$this->mission->ogroup;
        $og['template']=['phases'=>[['key'=>'insertion','label'=>'Infiltratie','fields'=>[['key'=>'method','label'=>'Methode']],'subphases'=>[]]]];
        $og['values']=['insertion'=>['fields'=>['method'=>'Te voet']]];
        $this->mission->update(['ogroup'=>$og]);
        $sources=$this->as($this->owner)->getJson($this->url())->assertOk()->json('data.sources');
        $phase=collect($sources)->firstWhere('key','phase:insertion');
        $this->assertSame('Te voet',$phase['value']['Methode']); $this->assertNotEmpty($phase['hash']);
    }
    public function test_private_media_is_encrypted_and_mission_scoped(): void {
        Storage::fake('local');
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0ZkAAAAASUVORK5CYII=');
        $file=UploadedFile::fake()->createWithContent('map.png',$png);
        $base='/api/v1/missions/'.$this->mission->id.'/briefing-media';
        $id=$this->as($this->owner)->post($base,['file'=>$file],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $raw=DB::table('mission_briefing_media')->where('id',$id)->first();
        $this->assertNotSame($png,Storage::disk('local')->get($raw->path));
        $this->as($this->owner)->get($base.'/'.$id)->assertOk()->assertContent($png);
        $this->as($this->user())->get($base.'/'.$id)->assertForbidden();
        $other=Mission::create(['owner_id'=>$this->owner->id,'name'=>'Andere missie']);
        $this->as($this->owner)->get('/api/v1/missions/'.$other->id.'/briefing-media/'.$id)->assertNotFound();
        $payload=$this->payload(); $payload['document']['sections'][0]['blocks'][0]['media_id']=$id;
        $this->as($this->owner)->putJson('/api/v1/missions/'.$other->id.'/briefing-documents/'.Str::uuid(),$payload)->assertUnprocessable();
    }
    public function test_legacy_image_import_is_private_and_keeps_original(): void {
        Storage::fake('local'); Storage::fake('public');
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0ZkAAAAASUVORK5CYII=');
        $og=$this->mission->ogroup; $og['presentation']['custom_pages'][0]['image']='data:image/png;base64,'.base64_encode($png);
        $this->mission->update(['ogroup'=>$og]);
        $source=$this->as($this->owner)->getJson($this->url())->assertOk()->json('data.legacy_media.0');
        $this->assertArrayNotHasKey('source',$source);
        $base='/api/v1/missions/'.$this->mission->id.'/briefing-media';
        $id=$this->as($this->owner)->postJson($base.'/import',['source_id'=>$source['id']])->assertCreated()->json('data.id');
        $this->as($this->owner)->get($base.'/'.$id)->assertOk()->assertContent($png);
        $this->assertSame($og,$this->mission->fresh()->ogroup);
        $this->as($this->owner)->postJson($base.'/import',['source_id'=>str_repeat('a',64)])->assertNotFound();
    }

}
