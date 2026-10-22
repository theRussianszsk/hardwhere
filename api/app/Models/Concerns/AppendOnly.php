<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Mirrors the database trigger so misuse fails fast in PHP too. */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn (Model $m) => throw new LogicException(class_basename($m).' is append-only.'));
        static::deleting(fn (Model $m) => throw new LogicException(class_basename($m).' is append-only.'));
    }
}
