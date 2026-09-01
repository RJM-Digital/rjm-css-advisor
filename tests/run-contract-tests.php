<?php

define( 'ABSPATH', __DIR__ );

$GLOBALS['rjm_test_options'] = [];

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $text ) {
	return $text;
}

function get_option( $key, $default = false ) {
	return $GLOBALS['rjm_test_options'][ $key ] ?? $default;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-github-client.php';

$failures = [];
$checks   = 0;

function rjm_assert( $condition, $message ) {
	global $checks, $failures;
	$checks++;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function rjm_invoke_private( $object, $method, $arguments = [] ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $arguments );
}

$client = new RJM_CSS_Advisor_GitHub_Client();
$option = RJM_CSS_Advisor_Settings::OPTION_KEY;

$GLOBALS['rjm_test_options'][ $option ] = [
	'ai_provider'            => 'openai',
	'openai_reasoning_effort' => 'medium',
];
rjm_assert( 'gpt-5.6' === RJM_CSS_Advisor_Settings::get_model(), 'OpenAI defaults to gpt-5.6.' );
rjm_assert( 'medium' === RJM_CSS_Advisor_Settings::get_openai_reasoning_effort(), 'OpenAI reasoning defaults to medium.' );

$GLOBALS['rjm_test_options'][ $option ]['openai_model'] = 'gpt-5.6';
$payload = rjm_invoke_private(
	$client,
	'build_openai_responses_payload',
	[ 'Inspect this component.', 'Return useful CSS.', [ 'data:image/png;base64,AAAA' ], 'css' ]
);
rjm_assert( 'gpt-5.6' === $payload['model'], 'Responses payload uses the configured OpenAI model.' );
rjm_assert( false === $payload['store'], 'Responses payload disables OpenAI storage.' );
rjm_assert( 'medium' === $payload['reasoning']['effort'], 'Responses payload includes reasoning effort.' );
rjm_assert( 'low' === $payload['text']['verbosity'], 'Responses payload uses low text verbosity.' );
rjm_assert( 'json_schema' === $payload['text']['format']['type'], 'CSS profile uses Structured Outputs.' );
rjm_assert( 'input_text' === $payload['input'][0]['content'][0]['type'], 'Responses text uses input_text.' );
rjm_assert( 'input_image' === $payload['input'][0]['content'][1]['type'], 'Responses images use input_image.' );
rjm_assert( 'data:image/png;base64,AAAA' === $payload['input'][0]['content'][1]['image_url'], 'Responses image preserves its validated data URL.' );

$title_payload = rjm_invoke_private( $client, 'build_openai_responses_payload', [ 'Name this chat.', 'Return a title.', [], 'title' ] );
rjm_assert( ! isset( $title_payload['text']['format'] ), 'Title profile remains plain text.' );
rjm_assert( 5000 === $title_payload['max_output_tokens'], 'Title profile has a smaller output ceiling.' );

$response = [
	'body' => json_encode( [
		'status' => 'completed',
		'output' => [
			[ 'type' => 'reasoning', 'summary' => [] ],
			[ 'type' => 'message', 'content' => [ [ 'type' => 'output_text', 'text' => 'First' ] ] ],
			[ 'type' => 'message', 'content' => [ [ 'type' => 'output_text', 'text' => ' second' ] ] ],
		],
	] ),
];
$content = rjm_invoke_private( $client, 'extract_openai_response_content', [ $response ] );
rjm_assert( 'First second' === $content, 'Blocking parser aggregates output_text across message items.' );

$incomplete = rjm_invoke_private(
	$client,
	'extract_openai_response_content',
	[ [ 'body' => json_encode( [ 'status' => 'incomplete', 'incomplete_details' => [ 'reason' => 'max_output_tokens' ] ] ) ] ]
);
rjm_assert( $incomplete instanceof WP_Error, 'Incomplete Responses return WP_Error.' );
rjm_assert( 'openai_incomplete' === $incomplete->get_error_code(), 'Incomplete Responses use a specific error code.' );

$delta = rjm_invoke_private( $client, 'parse_ai_stream_frame', [ [ 'type' => 'response.output_text.delta', 'delta' => 'Hello' ], 'openai' ] );
rjm_assert( 'Hello' === $delta['delta'] && '' === $delta['error'], 'Responses stream parser extracts text deltas.' );

$stream_error = rjm_invoke_private(
	$client,
	'parse_ai_stream_frame',
	[ [ 'type' => 'response.incomplete', 'response' => [ 'incomplete_details' => [ 'reason' => 'content_filter' ] ] ], 'openai' ]
);
rjm_assert( false !== strpos( $stream_error['error'], 'content_filter' ), 'Responses stream parser surfaces incomplete reasons.' );

$GLOBALS['rjm_test_options'][ $option ] = [
	'ai_provider'  => 'copilot',
	'copilot_model' => 'gpt-4.1',
];
$copilot_payload = rjm_invoke_private( $client, 'build_copilot_chat_payload', [ 'Hello', 'System', [] ] );
rjm_assert( 'gpt-4.1' === $copilot_payload['model'], 'Copilot retains its provider-specific model.' );
rjm_assert( isset( $copilot_payload['messages'] ) && ! isset( $copilot_payload['input'] ), 'Copilot retains Chat Completions payload shape.' );

$copilot_delta = rjm_invoke_private(
	$client,
	'parse_ai_stream_frame',
	[ [ 'choices' => [ [ 'delta' => [ 'content' => 'Legacy' ] ] ] ], 'copilot' ]
);
rjm_assert( 'Legacy' === $copilot_delta['delta'], 'Copilot stream parsing remains unchanged.' );

if ( $failures ) {
	fwrite( STDERR, "Contract tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "{$checks} contract checks passed.\n";
