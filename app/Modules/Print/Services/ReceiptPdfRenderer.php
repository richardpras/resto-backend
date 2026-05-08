<?php

namespace App\Modules\Print\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class ReceiptPdfRenderer
{
    public function render(string $html): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
