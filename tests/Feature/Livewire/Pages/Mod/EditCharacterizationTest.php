<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Mod;
use App\Models\ModCategory;
use App\Models\SourceCodeLink;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);
});

describe('staff editing', function (): void {
    it('allows an admin to edit a mod they do not own', function (): void {
        $owner = User::factory()->create();
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->recycle($owner)->create(['name' => 'Original Name']);

        $this->actingAs($staff);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('name', 'Staff Renamed')
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->name)->toBe('Staff Renamed');
    });

    it('allows an admin to open the edit page for a disabled mod', function (): void {
        $owner = User::factory()->create();
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->recycle($owner)->disabled()->create();

        $this->actingAs($staff);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->assertOk()
            ->assertSet('name', $mod->name);
    });
});

describe('field persistence', function (): void {
    it('persists a changed category', function (): void {
        $owner = User::factory()->create();
        $target = ModCategory::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('category', (string) $target->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->category_id)->toBe($target->id);
    });

    it('persists every boolean toggle', function (): void {
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create([
            'comments_disabled' => false,
            'cheat_notice' => false,
            'addons_disabled' => false,
            'lists_disabled' => false,
            'profile_binding_notice_disabled' => false,
        ]);

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('commentsDisabled', true)
            ->set('cheatNotice', true)
            ->set('addonsDisabled', true)
            ->set('listsDisabled', true)
            ->set('disableProfileBindingNotice', true)
            ->call('save')
            ->assertHasNoErrors();

        $mod->refresh();

        expect($mod->comments_disabled)->toBeTrue()
            ->and($mod->cheat_notice)->toBeTrue()
            ->and($mod->addons_disabled)->toBeTrue()
            ->and($mod->lists_disabled)->toBeTrue()
            ->and($mod->profile_binding_notice_disabled)->toBeTrue();
    });

    it('syncs additional authors', function (): void {
        $owner = User::factory()->create();
        $keep = User::factory()->create();
        $drop = User::factory()->create();
        $add = User::factory()->create();

        $mod = Mod::factory()->recycle($owner)->create();
        $mod->additionalAuthors()->sync([$keep->id, $drop->id]);

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('authorIds', [$keep->id, $add->id])
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->additionalAuthors->pluck('id')->sort()->values()->all())
            ->toBe(collect([$keep->id, $add->id])->sort()->values()->all());
    });
});

describe('derived values', function (): void {
    it('regenerates the slug from the censored name, not the raw input', function (): void {
        config()->set('censor.words', 'forbidden');

        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('name', 'A forbidden Mod')
            ->call('save')
            ->assertHasNoErrors();

        $mod->refresh();

        expect($mod->name)->not->toContain('forbidden')
            ->and($mod->slug)->toBe(Str::slug($mod->name))
            ->and($mod->slug)->not->toContain('forbidden');
    });

    it('converts the published at date from the actor timezone to UTC', function (): void {
        $owner = User::factory()->create(['timezone' => 'America/New_York']);
        $mod = Mod::factory()->recycle($owner)->create(['published_at' => null]);

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('publishedAtDate', '2026-06-15')
            ->set('publishedAtTime', '12:00')
            ->call('save')
            ->assertHasNoErrors();

        // 12:00 in America/New_York on 2026-06-15 (EDT, UTC-4) is 16:00 UTC.
        expect($mod->fresh()->published_at->utc()->format('Y-m-d H:i'))->toBe('2026-06-15 16:00');
    });

    it('replaces every source code link, keeping labels', function (): void {
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('sourceCodeLinks', [
                ['key' => 'link-0', 'url' => 'https://github.com/example/primary', 'label' => 'Primary'],
                ['key' => 'link-1', 'url' => 'https://github.com/example/mirror', 'label' => ''],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $links = $mod->fresh()->sourceCodeLinks;

        expect($links)->toHaveCount(2)
            ->and($links->pluck('url')->sort()->values()->all())->toBe([
                'https://github.com/example/mirror',
                'https://github.com/example/primary',
            ])
            ->and($links->firstWhere('url', 'https://github.com/example/primary')->label)->toBe('Primary');

        // The originals created by ModFactory::configure() are gone, not merged.
        expect(SourceCodeLink::query()
            ->where('sourceable_type', Mod::class)
            ->where('sourceable_id', $mod->id)
            ->count())->toBe(2);
    });
});

describe('ai content lock', function (): void {
    it('lets an admin lock the flag, which forces the flag on', function (): void {
        $owner = User::factory()->create();
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->recycle($owner)->create([
            'contains_ai_content' => false,
            'contains_ai_content_locked' => false,
            'custom_ai_disclosure' => null,
        ]);

        $this->actingAs($staff);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('containsAiContentLocked', true)
            ->set('customAiDisclosure', 'Locked by staff after review.')
            ->call('save')
            ->assertHasNoErrors();

        $mod->refresh();

        expect($mod->contains_ai_content_locked)->toBeTrue()
            ->and($mod->contains_ai_content)->toBeTrue();
    });

    it('ignores an owner clearing the flag while it is locked', function (): void {
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create([
            'contains_ai_content' => true,
            'contains_ai_content_locked' => true,
            'custom_ai_disclosure' => 'Original disclosure.',
        ]);

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('containsAiContent', false)
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->contains_ai_content)->toBeTrue();
    });

    it('lets an owner change the flag when it is not locked', function (): void {
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create([
            'contains_ai_content' => false,
            'contains_ai_content_locked' => false,
            'custom_ai_disclosure' => null,
        ]);

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('containsAiContent', true)
            ->set('customAiDisclosure', 'Generated the icon with AI.')
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->contains_ai_content)->toBeTrue();
    });
});

describe('edit auditing', function (): void {
    it('flags a staff edit of someone elses mod as a moderation action', function (): void {
        $owner = User::factory()->create();
        $staff = User::factory()->admin()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        $this->actingAs($staff);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('name', 'Renamed By Staff')
            ->call('save')
            ->assertHasNoErrors();

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::MOD_EDIT->value)
            ->sole();

        expect($event->is_moderation_action)->toBeTrue();
    });

    it('does not flag an owner editing their own mod', function (): void {
        $owner = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();

        $this->actingAs($owner);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('name', 'Renamed By Owner')
            ->call('save')
            ->assertHasNoErrors();

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::MOD_EDIT->value)
            ->sole();

        expect($event->is_moderation_action)->toBeFalse();
    });

    it('does not flag an additional author editing the mod', function (): void {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $mod = Mod::factory()->recycle($owner)->create();
        $mod->additionalAuthors()->attach($author->id);

        $this->actingAs($author);

        Livewire::test('pages::mod.edit', ['modId' => $mod->id])
            ->set('name', 'Renamed By Author')
            ->call('save')
            ->assertHasNoErrors();

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::MOD_EDIT->value)
            ->sole();

        expect($event->is_moderation_action)->toBeFalse();
    });
});
