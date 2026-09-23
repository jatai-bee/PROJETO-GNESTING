<?php

declare(strict_types=1);

namespace GNesting\Core;

final class Paginator
{
    public readonly int $page;
    public readonly int $lastPage;

    public function __construct(
        public readonly int $total,
        int $page,
        public readonly int $perPage = 20,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min(max(1, $page), $this->lastPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }
}
