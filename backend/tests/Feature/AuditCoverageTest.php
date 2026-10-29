<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuditCoverageTest extends TestCase
{
    /** Every model has a morph alias, so auditing or notifying about any record can never fail. */
    public function test_every_model_can_be_audited(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $alias = (new $class)->getMorphClass();
            $this->assertNotSame($class, $alias, "{$class} has no morph alias");
        }
    }

    public function test_disabling_a_payment_method_succeeds_and_is_audited(): void
    {
        $owner = $this->staff('owner');
        $cash = \App\Models\PaymentMethod::where('code', 'cash')->first();
        $this->actingAs($owner)->putJson("/api/admin/payment-methods/{$cash->id}", [
            'name' => 'Cash', 'code' => 'cash', 'type' => 'cash', 'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_method.saved', 'auditable_type' => 'payment_method', 'auditable_id' => $cash->id]);
    }
}
