<?php

declare(strict_types=1);

namespace WhollyCrypto\Http;

use WhollyCrypto\Options;

interface TransportInterface
{
    public function send(#[\SensitiveParameter] Request $request, Options $options): Response;
}
