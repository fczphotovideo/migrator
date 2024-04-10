<?php

namespace Fcz\Migrator;

use Throwable;

class Cursor
{
    protected bool $enabled = true;

    public function __construct(readonly public string $name)
    {
        //
    }

    /**
     * Reset cursor position to 0.
     */
    public function rewind(): void
    {
        cache()->forget("migration.$this->name");
    }

    /**
     * Get cursor position.
     */
    public function get(): int
    {
        try {
            return $this->enabled ?
                cache()->get("migration.$this->name") ?? 0 :
                0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Update cursor position.
     */
    public function set(int $position): void
    {
        if ($this->enabled) {
            cache()->put("migration.$this->name", $position);
        }
    }

    /**
     * Disable cursor.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isDisabled(): bool
    {
        return !$this->enabled;
    }
}