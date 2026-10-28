<?php
declare(strict_types=1);

namespace SaleSync\Seller;

const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES = 5242880;
const ALLOWED_IMAGE_MIMES = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
