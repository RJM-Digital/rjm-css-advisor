<?php
/**
 * Settings page for RJM CSS Advisor.
 *
 * Stores all configuration in a single WordPress option: `rjm_css_advisor_settings`.
 * The GitHub PAT and OpenAI API key are encrypted at rest using the site's AUTH_KEY.
 *
 * AI provider options:
 *  - 'copilot'  — GitHub Copilot Business API (default). Requires a GitHub PAT tied to a Copilot seat.
 *  - 'openai'   — OpenAI API. Requires a separate OpenAI API key; the GitHub PAT is still needed to
 *                 fetch component source files from GitHub.
 */

defined( 'ABSPATH' ) || exit;

class RJM_CSS_Advisor_Settings {

	const OPTION_KEY = 'rjm_css_advisor_settings';
	const DEBUG_OPTION_KEY = 'rjm_css_advisor_debug_logs';
	const PAGE_SLUG  = 'rjm-css-advisor';
	const DEBUG_LOG_LIMIT = 100;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_show_notices' ] );
		add_filter( 'allowed_http_origins', [ __CLASS__, 'add_cors_origins' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'handle_rest_cors' ] );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public static function add_menu_page() {
		add_options_page(
			__( 'RJM CSS Advisor', 'rjm-css-advisor' ),
			__( 'RJM CSS Advisor', 'rjm-css-advisor' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public static function register_settings() {
		register_setting(
			self::OPTION_KEY . '_group',
			self::OPTION_KEY,
			[ 'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ] ]
		);

		add_settings_section(
			'rjm_css_advisor_main',
			__( 'GitHub & AI Connection', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_section_intro' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'github_token',
			__( 'GitHub Fine-Grained PAT', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_token' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'github_repo',
			__( 'GitHub Repository', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_repo' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'github_branch',
			__( 'Branch', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_branch' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'ai_provider',
			__( 'AI Provider', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_ai_provider' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_openai_key' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'copilot_model',
			__( 'Copilot Model', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_copilot_model' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'openai_model',
			__( 'OpenAI Model', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_openai_model' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_field(
			'openai_reasoning_effort',
			__( 'OpenAI Reasoning Effort', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_openai_reasoning_effort' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_main'
		);

		add_settings_section(
			'rjm_css_advisor_cache',
			__( 'Cache Settings', 'rjm-css-advisor' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'cache_ttl',
			__( 'Cache Duration (minutes)', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_cache_ttl' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_cache'
		);

		add_settings_section(
			'rjm_css_advisor_headless',
			__( 'Headless WordPress (CORS)', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_section_headless' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'cors_origins',
			__( 'Allowed Admin Origins', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_cors_origins' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_headless'
		);

		add_settings_section(
			'rjm_css_advisor_debugging',
			__( 'Debugging', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_section_debugging' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'debug_logging_enabled',
			__( 'Enable Debug Logging', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_debug_logging_enabled' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_debugging'
		);

		add_settings_field(
			'disable_css_edit_access',
			__( 'Disable Custom CSS Edit Access', 'rjm-css-advisor' ),
			[ __CLASS__, 'render_field_disable_css_edit_access' ],
			self::PAGE_SLUG,
			'rjm_css_advisor_debugging'
		);
	}

	// -------------------------------------------------------------------------
	// Sanitize
	// -------------------------------------------------------------------------

	public static function sanitize_settings( $input ) {
		$existing = self::get_settings();
		$clean    = [];

		if ( ! defined( 'AUTH_KEY' ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'no_auth_key',
				__( 'WARNING: AUTH_KEY is not defined in wp-config.php. The GitHub token cannot be encrypted and will not be saved. Please define AUTH_KEY to use this plugin.', 'rjm-css-advisor' ),
				'error'
			);
			$clean['github_token_encrypted']  = $existing['github_token_encrypted'] ?? '';
			$clean['openai_key_encrypted']    = $existing['openai_key_encrypted'] ?? '';
		} else {
			// GitHub token: only update if a new non-placeholder value was submitted.
			$submitted_token = isset( $input['github_token'] ) ? trim( $input['github_token'] ) : '';
			if ( $submitted_token && $submitted_token !== '••••••••••••••••' ) {
				$clean['github_token_encrypted'] = self::encrypt( $submitted_token );
			} else {
				$clean['github_token_encrypted'] = $existing['github_token_encrypted'] ?? '';
			}

			// OpenAI key: same placeholder-guard pattern.
			$submitted_openai = isset( $input['openai_api_key'] ) ? trim( $input['openai_api_key'] ) : '';
			if ( $submitted_openai && $submitted_openai !== '••••••••••••••••' ) {
				$clean['openai_key_encrypted'] = self::encrypt( $submitted_openai );
			} else {
				$clean['openai_key_encrypted'] = $existing['openai_key_encrypted'] ?? '';
			}
		}

		$allowed_providers      = [ 'copilot', 'openai' ];
		$clean['ai_provider']   = in_array( $input['ai_provider'] ?? '', $allowed_providers, true )
								  ? $input['ai_provider']
								  : 'copilot';

		$default_repo           = defined( 'RJM_CSS_ADVISOR_REPO' ) ? RJM_CSS_ADVISOR_REPO : 'RJM-Digital/import-template-coach';
		$default_branch         = defined( 'RJM_CSS_ADVISOR_BRANCH' ) ? RJM_CSS_ADVISOR_BRANCH : 'main';
		$clean['github_repo']   = sanitize_text_field( $input['github_repo'] ?? $default_repo );
		$clean['github_branch'] = sanitize_text_field( $input['github_branch'] ?? $default_branch );

		$allowed_copilot_models = [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'claude-3.5-sonnet', 'o1-mini' ];
		$copilot_model = sanitize_text_field( $input['copilot_model'] ?? 'gpt-4o' );
		$clean['copilot_model'] = in_array( $copilot_model, $allowed_copilot_models, true ) ? $copilot_model : 'gpt-4o';

		$allowed_openai_models = [ 'gpt-5.6', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.5', 'gpt-4o' ];
		$openai_model = sanitize_text_field( $input['openai_model'] ?? 'gpt-5.6' );
		$clean['openai_model'] = in_array( $openai_model, $allowed_openai_models, true ) ? $openai_model : 'gpt-5.6';

		$allowed_reasoning_efforts = [ 'none', 'low', 'medium', 'high', 'xhigh' ];
		$reasoning_effort = sanitize_key( $input['openai_reasoning_effort'] ?? 'medium' );
		$clean['openai_reasoning_effort'] = in_array( $reasoning_effort, $allowed_reasoning_efforts, true ) ? $reasoning_effort : 'medium';
		$clean['cache_ttl']     = max( 1, intval( $input['cache_ttl'] ?? 60 ) );
		$clean['debug_logging_enabled'] = ! empty( $input['debug_logging_enabled'] ) ? '1' : '0';
		$clean['disable_css_edit_access'] = ! empty( $input['disable_css_edit_access'] ) ? '1' : '0';

		// Sanitize cors_origins: one URL per line, strip blank lines.
		$raw_origins = isset( $input['cors_origins'] ) ? (string) $input['cors_origins'] : '';
		$origins     = array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( "\n", $raw_origins ) ) ) );
		$clean['cors_origins'] = implode( "\n", $origins );

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Render settings page
	// -------------------------------------------------------------------------

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle cache clear action.
		if (
			isset( $_POST['rjm_clear_cache'] ) &&
			check_admin_referer( 'rjm_css_advisor_clear_cache' )
		) {
			self::clear_all_cache();
			add_settings_error(
				self::OPTION_KEY,
				'cache_cleared',
				__( 'AI CSS cache cleared successfully.', 'rjm-css-advisor' ),
				'success'
			);
		}

		if (
			isset( $_POST['rjm_clear_debug_logs'] ) &&
			check_admin_referer( 'rjm_css_advisor_clear_debug_logs' )
		) {
			self::clear_debug_logs();
			add_settings_error(
				self::OPTION_KEY,
				'debug_logs_cleared',
				__( 'Debug logs cleared successfully.', 'rjm-css-advisor' ),
				'success'
			);
		}

		settings_errors( self::OPTION_KEY );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RJM CSS Advisor', 'rjm-css-advisor' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connect to GitHub and an AI provider to generate custom CSS code directly inside the WordPress editor.', 'rjm-css-advisor' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_KEY . '_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'rjm-css-advisor' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Cache Management', 'rjm-css-advisor' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'AI-generated CSS is cached to avoid repeated API calls. Clear the cache after updating your component code in GitHub.', 'rjm-css-advisor' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'rjm_css_advisor_clear_cache' ); ?>
				<?php submit_button( __( 'Clear All Cached Advice', 'rjm-css-advisor' ), 'secondary', 'rjm_clear_cache' ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connection Status', 'rjm-css-advisor' ); ?></h2>
			<?php self::render_connection_status(); ?>

			<hr />

			<h2><?php esc_html_e( 'Debug Console', 'rjm-css-advisor' ); ?></h2>
			<?php self::render_debug_console(); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'Enter your GitHub Fine-Grained Personal Access Token (PAT). The token needs read access to Contents of the repository and is always required to fetch component source files from GitHub.', 'rjm-css-advisor' ) . '</p>';
		echo '<p><a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create a Fine-Grained PAT on GitHub →', 'rjm-css-advisor' ) . '</a></p>';
	}

	public static function render_field_token() {
		$settings = self::get_settings();
		$has_token = ! empty( $settings['github_token_encrypted'] );
		$placeholder = $has_token ? '••••••••••••••••' : __( 'Paste your GitHub PAT here', 'rjm-css-advisor' );
		?>
		<input
			type="password"
			id="rjm_github_token"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[github_token]"
			class="regular-text"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			autocomplete="new-password"
		/>
		<?php if ( $has_token ) : ?>
			<p class="description" style="color: green;">
				✓ <?php esc_html_e( 'A token is saved. Leave blank to keep the existing token.', 'rjm-css-advisor' ); ?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Required. Generate a Fine-Grained PAT with Contents: Read-only access on the repository.', 'rjm-css-advisor' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function render_field_repo() {
		$settings     = self::get_settings();
		$const_active = defined( 'RJM_CSS_ADVISOR_REPO' );
		$default      = $const_active ? RJM_CSS_ADVISOR_REPO : 'RJM-Digital/import-template-coach';
		$value        = $settings['github_repo'] ?? $default;
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[github_repo]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="owner/repository-name"
		/>
		<p class="description"><?php esc_html_e( 'Format: owner/repo-name (e.g. RJM-Digital/client-site-coach)', 'rjm-css-advisor' ); ?></p>
		<?php if ( $const_active ) : ?>
			<p class="description" style="color:#2271b1;">
				ℹ <?php
				printf(
					/* translators: %s: constant name */
					esc_html__( 'Default set by the %s constant in wp-config.php.', 'rjm-css-advisor' ),
					'<code>RJM_CSS_ADVISOR_REPO</code>'
				); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function render_field_branch() {
		$settings     = self::get_settings();
		$const_active = defined( 'RJM_CSS_ADVISOR_BRANCH' );
		$default      = $const_active ? RJM_CSS_ADVISOR_BRANCH : 'main';
		$value        = $settings['github_branch'] ?? $default;
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[github_branch]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="main"
		/>
		<p class="description"><?php esc_html_e( 'The branch to read component source code from. Usually "main".', 'rjm-css-advisor' ); ?></p>
		<?php if ( $const_active ) : ?>
			<p class="description" style="color:#2271b1;">
				ℹ <?php
				printf(
					/* translators: %s: constant name */
					esc_html__( 'Default set by the %s constant in wp-config.php.', 'rjm-css-advisor' ),
					'<code>RJM_CSS_ADVISOR_BRANCH</code>'
				); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function render_field_ai_provider() {
		$settings = self::get_settings();
		$value    = $settings['ai_provider'] ?? 'copilot';
		?>
		<select
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_provider]"
			id="rjm_ai_provider"
		>
			<option value="copilot" <?php selected( $value, 'copilot' ); ?>>
				<?php esc_html_e( 'GitHub Copilot Business', 'rjm-css-advisor' ); ?>
			</option>
			<option value="openai" <?php selected( $value, 'openai' ); ?>>
				<?php esc_html_e( 'OpenAI API (ChatGPT)', 'rjm-css-advisor' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'GitHub Copilot uses your GitHub PAT (requires a Copilot Business seat). OpenAI uses a separate API key from your OpenAI account — no Copilot seat needed.', 'rjm-css-advisor' ); ?>
		</p>
		<?php
	}

	public static function render_field_openai_key() {
		$settings    = self::get_settings();
		$has_key     = ! empty( $settings['openai_key_encrypted'] );
		$provider    = $settings['ai_provider'] ?? 'copilot';
		$placeholder = $has_key ? '••••••••••••••••' : __( 'Paste your OpenAI API key here (sk-…)', 'rjm-css-advisor' );
		?>
		<input
			type="password"
			id="rjm_openai_api_key"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_api_key]"
			class="regular-text"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			autocomplete="new-password"
		/>
		<?php if ( $has_key ) : ?>
			<p class="description" style="color: green;">
				✓ <?php esc_html_e( 'An API key is saved. Leave blank to keep the existing key.', 'rjm-css-advisor' ); ?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to OpenAI API keys page */
					esc_html__( 'Required when using OpenAI. %s', 'rjm-css-advisor' ),
					'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Get your API key from OpenAI →', 'rjm-css-advisor' )
					. '</a>'
				);
				?>
			</p>
		<?php endif; ?>
		<script>
		(function() {
			function rjmSyncProviderRows() {
				var sel = document.getElementById('rjm_ai_provider');
				var openAIModel = document.getElementById('rjm_openai_model');
				var reasoning = document.getElementById('rjm_openai_reasoning_effort');
				if (!sel) {
					return;
				}

				var openAIRows = document.querySelectorAll('.rjm-openai-setting-row');
				var copilotRows = document.querySelectorAll('.rjm-copilot-setting-row');
				var isOpenAI = sel.value === 'openai';
				var index;
				for (index = 0; index < openAIRows.length; index++) {
					openAIRows[index].style.display = isOpenAI ? '' : 'none';
				}
				for (index = 0; index < copilotRows.length; index++) {
					copilotRows[index].style.display = isOpenAI ? 'none' : '';
				}

				if (reasoning) {
					var reasoningRow = reasoning.closest('tr');
					if (reasoningRow) {
						reasoningRow.style.display = isOpenAI && (!openAIModel || openAIModel.value !== 'gpt-4o') ? '' : 'none';
					}
				}
			}

			document.addEventListener('DOMContentLoaded', function() {
				var sel = document.getElementById('rjm_ai_provider');
				var openAIIds = ['rjm_openai_api_key', 'rjm_openai_model', 'rjm_openai_reasoning_effort'];
				var copilotIds = ['rjm_copilot_model'];
				openAIIds.forEach(function(id) {
					var field = document.getElementById(id);
					var row = field ? field.closest('tr') : null;
					if (row) { row.classList.add('rjm-openai-setting-row'); }
				});
				copilotIds.forEach(function(id) {
					var field = document.getElementById(id);
					var row = field ? field.closest('tr') : null;
					if (row) { row.classList.add('rjm-copilot-setting-row'); }
				});
				rjmSyncProviderRows();
				if (sel) {
					sel.addEventListener('change', rjmSyncProviderRows);
				}
				var openAIModel = document.getElementById('rjm_openai_model');
				if (openAIModel) {
					openAIModel.addEventListener('change', rjmSyncProviderRows);
				}
			});
		})();
		</script>
		<?php
	}

	public static function render_field_copilot_model() {
		$settings = self::get_settings();
		$value    = $settings['copilot_model'] ?? 'gpt-4o';
		$models   = [
			'gpt-4o'              => 'GPT-4o (recommended)',
			'gpt-4o-mini'         => 'GPT-4o mini (faster, cheaper)',
			'gpt-4.1'             => 'GPT-4.1',
			'gpt-4.1-mini'        => 'GPT-4.1 mini',
			'claude-3.5-sonnet'   => 'Claude 3.5 Sonnet (Copilot only)',
			'o1-mini'             => 'o1 mini (Copilot only)',
		];
		?>
		<select id="rjm_copilot_model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[copilot_model]">
			<?php foreach ( $models as $model_id => $model_label ) : ?>
				<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $value, $model_id ); ?>>
					<?php echo esc_html( $model_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'The model used when GitHub Copilot Business is selected.', 'rjm-css-advisor' ); ?></p>
		<?php
	}

	public static function render_field_openai_model() {
		$settings = self::get_settings();
		$value    = $settings['openai_model'] ?? 'gpt-5.6';
		$models   = [
			'gpt-5.6'       => 'GPT-5.6 (recommended)',
			'gpt-5.6-terra' => 'GPT-5.6 Terra (balanced cost)',
			'gpt-5.6-luna'  => 'GPT-5.6 Luna (faster, lower cost)',
			'gpt-5.5'       => 'GPT-5.5',
			'gpt-4o'        => 'GPT-4o (legacy fallback)',
		];
		?>
		<select id="rjm_openai_model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_model]">
			<?php foreach ( $models as $model_id => $model_label ) : ?>
				<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $value, $model_id ); ?>>
					<?php echo esc_html( $model_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'OpenAI requests use the Responses API. GPT-5.6 provides the strongest default balance for CSS planning and generation.', 'rjm-css-advisor' ); ?></p>
		<?php
	}

	public static function render_field_openai_reasoning_effort() {
		$settings = self::get_settings();
		$value    = $settings['openai_reasoning_effort'] ?? 'medium';
		$efforts  = [
			'none'   => __( 'None (lowest latency)', 'rjm-css-advisor' ),
			'low'    => __( 'Low', 'rjm-css-advisor' ),
			'medium' => __( 'Medium (recommended)', 'rjm-css-advisor' ),
			'high'   => __( 'High', 'rjm-css-advisor' ),
			'xhigh'  => __( 'Extra high (slowest, highest cost)', 'rjm-css-advisor' ),
		];
		?>
		<select id="rjm_openai_reasoning_effort" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_reasoning_effort]">
			<?php foreach ( $efforts as $effort_id => $effort_label ) : ?>
				<option value="<?php echo esc_attr( $effort_id ); ?>" <?php selected( $value, $effort_id ); ?>>
					<?php echo esc_html( $effort_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Higher effort can improve difficult CSS analysis but increases response time and token cost.', 'rjm-css-advisor' ); ?></p>
		<?php
	}

	public static function render_field_cache_ttl() {
		$settings = self::get_settings();
		$value    = $settings['cache_ttl'] ?? 60;
		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_ttl]"
			value="<?php echo esc_attr( $value ); ?>"
			class="small-text"
			min="1"
			max="10080"
		/>
		<p class="description"><?php esc_html_e( 'How long to cache AI advice per component (1–10080 minutes). Cached responses are reused if the component file on GitHub has not changed.', 'rjm-css-advisor' ); ?></p>
		<?php
	}

	public static function render_section_headless() {
		echo '<p>' . esc_html__( 'When WordPress is running in headless mode, the WordPress address (siteurl) and the admin UI address differ. Enter the origin(s) that should be allowed to make authenticated REST API requests (e.g. the domain serving the WordPress admin).', 'rjm-css-advisor' ) . '</p>';
	}

	public static function render_section_debugging() {
		echo '<p>' . esc_html__( 'Enable structured plugin debugging to store recent request and error diagnostics directly in WordPress admin. Sensitive secrets are never logged.', 'rjm-css-advisor' ) . '</p>';
	}

	public static function render_field_cors_origins() {
		$settings = self::get_settings();
		$value    = $settings['cors_origins'] ?? '';
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cors_origins]"
			rows="4"
			class="large-text code"
			placeholder="https://import.rjmdigital.net"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One origin per line (scheme + host, no trailing slash). These origins will be allowed to make cross-origin REST API requests to this WordPress installation. Required when the admin UI is served from a different domain than the WordPress address.', 'rjm-css-advisor' ); ?>
		</p>
		<?php
	}

	public static function render_field_debug_logging_enabled() {
		$settings = self::get_settings();
		$enabled  = ! empty( $settings['debug_logging_enabled'] );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_KEY ); ?>[debug_logging_enabled]"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Store recent RJM CSS Advisor diagnostics in the admin debug console.', 'rjm-css-advisor' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Recommended when diagnosing request routing, AI provider failures, and Global CSS context issues.', 'rjm-css-advisor' ); ?></p>
		<?php
	}

	public static function render_field_disable_css_edit_access() {
		$settings = self::get_settings();
		$enabled  = ! empty( $settings['disable_css_edit_access'] );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_css_edit_access]"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Disable Custom CSS field edits and CSS generation for non-admin users.', 'rjm-css-advisor' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Admins can still edit and generate CSS while this lock is enabled.', 'rjm-css-advisor' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Connection status
	// -------------------------------------------------------------------------

	private static function render_connection_status() {
		$settings  = self::get_settings();
		$has_token = ! empty( $settings['github_token_encrypted'] );
		$provider  = self::get_ai_provider();

		if ( ! $has_token ) {
			echo '<p style="color:#d63638;">⚠ ' . esc_html__( 'No GitHub PAT saved. Please add your token above.', 'rjm-css-advisor' ) . '</p>';
			return;
		}

		// Verify connectivity to GitHub.
		$github_client = new RJM_CSS_Advisor_GitHub_Client();
		$test_result   = $github_client->test_connection();

		if ( is_wp_error( $test_result ) ) {
			echo '<p style="color:#d63638;">✗ ' . esc_html__( 'GitHub connection failed: ', 'rjm-css-advisor' ) . esc_html( $test_result->get_error_message() ) . '</p>';
		} else {
			echo '<p style="color:#00a32a;">✓ ' . esc_html__( 'GitHub connection OK — repository accessible.', 'rjm-css-advisor' ) . '</p>';
		}

		// Show which AI provider is active.
		if ( $provider === 'openai' ) {
			$has_openai_key = ! empty( $settings['openai_key_encrypted'] );
			if ( $has_openai_key ) {
				echo '<p style="color:#00a32a;">✓ ' . esc_html__( 'AI provider: OpenAI Responses API — key saved.', 'rjm-css-advisor' ) . '</p>';
			} else {
				echo '<p style="color:#d63638;">⚠ ' . esc_html__( 'AI provider: OpenAI Responses API — no API key saved. Please add your OpenAI API key above.', 'rjm-css-advisor' ) . '</p>';
			}
		} else {
			echo '<p style="color:#00a32a;">✓ ' . esc_html__( 'AI provider: GitHub Copilot Business.', 'rjm-css-advisor' ) . '</p>';
		}
	}

	private static function render_debug_console() {
		$entries = self::get_debug_entries();
		$enabled = self::is_debug_enabled();
		$settings = self::get_settings();
		?>
		<p class="description">
			<?php esc_html_e( 'This console stores recent RJM CSS Advisor diagnostics for troubleshooting request flow and AI failures.', 'rjm-css-advisor' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Debug logging:', 'rjm-css-advisor' ); ?></strong>
			<?php echo $enabled ? esc_html__( 'Enabled', 'rjm-css-advisor' ) : esc_html__( 'Disabled', 'rjm-css-advisor' ); ?>
			<br />
			<strong><?php esc_html_e( 'Plugin version:', 'rjm-css-advisor' ); ?></strong>
			<?php echo esc_html( defined( 'RJM_CSS_ADVISOR_VERSION' ) ? RJM_CSS_ADVISOR_VERSION : 'unknown' ); ?>
			<br />
			<strong><?php esc_html_e( 'Repository:', 'rjm-css-advisor' ); ?></strong>
			<?php echo esc_html( self::get_repo() ); ?>
			<br />
			<strong><?php esc_html_e( 'Branch:', 'rjm-css-advisor' ); ?></strong>
			<?php echo esc_html( self::get_branch() ); ?>
			<br />
			<strong><?php esc_html_e( 'AI provider/model:', 'rjm-css-advisor' ); ?></strong>
			<?php echo esc_html( self::get_ai_provider() . ' / ' . self::get_model() ); ?>
			<?php if ( 'openai' === self::get_ai_provider() ) : ?>
				<br />
				<strong><?php esc_html_e( 'OpenAI reasoning effort:', 'rjm-css-advisor' ); ?></strong>
				<?php echo esc_html( self::get_openai_reasoning_effort() ); ?>
			<?php endif; ?>
		</p>
		<form method="post" style="margin: 12px 0 18px;">
			<?php wp_nonce_field( 'rjm_css_advisor_clear_debug_logs' ); ?>
			<?php submit_button( __( 'Clear Debug Logs', 'rjm-css-advisor' ), 'secondary', 'rjm_clear_debug_logs', false ); ?>
		</form>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No debug entries recorded yet.', 'rjm-css-advisor' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width: 100%;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'rjm-css-advisor' ); ?></th>
					<th><?php esc_html_e( 'Source', 'rjm-css-advisor' ); ?></th>
					<th><?php esc_html_e( 'Action', 'rjm-css-advisor' ); ?></th>
					<th><?php esc_html_e( 'Result', 'rjm-css-advisor' ); ?></th>
					<th><?php esc_html_e( 'Details', 'rjm-css-advisor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['source'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['action'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['result'] ?? '' ); ?></td>
						<td>
							<details>
								<summary><?php esc_html_e( 'View details', 'rjm-css-advisor' ); ?></summary>
								<pre style="white-space: pre-wrap; margin: 10px 0 0;"><?php echo esc_html( wp_json_encode( $entry['details'] ?? [], JSON_PRETTY_PRINT ) ); ?></pre>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	public static function maybe_show_notices() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'acf' ) === false ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['github_token_encrypted'] ) ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to settings page */
				esc_html__( 'RJM CSS Advisor: No GitHub token configured. %s to enable AI CSS guidance.', 'rjm-css-advisor' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Add your token', 'rjm-css-advisor' ) . '</a>'
			);
			echo '</p></div>';
		}

		if ( self::get_ai_provider() === 'openai' && empty( $settings['openai_key_encrypted'] ) ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to settings page */
				esc_html__( 'RJM CSS Advisor: OpenAI is selected as the AI provider but no API key is saved. %s to add your OpenAI key.', 'rjm-css-advisor' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Go to settings', 'rjm-css-advisor' ) . '</a>'
			);
			echo '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Cache helpers
	// -------------------------------------------------------------------------

	public static function clear_all_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_rjm_css_%',
				'_transient_timeout_rjm_css_%'
			)
		);
	}

	public static function clear_debug_logs() {
		delete_option( self::DEBUG_OPTION_KEY );
	}

	// -------------------------------------------------------------------------
	// Public getters
	// -------------------------------------------------------------------------

	public static function get_settings() {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	public static function is_debug_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['debug_logging_enabled'] );
	}

	public static function is_css_edit_access_disabled() {
		$settings = self::get_settings();
		return ! empty( $settings['disable_css_edit_access'] );
	}

	public static function is_css_edit_lock_active_for_current_user() {
		if ( ! self::is_css_edit_access_disabled() ) {
			return false;
		}

		return ! current_user_can( 'manage_options' );
	}

	public static function add_debug_entry( $source, $action, $result, $details = [] ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		$entries = self::get_debug_entries();
		$entries[] = [
			'time'    => current_time( 'mysql' ),
			'source'  => sanitize_key( (string) $source ),
			'action'  => sanitize_key( (string) $action ),
			'result'  => sanitize_key( (string) $result ),
			'details' => self::sanitize_debug_details( $details ),
		];

		if ( count( $entries ) > self::DEBUG_LOG_LIMIT ) {
			$entries = array_slice( $entries, -1 * self::DEBUG_LOG_LIMIT );
		}

		update_option( self::DEBUG_OPTION_KEY, $entries, false );
	}

	public static function get_debug_entries() {
		$entries = get_option( self::DEBUG_OPTION_KEY, [] );
		return is_array( $entries ) ? array_reverse( $entries ) : [];
	}

	public static function get_token() {
		$settings = self::get_settings();
		$encrypted = $settings['github_token_encrypted'] ?? '';
		if ( ! $encrypted ) {
			return '';
		}
		return self::decrypt( $encrypted );
	}

	public static function get_repo() {
		$settings = self::get_settings();
		$default  = defined( 'RJM_CSS_ADVISOR_REPO' ) ? RJM_CSS_ADVISOR_REPO : 'RJM-Digital/import-template-coach';
		return $settings['github_repo'] ?? $default;
	}

	public static function get_branch() {
		$settings = self::get_settings();
		$default  = defined( 'RJM_CSS_ADVISOR_BRANCH' ) ? RJM_CSS_ADVISOR_BRANCH : 'main';
		return $settings['github_branch'] ?? $default;
	}

	public static function get_ai_provider() {
		$settings = self::get_settings();
		$provider = $settings['ai_provider'] ?? 'copilot';
		return in_array( $provider, [ 'copilot', 'openai' ], true ) ? $provider : 'copilot';
	}

	public static function get_openai_key() {
		$settings  = self::get_settings();
		$encrypted = $settings['openai_key_encrypted'] ?? '';
		if ( ! $encrypted ) {
			return '';
		}
		return self::decrypt( $encrypted );
	}

	public static function get_model() {
		$settings = self::get_settings();
		if ( 'openai' === self::get_ai_provider() ) {
			return $settings['openai_model'] ?? 'gpt-5.6';
		}

		return $settings['copilot_model'] ?? 'gpt-4o';
	}

	public static function get_openai_reasoning_effort() {
		$settings = self::get_settings();
		$effort   = $settings['openai_reasoning_effort'] ?? 'medium';
		return in_array( $effort, [ 'none', 'low', 'medium', 'high', 'xhigh' ], true ) ? $effort : 'medium';
	}

	public static function get_cache_ttl() {
		$settings = self::get_settings();
		return intval( $settings['cache_ttl'] ?? 60 ) * 60; // Convert to seconds.
	}

	private static function sanitize_debug_details( $details ) {
		if ( ! is_array( $details ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $details as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( in_array( $key, [ 'github_token', 'openai_api_key', 'authorization', 'body', 'prompt', 'user_message', 'system_prompt' ], true ) ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = mb_substr( trim( (string) $value ), 0, 500 );
				continue;
			}

			$encoded = wp_json_encode( $value );
			$sanitized[ $key ] = $encoded ? mb_substr( $encoded, 0, 500 ) : '';
		}

		return $sanitized;
	}

	/**
	 * Return configured CORS origins as an array of origin strings.
	 *
	 * @return string[]
	 */
	public static function get_cors_origins() {
		$settings = self::get_settings();
		$raw      = $settings['cors_origins'] ?? '';
		if ( ! $raw ) {
			return [];
		}
		return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
	}

	// -------------------------------------------------------------------------
	// CORS hooks
	// -------------------------------------------------------------------------

	/**
	 * Add configured origins to WordPress's allowed HTTP origins list.
	 * This makes rest_send_cors_headers() emit the correct header automatically.
	 *
	 * @param string[] $origins
	 * @return string[]
	 */
	public static function add_cors_origins( $origins ) {
		foreach ( self::get_cors_origins() as $origin ) {
			if ( ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}
		return $origins;
	}

	/**
	 * Handle REST API CORS preflight (OPTIONS) requests for configured origins.
	 *
	 * WordPress's rest_send_cors_headers() only runs on actual REST requests,
	 * not on the bare OPTIONS preflight. This hook ensures the preflight
	 * receives the necessary headers and a 200 response.
	 */
	public static function handle_rest_cors() {
		$configured = self::get_cors_origins();
		if ( empty( $configured ) ) {
			return;
		}

		$origin = get_http_origin();
		if ( ! $origin || ! in_array( $origin, $configured, true ) ) {
			return;
		}

		// For standard GET/POST REST requests WordPress handles CORS itself via
		// rest_send_cors_headers() once the origin is in allowed_http_origins.
		// We only need to intervene for OPTIONS preflights.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type' );
			header( 'Vary: Origin' );
			status_header( 200 );
			exit();
		}
	}

	// -------------------------------------------------------------------------
	// Encryption helpers (AES-256-CBC using AUTH_KEY as seed)
	// -------------------------------------------------------------------------

	private static function get_encryption_key() {
		if ( ! defined( 'AUTH_KEY' ) ) {
			return null;
		}
		return substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
	}

	public static function encrypt( $plain_text ) {
		$key = self::get_encryption_key();
		if ( null === $key ) {
			// Cannot encrypt without AUTH_KEY — store nothing.
			return '';
		}
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plain_text, 'AES-256-CBC', $key, 0, $iv );
		if ( $cipher === false ) {
			return '';
		}
		return base64_encode( $iv . $cipher );
	}

	public static function decrypt( $encrypted ) {
		$key = self::get_encryption_key();
		if ( null === $key ) {
			return '';
		}
		$decoded = base64_decode( $encrypted, true );
		if ( $decoded === false || strlen( $decoded ) < 17 ) {
			return '';
		}
		$iv     = substr( $decoded, 0, 16 );
		$cipher = substr( $decoded, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
		return ( $plain !== false ) ? $plain : '';
	}
}
