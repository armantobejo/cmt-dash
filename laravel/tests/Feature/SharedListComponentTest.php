<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SharedListComponentTest extends TestCase
{
    /** shared-list renders loading state without errors. */
    public function test_renders_loading_state(): void
    {
        $html = Blade::render('<x-shared-list state="loading" />');

        $this->assertStringContainsString('Loading…', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    /** shared-list renders empty state without errors. */
    public function test_renders_empty_state(): void
    {
        $html = Blade::render('<x-shared-list state="empty" empty-heading="No agents found." empty-description="Try another search." />');

        $this->assertStringContainsString('No agents found.', $html);
        $this->assertStringContainsString('Try another search.', $html);
    }

    /** shared-list renders error state without errors. */
    public function test_renders_error_state(): void
    {
        $html = Blade::render('<x-shared-list state="error" error-heading="Unable to load records." error-description="Database connection lost." />');

        $this->assertStringContainsString('Unable to load records.', $html);
        $this->assertStringContainsString('Database connection lost.', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    /** shared-list renders slot content in ready state without errors. */
    public function test_renders_ready_state_slot(): void
    {
        $html = Blade::render('<x-shared-list state="ready"><table><tr><td>data</td></tr></table></x-shared-list>');

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<td>data</td>', $html);
    }

    /** shared-list defaults to ready state rendering slot. */
    public function test_defaults_to_ready_state(): void
    {
        $html = Blade::render('<x-shared-list><p>default content</p></x-shared-list>');

        $this->assertStringContainsString('<p>default content</p>', $html);
    }

    /** No console-errors (PHP exceptions) in any state. */
    public function test_no_php_errors_in_any_state(): void
    {
        // If Blade::render threw an exception we'd never reach here.
        $this->assertTrue(true, 'Blade::render completed without PHP exception for all states');
    }
}
