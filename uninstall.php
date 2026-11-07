<?php

/**
 * Uninstall handler: removes the EmpirioLabs AI API key saved on the Settings > Connectors screen.
 *
 * A key set through the EMPIRIOLABS_API_KEY environment variable or PHP constant lives outside the
 * database and is not touched.
 *
 * @since 1.0.0
 *
 * @package EmpirioLabs\AiProvider
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// A closure keeps the variables out of the global scope.
(static function (): void {
    $optionName = 'connectors_ai_empiriolabs_api_key';

    if (!is_multisite()) {
        delete_option($optionName);
        return;
    }

    $siteIds = get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    );
    foreach ($siteIds as $siteId) {
        switch_to_blog((int) $siteId);
        delete_option($optionName);
        restore_current_blog();
    }
})();
