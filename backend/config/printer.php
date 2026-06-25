<?php

return [
    'connection_type' => env('PRINTER_CONNECTION_TYPE', 'network'),
    'ip_address' => env('PRINTER_IP_ADDRESS', '192.168.1.100'),
    'port' => env('PRINTER_PORT', 9100),
    'file_path' => env('PRINTER_FILE_PATH', '/dev/usb/lp0'),
    'windows_share' => env('PRINTER_WINDOWS_SHARE', 'smb://localhost/printer'),
];
