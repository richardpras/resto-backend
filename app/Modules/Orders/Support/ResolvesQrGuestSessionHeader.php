<?php

namespace App\Modules\Orders\Support;

use Illuminate\Http\Request;

trait ResolvesQrGuestSessionHeader
{
    protected function guestSessionTokenFromRequest(Request $request): ?string
    {
        $header = $request->header('X-Qr-Guest-Session');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $query = $request->query('guestSession');
        if (is_string($query) && trim($query) !== '') {
            return trim($query);
        }

        return null;
    }
}
