<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

final class ModIssuePolicy
{
    /**
     * Managers and staff keep the tab after issues are switched off, so existing issues stay reachable to the people
     * who can act on them.
     */
    public function viewAny(?User $user, Mod $mod): bool
    {
        if (! resolve(ModPolicy::class)->view($user, $mod)) {
            return false;
        }

        if ($mod->issues_enabled) {
            return true;
        }

        return $user instanceof User && $this->manages($user, $mod);
    }

    public function view(?User $user, ModIssue $issue): bool
    {
        if (! $this->viewAny($user, $issue->mod)) {
            return false;
        }

        if (! $issue->trashed()) {
            return true;
        }

        return $user instanceof User && $this->manages($user, $issue->mod);
    }

    /**
     * Managers may log issues before release and after switching issues off. The rate limit lives in the create page.
     */
    public function create(User $user, Mod $mod): Response
    {
        if (! $user->hasVerifiedEmail()) {
            return Response::deny(__('You must verify your email address before opening an issue.'));
        }

        if ($user->isBanned() || ! resolve(ModPolicy::class)->view($user, $mod)) {
            return Response::deny(__('You cannot open issues on this mod.'));
        }

        if ($this->manages($user, $mod)) {
            return Response::allow();
        }

        if (! $mod->issues_enabled || ! $mod->isPublished()) {
            return Response::deny(__('This mod is not accepting issues.'));
        }

        if ($mod->isIssueBanned($user)) {
            return Response::deny(__("You can't open issues on this mod."));
        }

        if ($mod->owner instanceof User && $user->isBlockedMutually($mod->owner)) {
            return Response::deny(__('You cannot open issues on this mod.'));
        }

        return Response::allow();
    }

    public function update(User $user, ModIssue $issue): bool
    {
        if (! $user->hasVerifiedEmail() || $issue->trashed()) {
            return false;
        }

        if ($this->manages($user, $issue->mod)) {
            return true;
        }

        return $user->id === $issue->user_id
            && $issue->status->isOpen()
            && ! $issue->mod->isIssueBanned($user);
    }

    /**
     * Status, type, fixed version, duplicate and lock.
     */
    public function manage(User $user, ModIssue $issue): bool
    {
        return $user->hasVerifiedEmail() && ! $issue->trashed() && $this->manages($user, $issue->mod);
    }

    /**
     * A banned reporter can still close their own issue: closing only ever reduces the managers' workload.
     */
    public function close(User $user, ModIssue $issue): bool
    {
        return ! $issue->trashed() && $user->id === $issue->user_id && $issue->status->isOpen();
    }

    /**
     * A reporter cannot overturn a manager's decision to close. They can only undo their own.
     */
    public function reopen(User $user, ModIssue $issue): bool
    {
        if ($issue->trashed() || $issue->status->isOpen()) {
            return false;
        }

        if ($this->manages($user, $issue->mod)) {
            return true;
        }

        return $user->id === $issue->user_id
            && $issue->closed_by === $user->id
            && ! $issue->mod->isIssueBanned($user);
    }

    public function delete(User $user, ModIssue $issue): bool
    {
        return $user->hasVerifiedEmail() && ! $issue->trashed() && $this->manages($user, $issue->mod);
    }

    /**
     * A manager may only undo their own deletion, so the mod's authors cannot reverse a staff removal.
     */
    public function restore(User $user, ModIssue $issue): bool
    {
        if (! $issue->trashed()) {
            return false;
        }

        if ($user->isModOrAdmin()) {
            return true;
        }

        return $issue->deleted_by === $user->id && $issue->mod->isAuthorOrOwner($user);
    }

    public function ban(User $user, Mod $mod, User $target): bool
    {
        if (! $this->manages($user, $mod) || $user->id === $target->id) {
            return false;
        }

        return ! $target->isModOrAdmin() && ! $mod->isAuthorOrOwner($target);
    }

    public function manageBans(User $user, Mod $mod): bool
    {
        return $this->manages($user, $mod);
    }

    public function react(User $user, ModIssue $issue): Response
    {
        if (! $user->hasVerifiedEmail()) {
            return Response::deny(__('You must verify your email address before reacting.'));
        }

        if ($user->id === $issue->user_id) {
            return Response::deny(__('You cannot react to your own issue.'));
        }

        if ($issue->mod->isIssueBanned($user)) {
            return Response::deny(__("You can't take part in this mod's issues."));
        }

        if ($user->isBlockedMutually($issue->user)) {
            return Response::deny(__('You cannot react to this issue.'));
        }

        return Response::allow();
    }

    public function report(User $user, Model $reportable): bool
    {
        if (! $user->hasVerifiedEmail() || $user->isModOrAdmin()) {
            return false;
        }

        if (! $reportable instanceof ModIssue || $reportable->user_id === $user->id) {
            return false;
        }

        return ! $reportable->hasBeenReportedBy($user->id);
    }

    private function manages(User $user, Mod $mod): bool
    {
        return $user->isModOrAdmin() || $mod->isAuthorOrOwner($user);
    }
}
