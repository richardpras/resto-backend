<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesQrGuestSession;
use Tests\TestCase;

class QrGuestSessionTest extends TestCase
{
    use CreatesQrGuestSession;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_resolve_creates_guest_session(): void
    {
        $ctx = $this->seedQrGuestOrderingContext();

        $this->getJson('/api/v1/qr/tables/'.$ctx['qrPublicId'], [
            'X-Qr-Guest-Session' => $ctx['guestSessionToken'],
        ])
            ->assertOk()
            ->assertJsonPath('data.guestSession.token', $ctx['guestSessionToken']);
    }

    public function test_submit_requires_guest_session_and_qr_public_id(): void
    {
        $ctx = $this->seedQrGuestOrderingContext();

        $this->postJson('/api/v1/qr-orders', [
            'outletId' => $ctx['outlet']->id,
            'tableId' => $ctx['table']->id,
            'customerName' => 'Guest',
            'items' => [['menuItemId' => $ctx['menuItem']->id, 'qty' => 1]],
        ])->assertStatus(422);

        $this->postQrOrderRequest($ctx)->assertCreated();
    }

    public function test_guest_session_orders_list_is_scoped(): void
    {
        $ctxA = $this->seedQrGuestOrderingContext();
        $codeA = (string) $this->postQrOrderRequest($ctxA)->json('data.requestCode');

        $resolveB = $this->getJson('/api/v1/qr/tables/'.$ctxA['qrPublicId'])->assertOk();
        $tokenB = (string) $resolveB->json('data.guestSession.token');
        $this->assertNotSame($ctxA['guestSessionToken'], $tokenB);

        $ctxB = array_merge($ctxA, ['guestSessionToken' => $tokenB]);
        $this->postQrOrderRequest($ctxB)->assertCreated();

        $listA = $this->getJson('/api/v1/public/qr-guest-sessions/'.$ctxA['guestSessionToken'].'/orders')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listA);
        $this->assertSame($codeA, $listA[0]['orderCode']);
    }

    public function test_pending_orders_expire_via_command(): void
    {
        $ctx = $this->seedQrGuestOrderingContext();
        $this->postQrOrderRequest($ctx)->assertCreated();

        $request = QrOrderRequest::query()->first();
        $this->assertNotNull($request);
        $request->update(['expires_at' => now()->subMinute()]);

        $this->artisan('qr-orders:expire-pending')->assertSuccessful();

        $this->assertSame('expired', (string) $request->fresh()->status);
    }
}
