<?php

return [
    'validation' => [
        'failed' => 'Data yang dikirim tidak valid.',
    ],
    'auth' => [
        'unauthenticated' => 'Belum terautentikasi.',
        'forbidden' => 'Anda tidak memiliki izin untuk tindakan ini.',
    ],
    'generic' => [
        'not_found' => 'Data tidak ditemukan.',
        'server_error' => 'Terjadi kesalahan. Silakan coba lagi.',
    ],
    'shift_close' => [
        'outlet_required' => 'Pilih outlet sebelum menutup shift.',
        'already_running' => 'Proses tutup shift sedang berjalan untuk outlet ini.',
        'blocked' => 'Tutup shift diblokir hingga masalah preflight diselesaikan.',
    ],
    'purchase' => [
        'invalid_status' => 'Dokumen pembelian tidak dapat diubah pada status saat ini.',
        'pr_not_approved' => 'Purchase request harus disetujui sebelum dikonversi ke PO.',
    ],
    'accounting' => [
        'period_closed' => 'Periode akuntansi sudah ditutup.',
        'unbalanced_journal' => 'Jurnal tidak seimbang.',
    ],
    'payroll' => [
        'run_locked' => 'Payroll run ini terkunci dan tidak dapat diubah.',
    ],
];
