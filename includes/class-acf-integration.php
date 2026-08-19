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
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'rjm_css_advisor' ),
				'streamUrl' => rest_url( RJM_CSS_Advisor_Ajax_Handler::REST_NAMESPACE . '/plan-stream' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'postId'    => (int) get_the_ID(),
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
					'screenshotTooLarge' => __( 'Screenshot is too large. Please choose an image under 4 MB.', 'rjm-css-advisor' ),
					'screenshotInvalid'  => __( 'Please choose a PNG, JPEG, or WebP image.', 'rjm-css-advisor' ),
					'screenshotRemove'   => __( 'Remove screenshot', 'rjm-css-advisor' ),
					'screenshotCount'   => __( '%1$d screenshots, %2$s total', 'rjm-css-advisor' ),
					'screenshotLimit'   => __( 'You can attach up to 5 screenshots per message and 20 MB total.', 'rjm-css-advisor' ),
					'stopBtn'           => __( 'Stop', 'rjm-css-advisor' ),
					'stoppedNote'       => __( 'Stopped', 'rjm-css-advisor' ),
					'thinkingStatuses'  => [
						__( 'Thinking…', 'rjm-css-advisor' ),
						__( 'Reading the component…', 'rjm-css-advisor' ),
						__( 'Working through your plan…', 'rjm-css-advisor' ),
						__( 'Almost there…', 'rjm-css-advisor' ),
					],
					'breakpointsAll'    => __( 'All breakpoints', 'rjm-css-advisor' ),
					'breakpointMobile'  => __( 'Mobile', 'rjm-css-advisor' ),
					'breakpointTablet'  => __( 'Tablet', 'rjm-css-advisor' ),
					'breakpointDesktop' => __( 'Desktop', 'rjm-css-advisor' ),
					'emptyTitle'        => __( 'Describe the styling you want', 'rjm-css-advisor' ),
					'emptyHint'         => __( 'Ask questions and refine the plan, then generate the CSS when you are happy.', 'rjm-css-advisor' ),
					'emptyTitleGenerate' => __( 'Describe the CSS you want', 'rjm-css-advisor' ),
					'emptyHintGenerate'  => __( 'Write one clear instruction and the CSS is generated in a single pass.', 'rjm-css-advisor' ),
					'emptyTitleBuild'    => __( 'Describe what to build', 'rjm-css-advisor' ),
					'emptyHintBuild'     => __( 'The work is split into small steps you can approve, revise, or skip.', 'rjm-css-advisor' ),
					'examplePrompts'    => [
						__( 'Make the heading navy blue at 2rem', 'rjm-css-advisor' ),
						__( 'Add more padding below this section on mobile', 'rjm-css-advisor' ),
						__( 'Centre the buttons and add a hover effect', 'rjm-css-advisor' ),
					],
					'historyEmpty'       => __( 'No saved chats for this component yet.', 'rjm-css-advisor' ),
					'historyUntitled'    => __( 'Untitled chat', 'rjm-css-advisor' ),
					'historyOpen'        => __( 'Open chat', 'rjm-css-advisor' ),
					'historyRename'      => __( 'Rename', 'rjm-css-advisor' ),
					'historyDelete'      => __( 'Delete', 'rjm-css-advisor' ),
					'historyRenamePrompt' => __( 'Chat name:', 'rjm-css-advisor' ),
					'historyDeleteConfirm' => __( 'Delete this chat? This cannot be undone.', 'rjm-css-advisor' ),
					'historyClearConfirm' => __( 'Delete every saved chat for this component? This cannot be undone.', 'rjm-css-advisor' ),
					'historyError'       => __( 'Chat history is unavailable right now.', 'rjm-css-advisor' ),
					/* translators: %d: number of screenshots. */
					'historyScreenshotMissing' => __( '%d screenshot(s) from this chat are no longer available.', 'rjm-css-advisor' ),
					'historyJustNow'     => __( 'Just now', 'rjm-css-advisor' ),
					/* translators: %s: human-readable time difference, e.g. "2 hours". */
					'historyAgo'         => __( '%s ago', 'rjm-css-advisor' ),
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
					<div class="rjm-css-panel-header">
						<div class="rjm-css-panel-title">
							<span class="rjm-css-panel-title-text"><?php esc_html_e( 'CSS Advisor', 'rjm-css-advisor' ); ?></span>
							<span class="rjm-css-panel-context"><?php echo esc_html( $layout_name ? $layout_name : $field_name ); ?></span>
						</div>
						<div class="rjm-css-panel-header-actions">
							<button type="button" class="rjm-css-icon-btn rjm-css-history-btn" title="<?php esc_attr_e( 'Chat history', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'Chat history', 'rjm-css-advisor' ); ?>" aria-pressed="false">
								<span aria-hidden="true">☰</span>
							</button>
							<button type="button" class="rjm-css-icon-btn rjm-css-advisor-tryagain" title="<?php esc_attr_e( 'New chat', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'New chat', 'rjm-css-advisor' ); ?>">
								<span aria-hidden="true">↻</span>
							</button>
							<button type="button" class="rjm-css-icon-btn rjm-css-fullscreen-btn" title="<?php esc_attr_e( 'Expand to full screen', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'Expand to full screen', 'rjm-css-advisor' ); ?>" aria-pressed="false">
								<span aria-hidden="true">⤢</span>
							</button>
							<button type="button" class="rjm-css-icon-btn rjm-css-advisor-close" title="<?php esc_attr_e( 'Close', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'Close', 'rjm-css-advisor' ); ?>">
								<span aria-hidden="true">✕</span>
							</button>
						</div>
					</div>

					<div class="rjm-css-panel-main">
						<aside class="rjm-css-history-sidebar" aria-label="<?php esc_attr_e( 'Previous chats', 'rjm-css-advisor' ); ?>">
							<div class="rjm-css-history-head">
								<span class="rjm-css-history-heading"><?php esc_html_e( 'Chats', 'rjm-css-advisor' ); ?></span>
								<button type="button" class="rjm-css-icon-btn rjm-css-history-new" title="<?php esc_attr_e( 'New chat', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'New chat', 'rjm-css-advisor' ); ?>">
									<span aria-hidden="true">+</span>
								</button>
							</div>
							<ul class="rjm-css-history-list"></ul>
							<div class="rjm-css-history-foot">
								<button type="button" class="button-link rjm-css-history-clear" hidden>
									<?php esc_html_e( 'Clear all history', 'rjm-css-advisor' ); ?>
								</button>
							</div>
						</aside>

						<div class="rjm-css-panel-body">
					<!-- Step 1: Goal entry form -->
					<div class="rjm-css-goal-form">
						<div class="rjm-css-goal-body">
							<div class="rjm-css-composer">
								<div class="rjm-css-screenshot-preview" hidden></div>
								<textarea
									id="<?php echo esc_attr( $goal_id ); ?>"
									class="rjm-css-goal-input"
									rows="2"
									aria-label="<?php esc_attr_e( 'Describe what you want to achieve', 'rjm-css-advisor' ); ?>"
									placeholder="<?php esc_attr_e( 'Describe what you want to achieve…', 'rjm-css-advisor' ); ?>"
								></textarea>

								<div class="rjm-css-composer-toolbar">
									<div class="rjm-css-composer-tools">
										<span class="rjm-css-screenshot-controls" hidden>
											<input type="file" class="rjm-css-screenshot-input" accept="image/png,image/jpeg,image/webp" multiple hidden />
											<button type="button" class="rjm-css-icon-btn rjm-css-screenshot-upload-btn" title="<?php esc_attr_e( 'Attach screenshot — or paste an image here', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'Attach screenshot', 'rjm-css-advisor' ); ?>">
												<span aria-hidden="true">+</span>
											</button>
										</span>

										<div class="rjm-css-menu rjm-css-mode-menu" data-mode="generate">
											<button type="button" class="rjm-css-menu-btn" aria-haspopup="true" aria-expanded="false">
												<span class="rjm-css-menu-icon" aria-hidden="true">
													<svg class="rjm-css-mode-icon" data-mode="generate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3 12.9 8.1 18 10l-5.1 1.9L11 17l-1.9-5.1L4 10l5.1-1.9z"/><path d="M18 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>
													<svg class="rjm-css-mode-icon" data-mode="ask" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 4H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3v4l4.5-4H20a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1z"/><path d="M8 9h8M8 12h5"/></svg>
													<svg class="rjm-css-mode-icon" data-mode="build" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h11M10 12h11M10 18h11"/><path d="M3 6.5 4.3 8 7 5"/><path d="M3 12.5 4.3 14 7 11"/><path d="M3 18.5 4.3 20 7 17"/></svg>
												</span>
												<span class="rjm-css-menu-label"><?php esc_html_e( 'Generate', 'rjm-css-advisor' ); ?></span>
												<span class="rjm-css-menu-caret" aria-hidden="true">▾</span>
											</button>
											<div class="rjm-css-menu-popover" role="radiogroup" aria-label="<?php esc_attr_e( 'CSS advisor mode', 'rjm-css-advisor' ); ?>" hidden>
												<label class="rjm-css-menu-option">
													<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="generate" checked />
													<span class="rjm-css-menu-option-body">
														<span class="rjm-css-menu-option-name"><?php esc_html_e( 'Generate', 'rjm-css-advisor' ); ?></span>
														<span class="rjm-css-menu-option-help"><?php echo esc_html( $mode_generate_help ); ?></span>
													</span>
												</label>
												<label class="rjm-css-menu-option">
													<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="ask" />
													<span class="rjm-css-menu-option-body">
														<span class="rjm-css-menu-option-name"><?php esc_html_e( 'Ask/Plan', 'rjm-css-advisor' ); ?></span>
														<span class="rjm-css-menu-option-help"><?php echo esc_html( $mode_ask_help ); ?></span>
													</span>
												</label>
												<label class="rjm-css-menu-option">
													<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="build" />
													<span class="rjm-css-menu-option-body">
														<span class="rjm-css-menu-option-name"><?php esc_html_e( 'Build', 'rjm-css-advisor' ); ?></span>
														<span class="rjm-css-menu-option-help"><?php echo esc_html( $mode_build_help ); ?></span>
													</span>
												</label>
											</div>
										</div>

										<div class="rjm-css-menu rjm-css-breakpoint-menu rjm-css-breakpoints">
											<button type="button" class="rjm-css-menu-btn" aria-haspopup="true" aria-expanded="false">
												<span class="rjm-css-menu-icon" aria-hidden="true">
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="13" height="10" rx="1.5"/><path d="M6 18h5"/><path d="M8.5 14v4"/><rect x="17" y="9" width="5" height="11" rx="1.5"/></svg>
												</span>
												<span class="rjm-css-menu-label"><?php esc_html_e( 'All breakpoints', 'rjm-css-advisor' ); ?></span>
												<span class="rjm-css-menu-caret" aria-hidden="true">▾</span>
											</button>
											<div class="rjm-css-menu-popover" aria-label="<?php esc_attr_e( 'Responsive breakpoints', 'rjm-css-advisor' ); ?>" hidden>
												<label class="rjm-css-menu-option is-check">
													<input type="checkbox" class="rjm-css-breakpoint-input" value="mobile" />
													<span><?php esc_html_e( 'Mobile', 'rjm-css-advisor' ); ?></span>
												</label>
												<label class="rjm-css-menu-option is-check">
													<input type="checkbox" class="rjm-css-breakpoint-input" value="tablet" />
													<span><?php esc_html_e( 'Tablet', 'rjm-css-advisor' ); ?></span>
												</label>
												<label class="rjm-css-menu-option is-check">
													<input type="checkbox" class="rjm-css-breakpoint-input" value="desktop" />
													<span><?php esc_html_e( 'Desktop', 'rjm-css-advisor' ); ?></span>
												</label>
											</div>
										</div>
									</div>

									<div class="rjm-css-composer-submit">
										<button type="button" class="button rjm-css-plan-generate-btn" hidden>
											<?php esc_html_e( 'Generate CSS from plan', 'rjm-css-advisor' ); ?>
										</button>
										<button type="button" class="rjm-css-send-btn rjm-css-generate-btn" title="<?php esc_attr_e( 'Generate CSS', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'Generate CSS', 'rjm-css-advisor' ); ?>">
											<span aria-hidden="true">↑</span>
										</button>
										<button type="button" class="rjm-css-send-btn is-stop rjm-css-plan-stop-btn" title="<?php esc_attr_e( 'Stop', 'rjm-css-advisor' ); ?>" aria-label="<?php esc_attr_e( 'Stop', 'rjm-css-advisor' ); ?>" hidden>
											<span aria-hidden="true">■</span>
										</button>
									</div>
								</div>
							</div>

							<button type="button" class="button-link rjm-css-screenshot-clear" hidden>
								<?php esc_html_e( 'Clear screenshots', 'rjm-css-advisor' ); ?>
							</button>
							<p class="rjm-css-screenshot-error rjm-error" role="alert" hidden></p>
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

						<!-- Step 2: Actions (Generate/Build results only) -->
						<div class="rjm-css-advisor-actions" hidden>
							<button type="button" class="button rjm-css-advisor-tryagain">
								<?php esc_html_e( '↻ Try again', 'rjm-css-advisor' ); ?>
							</button>
						</div>
					</div>
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
