<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\PaymentWebhookRequest;
use App\Modules\Payments\Http\Requests\ReconcilePaymentTransactionsRequest;
use App\Modules\Payments\Http\Requests\StorePaymentTransactionRequest;
use App\Modules\Payments\Http\Resources\PaymentTransactionResource;
use App\Modules\Payments\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentTransactionController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $paymentGatewayService,
    ) {}

    public function store(StorePaymentTransactionRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof \App\Models\User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $transaction = $this->paymentGatewayService->initiateTransaction($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Payment transaction initiated successfully.',
            'data' => new PaymentTransactionResource($transaction),
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function show(int $transaction): JsonResponse
    {
        $user = request()->user('api');
        abort_if(! $user instanceof \App\Models\User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $entity = $this->paymentGatewayService->showTransaction($user, $transaction);

        return response()->json([
            'success' => true,
            'message' => 'Payment transaction retrieved successfully.',
            'data' => new PaymentTransactionResource($entity),
            'meta' => null,
        ]);
    }

    public function webhook(PaymentWebhookRequest $request, string $provider): JsonResponse
    {
        $headers = collect($request->headers->all())
            ->mapWithKeys(fn (array $value, string $key): array => [strtolower($key) => (string) ($value[0] ?? '')])
            ->all();
        $transaction = $this->paymentGatewayService->handleWebhook($provider, $request->validated(), $headers, (string) $request->getContent());

        return response()->json([
            'success' => true,
            'message' => 'Payment webhook processed successfully.',
            'data' => new PaymentTransactionResource($transaction),
            'meta' => null,
        ]);
    }

    public function reconcile(ReconcilePaymentTransactionsRequest $request): JsonResponse
    {
        $transactions = $this->paymentGatewayService->reconcilePendingTransactions(
            $request->validated('transactionIds', []),
            (int) $request->validated('limit', 50)
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment reconciliation processed successfully.',
            'data' => PaymentTransactionResource::collection($transactions)->resolve(),
            'meta' => [
                'count' => $transactions->count(),
            ],
        ]);
    }
}
