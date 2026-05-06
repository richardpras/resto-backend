<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\PurchaseRequest;
use App\Models\Modules\Purchase\Domain\PurchaseRequestItem;
use App\Modules\Purchase\Http\Requests\StorePurchaseRequestRequest;
use App\Modules\Purchase\Http\Requests\UpdatePurchaseRequestRequest;
use App\Modules\Purchase\Http\Resources\PurchaseRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PurchaseRequestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = PurchaseRequest::query()
            ->with('items')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => PurchaseRequestResource::collection($rows),
        ]);
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        return response()->json([
            'data' => new PurchaseRequestResource($purchaseRequest->load('items')),
        ]);
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $data = $request->validated();
        $created = DB::transaction(function () use ($data): PurchaseRequest {
            $row = PurchaseRequest::query()->create([
                'number' => $this->nextNumber(),
                'request_date' => $data['date'],
                'outlet' => $data['outlet'] ?? null,
                'requested_by' => $data['requestedBy'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                PurchaseRequestItem::query()->create([
                    'purchase_request_id' => $row->id,
                    'ingredient_id' => (int) $item['inventoryItemId'],
                    'requested_qty' => (float) $item['qty'],
                    'unit' => $item['unit'] ?? null,
                ]);
            }

            return $row->fresh()->load('items');
        });

        return response()->json([
            'message' => 'Purchase request created successfully.',
            'data' => new PurchaseRequestResource($created),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($data, $purchaseRequest): PurchaseRequest {
            $purchaseRequest->fill([
                'request_date' => $data['date'] ?? $purchaseRequest->request_date,
                'outlet' => $data['outlet'] ?? $purchaseRequest->outlet,
                'requested_by' => $data['requestedBy'] ?? $purchaseRequest->requested_by,
                'status' => $data['status'] ?? $purchaseRequest->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $purchaseRequest->notes,
            ]);
            $purchaseRequest->save();

            if (array_key_exists('items', $data)) {
                $purchaseRequest->items()->delete();
                foreach ($data['items'] as $item) {
                    PurchaseRequestItem::query()->create([
                        'purchase_request_id' => $purchaseRequest->id,
                        'ingredient_id' => (int) $item['inventoryItemId'],
                        'requested_qty' => (float) $item['qty'],
                        'unit' => $item['unit'] ?? null,
                    ]);
                }
            }

            return $purchaseRequest->fresh()->load('items');
        });

        return response()->json([
            'message' => 'Purchase request updated successfully.',
            'data' => new PurchaseRequestResource($updated),
        ]);
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $purchaseRequest->delete();

        return response()->json([
            'message' => 'Purchase request deleted successfully.',
        ]);
    }

    private function nextNumber(): string
    {
        $lastId = (int) (PurchaseRequest::query()->max('id') ?? 0);
        return 'PR-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
