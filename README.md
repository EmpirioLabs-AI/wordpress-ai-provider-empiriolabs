# AI Provider for EmpirioLabs AI

A WordPress plugin that adds [EmpirioLabs AI](https://empiriolabs.ai) as a provider for the AI Client built into WordPress 7.0 and later. Any plugin or feature that uses the AI Client can then run EmpirioLabs AI text, image, and embedding models.

EmpirioLabs AI is specialized AI model hosting for open, proprietary, and custom stacks. We run open-source models on GPU infrastructure we manage, serve optimized endpoints for proprietary models, and help teams deploy their own models to real users. The catalog spans text, image, video, audio, transcription, embedding, reranking, research, and 3D models, with a browser playground, GPU Cloud instances, and hosted AI agents.

## Requirements

- WordPress 7.0 or later
- PHP 7.4 or later
- An EmpirioLabs AI API key from the [API Keys page](https://platform.empiriolabs.ai/dashboard/api-keys)

## Setup

1. Install and activate the plugin.
2. Go to **Settings > Connectors** and paste your API key into the EmpirioLabs AI connector.

You can also set the key with the `EMPIRIOLABS_API_KEY` environment variable or a PHP constant of the same name in `wp-config.php`.

## Usage

The provider ID is `empiriolabs`.

```php
$text = wp_ai_client_prompt( 'Write a two-sentence welcome message for a bakery website.' )
	->using_provider( 'empiriolabs' )
	->generate_text();

$image = wp_ai_client_prompt( 'A watercolor painting of a lighthouse at dawn' )
	->using_provider( 'empiriolabs' )
	->using_model_preference( 'MODEL_ID' )
	->as_output_media_aspect_ratio( '16:9' )
	->generate_image();
```

Replace `MODEL_ID` with a model ID from [empiriolabs.ai/models](https://empiriolabs.ai/models). Without a model preference, the AI Client uses the first EmpirioLabs AI model that supports the request.

## What the plugin supports

| AI Client capability | Supported options |
| --- | --- |
| Text generation | System instruction, chat history, maximum tokens, temperature, top P, top K, stop sequences, presence and frequency penalty, candidate count, log probabilities, function calling, JSON output and output schemas, image and audio input. Each model lists only the options it accepts. |
| Image generation | Candidate count, inline or remote output, output MIME type, aspect ratio, and orientation. Each model lists only the options it accepts. |
| Embedding generation | Text input and dimensions. Needs version 1.4.0 or later of the AI Client (WordPress 7.2 and later). |

The capabilities and options of each model come from the live model catalog at `GET https://api.empiriolabs.ai/v1/models`, so new models are available without a plugin update.

## External service

The plugin sends requests to the EmpirioLabs AI API (`https://api.empiriolabs.ai`). See the External services section of `readme.txt`, the [Terms of Service](https://empiriolabs.ai/terms-of-service), and the [Privacy Policy](https://empiriolabs.ai/privacy-policy).

## Support

[Documentation](https://docs.empiriolabs.ai) and support@empiriolabs.ai.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
