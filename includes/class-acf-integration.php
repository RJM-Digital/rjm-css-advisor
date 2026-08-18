<?php
/**
 * ACF field integration.
 *
 * Hooks into Advanced Custom Fields to inject a "Get CSS Advice ✨" button
 * beneath every custom_css textarea field (and all sub-component CSS variants),
 * as well as the global Custom CSS field in Theme Settings.
 *
 * The button, loading state, and advice panel are all rendered via a small
 * inline JavaScript block that makes an AJAX call to class-ajax-handler.php.
 */

defined( 'ABSPATH' ) || exit;

class RJM_CSS_Advisor_ACF_Integration {

	// All ACF field names that should receive the advice button.
	const CSS_FIELD_NAMES = [
		'custom_css',
		'css', // Hero component ACF field.
		'pricing_card_custom_css',
		'card_custom_css',
		'step_custom_css',
		'global_custom_css', // Theme Settings → Global Custom CSS (wpComponent).
	];

	public static function init() {
		// Only run when ACF is active.
		if ( ! function_exists( 'acf_get_field' ) && ! defined( 'ACF_VERSION' ) ) {
			return;
		}

		// Enqueue admin assets when editing posts/pages/components.
		add_action( 'acf/input/admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// Inject the advice button after each CSS field is rendered.
		foreach ( self::CSS_FIELD_NAMES as $field_name ) {
			add_action(
				'acf/render_field/name=' . $field_name,
				[ __CLASS__, 'render_advice_button' ]
			);
		}
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue_assets() {
		wp_enqueue_style(
			'rjm-css-advisor',
			RJM_CSS_ADVISOR_URL . 'assets/css-advisor.css',
			[],
			RJM_CSS_ADVISOR_VERSION
		);

		wp_enqueue_script(
			'rjm-css-advisor',
			RJM_CSS_ADVISOR_URL . 'assets/css-advisor.js',
			[ 'jquery' ],
			RJM_CSS_ADVISOR_VERSION,
			true
		);

		wp_localize_script(
			'rjm-css-advisor',
			'rjmCssAdvisor',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rjm_css_advisor' ),
				'postId'  => (int) get_the_ID(),
				'i18n'    => [
					'buttonLabel'     => __( 'Generate Custom CSS ✨', 'rjm-css-advisor' ),
					'goalLabel'       => __( 'Describe what you want to achieve:', 'rjm-css-advisor' ),
					'goalPlaceholder' => __( 'e.g. Make the heading navy blue with font size 2rem, add extra padding below the section on mobile…', 'rjm-css-advisor' ),
					'modeLabel'       => __( 'Mode', 'rjm-css-advisor' ),
					'modeGenerate'    => __( 'Generate', 'rjm-css-advisor' ),
					'modeAsk'         => __( 'Ask/Plan', 'rjm-css-advisor' ),
					'modeBuild'       => __( 'Build', 'rjm-css-advisor' ),
					'generateBtn'     => __( 'Generate CSS ✨', 'rjm-css-advisor' ),
					'sendPlanBtn'     => __( 'Send message', 'rjm-css-advisor' ),
					'generatePlanBtn' => __( 'Generate CSS from plan', 'rjm-css-advisor' ),
					'startBuildBtn'   => __( 'Start build', 'rjm-css-advisor' ),
					'approveStepBtn'  => __( 'Approve step', 'rjm-css-advisor' ),
					'reviseStepBtn'   => __( 'Revise step', 'rjm-css-advisor' ),
					'skipStepBtn'     => __( 'Skip step', 'rjm-css-advisor' ),
					'generating'      => __( 'Generating CSS…', 'rjm-css-advisor' ),
					'planning'        => __( 'Thinking through your plan…', 'rjm-css-advisor' ),
					'building'        => __( 'Building CSS step-by-step…', 'rjm-css-advisor' ),
					'tryAgainBtn'     => __( '↻ Try again', 'rjm-css-advisor' ),
					'cancelBtn'       => __( '✕ Cancel', 'rjm-css-advisor' ),
					'closeBtn'        => __( '✕ Close', 'rjm-css-advisor' ),
					'errorPrefix'     => __( 'Error: ', 'rjm-css-advisor' ),
					'copyBtn'         => __( 'Copy', 'rjm-css-advisor' ),
					'copiedBtn'       => __( 'Copied!', 'rjm-css-advisor' ),
					'insertBtn'       => __( '↑ Insert into field', 'rjm-css-advisor' ),
					'expandGoalBtn'   => __( 'Expand', 'rjm-css-advisor' ),
					'reduceGoalBtn'   => __( 'Reduce', 'rjm-css-advisor' ),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Button injection
	// -------------------------------------------------------------------------

	/**
	 * Render the advice button + panel container directly after the ACF field.
	 *
	 * ACF provides the field array as the only argument.
	 *
	 * @param array $field  ACF field definition.
	 */
	public static function render_advice_button( $field ) {
		$layout_name = self::detect_layout_name( $field );
		$field_name  = $field['_name'] ?? $field['name'] ?? 'custom_css';
		$field_key   = $field['key'] ?? '';
		$is_global   = ( $field_name === 'global_custom_css' );

		$panel_id = 'rjm-advice-' . wp_generate_uuid4();
		$goal_id  = 'rjm-goal-'   . wp_generate_uuid4();
		$mode_id  = 'rjm-mode-'   . wp_generate_uuid4();
		$mode_generate_help = __( 'Generate mode creates CSS in one pass from your goal.', 'rjm-css-advisor' );
		$mode_ask_help      = __( 'Ask/Plan mode lets you chat and refine requirements before generating final CSS.', 'rjm-css-advisor' );
		$mode_build_help    = __( 'Build mode creates CSS step-by-step with approve, revise, or skip controls.', 'rjm-css-advisor' );

		?>
		<div
			class="rjm-css-advisor-wrap"
			data-layout="<?php echo esc_attr( $layout_name ); ?>"
			data-field="<?php echo esc_attr( $field_name ); ?>"
			data-field-key="<?php echo esc_attr( $field_key ); ?>"
			data-global="<?php echo $is_global ? '1' : '0'; ?>"
			data-panel="<?php echo esc_attr( $panel_id ); ?>"
		>
			<button
				type="button"
				class="button rjm-css-advisor-btn"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $panel_id ); ?>"
			>
				<?php esc_html_e( 'Generate Custom CSS ✨', 'rjm-css-advisor' ); ?>
			</button>

			<div
				id="<?php echo esc_attr( $panel_id ); ?>"
				class="rjm-css-advisor-panel"
				role="region"
				aria-label="<?php esc_attr_e( 'CSS Generator', 'rjm-css-advisor' ); ?>"
				hidden
			>
				<div class="rjm-css-advisor-panel-inner">
					<div class="rjm-css-mode-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'CSS advisor mode', 'rjm-css-advisor' ); ?>">
						<span class="rjm-css-mode-picker-label"><?php esc_html_e( 'Mode', 'rjm-css-advisor' ); ?></span>
						<label class="rjm-css-mode-chip" data-tooltip="<?php echo esc_attr( $mode_generate_help ); ?>" title="<?php echo esc_attr( $mode_generate_help ); ?>">
							<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="generate" title="<?php echo esc_attr( $mode_generate_help ); ?>" aria-label="<?php echo esc_attr( $mode_generate_help ); ?>" checked />
							<span><?php esc_html_e( 'Generate', 'rjm-css-advisor' ); ?></span>
						</label>
						<label class="rjm-css-mode-chip" data-tooltip="<?php echo esc_attr( $mode_ask_help ); ?>" title="<?php echo esc_attr( $mode_ask_help ); ?>">
							<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="ask" title="<?php echo esc_attr( $mode_ask_help ); ?>" aria-label="<?php echo esc_attr( $mode_ask_help ); ?>" />
							<span><?php esc_html_e( 'Ask/Plan', 'rjm-css-advisor' ); ?></span>
						</label>
						<label class="rjm-css-mode-chip" data-tooltip="<?php echo esc_attr( $mode_build_help ); ?>" title="<?php echo esc_attr( $mode_build_help ); ?>">
							<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="build" title="<?php echo esc_attr( $mode_build_help ); ?>" aria-label="<?php echo esc_attr( $mode_build_help ); ?>" />
							<span><?php esc_html_e( 'Build', 'rjm-css-advisor' ); ?></span>
						</label>
					</div>

					<!-- Step 1: Goal entry form -->
					<div class="rjm-css-goal-form">
						<div class="rjm-css-goal-header">
							<label for="<?php echo esc_attr( $goal_id ); ?>" class="rjm-css-goal-label">
								<?php esc_html_e( 'Describe what you want to achieve:', 'rjm-css-advisor' ); ?>
							</label>
							<button type="button" class="button-link rjm-css-goal-toggle" aria-expanded="true" aria-label="<?php esc_attr_e( 'Reduce', 'rjm-css-advisor' ); ?>">
								<span class="rjm-css-goal-toggle-icon" aria-hidden="true">▾</span>
								<span class="rjm-css-goal-toggle-text"><?php esc_html_e( 'Reduce', 'rjm-css-advisor' ); ?></span>
							</button>
						</div>
						<div class="rjm-css-goal-body">
							<textarea
								id="<?php echo esc_attr( $goal_id ); ?>"
								class="rjm-css-goal-input large-text"
								rows="3"
								placeholder="<?php esc_attr_e( 'e.g. Make the heading navy blue with font size 2rem, add extra padding below the section on mobile…', 'rjm-css-advisor' ); ?>"
							></textarea>
							<fieldset class="rjm-css-breakpoints" aria-label="<?php esc_attr_e( 'Responsive breakpoints', 'rjm-css-advisor' ); ?>">
								<legend class="rjm-css-breakpoints-legend"><?php esc_html_e( 'Apply to breakpoints', 'rjm-css-advisor' ); ?></legend>
								<div class="rjm-css-breakpoints-list">
									<label class="rjm-css-breakpoint">
										<input type="checkbox" class="rjm-css-breakpoint-input" value="mobile" />
										<span><?php esc_html_e( 'Mobile', 'rjm-css-advisor' ); ?></span>
									</label>
									<label class="rjm-css-breakpoint">
										<input type="checkbox" class="rjm-css-breakpoint-input" value="tablet" />
										<span><?php esc_html_e( 'Tablet', 'rjm-css-advisor' ); ?></span>
									</label>
									<label class="rjm-css-breakpoint">
										<input type="checkbox" class="rjm-css-breakpoint-input" value="desktop" />
										<span><?php esc_html_e( 'Desktop', 'rjm-css-advisor' ); ?></span>
									</label>
								</div>
							</fieldset>
							<div class="rjm-css-goal-actions">
								<button type="button" class="button button-primary rjm-css-generate-btn">
									<?php esc_html_e( 'Generate CSS ✨', 'rjm-css-advisor' ); ?>
								</button>
								<button type="button" class="button rjm-css-advisor-close">
									<?php esc_html_e( '✕ Cancel', 'rjm-css-advisor' ); ?>
								</button>
							</div>
						</div>
					</div>

					<div class="rjm-css-result-zone">
						<!-- Step 2: Loading -->
						<div class="rjm-css-advisor-loading" hidden>
							<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
							<?php esc_html_e( 'Generating CSS…', 'rjm-css-advisor' ); ?>
						</div>

						<!-- Step 2: Generated CSS result -->
						<div class="rjm-css-advisor-content"></div>

						<!-- Build controls for in-progress step decisions -->
						<div class="rjm-css-build-actions" hidden>
							<button type="button" class="button button-primary rjm-css-build-action" data-decision="approve"><?php esc_html_e( 'Approve step', 'rjm-css-advisor' ); ?></button>
							<button type="button" class="button rjm-css-build-action" data-decision="revise"><?php esc_html_e( 'Revise step', 'rjm-css-advisor' ); ?></button>
							<button type="button" class="button rjm-css-build-action" data-decision="skip"><?php esc_html_e( 'Skip step', 'rjm-css-advisor' ); ?></button>
						</div>

						<!-- Step 2: Actions -->
						<div class="rjm-css-advisor-actions" hidden>
							<button type="button" class="button button-primary rjm-css-plan-generate-btn" hidden>
								<?php esc_html_e( 'Generate CSS from plan', 'rjm-css-advisor' ); ?>
							</button>
							<button type="button" class="button rjm-css-advisor-tryagain">
								<?php esc_html_e( '↻ Try again', 'rjm-css-advisor' ); ?>
							</button>
							<button type="button" class="button rjm-css-advisor-close">
								<?php esc_html_e( '✕ Close', 'rjm-css-advisor' ); ?>
							</button>
						</div>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Attempt to detect the ACF flexible content layout name from the field key.
	 *
	 * ACF stores the parent layout info in the field key prefix or via global
	 * state during rendering. We fall back to inspecting the field key prefix
	 * against known layout → component mappings.
	 *
	 * @param array $field
	 * @return string  Layout slug, or empty string for global/unknown.
	 */
	private static function detect_layout_name( $field ) {
		// Global CSS field — not part of a layout.
		if ( ( $field['name'] ?? '' ) === 'global_custom_css' ) {
			return '';
		}

		// ACF exposes the active layout via acf_get_loop().
		if ( function_exists( 'acf_get_loop' ) ) {
			$loop = acf_get_loop( 'active' );
			if ( $loop && ! empty( $loop['layout'] ) ) {
				return $loop['layout']['name'] ?? '';
			}
		}

		// Fallback: get_row_layout() returns the current flexible content layout
		// name when ACF is iterating through rows during admin rendering.
		if ( function_exists( 'get_row_layout' ) ) {
			$layout = get_row_layout();
			if ( $layout ) {
				return $layout;
			}
		}

		// The JS detectLayoutName() will resolve the layout from the DOM at
		// click time, so returning '' here is safe.
		return '';
	}
}
