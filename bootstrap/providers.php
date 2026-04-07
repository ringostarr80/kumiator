<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\WebAuthnServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    WebAuthnServiceProvider::class,
];
