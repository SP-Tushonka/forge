<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @template TModel of Model
 *
 * @mixin TModel
 */
trait HasReactions
{
    /**
     * The relationship between a model and the reactions left on it.
     *
     * @return MorphMany<Reaction, static>
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    /**
     * Open by default. Models with publication or deletion states override this.
     */
    public function canReceiveReactions(): bool
    {
        return true;
    }
}
