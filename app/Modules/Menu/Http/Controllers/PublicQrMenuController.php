<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Resources\PublicMenuItemResource;
use App\Modules\Menu\Services\PublicQrMenuService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicQrMenuController extends Controller
{
    public function __construct(
        private readonly PublicQrMenuService $publicQrMenuService,
    ) {}

    public function show(string $qrPublicId): JsonResponse
    {
        try {
            $items = $this->publicQrMenuService->listForQrPublicId($qrPublicId);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Table not found.',
                'code' => 'table_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => PublicMenuItemResource::collection($items),
        ]);
    }
}
