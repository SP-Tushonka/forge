<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Reaction;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Deliberately non-generic, unlike Commentable. Commentable carries a decorative `@template TModel of Model` that
 * nothing in it consumes; copying that here made every implementor a distinct `Reactable<Comment>` / `Reactable<Mod>`,
 * which the invariant intersection type in HandlesReactions then rejected.
 */
interface Reactable
{
    /**
     * Every reaction left on this model.
     *
     * @return MorphMany<Reaction, static>
     */
    public function reactions(): MorphMany;

    /**
     * Whether this model is currently open to new reactions. All reaction eligibility that depends on the model itself
     * — publication state, deletion, moderation — belongs here. Per-user rules live in the policy.
     */
    public function canReceiveReactions(): bool;
}
