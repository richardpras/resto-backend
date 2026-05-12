<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Resources\PaymentTransactionResource;
use App\Modules\Payments\Services\PaymentGatewayService;
use App\Modules\Payments\Webhooks\XenditInvoiceWebhookNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xendit-native invoice callback entrypoint (raw JSON body).
 * Forwards into {@see PaymentGatewayService::handleWebhook()} after normalization.
 */
final class XenditInvoiceWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $paymentGatewayService,
        private readonly XenditInvoiceWebhookNormalizer $normalizer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid JSON body.',
                'data' => null,
                'meta' => null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $headers = collect($request->headers->all())
            ->mapWithKeys(fn (array $value, string $key): array => [strtolower($key) => (string) ($value[0] ?? '')])
            ->all();

        $normalized = $this->normalizer->normalize($decoded);
        $transaction = $this->paymentGatewayService->handleWebhook('xendit', $normalized, $headers, $raw);

        return response()->json([
            'success' => true,
            'message' => 'Payment webhook processed successfully.',
            'data' => new PaymentTransactionResource($transaction),
            'meta' => null,
        ]);
    }
}
