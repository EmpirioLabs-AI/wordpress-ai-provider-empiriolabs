# EmpirioLabs AI Provider

A WordPress plugin that adds [EmpirioLabs AI](https://empiriolabs.ai) as a provider for the AI Client built into WordPress 7.0 and later. Any plugin or feature that uses the AI Client can then run EmpirioLabs AI text, image, speech, video, and embedding models.

EmpirioLabs AI is specialized AI model hosting for open, proprietary, and custom stacks. We run open-source models on GPU infrastructure we manage, serve optimized endpoints for proprietary models, and help teams deploy their own models to real users. The catalog spans text, image, video, audio, transcription, embedding, reranking, research, and 3D models, with a browser playground, GPU Cloud instances, and hosted AI agents.

## Requirements

- WordPress 7.0 or later
- PHP 7.4 or later
- An EmpirioLabs AI API key from the [API Keys page](https://platform.empiriolabs.ai/dashboard/api-keys)

## Setup

1. Download `empiriolabs-ai-provider.zip` from the [latest release](https://github.com/EmpirioLabs-AI/wordpress-ai-provider-empiriolabs/releases/latest).
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**, choose the file, and activate the plugin.
3. Go to **Settings > Connectors** and paste your API key into the EmpirioLabs AI connector.

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

### Speech

```php
$audio = wp_ai_client_prompt( 'Welcome to our bakery. We open at seven every morning.' )
	->using_provider( 'empiriolabs' )
	->using_model_preference( 'MODEL_ID' )
	->as_output_speech_voice( 'VOICE' )
	->as_output_mime_type( 'audio/mpeg' )
	->convert_text_to_speech();
```

Replace `VOICE` with one of the model's voices, which its page in the [documentation](https://docs.empiriolabs.ai) lists. Without a voice, the model speaks with its default voice.

### Video

A video takes minutes to generate. Start it as an operation, keep the operation ID, and read the result in a later request:

```php
use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

$config = new ModelConfig();
$config->setOutputMediaAspectRatio( '16:9' );
$config->setCustomOption( 'duration', 5 );

$model     = AiClient::defaultRegistry()->getProviderModel( 'empiriolabs', 'MODEL_ID', $config );
$operation = $model->generateVideoOperation(
	array( new UserMessage( array( new MessagePart( 'A paper boat drifting down a rainy street' ) ) ) )
);
update_option( 'my_video_operation_id', $operation->getId() );

// Later, for example in a scheduled task:
$operation = EmpirioLabsProvider::operationsHandler()->getOperation( get_option( 'my_video_operation_id' ) );
if ( $operation->getState()->isSucceeded() ) {
	$video_url = $operation->getResult()->toVideoFile()->getUrl();
} elseif ( $operation->getState()->isFailed() ) {
	$reason = $operation->getErrorMessage();
}
```

The video is returned as a link to the file. Download it to keep it on your site. These calls throw an exception when a request fails.

`wp_ai_client_prompt( ... )->generate_video()` is also available. It waits for the video for as long as the request timeout allows (30 seconds by default, see the `wp_ai_client_default_request_timeout` filter). A video that needs longer keeps running, and the error message contains its operation ID.

## What the plugin supports

| AI Client capability | Supported options |
| --- | --- |
| Text generation | System instruction, chat history, maximum tokens, temperature, top P, top K, stop sequences, presence and frequency penalty, candidate count, log probabilities, function calling, JSON output and output schemas, image and audio input. Each model lists only the options it accepts. |
| Image generation | Candidate count, inline or remote output, output MIME type, aspect ratio, and orientation. Each model lists only the options it accepts. |
| Text-to-speech conversion | Voice, output MIME type, and inline or remote output. Each model lists only the options it accepts. |
| Video generation | A description, with a source image given as a link on the models that take one, aspect ratio, and orientation, with the video returned as a link. Each model lists only the options it accepts. Available as an operation that is read later, or as a call that waits. |
| Embedding generation | Text input and dimensions. Needs version 1.4.0 or later of the AI Client (WordPress 7.2 and later). |

A parameter of a model that the AI Client has no option for, such as the length or the file format of a video, is passed as a custom option of the model configuration.

The AI Client's speech generation and music generation capabilities are not offered. To turn text into audio, use text-to-speech conversion.

The capabilities and options of each model come from the live model catalog at `GET https://api.empiriolabs.ai/v1/models`, so new models are available without a plugin update.

## External service

The plugin sends requests to the EmpirioLabs AI API (`https://api.empiriolabs.ai`). See the External services section of `readme.txt`, the [Terms of Service](https://empiriolabs.ai/terms-of-service), and the [Privacy Policy](https://empiriolabs.ai/privacy-policy).

## Releases

Each release's package is built from its tag by GitHub Actions and carries a build provenance attestation. To verify a download:

```bash
gh attestation verify empiriolabs-ai-provider.zip --repo EmpirioLabs-AI/wordpress-ai-provider-empiriolabs
```

## Support

[Documentation](https://docs.empiriolabs.ai) and support@empiriolabs.ai.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
