<?php
// /config/app.php
// MartFlow POS - Global Configuration

return [
    'app_name' => 'MARTFLOW POS',
    'app_title' => 'MARTFLOW POS - Modern Retail & POS System',
    'app_version' => '1.0.0',
    'timezone' => 'Asia/Jakarta',
    
    // Database configuration
    // Default to 'sqlite' for zero-config run in this dev environment.
    // Change to 'mysql' and fill the credentials below for local MySQL deployment.
    'db_type' => 'sqlite', // 'mysql' or 'sqlite'
    
    'db_host' => '127.0.0.1',
    'db_name' => 'martflow_pos',
    'db_user' => 'root',
    'db_pass' => '',
    
    // Store Info
    'store_name' => 'MartFlow Minimarket',
    'store_address' => 'Jl. Jenderal Sudirman No. 45, Jakarta',
    'store_phone' => '(021) 555-0199',
    'store_email' => 'contact@martflow.com',
    'store_receipt_header' => "MARTFLOW MINIMARKET\nModern Retail & POS\nTelp: (021) 555-0199",
    'store_receipt_footer' => "Terima Kasih Atas Kunjungan Anda\nBarang yang sudah dibeli tidak dapat ditukar\nMartFlow POS v1.0.0",
    
    // Tax and Settings
    'tax_rate' => 0.11, // 11% VAT / PPN
    'points_multiplier' => 0.01, // 1% of total transaction is added as points (e.g., 100,000 spend = 1,000 points)
];
