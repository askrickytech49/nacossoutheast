<?php
declare(strict_types=1);

function nacos_payment_method_settings(?PDO $pdo = null): array
{
    $settings = ['automatic_enabled' => true, 'manual_enabled' => true, 'default_method' => 'automatic'];
    try {
        $pdo = $pdo ?? nacos_db();
        $row = $pdo->query('SELECT automatic_enabled, manual_enabled, default_method FROM payment_method_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $settings['automatic_enabled'] = (bool) $row['automatic_enabled'];
            $settings['manual_enabled'] = (bool) $row['manual_enabled'];
            $settings['default_method'] = in_array($row['default_method'], ['automatic', 'manual'], true) ? $row['default_method'] : 'automatic';
        }
    } catch (Throwable $error) {
        // Keep the current checkout behavior until the settings migration is installed.
    }
    if (!$settings['automatic_enabled'] && !$settings['manual_enabled']) {
        $settings['manual_enabled'] = true;
    }
    if (($settings['default_method'] === 'automatic' && !$settings['automatic_enabled']) || ($settings['default_method'] === 'manual' && !$settings['manual_enabled'])) {
        $settings['default_method'] = $settings['automatic_enabled'] ? 'automatic' : 'manual';
    }
    return $settings;
}
