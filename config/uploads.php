<?php

declare(strict_types=1);

return [
    // Tamanho máximo por imagem enviada (o servidor também precisa aceitar:
    // upload_max_filesize e post_max_size no php.ini / cPanel).
    'max_image_bytes' => (int) env('UPLOAD_MAX_IMAGE_MB', 5) * 1024 * 1024,
    'max_production_file_bytes' => (int) env('UPLOAD_MAX_PRODUCTION_FILE_MB', 20) * 1024 * 1024,
];
