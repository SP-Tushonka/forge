<?php

declare(strict_types=1);

use App\Models\User;

describe('admin page access', function (): void {
    it('renders the admin user management page', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/user-management')
            ->assertOk();
    });

    it('renders the admin SPT version management page', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/spt-versions')
            ->assertOk();
    });

    it('renders the admin visitor analytics page', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/visitor-analytics')
            ->assertOk();
    });

    it('renders the admin alt detection page', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/alt-detection')
            ->assertOk();
    });

    it('renders the admin alt monitoring page', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/alt-monitoring')
            ->assertOk();
    });

    it('renders the admin new alt watch page', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/alt-monitoring/watches/create')
            ->assertOk();
    });

    it('redirects guests from admin pages to login', function (): void {
        $this->get('/admin/user-management')->assertRedirect('/login');
    });
});
