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

	// Standalone Theme Settings field groups (Navbar/Footer/Banner) are plain
	// field groups, not flexible_content layouts, so their custom_css field
	// can't be resolved via acf_get_loop()/get_row_layout(). Their field keys
	// are globally unique, so map them straight to a layout slug here.
	const FIELD_KEY_TO_LAYOUT = [
		'field_695f41a22c8ef' => 'banner',
		'field_695f4e2f75aaf' => 'footer',
		'field_695f412005e0b' => 'navbar',
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
		$layout_name     = self::detect_layout_name( $field );
		$field_name      = $field['_name'] ?? $field['name'] ?? 'custom_css';
		$field_key       = $field['key'] ?? '';
		$is_global       = ( $field_name === 'global_custom_css' );
		$native_settings = self::detect_native_settings( $field, $field_name, $layout_name );

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
			<?php if ( $native_settings ) : ?>
			data-native-settings="<?php echo esc_attr( wp_json_encode( $native_settings ) ); ?>"
			<?php endif; ?>
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

										<div class="rjm-css-menu rjm-css-mode-menu" data-mode="ask">
											<button type="button" class="rjm-css-menu-btn" aria-haspopup="true" aria-expanded="false">
												<span class="rjm-css-menu-icon" aria-hidden="true">
													<svg class="rjm-css-mode-icon" data-mode="generate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3 12.9 8.1 18 10l-5.1 1.9L11 17l-1.9-5.1L4 10l5.1-1.9z"/><path d="M18 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>
													<svg class="rjm-css-mode-icon" data-mode="ask" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 4H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3v4l4.5-4H20a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1z"/><path d="M8 9h8M8 12h5"/></svg>
													<svg class="rjm-css-mode-icon" data-mode="build" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h11M10 12h11M10 18h11"/><path d="M3 6.5 4.3 8 7 5"/><path d="M3 12.5 4.3 14 7 11"/><path d="M3 18.5 4.3 20 7 17"/></svg>
												</span>
												<span class="rjm-css-menu-label"><?php esc_html_e( 'Ask/Plan', 'rjm-css-advisor' ); ?></span>
												<span class="rjm-css-menu-caret" aria-hidden="true">▾</span>
											</button>
											<div class="rjm-css-menu-popover" role="radiogroup" aria-label="<?php esc_attr_e( 'CSS advisor mode', 'rjm-css-advisor' ); ?>" hidden>
												<label class="rjm-css-menu-option">
													<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="generate" />
													<span class="rjm-css-menu-option-body">
														<span class="rjm-css-menu-option-name"><?php esc_html_e( 'Generate', 'rjm-css-advisor' ); ?></span>
														<span class="rjm-css-menu-option-help"><?php echo esc_html( $mode_generate_help ); ?></span>
													</span>
												</label>
												<label class="rjm-css-menu-option">
													<input type="radio" name="<?php echo esc_attr( $mode_id ); ?>" class="rjm-css-mode-input" value="ask" checked />
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

		// Standalone Theme Settings field groups (Navbar/Footer/Banner) — matched
		// by field key since they're never inside an ACF flexible-content loop.
		$field_key = (string) ( $field['key'] ?? '' );
		if ( isset( self::FIELD_KEY_TO_LAYOUT[ $field_key ] ) ) {
			return self::FIELD_KEY_TO_LAYOUT[ $field_key ];
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

	// -------------------------------------------------------------------------
	// Native settings detection
	// -------------------------------------------------------------------------

	// ACF field types never considered a styling control.
	const STRUCTURAL_FIELD_TYPES = [
		'tab', 'message', 'accordion', 'repeater', 'flexible_content',
		'gallery', 'relationship', 'post_object', 'taxonomy', 'user', 'image', 'file',
	];

	// Container field types whose sub_fields are scanned recursively (ACF Group/Clone
	// fields are sometimes used to bundle shared style controls).
	const CONTAINER_FIELD_TYPES = [ 'group', 'clone' ];

	// ACF field types that are always treated as a styling control when present.
	const ALWAYS_STYLE_FIELD_TYPES = [ 'color_picker', 'range' ];

	// ACF field types only treated as styling controls when their name/label matches a style keyword.
	const KEYWORD_STYLE_FIELD_TYPES = [ 'select', 'radio', 'button_group', 'true_false', 'text', 'number', 'checkbox' ];

	// Field name/label keywords that suggest a visual styling control.
	const STYLE_KEYWORD_PATTERN = '/color|colour|font|size|weight|hover|align|spacing|margin|padding|radius|shadow|border|background|\bbg\b|opacity|width|height|style|underline|italic|bold/i';

	// Breakpoint thresholds shown alongside per-breakpoint native fields — must
	// match the thresholds the AI is told to use in get_selected_media_headers().
	const BREAKPOINT_LABELS = [
		'mobile'  => 'Mobile (base)',
		'tablet'  => 'Tablet (≥768px)',
		'desktop' => 'Desktop (≥1200px)',
	];

	/**
	 * Find ACF fields that look like native styling controls (colors, sizes,
	 * hover toggles, etc.) relevant to the component being styled, so the AI
	 * can recommend those instead of duplicating them with custom CSS.
	 *
	 * Two scopes are collected and kept distinct: 'component' fields defined on
	 * this specific layout (e.g. Hero's own Font Settings tab overrides, often
	 * labelled Heading/Subheading/Body rather than H1/H2), and 'global' fields
	 * from the site-wide Theme Settings field group (the H1-H6 etc. defaults
	 * components fall back to). Both are surfaced so the AI can prefer the more
	 * specific component override when one exists.
	 *
	 * @param array  $field       ACF field definition for the CSS field itself.
	 * @param string $field_name  Resolved name of the CSS field.
	 * @param string $layout_name Resolved flexible-content layout name, or '' for the global field.
	 * @return array  List of { label, name, type, choices, scope }.
	 */
	private static function detect_native_settings( $field, $field_name, $layout_name ) {
		if ( 'global_custom_css' === $field_name ) {
			$global_context = self::find_field_definition_context_by_name( $field_name, '' );
			$candidate_fields = $global_context['sub_fields'] ?? [];

			$settings = [];
			$seen = [];
			self::collect_style_fields( $candidate_fields, $field_name, 'global', $settings, $seen );

			self::log_native_settings_detection( $field_name, $layout_name, $candidate_fields, $settings );

			return $settings;
		}

		$field_key = (string) ( $field['key'] ?? '' );
		$layout_context = '' !== $field_key
			? self::find_field_definition_context_by_key( $field_key )
			: self::find_field_definition_context_by_name( $field_name, $layout_name );
		$layout_fields  = $layout_context['sub_fields'] ?? [];

		$global_context = self::find_field_definition_context_by_name( 'global_custom_css', '' );
		$global_fields  = $global_context['sub_fields'] ?? [];

		$settings = [];

		// Component-specific overrides first, so they're prioritised if the total is capped.
		$seen_component = [];
		self::collect_style_fields( $layout_fields, $field_name, 'component', $settings, $seen_component );

		// Site-wide defaults these components fall back to.
		$seen_global = [];
		self::collect_style_fields( $global_fields, $field_name, 'global', $settings, $seen_global );

		self::log_native_settings_detection( $field_name, $layout_name, $layout_fields, $settings );

		return $settings;
	}

	/**
	 * Record a debug entry describing what the detector found, when debug logging is enabled.
	 *
	 * @param string $field_name
	 * @param string $layout_name
	 * @param array  $candidate_fields
	 * @param array  $settings
	 * @return void
	 */
	private static function log_native_settings_detection( $field_name, $layout_name, $candidate_fields, $settings ) {
		if ( ! RJM_CSS_Advisor_Settings::is_debug_enabled() ) {
			return;
		}

		RJM_CSS_Advisor_Settings::add_debug_entry( 'acf_integration', 'detect_native_settings', 'success', [
			'field'      => $field_name,
			'layout'     => $layout_name,
			'candidates' => count( $candidate_fields ),
			'matched'    => count( $settings ),
		] );
	}

	/**
	 * Locate the sibling field list for a given field name by searching every
	 * registered/synced ACF field group's static definition — not the live
	 * render loop, which does not reliably expose field schemas at the point
	 * the advice button is rendered.
	 *
	 * When $layout_name is given, only a flexible_content layout with that
	 * exact name is matched, so fields sharing a name across multiple layouts
	 * (e.g. "custom_css" used by several component types) resolve to the
	 * correct layout's siblings rather than the first one found.
	 *
	 * @param string $field_name
	 * @param string $layout_name  Flexible-content layout name, or '' for a plain field.
	 * @return array{sub_fields: array}|null
	 */
	private static function find_field_definition_context_by_name( $field_name, $layout_name ) {
		static $cache = [];
		$cache_key = $field_name . '|' . $layout_name;

		if ( array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		$result = null;

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$fields = acf_get_fields( $group ) ?: [];
				$found  = self::search_fields_for_name( $fields, $field_name, $layout_name );
				if ( null !== $found ) {
					$result = $found;
					break;
				}
			}
		}

		$cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Locate the sibling field list for a globally unique ACF field key. Unlike
	 * names, field keys remain unambiguous when ACF's active render-loop layout
	 * cannot be resolved.
	 *
	 * @param string $field_key
	 * @return array{sub_fields: array}|null
	 */
	private static function find_field_definition_context_by_key( $field_key ) {
		static $cache = [];

		if ( array_key_exists( $field_key, $cache ) ) {
			return $cache[ $field_key ];
		}

		$result = null;

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$fields = acf_get_fields( $group ) ?: [];
				$found  = self::search_fields_for_key( $fields, $field_key );
				if ( null !== $found ) {
					$result = $found;
					break;
				}
			}
		}

		$cache[ $field_key ] = $result;

		return $result;
	}

	/**
	 * Recursively search a static field list for a field by name, descending
	 * into flexible_content layouts (restricted to $layout_name when given)
	 * and Group/Clone containers.
	 *
	 * @param array  $fields
	 * @param string $field_name
	 * @param string $layout_name
	 * @return array{sub_fields: array}|null
	 */
	private static function search_fields_for_name( $fields, $field_name, $layout_name ) {
		foreach ( (array) $fields as $sub_field ) {
			if ( ! is_array( $sub_field ) ) {
				continue;
			}

			if ( ( $sub_field['name'] ?? '' ) === $field_name ) {
				return [ 'sub_fields' => $fields ];
			}

			$type = (string) ( $sub_field['type'] ?? '' );

			if ( 'flexible_content' === $type && ! empty( $sub_field['layouts'] ) ) {
				foreach ( (array) $sub_field['layouts'] as $layout ) {
					if ( '' !== $layout_name && ( $layout['name'] ?? '' ) !== $layout_name ) {
						continue;
					}

					$found = self::search_fields_for_name( $layout['sub_fields'] ?? [], $field_name, $layout_name );
					if ( null !== $found ) {
						return $found;
					}
				}

				continue;
			}

			if ( in_array( $type, self::CONTAINER_FIELD_TYPES, true ) && ! empty( $sub_field['sub_fields'] ) ) {
				$found = self::search_fields_for_name( $sub_field['sub_fields'], $field_name, $layout_name );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Recursively search a static field list for a globally unique field key.
	 * Every flexible-content layout is searched because the key itself is the
	 * unambiguous identifier, independent of the active render loop.
	 *
	 * @param array  $fields
	 * @param string $field_key
	 * @return array{sub_fields: array}|null
	 */
	private static function search_fields_for_key( $fields, $field_key ) {
		foreach ( (array) $fields as $sub_field ) {
			if ( ! is_array( $sub_field ) ) {
				continue;
			}

			if ( ( $sub_field['key'] ?? '' ) === $field_key ) {
				return [ 'sub_fields' => $fields ];
			}

			$type = (string) ( $sub_field['type'] ?? '' );

			if ( 'flexible_content' === $type && ! empty( $sub_field['layouts'] ) ) {
				foreach ( (array) $sub_field['layouts'] as $layout ) {
					$found = self::search_fields_for_key( $layout['sub_fields'] ?? [], $field_key );
					if ( null !== $found ) {
						return $found;
					}
				}

				continue;
			}

			if ( in_array( $type, self::CONTAINER_FIELD_TYPES, true ) && ! empty( $sub_field['sub_fields'] ) ) {
				$found = self::search_fields_for_key( $sub_field['sub_fields'], $field_key );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}


	/**
	 * Walk a flat list of ACF sub-fields, recursing into Group/Clone containers,
	 * and append any style-related field found to $settings (capped at 80 total).
	 * De-duplicated by label + breakpoint within this scope, since the same
	 * setting name/label often repeats once per breakpoint (mobile/tablet/
	 * desktop) with a different threshold each field actually applies from —
	 * losing that distinction would hide a Desktop-specific override behind an
	 * identically-labelled Mobile one.
	 *
	 * @param array  $fields
	 * @param string $exclude_name
	 * @param string $scope         'component' or 'global'.
	 * @param array  $settings
	 * @param array  $seen_labels
	 * @param int    $depth
	 * @return void
	 */
	private static function collect_style_fields( $fields, $exclude_name, $scope, array &$settings, array &$seen_labels, $depth = 0 ) {
		if ( $depth > 3 ) {
			return;
		}

		foreach ( (array) $fields as $sub_field ) {
			if ( count( $settings ) >= 80 ) {
				return;
			}

			if ( ! is_array( $sub_field ) ) {
				continue;
			}

			$type = (string) ( $sub_field['type'] ?? '' );

			if ( in_array( $type, self::CONTAINER_FIELD_TYPES, true ) && ! empty( $sub_field['sub_fields'] ) ) {
				self::collect_style_fields( $sub_field['sub_fields'], $exclude_name, $scope, $settings, $seen_labels, $depth + 1 );
				continue;
			}

			if ( ! self::is_style_related_field( $sub_field, $exclude_name ) ) {
				continue;
			}

			$label = trim( (string) ( $sub_field['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$breakpoint = self::detect_breakpoint_from_name( (string) ( $sub_field['name'] ?? '' ) );
			$dedup_key  = strtolower( $label ) . '|' . $breakpoint;
			if ( isset( $seen_labels[ $dedup_key ] ) ) {
				continue;
			}
			$seen_labels[ $dedup_key ] = true;

			$display_label = $label;
			if ( '' !== $breakpoint ) {
				$display_label .= ' — ' . self::BREAKPOINT_LABELS[ $breakpoint ];
			}

			$settings[] = [
				'label'   => sanitize_text_field( mb_substr( $display_label, 0, 80 ) ),
				'name'    => sanitize_key( $sub_field['name'] ?? '' ),
				'type'    => sanitize_key( $sub_field['type'] ?? '' ),
				'choices' => self::extract_choices( $sub_field ),
				'scope'   => $scope,
			];
		}
	}

	/**
	 * Detect a mobile/tablet/desktop marker in an ACF field name, e.g.
	 * "fs_h1_desktop_font_size" or "benefit_text_mobile_font_size".
	 *
	 * @param string $name
	 * @return string  'mobile', 'tablet', 'desktop', or '' if none detected.
	 */
	private static function detect_breakpoint_from_name( $name ) {
		$name = strtolower( (string) $name );

		foreach ( [ 'mobile', 'tablet', 'desktop' ] as $breakpoint ) {
			if ( preg_match( '/(^|_)' . $breakpoint . '(_|$)/', $name ) ) {
				return $breakpoint;
			}
		}

		return '';
	}


	/**
	 * Heuristic check for whether an ACF sub-field looks like a styling control.
	 *
	 * @param array  $sub_field
	 * @param string $exclude_name  Name of the CSS field to skip.
	 * @return bool
	 */
	private static function is_style_related_field( $sub_field, $exclude_name ) {
		$name = strtolower( (string) ( $sub_field['name'] ?? '' ) );
		$type = (string) ( $sub_field['type'] ?? '' );

		if ( '' === $name || $name === strtolower( $exclude_name ) ) {
			return false;
		}

		if ( in_array( $type, self::STRUCTURAL_FIELD_TYPES, true ) ) {
			return false;
		}

		if ( in_array( $type, self::ALWAYS_STYLE_FIELD_TYPES, true ) ) {
			return true;
		}

		if ( in_array( $type, self::KEYWORD_STYLE_FIELD_TYPES, true ) ) {
			$label = (string) ( $sub_field['label'] ?? '' );
			return (bool) preg_match( self::STYLE_KEYWORD_PATTERN, $name . ' ' . $label );
		}

		return false;
	}

	/**
	 * Extract up to 10 sanitized choice labels from a select/radio/button_group field.
	 *
	 * @param array $sub_field
	 * @return array<int,string>
	 */
	private static function extract_choices( $sub_field ) {
		$choices = $sub_field['choices'] ?? null;
		if ( ! is_array( $choices ) ) {
			return [];
		}

		$labels = array_map(
			static function ( $label ) {
				return sanitize_text_field( mb_substr( (string) $label, 0, 40 ) );
			},
			array_values( $choices )
		);

		return array_slice( array_filter( $labels ), 0, 10 );
	}
}
