<?php

namespace Tests;

use Illuminate\Support\Str;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use App\Services\Translations\JavaScript;

class JavaScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(JavaScript::class);
    }

    public function testGenerateFreshFile()
    {
        $this->mock(Filesystem::class, function ($mock) {
            $mock->shouldReceive('lastModified')
                ->with(resource_path('lang/en/front-end.yml'))
                ->once()
                ->andReturn(1);
            $mock->shouldReceive('put')
                ->withArgs(function ($path, $content) {
                    $this->assertEquals(public_path('lang/en.js'), $path);
                    $this->assertTrue(Str::startsWith($content, 'blessing.i18n'));
                    return true;
                })
                ->once()
                ->andReturn(1);
        });
        $this->mock(Repository::class, function ($mock) {
            $mock->shouldReceive('get')
                ->with('front-end-trans-en', 0)
                ->once()
                ->andReturn(0);
            $mock->shouldReceive('put')
                ->with('front-end-trans-en', 1)
                ->once();
        });

        $this->assertEquals(url('lang/en.js?t=1'), resolve(JavaScript::class)->generate('en'));
    }

    public function testGenerateCached()
    {
        $this->mock(Filesystem::class, function ($mock) {
            $mock->shouldReceive('lastModified')
                ->with(resource_path('lang/en/front-end.yml'))
                ->once()
                ->andReturn(1);
            $mock->shouldReceive('exists')
                ->with(public_path('lang/en.js'))
                ->once()
                ->andReturn(true);
        });
        $this->mock(Repository::class, function ($mock) {
            $mock->shouldReceive('get')
                ->with('front-end-trans-en', 0)
                ->once()
                ->andReturn(1);
        });

        $this->assertEquals(url('lang/en.js?t=1'), resolve(JavaScript::class)->generate('en'));
    }

    public function testPlugin()
    {
        $this->mock(Filesystem::class, function ($mock) {
            $mock->shouldReceive('exists')
                ->with(public_path('lang/en_plugin.js'))
                ->twice()
                ->andReturn(false, true);
            $mock->shouldReceive('lastModified')
                ->with(public_path('lang/en_plugin.js'))
                ->once()
                ->andReturn(1);
        });

        $this->assertEquals('', resolve(JavaScript::class)->plugin('en'));

        $this->assertEquals(
            url('lang/en_plugin.js?t=1'),
            resolve(JavaScript::class)->plugin('en')
        );
    }
}
