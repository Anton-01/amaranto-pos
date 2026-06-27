<?php

return [
    'connection_type' => env('PRINTER_CONNECTION_TYPE', 'network'),
    'ip_address' => env('PRINTER_IP_ADDRESS', '192.168.1.100'),
    'port' => env('PRINTER_PORT', 9100),
    'file_path' => env('PRINTER_FILE_PATH', '/dev/usb/lp0'),
    'smb_host' => env('PRINTER_SMB_HOST', 'localhost'),
    'smb_share' => env('PRINTER_SMB_SHARE', 'printer'),
    'smb_user' => env('PRINTER_SMB_USER'),
    'smb_pass' => env('PRINTER_SMB_PASS'),
];
