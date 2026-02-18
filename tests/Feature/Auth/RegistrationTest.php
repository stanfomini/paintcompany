<?php

test('registration is disabled (invitation-only system)', function () {
    // Registration was intentionally disabled in config/fortify.php
    // New users must be invited via the InvitationService
    expect(Illuminate\Support\Facades\Route::has('register'))->toBeFalse();
});
