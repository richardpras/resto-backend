<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Modules\Payments\Http\Resources\PaymentTransactionResource;
use App\Modules\Payments\Services\PaymentGatewayService;
use App\Modules\Payments\Services\XenditSandboxSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class XenditSandboxSimulationController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $paymentGatewayService,
        private readonly XenditSandboxSimulationService $xenditSandboxSimulationService,
    ) {}

    public function simulatePaid(int $paymentId): JsonResponse
    {
        $currentEnv = strtolower((string) config('app.env', app()->environment()));
        if (! in_array($currentEnv, ['local', 'testing', 'sandbox'], true)) {
            abort(Response::HTTP_FORBIDDEN, 'Sandbox simulation is only available in non-production environments.');
        }

        $tx = PaymentTransaction::query()->find($paymentId);
        if ($tx === null) {
            abort(Response::HTTP_NOT_FOUND, 'Payment transaction not found.');
        }

        if ((string) $tx->provider !== 'xendit') {
            throw ValidationException::withMessages([
                'paymentId' => ['Only Xendit transactions can be simulated by this endpoint.'],
            ]);
        }

        if (! in_array((string) $tx->status, ['pending', 'authorized', 'paid'], true)) {
            throw ValidationException::withMessages([
                'paymentId' => ['Only pending/authorized/paid transactions are supported for sandbox simulation.'],
            ]);
        }

        $token = trim((string) config('payments.providers.xendit.webhook_token', ''));
        if ($token === '') {
            throw ValidationException::withMessages([
                'webhook' => ['XENDIT_WEBHOOK_TOKEN is required before running sandbox simulation.'],
            ]);
        }

        $eventId = 'xendit-sandbox-simulate-paid#'.$tx->id;
        $payload = [
            'externalReference' => (string) $tx->external_reference,
            'status' => 'paid',
            'paymentMethod' => (string) ($tx->payment_method ?: 'qris'),
            'eventId' => $eventId,
            'occurredAt' => now()->toIso8601String(),
            'payload' => [
                'sandbox' => true,
                'simulated' => true,
                'source' => 'PAYMENT-XENDIT-SANDBOX-SIMULATOR-01',
                'xendit' => [
                    'id' => 'sandbox-qrpy-'.$tx->id,
                    'external_id' => (string) $tx->external_reference,
                    'status' => 'COMPLETED',
                    'qr_code' => [
                        'id' => 'sandbox-qr-'.$tx->id,
                        'external_id' => (string) $tx->external_reference,
                        'type' => 'DYNAMIC',
                    ],
                ],
            ],
        ];

        Log::info('Xendit sandbox simulation requested.', [
            'marker' => 'PAYMENT-XENDIT-SANDBOX-SIMULATOR-01',
            'payment_transaction_id' => (int) $tx->id,
            'status_before' => (string) $tx->status,
            'event_id' => $eventId,
        ]);

        $updated = $this->paymentGatewayService->handleWebhook(
            'xendit',
            $payload,
            ['x-callback-token' => $token, 'x-sandbox-simulated' => 'true'],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        );

        Log::info('Xendit sandbox simulation finished.', [
            'marker' => 'PAYMENT-XENDIT-SANDBOX-SIMULATOR-01',
            'payment_transaction_id' => (int) $updated->id,
            'status_after' => (string) $updated->status,
            'event_id' => $eventId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sandbox payment simulation processed successfully.',
            'data' => new PaymentTransactionResource($updated),
            'meta' => [
                'sandbox' => true,
                'simulated' => true,
                'marker' => 'PAYMENT-XENDIT-SANDBOX-SIMULATOR-01',
            ],
        ]);
    }

    public function simulateProvider(int $paymentId): JsonResponse
    {
        $user = request()->user('api');
        abort_if(! $user instanceof \App\Models\User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $roleNames = $user->roles()->pluck('name')->map(static fn ($v): string => Str::lower((string) $v))->values()->all();
        $managerOrOwner = collect($roleNames)->contains(static fn (string $name): bool => str_contains($name, 'manager') || str_contains($name, 'owner'));
        abort_if(! $managerOrOwner, Response::HTTP_FORBIDDEN, 'Only manager/owner can call provider simulation.');

        $result = $this->xenditSandboxSimulationService->simulateProviderPaid($paymentId);
        /** @var \App\Models\Modules\Payments\Domain\PaymentTransaction $tx */
        $tx = $result['transaction'];

        return response()->json([
            'success' => true,
            'message' => 'Xendit provider simulation dispatched. Waiting for webhook callback.',
            'data' => new PaymentTransactionResource($tx),
            'meta' => [
                'sandbox' => true,
                'simulated' => true,
                'mode' => 'provider',
                'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
                'providerResponseStatus' => $result['providerResponseStatus'],
            ],
        ]);
    }
}

