<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasicAuthGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_is_off_when_credentials_are_not_configured()
    {
        config(['auth.basic_gate' => ['user' => null, 'password' => null]]);

        $this->get(route('who.index'))->assertOk();
    }

    public function test_gate_is_off_when_only_one_credential_is_configured()
    {
        config(['auth.basic_gate' => ['user' => 'impruve', 'password' => '']]);

        $this->get(route('who.index'))->assertOk();
    }

    public function test_requests_without_credentials_are_rejected_when_the_gate_is_on()
    {
        $this->enableGate();

        $this->get(route('who.index'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_requests_with_wrong_credentials_are_rejected()
    {
        $this->enableGate();

        $this->withBasicAuth('impruve', 'wrong')
            ->get(route('who.index'))
            ->assertUnauthorized();
    }

    public function test_requests_with_correct_credentials_pass()
    {
        $this->enableGate();

        $this->withBasicAuth('impruve', 'secret')
            ->get(route('who.index'))
            ->assertOk();
    }

    public function test_gate_covers_every_route()
    {
        $this->enableGate();

        $this->post(route('who.store'))->assertUnauthorized();
        $this->get('/')->assertUnauthorized();
    }

    private function enableGate(): void
    {
        config(['auth.basic_gate' => ['user' => 'impruve', 'password' => 'secret']]);
    }
}
