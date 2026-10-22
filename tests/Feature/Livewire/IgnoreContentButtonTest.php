<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->viewer = User::factory()->create();
    $this->target = User::factory()->create();
});

it('ignores owned content by default', function (): void {
    Livewire::actingAs($this->viewer)
        ->test('ignore-content-button', ['user' => $this->target])
        ->call('openModal')
        ->assertSee('You are about to ignore all mods, addons and lists belonging to')
        ->call('confirmIgnore')
        ->assertRedirect(route('user.show', ['userId' => $this->target->id, 'slug' => $this->target->slug]));

    $ignore = $this->viewer->ignoredUsers()->sole();
    expect($ignore->ignored_user_id)->toBe($this->target->id)
        ->and($ignore->include_coauthored)->toBeFalse();
});

it('can include co-authored content', function (): void {
    Livewire::actingAs($this->viewer)
        ->test('ignore-content-button', ['user' => $this->target])
        ->call('openModal')
        ->set('scope', 'coauthored')
        ->call('confirmIgnore');

    expect($this->viewer->ignoredUsers()->sole()->include_coauthored)->toBeTrue();
});

it('updates the scope when ignoring again', function (): void {
    $this->viewer->ignoreUser($this->target);
    $this->viewer->ignoreUser($this->target, includeCoauthored: true);

    expect($this->viewer->ignoredUsers()->count())->toBe(1)
        ->and($this->viewer->ignoredUsers()->sole()->include_coauthored)->toBeTrue();
});

it('stops ignoring', function (): void {
    $this->viewer->ignoreUser($this->target);

    Livewire::actingAs($this->viewer)
        ->test('ignore-content-button', ['user' => $this->target])
        ->assertSee('Stop ignoring content')
        ->call('stopIgnoring');

    expect($this->viewer->ignoredUsers()->exists())->toBeFalse();
});

it('cannot ignore yourself', function (): void {
    Livewire::actingAs($this->viewer)
        ->test('ignore-content-button', ['user' => $this->viewer])
        ->call('confirmIgnore');

    expect($this->viewer->ignoredUsers()->exists())->toBeFalse();
});

it('shows the banner on the profile of an ignored user', function (): void {
    $this->viewer->ignoreUser($this->target);
    $url = route('user.show', ['userId' => $this->target->id, 'slug' => $this->target->slug]);

    $this->actingAs($this->viewer)->get($url)->assertSee('data-test="ignored-content-banner"', false);
    $this->actingAs(User::factory()->create())->get($url)->assertDontSee('data-test="ignored-content-banner"', false);
});
