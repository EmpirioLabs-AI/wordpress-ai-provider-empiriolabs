=== AI Provider for EmpirioLabs AI ===
Contributors:      EMPIRIOLABS_WPORG_USER
Tags:              ai, artificial-intelligence, connector, llm, image-generation
Requires at least: 7.0
Tested up to:      7.1
Stable tag:        1.0.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Adds EmpirioLabs AI as a provider for the WordPress AI Client, with text, image, and embedding models from the EmpirioLabs AI catalog.

== Description ==

EmpirioLabs AI is specialized AI model hosting for open, proprietary, and custom stacks. We run open-source models on GPU infrastructure we manage, serve optimized endpoints for proprietary models, and help teams deploy their own models to real users. The catalog spans text, image, video, audio, transcription, embedding, reranking, research, and 3D models, with a browser playground, GPU Cloud instances, and hosted AI agents.

This plugin registers EmpirioLabs AI with the AI Client built into WordPress, so any plugin or feature that uses the AI Client can run EmpirioLabs AI models. Add your API key on the Settings > Connectors screen and EmpirioLabs AI appears with the other AI providers on your site.

**Features**

* Text generation with chat models, including system instructions, multi-turn conversations, function calling, and JSON output on the models that support them.
* Image and audio input for the models that accept them.
* Image generation, with aspect ratio, orientation, number of images, and output format on the models that offer them.
* Embedding generation on WordPress versions whose AI Client includes embeddings (WordPress 7.2 and later).
* The model list loads from the EmpirioLabs AI catalog, so new models are available without a plugin update.
* The API key can come from the Settings > Connectors screen, the `EMPIRIOLABS_API_KEY` environment variable, or a PHP constant of the same name.

**Using EmpirioLabs AI in your own code**

Any prompt sent through the AI Client can run on EmpirioLabs AI. To require it for a prompt:

`
$text = wp_ai_client_prompt( 'Write a two-sentence welcome message for a bakery website.' )
    ->using_provider( 'empiriolabs' )
    ->generate_text();
`

To prefer a specific model, pass its model ID, for example `using_model_preference( 'MODEL_ID' )`. Model IDs are listed at [empiriolabs.ai/models](https://empiriolabs.ai/models). Without a preference, the AI Client picks the first EmpirioLabs AI model that supports the request.

== Installation ==

1. Install the plugin from **Plugins > Add New**, or upload the `ai-provider-for-empiriolabs-ai` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin on the **Plugins** screen.
3. Create an API key on the [API Keys page](https://platform.empiriolabs.ai/dashboard/api-keys) of the EmpirioLabs AI dashboard.
4. Go to **Settings > Connectors** and paste the key into the EmpirioLabs AI connector.

To set the key outside the database, define `EMPIRIOLABS_API_KEY` as an environment variable or as a constant in `wp-config.php`:

`
define( 'EMPIRIOLABS_API_KEY', 'your-api-key' );
`

== Frequently Asked Questions ==

= How do I get an API key? =

Sign in or create an account at [platform.empiriolabs.ai](https://platform.empiriolabs.ai), then create a key on the [API Keys page](https://platform.empiriolabs.ai/dashboard/api-keys). Requests use the credits on your EmpirioLabs AI account at the prices on the [pricing page](https://empiriolabs.ai/pricing).

= Which models can I use? =

The text, image, and embedding models in the EmpirioLabs AI catalog that the AI Client can call. The plugin reads the model list from the API, so new models appear without a plugin update. Browse the models at [empiriolabs.ai/models](https://empiriolabs.ai/models).

= Does the plugin need WordPress 7.0? =

Yes. The plugin adds a provider to the AI Client that ships with WordPress 7.0 and later.

= An image request fails after 30 seconds =

WordPress allows each AI Client request 30 seconds by default, and some image models take longer. Raise the limit with the `wp_ai_client_default_request_timeout` filter, for example to 120 seconds:

`
add_filter( 'wp_ai_client_default_request_timeout', function () {
    return 120;
} );
`

= Where is my API key stored? =

A key saved on the Settings > Connectors screen is stored in your site's database, and WordPress shows only its last four characters afterward. Deleting the plugin removes the saved key. A key set with the `EMPIRIOLABS_API_KEY` environment variable or constant is never stored in the database.

= Where can I get help? =

Read the [EmpirioLabs AI documentation](https://docs.empiriolabs.ai) or email support@empiriolabs.ai.

== External services ==

This plugin connects to the EmpirioLabs AI API at `https://api.empiriolabs.ai` to list and run models. The service is required for every feature of the plugin, and the plugin sends nothing until an API key is set.

What is sent, and when:

* When WordPress checks the connection, for example when you save a key on the Settings > Connectors screen or when a request needs a model, the plugin requests the list of models with your API key.
* When a plugin or feature on your site generates text, images, or embeddings through the AI Client with EmpirioLabs AI, the plugin sends the content of that request (the prompt, earlier messages of the conversation, the system instruction, any attached images or audio, and the generation settings), the model ID, and your API key.
* Every request identifies the plugin by its name and version in the User-Agent header.

The service is provided by EmpirioLabs.ai LLC: [Terms of Service](https://empiriolabs.ai/terms-of-service), [Privacy Policy](https://empiriolabs.ai/privacy-policy).

== Screenshots ==

1. Add your EmpirioLabs AI API key on the Settings > Connectors screen.
2. EmpirioLabs AI connected and available to the AI Client.

== Changelog ==

= 1.0.0 =

* Initial release: text generation, image generation, and embedding generation with EmpirioLabs AI models through the WordPress AI Client.
