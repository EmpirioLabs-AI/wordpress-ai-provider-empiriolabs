<?php

/**
 * Plugin Name:       EmpirioLabs AI Provider
 * Plugin URI:        https://github.com/EmpirioLabs-AI/wordpress-ai-provider-empiriolabs
 * Description:       Adds EmpirioLabs AI as a provider for the WordPress AI Client, with text, image, and embedding models.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           1.0.0
 * Author:            EmpirioLabs AI
 * Author URI:        https://empiriolabs.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       empiriolabs-ai-provider
 *
 * @package EmpirioLabs\AiProvider
 */

declare(strict_types=1);

namespace EmpirioLabs\AiProvider;

use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    return;
}

// Preserve the path beneath WP_PLUGIN_DIR when WordPress loads the plugin through a symlink.
if (!defined('EMPIRIOLABS_AI_PROVIDER_PLUGIN_DIR')) {
    define(
        'EMPIRIOLABS_AI_PROVIDER_PLUGIN_DIR',
        WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__))
    );
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the EmpirioLabs AI provider with the AI Client.
 *
 * Runs before the Connectors screen reads the AI Client registry, so the provider
 * appears there with its API key field.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(EmpirioLabsProvider::class)) {
        return;
    }

    $registry->registerProvider(EmpirioLabsProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
