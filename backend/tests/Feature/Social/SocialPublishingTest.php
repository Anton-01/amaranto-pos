<?php

namespace Tests\Feature\Social;

use App\Models\MediaFile;
use App\Models\MediaShareLink;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialPublishingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * The publishing module's design guarantees, pinned.
 *
 * What is asserted here is not "the happy path works" but the four decisions
 * the module was built around, each of which is invisible until it breaks:
 * tokens are unreadable at rest and never leave through the API, one failing
 * network never costs another one its publication, Meta's own diagnosis
 * survives into the log, and no attempt is ever left without an outcome.
 *
 * Graph is faked at the HTTP layer rather than mocked at the service boundary,
 * so the real client, job, models and share-link machinery all execute.
 */
class SocialPublishingTest extends TestCase
{
    use DatabaseMigrations;
    use RequiresPostgres;

    protected $dropTypes = true;

    private const TOKEN = 'EAAGtest-token-that-should-never-be-readable';

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPostgresAvailable();

        // The Instagram poll is exercised for its logic, not for its latency.
        config(['social.instagram.container_poll_seconds' => 0]);
    }

    public function test_the_access_token_is_unreadable_at_rest_and_never_leaves_through_the_api(): void
    {
        $account = $this->account('facebook');

        $stored = DB::table('social_accounts')->where('id', $account->id)->value('access_token');

        $this->assertNotSame(self::TOKEN, $stored, 'El token viajó a la base en claro.');
        $this->assertStringNotContainsString('EAAGtest', (string) $stored);
        $this->assertSame(self::TOKEN, $account->fresh()->access_token, 'El cast no lo descifra de vuelta.');

        // The serialized model is what a controller would return.
        $serialized = $account->toArray();
        $this->assertArrayNotHasKey('access_token', $serialized);
        $this->assertTrue($serialized['has_access_token']);

        $response = $this->actingAs($this->admin())->getJson('/api/social/accounts');

        $response->assertOk();
        $this->assertStringNotContainsString('EAAGtest', $response->getContent());
    }

    public function test_publishing_answers_202_and_leaves_one_pending_row_per_channel(): void
    {
        Http::fake(['*' => Http::response(['id' => '1'], 200)]);

        $file = $this->image();
        $this->account('facebook');
        $this->account('instagram');

        // The queue must not run inside the request: what the caller sees is
        // the accepted state, not the outcome.
        config(['queue.default' => 'null']);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/social/publish/{$file->id}", [
                'caption' => 'Promoción del día',
                'channels' => ['facebook', 'instagram'],
            ]);

        $response->assertStatus(202);

        $rows = SocialPost::where('media_file_id', $file->id)->get();

        $this->assertCount(2, $rows, 'Debe haber una fila por canal, no una por envío.');
        $this->assertCount(1, $rows->pluck('batch_id')->unique(), 'Las filas del mismo envío comparten batch_id.');
        $this->assertTrue($rows->every(fn (SocialPost $row) => $row->status === SocialPost::STATUS_PENDING));
    }

    public function test_a_failing_network_does_not_cost_the_other_its_publication(): void
    {
        $file = $this->image();
        $this->account('facebook', ['page_id' => '111']);
        $this->account('instagram', ['ig_user_id' => '999']);

        Http::fake([
            '*/111/photos' => Http::response(['id' => '5', 'post_id' => '111_5']),
            '*/999/media' => Http::response([
                'error' => [
                    'message' => 'The image URL is not accessible.',
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_subcode' => 2207003,
                ],
            ], 400),
        ]);

        $rows = $this->publish($file, ['facebook', 'instagram']);

        $this->assertSame(SocialPost::STATUS_SUCCESS, $rows['facebook']->status);
        $this->assertSame(SocialPost::STATUS_FAILED, $rows['instagram']->status);

        // Facebook's own id must be the addressable post, not the photo node.
        $this->assertSame('111_5', $rows['facebook']->api_response_id);

        // Meta's diagnosis survives intact: the code IS the repair instruction.
        $this->assertSame('The image URL is not accessible.', $rows['instagram']->error_message);
        $this->assertSame('100/2207003', $rows['instagram']->error_code);
    }

    public function test_instagram_waits_for_the_container_and_gives_up_on_a_bounded_budget(): void
    {
        $file = $this->image();
        $this->account('instagram', ['ig_user_id' => '999']);

        // FINISHED only on the second look: publishing on the first would be
        // the race the poll exists to prevent.
        Http::fake([
            '*/999/media' => Http::response(['id' => 'container-1']),
            '*/container-1*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
            '*/999/media_publish' => Http::response(['id' => 'ig-9']),
        ]);

        $rows = $this->publish($file, ['instagram']);

        $this->assertSame(SocialPost::STATUS_SUCCESS, $rows['instagram']->status);
        $this->assertSame('ig-9', $rows['instagram']->api_response_id);
        $this->assertSame('container-1', $rows['instagram']->metadata['creation_id']);
    }

    public function test_a_container_that_never_finishes_fails_instead_of_pinning_a_worker(): void
    {
        // The usual cause is an image URL Meta's crawler cannot reach, and an
        // unbounded wait would hold a queue worker for as long as that lasts.
        config(['social.instagram.container_poll_attempts' => 3]);

        $file = $this->image();
        $this->account('instagram', ['ig_user_id' => '999']);

        Http::fake([
            '*/999/media' => Http::response(['id' => 'container-2']),
            '*/container-2*' => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        $rows = $this->publish($file, ['instagram']);

        $this->assertSame(SocialPost::STATUS_FAILED, $rows['instagram']->status);
        $this->assertStringContainsString('3 revisiones', $rows['instagram']->error_message);
        $this->assertStringContainsString('IN_PROGRESS', $rows['instagram']->error_message);

        // It never reached media_publish: giving up is not publishing blind.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'media_publish'));
    }

    public function test_the_image_is_exposed_through_one_short_lived_view_only_share_link(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'post_id' => '1_1'])]);

        $file = $this->image();
        $this->account('facebook', ['page_id' => '111']);
        $this->account('instagram', ['ig_user_id' => '999']);

        Http::fake([
            '*/111/photos' => Http::response(['id' => '1', 'post_id' => '1_1']),
            '*/999/media' => Http::response(['id' => 'c1']),
            '*/c1*' => Http::response(['status_code' => 'FINISHED']),
            '*/999/media_publish' => Http::response(['id' => 'ig-1']),
        ]);

        $this->publish($file, ['facebook', 'instagram']);

        $links = MediaShareLink::where('media_file_id', $file->id)->get();

        // Two channels, one link: they need the same picture, and one row is
        // one thing to revoke if the exposure has to be closed by hand.
        $this->assertCount(1, $links);
        $this->assertSame(MediaShareLink::PERMISSION_VIEW, $links->first()->permission);
        $this->assertNull($links->first()->max_downloads);
        $this->assertTrue($links->first()->expires_at->lessThanOrEqualTo(now()->addHours(2)));

        // Drive was never asked to make the object public.
        $this->assertSame(MediaFile::VISIBILITY_PRIVATE, $file->fresh()->visibility);
    }

    public function test_a_channel_without_usable_credentials_fails_naming_what_is_missing(): void
    {
        $file = $this->image();

        // Nothing configured at all.
        $rows = $this->publish($file, ['facebook']);
        $this->assertSame(SocialPost::STATUS_FAILED, $rows['facebook']->status);
        $this->assertStringContainsString('No hay una conexión activa', $rows['facebook']->error_message);

        // Configured, but half of it.
        SocialPost::query()->delete();
        $this->account('instagram', ['ig_user_id' => null]);

        $rows = $this->publish($file, ['instagram']);
        $this->assertSame(SocialPost::STATUS_FAILED, $rows['instagram']->status);
        $this->assertStringContainsString('ID de la cuenta de Instagram Business', $rows['instagram']->error_message);
    }

    public function test_no_attempt_is_ever_left_without_an_outcome(): void
    {
        Http::fake(['*' => Http::response(['id' => '1'])]);

        $this->account('facebook', ['page_id' => '111']);

        // The image disappears between the click and the worker.
        $file = $this->image();
        $batch = (string) \Illuminate\Support\Str::uuid();

        SocialPost::create([
            'batch_id' => $batch,
            'media_file_id' => $file->id,
            'provider' => 'facebook',
            'status' => SocialPost::STATUS_PENDING,
            'caption' => 'x',
            'created_by' => $this->admin()->id,
        ]);

        dispatch(new \App\Jobs\PublishSocialMediaPost(
            mediaFileId: (string) \Illuminate\Support\Str::uuid(),
            caption: 'x',
            providers: ['facebook'],
            batchId: $batch,
            actorId: $this->admin()->id,
        ));

        $this->assertSame(SocialPost::STATUS_FAILED, SocialPost::where('batch_id', $batch)->first()->status);
        $this->assertSame(0, SocialPost::whereIn('status', ['pending', 'publishing'])->count());
    }

    public function test_the_caption_is_validated_against_the_strictest_selected_network(): void
    {
        $file = $this->image();
        $this->account('facebook', ['page_id' => '111']);
        $this->account('instagram', ['ig_user_id' => '999']);

        Http::fake(['*' => Http::response(['id' => '1', 'post_id' => '1_1'])]);

        $long = str_repeat('a', 2500);

        // Instagram's 2 200 ceiling rejects it…
        $this->actingAs($this->admin())
            ->postJson("/api/social/publish/{$file->id}", ['caption' => $long, 'channels' => ['instagram']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('caption');

        // …while Facebook's 5 000 accepts the very same text.
        $this->actingAs($this->admin())
            ->postJson("/api/social/publish/{$file->id}", ['caption' => $long, 'channels' => ['facebook']])
            ->assertStatus(202);
    }

    public function test_only_active_images_can_be_published(): void
    {
        $admin = $this->admin();

        $document = MediaFile::create($this->fileAttributes([
            'name' => 'Manual',
            'original_name' => 'manual.pdf',
            'storage_name' => 'manual.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'category' => 'document',
        ]));

        $this->actingAs($admin)
            ->postJson("/api/social/publish/{$document->id}", ['caption' => 'x', 'channels' => ['facebook']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_SOCIAL_NOT_AN_IMAGE');

        $archived = $this->image();
        $archived->update(['is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/social/publish/{$archived->id}", ['caption' => 'x', 'channels' => ['facebook']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_SOCIAL_FILE_ARCHIVED');
    }

    public function test_whatsapp_is_published_as_a_simulation_and_says_so(): void
    {
        $file = $this->image();
        $this->account('whatsapp', ['phone_number_id' => '555']);

        // No HTTP fake: the mock must not touch the network at all.
        Http::preventStrayRequests();

        $rows = $this->publish($file, ['whatsapp']);

        $this->assertSame(SocialPost::STATUS_SUCCESS, $rows['whatsapp']->status);
        $this->assertStringStartsWith('wa_mock_', $rows['whatsapp']->api_response_id);
        $this->assertTrue($rows['whatsapp']->metadata['simulated']);

        // And the composer's catalog labels it, so no operator believes a
        // status went out that never left the server.
        $channels = $this->actingAs($this->admin())->getJson('/api/social/catalogs')->json('data.channels');
        $whatsapp = collect($channels)->firstWhere('provider', 'whatsapp');

        $this->assertTrue($whatsapp['simulated']);
    }

    // --- Helpers ------------------------------------------------------------

    /** @return array<string, SocialPost> */
    private function publish(MediaFile $file, array $channels): array
    {
        app(SocialPublishingService::class)->queue($file, $this->admin(), 'Texto de prueba', $channels);

        return SocialPost::where('media_file_id', $file->id)->get()->keyBy('provider')->all();
    }

    private function account(string $provider, array $overrides = []): SocialAccount
    {
        $defaults = match ($provider) {
            'facebook' => ['page_id' => '111'],
            'instagram' => ['ig_user_id' => '999'],
            default => ['phone_number_id' => '555'],
        };

        return SocialAccount::create(array_merge([
            'provider' => $provider,
            'label' => 'Cuenta de prueba '.$provider,
            'access_token' => self::TOKEN,
            'is_active' => true,
        ], $defaults, $overrides));
    }

    private function image(): MediaFile
    {
        return MediaFile::create($this->fileAttributes());
    }

    private function fileAttributes(array $overrides = []): array
    {
        return array_merge([
            'drive_file_id' => 'drive-'.uniqid(),
            'name' => 'Promoción',
            'original_name' => 'promo.jpg',
            'storage_name' => 'promo.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'category' => 'image',
            'size_bytes' => 204800,
            'width' => 1080,
            'height' => 1080,
            'visibility' => MediaFile::VISIBILITY_PRIVATE,
            'is_active' => true,
            'uploaded_by' => $this->admin()->id,
        ], $overrides);
    }

    private function admin(): User
    {
        static $admin = null;

        if ($admin && User::find($admin->id)) {
            return $admin;
        }

        $role = Role::firstOrCreate(['name' => 'admin'], ['description' => 'admin']);

        $admin = User::create([
            'name' => 'Admin de prueba',
            'email' => 'social-admin@cronos.pos',
            'password' => bcrypt('secret1234'),
            'status' => 'active',
        ]);

        DB::table('model_has_roles')->insert([
            'model_id' => $admin->id,
            'role_id' => $role->id,
        ]);

        return $admin->fresh();
    }
}
