<?php

declare(strict_types=1);

use App\Models\User;

it('renders the notification centre for authenticated users', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('notifications'))
        ->assertOk()
        ->assertSee('No notifications')
        ->assertSee("You're all caught up! New notifications will appear here.");
});

it('redirects guests to login', function (): void {
    $this->get(route('notifications'))->assertRedirect(route('login'));
});

// The dashboard used to host the notification centre, so both bells pointed at it. It no longer does.
it('points the navigation bells at the notifications page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSeeHtml(sprintf('href="%s"', route('notifications')));
});
