<?php

declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

final readonly class HttpResponse {
    public function __construct(public int $status, public string $body, public array $headers = []) {}
}
