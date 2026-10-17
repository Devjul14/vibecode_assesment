<?php

namespace App\Data;

use App\Enums\ActorRole;

/**
 * Pelaku sebuah perubahan — dicatat di setiap entri audit.
 */
final readonly class Actor
{
    public function __construct(
        public ActorRole $role,
        public ?string $name = null,
    ) {}

    public static function system(): self
    {
        return new self(ActorRole::System, 'Sistem');
    }

    public function canAcceptDelivery(): bool
    {
        return in_array($this->role, [ActorRole::Requester, ActorRole::Manager], true);
    }

    public function label(): string
    {
        return $this->name ? "{$this->name} ({$this->role->label()})" : $this->role->label();
    }
}
