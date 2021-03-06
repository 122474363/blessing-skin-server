<?php

namespace Tests;

use App\Models\Texture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ClosetControllerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var User
     */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = factory(User::class)->create();
        $this->actingAs($this->user);
    }

    public function testIndex()
    {
        $filter = Fakes\Filter::fake();

        $this->get('/user/closet')->assertViewIs('user.closet');
        $filter->assertApplied('grid:user.closet');
    }

    public function testGetClosetData()
    {
        $textures = factory(Texture::class, 10)->create();
        $textures->each(function ($t) {
            $this->user->closet()->attach($t->tid, ['item_name' => $t->name]);
        });

        // Use default query parameters
        $this->getJson('/user/closet/list')
            ->assertJsonStructure([
                'data' => [['tid', 'name', 'type']],
            ]);

        // Get capes
        $cape = factory(Texture::class)->states('cape')->create();
        $this->user->closet()->attach($cape->tid, ['item_name' => 'custom_name']);
        $this->getJson('/user/closet/list?category=cape')
            ->assertJson(['data' => [[
                    'tid' => $cape->tid,
                    'type' => 'cape',
                    'pivot' => ['item_name' => 'custom_name'],
                ],
            ]]);

        // Search by keyword
        $random = $textures->random();
        $this->getJson('/user/closet/list?q='.$random->name)
            ->assertJson(['data' => [[
                    'tid' => $random->tid,
                    'name' => $random->name,
                    'type' => $random->type,
                ],
            ]]);
    }

    public function testAllIds()
    {
        $texture = factory(Texture::class)->create();
        $user = factory(User::class)->create();
        $user->closet()->attach($texture->tid, ['item_name' => '']);

        $this->actingAs($user)
            ->getJson(route('user.closet.ids'))
            ->assertJson([$texture->tid]);
    }

    public function testAdd()
    {
        $uploader = factory(User::class)->create(['score' => 0]);
        $texture = factory(Texture::class)->create(['uploader' => $uploader->uid]);
        $likes = $texture->likes;
        $name = 'my';
        option(['score_per_closet_item' => 10]);

        // Missing `tid` field
        $this->postJson('/user/closet/add')->assertJsonValidationErrors('tid');

        // `tid` is not a integer
        $this->postJson(
            '/user/closet/add',
            ['tid' => 'string']
        )->assertJsonValidationErrors('tid');

        // Missing `name` field
        $this->postJson(
            '/user/closet/add',
            ['tid' => 0]
        )->assertJsonValidationErrors('name');

        // The user doesn't have enough score to add a texture
        $this->user->score = 0;
        $this->user->save();
        $this->postJson(
            '/user/closet/add',
            ['tid' => $texture->tid, 'name' => $name]
        )->assertJson([
            'code' => 7,
            'message' => trans('user.closet.add.lack-score'),
        ]);

        // Add a not-existed texture
        $this->user->score = 100;
        $this->user->save();
        $this->postJson(
            '/user/closet/add',
            ['tid' => -1, 'name' => 'my']
        )->assertJson([
            'code' => 1,
            'message' => trans('user.closet.add.not-found'),
        ]);

        // Texture is private
        option(['score_award_per_like' => 5]);
        $privateTexture = factory(Texture::class)->create([
            'public' => false,
            'uploader' => $uploader->uid + 1,
        ]);
        $this->postJson(
            '/user/closet/add',
            ['tid' => $privateTexture->tid, 'name' => $name]
        )->assertJson([
            'code' => 1,
            'message' => trans('skinlib.show.private'),
        ]);

        // Administrator can add it.
        $privateTexture = factory(Texture::class)->state('private')->create([
            'uploader' => 0,
        ]);
        $this->actingAs(factory(User::class)->state('admin')->create())
            ->postJson(
                '/user/closet/add',
                ['tid' => $privateTexture->tid, 'name' => $name]
            )->assertJson([
                'code' => 0,
                'message' => trans('user.closet.add.success', ['name' => $name]),
            ]);

        // Add a texture successfully
        $this->actingAs($this->user)
            ->postJson(
                '/user/closet/add',
                ['tid' => $texture->tid, 'name' => $name]
            )->assertJson([
                'code' => 0,
                'message' => trans('user.closet.add.success', ['name' => $name]),
            ]);
        $this->assertEquals($likes + 1, Texture::find($texture->tid)->likes);
        $this->user = User::find($this->user->uid);
        $this->assertEquals(90, $this->user->score);
        $this->assertEquals(1, $this->user->closet()->count());
        $uploader->refresh();
        $this->assertEquals(5, $uploader->score);

        // If the texture is duplicated, should be warned
        $this->postJson(
            '/user/closet/add',
            ['tid' => $texture->tid, 'name' => $name]
        )->assertJson([
            'code' => 1,
            'message' => trans('user.closet.add.repeated'),
        ]);
    }

    public function testRename()
    {
        $texture = factory(Texture::class)->create();
        $name = 'new';

        // Missing `name` field
        $this->postJson('/user/closet/rename/0')->assertJsonValidationErrors('name');

        // Rename a not-existed texture
        $this->postJson('/user/closet/rename/-1', ['name' => $name])
            ->assertJson([
                'code' => 1,
                'message' => trans('user.closet.remove.non-existent'),
            ]);

        // Rename a closet item successfully
        $this->user->closet()->attach($texture->tid, ['item_name' => 'name']);
        $this->postJson('/user/closet/rename/'.$texture->tid, ['name' => $name])
            ->assertJson([
                'code' => 0,
                'message' => trans('user.closet.rename.success', ['name' => $name]),
            ]);
        $this->assertEquals(1, $this->user->closet()->where('item_name', $name)->count());
    }

    public function testRemove()
    {
        $uploader = factory(User::class)->create(['score' => 5]);
        $texture = factory(Texture::class)->create(['uploader' => $uploader->uid]);
        $likes = $texture->likes;

        // Rename a not-existed texture
        $this->postJson('/user/closet/remove/-1')
            ->assertJson([
                'code' => 1,
                'message' => trans('user.closet.remove.non-existent'),
            ]);

        // Should return score if `return_score` is true
        option(['score_award_per_like' => 5]);
        $this->user->closet()->attach($texture->tid, ['item_name' => 'name']);
        $score = $this->user->score;
        $this->postJson('/user/closet/remove/'.$texture->tid)
            ->assertJson([
                'code' => 0,
                'message' => trans('user.closet.remove.success'),
            ]);
        $this->assertEquals($likes - 1, Texture::find($texture->tid)->likes);
        $this->assertEquals($score + option('score_per_closet_item'), $this->user->score);
        $this->assertEquals(0, $this->user->closet()->count());
        $uploader->refresh();
        $this->assertEquals(0, $uploader->score);

        $texture = Texture::find($texture->tid);
        $likes = $texture->likes;
        // Should not return score if `return_score` is false
        option(['return_score' => false]);
        $this->user->closet()->attach($texture->tid, ['item_name' => 'name']);
        $score = $this->user->score;
        $this->postJson('/user/closet/remove/'.$texture->tid)->assertJson(['code' => 0]);
        $this->assertEquals($likes - 1, Texture::find($texture->tid)->likes);
        $this->assertEquals($score, $this->user->score);
        $this->assertEquals(0, $this->user->closet()->count());
    }
}
