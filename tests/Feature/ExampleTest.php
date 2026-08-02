<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_redirects_guests_toward_login(): void
    {
        $this->get('/')
            ->assertRedirect('/imports');

        $this->get('/imports')
            ->assertRedirect('/login');
    }
}
