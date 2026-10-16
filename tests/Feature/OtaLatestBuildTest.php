<?php
namespace Tests\Feature;
use App\Models\OtaBuild;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;
class OtaLatestBuildTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);DB::purge('sqlite');
        Schema::create('ota_builds',function(Blueprint $t){$t->id();$t->string('version');$t->string('platform');$t->string('channel');$t->string('bundle_path');$t->string('checksum');$t->integer('size');$t->string('min_native_version')->nullable();$t->boolean('active');$t->boolean('mandatory');$t->timestamps();});
    }
    private function build(string $version,array $extra=[]): void { OtaBuild::create(array_merge(['version'=>$version,'platform'=>'all','channel'=>'production','bundle_path'=>'ota/all/test.zip','checksum'=>str_repeat('a',64),'size'=>10,'active'=>true,'mandatory'=>false],$extra)); }
    private function check(array $body=[]) { return $this->postJson('/api/v1/ota/check',array_merge(['platform'=>'ios','version_build'=>'1.38','version_name'=>'1.38+20261016000100'],$body)); }
    public function test_old_device_jumps_directly_to_latest_and_then_receives_no_update(): void {
        foreach(['1.38+20261016000200','1.38+20261016000300','1.38+20261016000400'] as $v)$this->build($v);
        $this->check()->assertOk()->assertJsonPath('version','1.38+20261016000400');
        $this->check(['version_name'=>'1.38+20261016000400'])->assertOk()->assertJsonPath('message','No update available');
    }
    public function test_late_upload_never_downgrades_or_hides_the_latest_version(): void {
        $this->build('1.38+20261016000400');$this->build('1.38+20261016000200');
        $this->check()->assertJsonPath('version','1.38+20261016000400');
        $this->check(['version_name'=>'1.39.0'])->assertJsonPath('message','No update available');
    }
    public function test_platform_channel_active_and_native_compatibility_are_respected(): void {
        $this->build('1.38+20261016000400');
        $this->build('1.38+20261016000500',['platform'=>'android']);
        $this->build('1.38+20261016000600',['channel'=>'beta']);
        $this->build('1.38+20261016000700',['active'=>false]);
        $this->build('1.39+20261016000800',['min_native_version'=>'1.39']);
        $this->check()->assertJsonPath('version','1.38+20261016000400');
        $this->check(['version_build'=>'1.39','version_name'=>'1.39'])->assertJsonPath('version','1.39+20261016000800');
        $this->check(['channel'=>null,'defaultChannel'=>'beta'])->assertJsonPath('version','1.38+20261016000600');
    }
    public function test_desktop_fallback_and_numeric_ordering(): void {
        $this->build('1.9+20261016000500');$this->build('1.10+20261016000400');
        $this->check(['platform'=>'electron','version_name'=>null,'version_build'=>'1.8.0'])->assertJsonPath('version','1.10+20261016000400');
        $this->check(['version_name'=>'unknown'])->assertJsonPath('message','No update available');
    }
}
