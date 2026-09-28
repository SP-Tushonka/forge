<?php

declare(strict_types=1);

describe('TabButton Blade Component', function (): void {
    it('renders the count as a badge beside the name', function (): void {
        $this->blade('<x-tab-button name="Versions" :count="17" />')
            ->assertSeeInOrder(['Versions', '>17</span>'], false);
    });

    it('renders no badge without a count', function (): void {
        $this->blade('<x-tab-button name="Description" />')
            ->assertSee('Description')
            ->assertDontSee('</span>', false);
    });
});
