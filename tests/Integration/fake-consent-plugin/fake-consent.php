<?php
/**
 * Plugin Name: Fake Consent (Test Only)
 * Description: Simulates cookie consent for integration testing.
 */

add_filter('kntnt_ad_attr_has_consent', function (): ?bool {
    $state = get_option('test_consent_state', 'default');
    return match ($state) {
        'granted' => true,
        'denied'  => false,
        'pending' => null,
        default   => null, // 'default' = no filter effect
    };
});
