<?php

namespace App\Data;

use App\Models\ServiceRequest;

/**
 * Hasil transisi: request terbaru + peringatan yang tidak memblokir (mis. melewati batas WIP).
 */
final readonly class TransitionResult
{
    /**
     * @param  list<array{code: string, message: string}>  $warnings
     */
    public function __construct(
        public ServiceRequest $request,
        public array $warnings = [],
    ) {}

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
