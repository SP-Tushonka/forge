<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\User;
use App\Policies\ModPolicy;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->mod = Mod::factory()->create(['owner_id' => $this->owner->id]);
    $this->policy = new ModPolicy;
});

it('lets the owner view stats', function (): void {
    expect($this->policy->viewStats($this->owner, $this->mod))->toBeTrue();
});

it('lets a co-author view stats', function (): void {
    $author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($author);

    expect($this->policy->viewStats($author, $this->mod))->toBeTrue();
});

it('lets staff administrators view any mod\'s stats', function (): void {
    expect($this->policy->viewStats(User::factory()->admin()->create(), $this->mod))->toBeTrue();
});

it('denies moderators and senior moderators', function (): void {
    expect($this->policy->viewStats(User::factory()->moderator()->create(), $this->mod))->toBeFalse()
        ->and($this->policy->viewStats(User::factory()->seniorModerator()->create(), $this->mod))->toBeFalse();
});

it('denies other users', function (): void {
    expect($this->policy->viewStats(User::factory()->create(), $this->mod))->toBeFalse();
});

it('denies an owner whose email is not verified', function (): void {
    $unverified = User::factory()->unverified()->create();
    $mod = Mod::factory()->create(['owner_id' => $unverified->id]);

    expect($this->policy->viewStats($unverified, $mod))->toBeFalse();
});
