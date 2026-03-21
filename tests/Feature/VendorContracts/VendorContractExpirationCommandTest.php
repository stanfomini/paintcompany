<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Notifications\VendorContractExpired;
use App\Notifications\VendorContractExpiringSoon;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

describe('vendor-contracts:check-expiration command', function () {
    it('sends 30-day expiry warning', function () {
        Notification::fake();

        $vendor = Vendor::factory()->create(['email' => 'vendor@example.com']);
        $creator = User::factory()->internal()->create();
        $signer = User::factory()->internal()->create();

        $contract = VendorContract::factory()->fullyExecuted()->withExpiration(20)->create([
            'vendor_id' => $vendor->id,
            'created_by' => $creator->id,
        ]);

        VendorContractSigner::factory()->signed()->create([
            'vendor_contract_id' => $contract->id,
            'user_id' => $signer->id,
        ]);

        $this->artisan('vendor-contracts:check-expiration')
            ->assertSuccessful();

        Notification::assertSentTo($signer, VendorContractExpiringSoon::class);
        Notification::assertSentTo($creator, VendorContractExpiringSoon::class);
        Notification::assertSentTo(
            new AnonymousNotifiable,
            VendorContractExpiringSoon::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === 'vendor@example.com';
            }
        );

        $contract->refresh();
        expect($contract->expiry_warning_sent_at)->not->toBeNull();
    });

    it('does not re-send 30-day warning', function () {
        Notification::fake();

        $contract = VendorContract::factory()->fullyExecuted()->withExpiration(20)->create([
            'expiry_warning_sent_at' => now()->subDays(5),
        ]);

        VendorContractSigner::factory()->signed()->create([
            'vendor_contract_id' => $contract->id,
        ]);

        $this->artisan('vendor-contracts:check-expiration')
            ->assertSuccessful();

        Notification::assertNotSentTo(
            [$contract->creator],
            VendorContractExpiringSoon::class,
        );
    });

    it('auto-expires contracts past expiration', function () {
        Notification::fake();

        $vendor = Vendor::factory()->create(['email' => 'expired-vendor@example.com']);
        $creator = User::factory()->internal()->create();
        $signer = User::factory()->internal()->create();

        $contract = VendorContract::factory()->fullyExecuted()->create([
            'vendor_id' => $vendor->id,
            'created_by' => $creator->id,
            'expires_at' => now()->subDay(),
        ]);

        VendorContractSigner::factory()->signed()->create([
            'vendor_contract_id' => $contract->id,
            'user_id' => $signer->id,
        ]);

        $this->artisan('vendor-contracts:check-expiration')
            ->assertSuccessful();

        $contract->refresh();
        expect($contract->status)->toBe(VendorContract::STATUS_EXPIRED)
            ->and($contract->is_valid)->toBeFalse();

        Notification::assertSentTo($signer, VendorContractExpired::class);
        Notification::assertSentTo($creator, VendorContractExpired::class);
        Notification::assertSentTo(
            new AnonymousNotifiable,
            VendorContractExpired::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === 'expired-vendor@example.com';
            }
        );
    });

    it('skips evergreen contracts with null expires_at', function () {
        Notification::fake();

        $contract = VendorContract::factory()->fullyExecuted()->create([
            'expires_at' => null,
        ]);

        $this->artisan('vendor-contracts:check-expiration')
            ->assertSuccessful();

        $contract->refresh();
        expect($contract->status)->toBe(VendorContract::STATUS_FULLY_EXECUTED)
            ->and($contract->is_valid)->toBeTrue();

        Notification::assertNothingSent();
    });
});
