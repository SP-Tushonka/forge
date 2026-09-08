<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('refuses a non staff user', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('admin.staff-tools.mod-tool')
        ->assertForbidden();
});

it('refuses a moderator', function (): void {
    $moderator = User::factory()->moderator()->create();

    Livewire::actingAs($moderator)
        ->test('admin.staff-tools.mod-tool')
        ->assertForbidden();
});

it('returns nothing until a term is typed', function (): void {
    $staff = User::factory()->admin()->create();
    Mod::factory()->create();

    $component = Livewire::actingAs($staff)->test('admin.staff-tools.mod-tool');

    expect($component->instance()->results)->toBeEmpty();
});

it('finds a mod by name, slug, guid, id and owner name', function (): void {
    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create(['name' => 'Zibbedy']);
    $mod = Mod::factory()->recycle($owner)->create([
        'name' => 'Searchable Widget',
        'slug' => 'searchable-widget',
        'guid' => 'com.example.searchable-widget',
    ]);

    $component = Livewire::actingAs($staff)->test('admin.staff-tools.mod-tool');

    $missed = [];

    foreach (['Searchable', 'searchable-widget', 'com.example.searchable', (string) $mod->id, 'Zibbedy'] as $term) {
        $component->set('search', $term);

        if (! in_array($mod->id, $component->instance()->results->pluck('id')->all(), true)) {
            $missed[] = $term;
        }
    }

    // Collected rather than asserted per term so a failure names every search that missed.
    expect($missed)->toBe([]);
});

it('finds disabled and unpublished mods', function (): void {
    $staff = User::factory()->admin()->create();
    $disabled = Mod::factory()->disabled()->create(['name' => 'Hidden Alpha']);
    $unpublished = Mod::factory()->unpublished()->create(['name' => 'Hidden Beta']);

    $component = Livewire::actingAs($staff)->test('admin.staff-tools.mod-tool');

    $component->set('search', 'Hidden');

    expect($component->instance()->results->pluck('id')->all())
        ->toContain($disabled->id)
        ->toContain($unpublished->id);
});

it('selects a mod and clears it', function (): void {
    $staff = User::factory()->admin()->create();
    $mod = Mod::factory()->create();

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.mod-tool')
        ->call('selectMod', $mod->id)
        ->assertSet('modId', $mod->id)
        ->assertSet('search', '')
        ->call('clearMod')
        ->assertSet('modId', null);
});

describe('details panel', function (): void {
    beforeEach(function (): void {
        config()->set('honeypot.enabled', false);
    });

    it('applies the fields and records a moderation action with the reason', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create(['name' => 'Before']);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('name', 'After')
            ->set('reason', 'Name violated the naming guidelines')
            ->call('saveDetails')
            ->assertHasNoErrors();

        expect($mod->fresh()->name)->toBe('After');

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::MOD_EDIT->value)
            ->sole();

        expect($event->is_moderation_action)->toBeTrue()
            ->and($event->reason)->toBe('Name violated the naming guidelines');
    });

    it('requires a reason', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('name', 'Renamed')
            ->set('reason', '')
            ->call('saveDetails')
            ->assertHasErrors(['reason' => 'required']);
    });

    it('rejects a reason over the column limit', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('reason', str_repeat('a', 1001))
            ->call('saveDetails')
            ->assertHasErrors(['reason' => 'max']);
    });

    it('validates the mod fields under their bare keys', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('name', '')
            ->set('reason', 'Testing validation')
            ->call('saveDetails')
            ->assertHasErrors(['name' => 'required']);
    });

    it('changes the additional authors through the tool', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $keep = User::factory()->create();
        $drop = User::factory()->create();
        $add = User::factory()->create();

        $mod = Mod::factory()->recycle($owner)->create();
        $mod->additionalAuthors()->sync([$keep->id, $drop->id]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSet('authorIds', [$keep->id, $drop->id])
            ->set('authorIds', [$keep->id, $add->id])
            ->set('reason', 'Author list was wrong')
            ->call('saveDetails')
            ->assertHasNoErrors();

        expect($mod->fresh()->additionalAuthors->pluck('id')->sort()->values()->all())
            ->toBe(collect([$keep->id, $add->id])->sort()->values()->all());
    });

    it('renders the author picker', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        // Every other author test passes on the wiring alone — this is the one that fails if
        // the picker is missing from the markup, which is exactly how it shipped the first time.
        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSeeLivewire('form.user-select')
            ->assertSee('Additional Authors');
    });

    it('shows user ids in the author picker so staff can tell duplicate names apart', function (): void {
        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $author = User::factory()->create(['name' => 'Ambiguous Name']);

        $mod = Mod::factory()->recycle($owner)->create();
        $mod->additionalAuthors()->sync([$author->id]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSeeHtml('data-user-id-label')
            ->assertSee('#'.$author->id);
    });

    it('accepts the author list from the user-select child component', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();
        $author = User::factory()->create();

        // The picker is a child component that reports upward through this event rather than
        // binding directly, so the listener is the only link between the two.
        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->dispatch('updateAuthorIds', ids: [$author->id])
            ->assertSet('authorIds', [$author->id]);
    });

    it('rehydrates the author list when a different mod is selected', function (): void {
        $staff = User::factory()->admin()->create();

        $first = Mod::factory()->create();
        $firstAuthor = User::factory()->create();
        $first->additionalAuthors()->sync([$firstAuthor->id]);

        $second = Mod::factory()->create();
        $secondAuthor = User::factory()->create();
        $second->additionalAuthors()->sync([$secondAuthor->id]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $first->id])
            ->assertSet('authorIds', [$firstAuthor->id])
            ->call('selectMod', $second->id)
            ->assertSet('authorIds', [$secondAuthor->id]);
    });

    it('refuses an author with a block relationship with the owner', function (): void {
        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $blocked = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        $owner->block($blocked);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('authorIds', [$blocked->id])
            ->set('reason', 'Trying to add a blocked user')
            ->call('saveDetails')
            ->assertHasErrors(['authorIds.0']);
    });

    it('sends no notification for a field edit', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('reason', 'Tidy up')
            ->call('saveDetails')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    });
});

describe('ownership panel', function (): void {
    it('transfers ownership through the tool', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $previous = User::factory()->create();
        $next = User::factory()->create();
        $mod = Mod::factory()->recycle($previous)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->call('selectNewOwner', $next->id)
            ->set('ownershipReason', 'Original author asked us to')
            ->call('transferOwnership')
            ->assertHasNoErrors();

        expect($mod->fresh()->owner_id)->toBe($next->id);
    });

    it('clears ownership through the tool', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('ownershipReason', 'Owner account was compromised')
            ->call('clearOwnership')
            ->assertHasNoErrors();

        expect($mod->fresh()->owner_id)->toBeNull();
    });

    it('requires a reason to transfer', function (): void {
        $staff = User::factory()->admin()->create();
        $previous = User::factory()->create();
        $next = User::factory()->create();
        $mod = Mod::factory()->recycle($previous)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->call('selectNewOwner', $next->id)
            ->set('ownershipReason', '')
            ->call('transferOwnership')
            ->assertHasErrors(['ownershipReason' => 'required']);
    });

    it('requires a reason to clear', function (): void {
        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('ownershipReason', '')
            ->call('clearOwnership')
            ->assertHasErrors(['ownershipReason' => 'required']);
    });

    it('keeps the details reason and the ownership reason independent', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $previous = User::factory()->create();
        $next = User::factory()->create();
        $mod = Mod::factory()->recycle($previous)->create();

        // A reason typed into the Details panel must not satisfy the Ownership panel.
        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('reason', 'Fixing a typo in the description')
            ->call('selectNewOwner', $next->id)
            ->call('transferOwnership')
            ->assertHasErrors(['ownershipReason' => 'required']);

        expect($mod->fresh()->owner_id)->toBe($previous->id);
    });

    it('records the ownership reason on the tracking event, not the details reason', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $previous = User::factory()->create();
        $next = User::factory()->create();
        $mod = Mod::factory()->recycle($previous)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('reason', 'Details reason that must not be used')
            ->call('selectNewOwner', $next->id)
            ->set('ownershipReason', 'Ownership reason')
            ->call('transferOwnership')
            ->assertHasNoErrors();

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::MOD_OWNERSHIP_TRANSFER->value)
            ->sole();

        expect($event->reason)->toBe('Ownership reason');
    });

    it('surfaces a refusal as an error instead of a success', function (): void {
        Notification::fake();

        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $banned = User::factory()->create();
        $banned->ban();
        $mod = Mod::factory()->recycle($owner)->create();

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->call('selectNewOwner', $banned->id)
            ->set('ownershipReason', 'Trying anyway')
            ->call('transferOwnership')
            ->assertHasErrors('action');

        expect($mod->fresh()->owner_id)->toBe($owner->id);
    });

    it('surfaces clearing an already unowned mod as an error', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create(['owner_id' => null]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('ownershipReason', 'Nothing to clear')
            ->call('clearOwnership')
            ->assertHasErrors('action');
    });
});

describe('versions panel', function (): void {
    it('lists every version including unpublished and disabled ones', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        ModVersion::factory()->recycle($mod)->create(['version' => '1.0.0']);
        ModVersion::factory()->recycle($mod)->create(['version' => '1.1.0', 'disabled' => true]);
        ModVersion::factory()->recycle($mod)->create(['version' => '1.2.0', 'published_at' => null]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSee('1.0.0')
            ->assertSee('1.1.0')
            ->assertSee('1.2.0');
    });

    it('always shows a state, including for a healthy published version', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        ModVersion::factory()->recycle($mod)->create([
            'version' => '3.0.0',
            'disabled' => false,
            'published_at' => now()->subDay(),
        ]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSee('Published');
    });

    it('labels a future dated version as scheduled', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        ModVersion::factory()->recycle($mod)->create([
            'version' => '4.0.0',
            'disabled' => false,
            'published_at' => now()->addWeek(),
        ]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSee('Scheduled');
    });

    it('shows both disabled and unpublished on the same version', function (): void {
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->create();

        ModVersion::factory()->recycle($mod)->create([
            'version' => '5.0.0',
            'disabled' => true,
            'published_at' => null,
        ]);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->assertSee('Disabled')
            ->assertSee('Unpublished');
    });

    it('deletes a version and records a moderation action', function (): void {
        $staff = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();
        $version = ModVersion::factory()->recycle($mod)->create(['version' => '2.0.0']);

        Livewire::actingAs($staff)
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->call('deleteModVersion', $version->id, 'Malware in the archive')
            ->assertHasNoErrors();

        expect(ModVersion::query()->find($version->id))->toBeNull();

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::VERSION_DELETE->value)
            ->sole();

        expect($event->is_moderation_action)->toBeTrue()
            ->and($event->reason)->toBe('Malware in the archive');
    });
});
