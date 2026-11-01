<?php
namespace Tests\Unit;

use App\Console\Commands\ImportDiscoverTemplates;
use PHPUnit\Framework\TestCase;

class DiscoverTemplateTest extends TestCase
{
    private function data(array $ways): array
    {
        $elements = [];
        foreach ([1,2,3,4,5] as $n) $elements[] = ['type'=>'node','id'=>$n,'lat'=>52+$n/1000,'lon'=>5];
        foreach ($ways as $id=>$nodes) $elements[] = ['type'=>'way','id'=>$id,'nodes'=>$nodes];
        $elements[] = ['type'=>'relation','id'=>99,'tags'=>['name'=>'Test','route'=>'hiking'],'members'=>array_map(fn($id)=>['type'=>'way','ref'=>$id,'role'=>''],array_keys($ways))];
        return ['elements'=>$elements];
    }

    public function test_reversed_ways_are_joined_without_extra_connectors(): void
    {
        $route = ImportDiscoverTemplates::parse($this->data([10=>[1,2],11=>[3,2],12=>[3,4]]),'99');
        self::assertSame([[52.001,5.0],[52.002,5.0],[52.003,5.0],[52.004,5.0]],$route['line']);
    }

    public function test_disconnected_ways_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        ImportDiscoverTemplates::parse($this->data([10=>[1,2],11=>[4,5]]),'99');
    }
}
