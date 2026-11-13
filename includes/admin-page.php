<?php
/**
 * formexposed - the settings page (view). Expects $last (array) and $msg
 * (array(kind, text) or false) from Formexposed_Core::page().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Formexposed_Core::link_check();   // 1.5.1: after Connect, show "connected" once formexposed.com confirms

$formexposed_status = isset( $last['status'] ) ? $last['status'] : '';
$formexposed_laeuft = in_array( $formexposed_status, array( 'queued', 'running' ), true );
$formexposed_fertig = 'done' === $formexposed_status;
$formexposed_level  = isset( $last['level'] ) ? $last['level'] : '';
list( $formexposed_farbe, $formexposed_titel, $formexposed_satz ) = Formexposed_Core::level_text( $formexposed_level );
$formexposed_check_url = wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_check' ), 'formexposed_check' );
?>
<div class="wrap" style="max-width:820px">
	<h1>formexposed</h1>
	<?php Formexposed_Core::nav( '' ); ?>
	<p class="description"><?php esc_html_e( 'Checks whether the contact forms on this site are protected against bots and spam. The service loads a few of your public pages and looks at the forms - it never submits one.', 'formexposed' ); ?></p>

	<?php if ( is_array( $msg ) ) : ?>
		<div class="notice notice-<?php echo 'ok' === $msg[0] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $last['error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $last['error'] ); ?></p></div>
	<?php endif; ?>

	<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo esc_attr( $formexposed_fertig ? $formexposed_farbe : '#2271b1' ); ?>;padding:16px 20px;margin:16px 0">
		<?php if ( '' === $formexposed_status ) : ?>
			<h2 style="margin-top:0"><?php esc_html_e( 'Not checked yet', 'formexposed' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: host name */ __( 'Run the first check for %s. It takes a minute or two and does not change anything on your site.', 'formexposed' ), Formexposed_Core::host() ) ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $formexposed_check_url ); ?>"><?php esc_html_e( 'Check now', 'formexposed' ); ?></a></p>

		<?php elseif ( $formexposed_laeuft ) : ?>
			<h2 style="margin-top:0" id="fe-wait"><?php esc_html_e( 'Checking …', 'formexposed' ); ?></h2>
			<p><?php esc_html_e( 'The service is loading your pages. This page refreshes itself when the result is in.', 'formexposed' ); ?></p>
			<?php // assets/js/admin.js polls admin-ajax (formexposed_status) and reloads the page once the check is done - see Formexposed_Core::admin_assets(). ?>

		<?php elseif ( $formexposed_fertig ) : ?>
			<h2 style="margin-top:0;color:<?php echo esc_attr( $formexposed_farbe ); ?>"><?php echo esc_html( $formexposed_titel ); ?>
				<?php if ( isset( $last['score'] ) && 'noform' !== $formexposed_level ) : ?>
					<span style="font-weight:normal;color:#50575e;font-size:14px"> · <?php echo esc_html( sprintf( /* translators: %d: score 0-100 */ __( 'risk score %d / 100', 'formexposed' ), (int) $last['score'] ) ); ?></span>
				<?php endif; ?>
			</h2>
			<p><?php echo esc_html( $formexposed_satz ); ?></p>
			<?php if ( ! empty( $last['missing'] ) ) : ?>
				<h3><?php esc_html_e( 'What is missing', 'formexposed' ); ?></h3>
				<ul style="list-style:disc;padding-left:20px">
				<?php foreach ( $last['missing'] as $formexposed_m ) : ?>
					<li><strong><?php echo esc_html( $formexposed_m['title'] ); ?></strong><br><?php echo esc_html( '' !== $formexposed_m['cms'] ? $formexposed_m['cms'] : $formexposed_m['general'] ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p style="color:#50575e;font-size:12px"><?php echo esc_html( sprintf( /* translators: 1: pages, 2: forms, 3: date */ __( '%1$d pages loaded, %2$d forms found, checked on %3$s.', 'formexposed' ), (int) $last['pages'], (int) $last['forms'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['checked_at'] ) ) ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $last['report_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the full report', 'formexposed' ); ?></a>
				<a class="button" href="<?php echo esc_url( $formexposed_check_url ); ?>"><?php esc_html_e( 'Check again', 'formexposed' ); ?></a>
			</p>

		<?php else : ?>
			<h2 style="margin-top:0"><?php esc_html_e( 'The check could not be completed', 'formexposed' ); ?></h2>
			<p><?php echo esc_html( ! empty( $last['reason'] ) ? $last['reason'] : __( 'Please try again later.', 'formexposed' ) ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $formexposed_check_url ); ?>"><?php esc_html_e( 'Try again', 'formexposed' ); ?></a></p>
		<?php endif; ?>
	</div>

	<?php
	$formexposed_guard   = Formexposed_Guard::on();
	$formexposed_plugins = Formexposed_Guard::plugins();
	$formexposed_testkey = Formexposed_Guard::cf7_test_key();
	$formexposed_opt     = get_option( Formexposed_Guard::OPT );
	$formexposed_blocked = Formexposed_Stats::total_blocked();
	$formexposed_q       = Formexposed_Quarantine::count_open();
	$formexposed_swarm   = get_option( 'formexposed_swarm' );
	$formexposed_swarm_n = is_array( $formexposed_swarm ) && isset( $formexposed_swarm['links'] ) ? count( (array) $formexposed_swarm['links'] ) : 0;
	?>
	<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $formexposed_guard ? '#00750f' : '#2271b1'; ?>;padding:16px 20px;margin:16px 0">
		<h2 style="margin-top:0"><?php esc_html_e( 'Fix it for me', 'formexposed' ); ?>
			<span style="font-weight:normal;color:#50575e;font-size:13px"> · <?php esc_html_e( 'free, runs on your server only', 'formexposed' ); ?></span></h2>
		<?php if ( $formexposed_testkey ) : ?>
			<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Contact Form 7 uses a Google reCAPTCHA TEST key.', 'formexposed' ); ?></strong> <?php esc_html_e( 'A test key lets every submission pass - the CAPTCHA protects nothing.', 'formexposed' ); ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_testkey' ), 'formexposed_testkey' ) ); ?>"><?php esc_html_e( 'Remove the test key and protect the forms', 'formexposed' ); ?></a></p></div>
		<?php endif; ?>
		<?php if ( $formexposed_guard ) : ?>
			<p><strong style="color:#00750f"><?php esc_html_e( 'Protection is on.', 'formexposed' ); ?></strong>
				<?php
				if ( is_array( $formexposed_opt ) && ! empty( $formexposed_opt['since'] ) ) {
					echo esc_html( sprintf( /* translators: %s: date */ __( 'Since %s.', 'formexposed' ), wp_date( get_option( 'date_format' ), (int) $formexposed_opt['since'] ) ) ); }
				?>
				<?php echo esc_html( sprintf( /* translators: %d: number of submissions */ _n( '%d submission blocked in the last 14 days.', '%d submissions blocked in the last 14 days.', $formexposed_blocked, 'formexposed' ), $formexposed_blocked ) ); ?></p>
			<p style="color:#50575e"><?php echo esc_html( sprintf( /* translators: %s: list of form plugins */ __( 'Protected here: %s.', 'formexposed' ), $formexposed_plugins ? implode( ', ', $formexposed_plugins ) : __( 'none of the supported form plugins is active', 'formexposed' ) ) ); ?></p>
			<p><a class="button" href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'quarantine' ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: number of messages */ _n( 'Quarantine: %d message held back', 'Quarantine: %d messages held back', $formexposed_q, 'formexposed' ), $formexposed_q ) ); ?></a>
				<span style="color:#50575e;font-size:12px"><?php esc_html_e( 'Stopped by mistake? Deliver it from there with one click.', 'formexposed' ); ?></span></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
				<input type="hidden" name="action" value="formexposed_pow">
				<?php wp_nonce_field( 'formexposed_pow' ); ?>
				<label><input type="checkbox" name="pow_on" value="1" <?php checked( Formexposed_Pow::enabled() ); ?>>
					<strong><?php esc_html_e( 'Invisible proof of work', 'formexposed' ); ?></strong> - <?php esc_html_e( 'before a form is sent, the visitor\'s browser solves a small calculation (well under a second). No puzzle, no cookie, no third party: a CAPTCHA replacement that runs on your server.', 'formexposed' ); ?></label>
				<button class="button button-small" style="margin-left:6px"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
				<input type="hidden" name="action" value="formexposed_swarm">
				<?php wp_nonce_field( 'formexposed_swarm' ); ?>
				<label><input type="checkbox" name="swarm_on" value="1" <?php checked( ! get_option( 'formexposed_swarm_off' ) ); ?>>
					<strong><?php esc_html_e( 'Swarm protection', 'formexposed' ); ?></strong> - <?php esc_html_e( 'share the domains of links in stopped messages (no addresses, no text) and also stop links that formexposed has seen in spam on several other sites.', 'formexposed' ); ?></label>
				<?php if ( $formexposed_swarm_n && ! get_option( 'formexposed_swarm_off' ) ) : ?>
					<br><span style="color:#50575e;font-size:12px"><?php echo esc_html( sprintf( /* translators: 1: number of domains, 2: date */ __( '%1$d domains on the list, updated %2$s.', 'formexposed' ), $formexposed_swarm_n, wp_date( get_option( 'date_format' ), (int) $formexposed_swarm['at'] ) ) ); ?></span>
				<?php endif; ?>
				<button class="button button-small" style="margin-left:6px"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
			</form>
			<?php $formexposed_extra = Formexposed_Guard::extra(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
				<input type="hidden" name="action" value="formexposed_guard_extra">
				<?php wp_nonce_field( 'formexposed_guard_extra' ); ?>
				<strong><?php esc_html_e( 'Also protect', 'formexposed' ); ?></strong><br>
				<label><input type="checkbox" name="extra_comments" value="1" <?php checked( $formexposed_extra['comments'] ); ?>> <?php esc_html_e( 'Comments (stopped ones go to Comments -> Spam)', 'formexposed' ); ?></label><br>
				<label><input type="checkbox" name="extra_register" value="1" <?php checked( $formexposed_extra['register'] ); ?>> <?php esc_html_e( 'WordPress registration', 'formexposed' ); ?></label><br>
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<label><input type="checkbox" name="extra_woo_register" value="1" <?php checked( $formexposed_extra['woo_register'] ); ?>> <?php esc_html_e( 'WooCommerce registration', 'formexposed' ); ?></label><br>
				<label><input type="checkbox" name="extra_woo_checkout" value="1" <?php checked( $formexposed_extra['woo_checkout'] ); ?>> <?php esc_html_e( 'WooCommerce checkout (classic checkout only; test an order after switching on)', 'formexposed' ); ?></label><br>
				<?php else : ?>
				<input type="hidden" name="extra_woo_register" value="<?php echo $formexposed_extra['woo_register'] ? '1' : ''; ?>">
				<?php endif; ?>
				<label><input type="checkbox" name="extra_login" value="1" <?php checked( $formexposed_extra['login'] ); ?> <?php disabled( defined( 'FORMEXPOSED_NO_LOGIN_GUARD' ) && FORMEXPOSED_NO_LOGIN_GUARD ); ?>> <?php esc_html_e( 'Login form (only the page check, no rate limit)', 'formexposed' ); ?></label>
				<br><span style="color:#50575e;font-size:12px;margin-left:24px"><?php esc_html_e( 'Locked out? Add this line to wp-config.php:', 'formexposed' ); ?> <code style="word-break:break-all">define( 'FORMEXPOSED_NO_LOGIN_GUARD', true );</code></span><br>
				<button class="button button-small" style="margin-top:6px"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
			</form>
			<?php
			$formexposed_candidate = Formexposed_Filters::candidate();
			$formexposed_f         = $formexposed_candidate ? $formexposed_candidate['settings'] : Formexposed_Filters::settings();
			$formexposed_land      = Formexposed_Filters::country_source();
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
				<input type="hidden" name="action" value="formexposed_filters">
				<?php wp_nonce_field( 'formexposed_filters' ); ?>
			<?php
			if ( $formexposed_candidate ) :
				$formexposed_obs    = get_option( Formexposed_Filters::OBSERVATIONS, array() );
				$formexposed_totals = array();
				if ( ( $formexposed_obs['hash'] ?? '' ) === $formexposed_candidate['hash'] ) {
					foreach ( ( $formexposed_obs['days'] ?? array() ) as $formexposed_counts ) {
						foreach ( $formexposed_counts as $formexposed_reason => $formexposed_n ) {
							$formexposed_totals[ $formexposed_reason ] = ( $formexposed_totals[ $formexposed_reason ] ?? 0 ) + (int) $formexposed_n;
						}
					}
				}
				?>
				<p><strong><?php esc_html_e( 'Observation active; current protection is unchanged.', 'formexposed' ); ?></strong>
				<?php
				/* translators: 1: end date and time, 2: number of requests */
				echo esc_html( sprintf( __( 'Ends: %1$s. Additional requests that the candidate would have blocked: %2$d. These are requests, not confirmed people or false positives.', 'formexposed' ), wp_date( 'Y-m-d H:i', $formexposed_candidate['expires'] ), array_sum( $formexposed_totals ) ) );
				?>
				</p>
				<ul>
				<?php foreach ( $formexposed_totals as $formexposed_reason => $formexposed_n ) : ?>
					<li><?php echo esc_html( $formexposed_reason . ': ' . $formexposed_n ); ?></li>
				<?php endforeach; ?>
				</ul>
				<button class="button" name="activate_candidate" value="1"><?php esc_html_e( 'Activate tested candidate', 'formexposed' ); ?></button>
				<button class="button" name="discard_candidate" value="1"><?php esc_html_e( 'Discard candidate', 'formexposed' ); ?></button>
			<?php endif; ?>
				<p><label><input type="radio" name="filter_mode" value="observe" checked> <?php esc_html_e( 'Observe changed rules for seven days', 'formexposed' ); ?></label><br>
				<label><input type="radio" name="filter_mode" value="active"> <?php esc_html_e( 'Apply these rules immediately', 'formexposed' ); ?></label></p>
				<strong><?php esc_html_e( 'Filters', 'formexposed' ); ?></strong> <span style="color:#50575e;font-size:12px"><?php esc_html_e( 'Stopped messages wait in the quarantine like all others.', 'formexposed' ); ?></span><br>
				<label><input type="checkbox" name="disposable" value="1" <?php checked( $formexposed_f['disposable'] ); ?>> <?php echo esc_html( sprintf( /* translators: %d: number of services */ __( 'Stop throw-away e-mail addresses (%d known services)', 'formexposed' ), count( Formexposed_Filters::DISPOSABLE ) ) ); ?></label><br>
				<label style="display:block;margin:4px 0 8px 24px;color:#50575e;font-size:12px"><?php esc_html_e( 'More domains, one per line:', 'formexposed' ); ?><br><textarea name="extra_disposable" rows="2" cols="40" style="max-width:100%" class="code"><?php echo esc_textarea( $formexposed_f['extra_disposable'] ); ?></textarea></label>
				<span><?php esc_html_e( 'Stop messages written mainly in:', 'formexposed' ); ?></span>
				<?php
				$formexposed_schriften = array(
					'cyrillic'   => __( 'Cyrillic', 'formexposed' ),
					'cjk'        => __( 'Chinese, Japanese, Korean', 'formexposed' ),
					'arabic'     => __( 'Arabic', 'formexposed' ),
					'hebrew'     => __( 'Hebrew', 'formexposed' ),
					'greek'      => __( 'Greek', 'formexposed' ),
					'thai'       => __( 'Thai', 'formexposed' ),
					'devanagari' => __( 'Devanagari', 'formexposed' ),
				);
				foreach ( $formexposed_schriften as $formexposed_k => $formexposed_n ) :
					?>
					<label style="margin-right:10px"><input type="checkbox" name="scripts[]" value="<?php echo esc_attr( $formexposed_k ); ?>" <?php checked( in_array( $formexposed_k, $formexposed_f['scripts'], true ) ); ?>> <?php echo esc_html( $formexposed_n ); ?></label>
				<?php endforeach; ?><br>
				<label style="display:block;margin:8px 0"><?php esc_html_e( 'Blocked words or phrases, one per line (whole words, case does not matter):', 'formexposed' ); ?><br><textarea name="words" rows="3" cols="40" style="max-width:100%" class="code" placeholder="casino&#10;seo services&#10;crypto"><?php echo esc_textarea( $formexposed_f['words'] ); ?></textarea></label>
				<label><?php esc_html_e( 'Countries:', 'formexposed' ); ?>
					<select name="country_mode">
						<option value="off" <?php selected( $formexposed_f['country_mode'], 'off' ); ?>><?php esc_html_e( 'no country filter', 'formexposed' ); ?></option>
						<option value="allow" <?php selected( $formexposed_f['country_mode'], 'allow' ); ?>><?php esc_html_e( 'only from these countries', 'formexposed' ); ?></option>
						<option value="block" <?php selected( $formexposed_f['country_mode'], 'block' ); ?>><?php esc_html_e( 'never from these countries', 'formexposed' ); ?></option>
					</select>
					<input type="text" name="countries" value="<?php echo esc_attr( $formexposed_f['countries'] ); ?>" placeholder="DE, AT, CH, PL" class="regular-text code" style="max-width:100%"></label><br>
				<span style="color:#50575e;font-size:12px"><?php echo esc_html( '' !== $formexposed_land ? sprintf( /* translators: %s: source of the country */ __( 'Country known from: %s.', 'formexposed' ), $formexposed_land ) : __( 'No country source on this site (Cloudflare, a GeoIP module of the server or the filter formexposed_client_country) - the country filter cannot block anything here.', 'formexposed' ) ); ?></span><br>
				<button class="button button-small" style="margin-top:6px"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
			</form>
		<?php elseif ( ! $formexposed_plugins ) : ?>
			<p><?php esc_html_e( 'The one-click protection works with Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable, Ninja Forms and WooCommerce. None of them is active on this site - for other form plugins, follow the guide above.', 'formexposed' ); ?></p>
		<?php else : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: list of form plugins */ __( 'One click adds a protection layer to every form of %s on this site. It runs here, on your server - no keys, no third-party service. Only the number of blocked submissions is shared with your formexposed account.', 'formexposed' ), implode( ', ', $formexposed_plugins ) ) ); ?></p>
		<?php endif; ?>
		<ul style="list-style:disc;padding-left:20px;color:#50575e">
			<li><?php esc_html_e( 'A hidden trap field that only bots fill in', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'A signed form token and a minimum fill time of 3 seconds', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'Proof that a person used the page before sending', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'At most 5 messages per visitor in 10 minutes', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'No line breaks in name, e-mail or subject (header injection)', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'The e-mail address must belong to a domain that can receive mail', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'Stopped messages wait 14 days in a quarantine on this site - nothing is lost', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'Optional: an invisible proof of work instead of a CAPTCHA', 'formexposed' ); ?></li>
			<li><?php esc_html_e( 'Also comments and registration; throw-away addresses and filters you choose', 'formexposed' ); ?></li>
		</ul>
		<p style="color:#50575e;font-size:12px"><?php esc_html_e( 'Blocked messages are not delivered: Contact Form 7 and Gravity Forms file them as spam, WPForms and Elementor show the sender an error. Every one of them waits in the quarantine. After switching on, a new check starts so you can see the result.', 'formexposed' ); ?></p>
		<p>
		<?php if ( $formexposed_guard ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_guard_off' ), 'formexposed_guard_off' ) ); ?>"><?php esc_html_e( 'Turn protection off', 'formexposed' ); ?></a>
		<?php elseif ( $formexposed_plugins ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_guard_on' ), 'formexposed_guard_on' ) ); ?>"><?php esc_html_e( 'Turn on protection', 'formexposed' ); ?></a>
		<?php endif; ?>
		</p>
	</div>

	<?php
	$formexposed_rc     = Formexposed_Recaptcha::details();
	$formexposed_rc_an  = Formexposed_Recaptcha::places_from( $formexposed_rc );
	$formexposed_rc_bak = Formexposed_Recaptcha::backup();
	if ( $formexposed_rc_an || $formexposed_rc_bak ) :
		?>
	<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $formexposed_rc_an ? '#996800' : '#00750f'; ?>;padding:16px 20px;margin:16px 0">
		<h2 style="margin-top:0"><?php esc_html_e( 'Move away from Google reCAPTCHA', 'formexposed' ); ?></h2>
		<?php if ( $formexposed_rc_an ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: list of form plugins */ __( 'reCAPTCHA is still in use here: %s.', 'formexposed' ), implode( ', ', array_map( array( 'Formexposed_Recaptcha', 'place_name' ), $formexposed_rc_an ) ) ) ); ?></p>
			<ul style="list-style:disc;padding-left:20px;color:#50575e">
				<li><?php esc_html_e( 'Quota: Google limits the free reCAPTCHA checks per month. Above the limit every request passes - the protection is gone without a warning.', 'formexposed' ); ?></li>
				<li><?php esc_html_e( 'Consent: reCAPTCHA loads Google scripts and cookies. In the EU that needs the visitor\'s consent first - and a visitor who declines cannot send the form.', 'formexposed' ); ?></li>
				<li><?php esc_html_e( 'Privacy: the IP address and browser data of every visitor go to Google.', 'formexposed' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'One click switches this site to the invisible proof of work of the protection layer: it runs on your server and needs no keys and no consent. Contact Form 7 and WPForms are switched over for you, with a backup and Undo. Gravity Forms and Elementor forms are never edited by the plugin - remove their reCAPTCHA fields as described below.', 'formexposed' ); ?></p>
			<?php if ( ! $formexposed_guard ) : ?>
				<p><em><?php esc_html_e( 'Turn on the protection layer above first - the proof of work is one of its checks.', 'formexposed' ); ?></em></p>
			<?php else : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_recaptcha_off' ), 'formexposed_recaptcha_off' ) ); ?>"><?php esc_html_e( 'Replace reCAPTCHA with the proof of work', 'formexposed' ); ?></a>
					<span style="color:#50575e;font-size:12px"><?php esc_html_e( 'A self-test runs first; if it fails, nothing is changed.', 'formexposed' ); ?></span></p>
			<?php endif; ?>
			<?php if ( $formexposed_rc['gravityforms'] ) : ?>
				<p><strong>Gravity Forms:</strong> <?php esc_html_e( 'open the form, delete the CAPTCHA field, update the form. For the reCAPTCHA add-on: Forms -> Settings -> reCAPTCHA, remove the keys or deactivate the add-on.', 'formexposed' ); ?></p>
				<ul style="list-style:disc;padding-left:20px">
				<?php
				foreach ( $formexposed_rc['gravityforms'] as $formexposed_f ) :
					?>
					<li><a href="<?php echo esc_url( $formexposed_f['edit'] ); ?>"><?php echo esc_html( $formexposed_f['title'] ); ?></a></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $formexposed_rc['elementor'] ) : ?>
				<p><strong>Elementor Pro:</strong> <?php esc_html_e( 'edit the page with Elementor, select the form, delete the reCAPTCHA field, update.', 'formexposed' ); ?></p>
				<ul style="list-style:disc;padding-left:20px">
				<?php
				foreach ( $formexposed_rc['elementor'] as $formexposed_f ) :
					?>
					<li><a href="<?php echo esc_url( $formexposed_f['edit'] ); ?>"><?php echo esc_html( $formexposed_f['title'] ); ?></a></li><?php endforeach; ?></ul>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $formexposed_rc_bak ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Switched to the proof of work on %s. The former reCAPTCHA settings are kept.', 'formexposed' ), wp_date( get_option( 'date_format' ), (int) $formexposed_rc_bak['at'] ) ) ); ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_recaptcha_undo' ), 'formexposed_recaptcha_undo' ) ); ?>"><?php esc_html_e( 'Undo', 'formexposed' ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php
	$formexposed_h = Formexposed_Health::last();
	if ( ! $formexposed_h || time() - (int) $formexposed_h['at'] > DAY_IN_SECONDS ) {
		$formexposed_h = Formexposed_Health::scan( 'cache' );   // no request to the site itself from here - the Form settings tab does that
	}
	$formexposed_hc   = $formexposed_h['counts'];
	$formexposed_prob = $formexposed_hc['from_visitor'] + $formexposed_hc['notifications_off'] + $formexposed_hc['recipient_freemail'] + $formexposed_hc['upload_no_types'] + $formexposed_hc['uploads_listing'] + $formexposed_hc['autoresponder'];
	$formexposed_ret  = Formexposed_Retention::days();
	$formexposed_rw   = Formexposed_Rescue::count_waiting();
	?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0">
		<h2 style="margin-top:0"><?php esc_html_e( 'Your forms on this site', 'formexposed' ); ?></h2>
		<ul style="list-style:disc;padding-left:20px">
			<li><a href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'forms' ) ) ); ?>"><?php esc_html_e( 'Form settings', 'formexposed' ); ?></a>:
				<?php
				echo esc_html( sprintf( /* translators: %d: number of forms */ _n( '%d form', '%d forms', (int) $formexposed_hc['forms'], 'formexposed' ), (int) $formexposed_hc['forms'] ) ) . ', ';
				echo $formexposed_prob
					? '<strong style="color:#996800">' . esc_html( sprintf( /* translators: %d: number of findings */ _n( '%d point to look at', '%d points to look at', $formexposed_prob, 'formexposed' ), $formexposed_prob ) ) . '</strong>'
					: esc_html__( 'nothing to fix', 'formexposed' );
				?>
			</li>
			<li><a href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'rescue' ) ) ); ?>"><?php esc_html_e( 'Rescued enquiries', 'formexposed' ); ?></a>:
				<?php echo $formexposed_rw ? '<strong style="color:#b32d2e">' . esc_html( sprintf( /* translators: %d: number of enquiries */ _n( '%d enquiry could not be e-mailed', '%d enquiries could not be e-mailed', $formexposed_rw, 'formexposed' ), $formexposed_rw ) ) . '</strong>' : esc_html__( 'none - every form e-mail went out', 'formexposed' ); ?>
			</li>
			<li><a href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'storage' ) ) ); ?>"><?php esc_html_e( 'Stored enquiries', 'formexposed' ); ?></a>:
				<?php echo esc_html( $formexposed_ret ? sprintf( /* translators: %d: days */ __( 'deleted automatically after %d days', 'formexposed' ), $formexposed_ret ) : __( 'no storage limit set', 'formexposed' ) ); ?>
			</li>
		</ul>
	</div>

	<div style="background:#f6f7f7;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0">
		<h2 style="margin-top:0"><?php esc_html_e( 'Keep it protected: monitoring', 'formexposed' ); ?></h2>
		<p><?php esc_html_e( 'A theme update, a new plugin, a changed form - protection disappears quietly. With a free formexposed account this site is checked on a schedule and you get a message the moment its protection drops.', 'formexposed' ); ?></p>
		<p><?php esc_html_e( 'Because this plugin is installed here, the site counts as yours: one click connects it to your account, no DNS record and no file upload needed.', 'formexposed' ); ?></p>
		<?php if ( Formexposed_Core::rekey_pending() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Version 1.2.0 renewed the key of this site. If it was connected to a formexposed account before, connect it once more.', 'formexposed' ); ?></p></div>
		<?php endif; ?>
		<?php $formexposed_wartung = Formexposed_Maintenance::state(); ?>
		<?php if ( $formexposed_wartung['on'] ) : ?>
			<p><strong><?php esc_html_e( 'Maintenance mode detected:', 'formexposed' ); ?></strong> <?php echo esc_html( Formexposed_Maintenance::source_text( $formexposed_wartung['source'] ) ); ?>.
				<?php echo esc_html( Formexposed_Core::linked() ? __( 'formexposed keeps your alerts quiet while it lasts.', 'formexposed' ) : __( 'Connected sites keep their alerts quiet while it lasts.', 'formexposed' ) ); ?></p>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( Formexposed_Core::connect_action_url() ); ?>"><?php esc_html_e( 'Connect to a formexposed account', 'formexposed' ); ?></a></p>
	</div>

	<?php $formexposed_code_msg = get_option( Formexposed_Core::OPT_CODE_MSG ); ?>
	<div style="background:#f6f7f7;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0">
		<h2 style="margin-top:0"><?php esc_html_e( 'Looked after by an agency?', 'formexposed' ); ?></h2>
		<?php if ( get_option( Formexposed_Core::OPT_CODE_OK ) ) : ?>
			<p><strong style="color:#00750f"><?php esc_html_e( 'This site is connected to your agency\'s formexposed account.', 'formexposed' ); ?></strong></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'If your agency gave you a code (fxc_...), enter it here. The site is then connected to the agency\'s account - no login needed.', 'formexposed' ); ?></p>
		<?php if ( is_array( $formexposed_code_msg ) && ! empty( $formexposed_code_msg[1] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $formexposed_code_msg[1] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
			<input type="hidden" name="action" value="formexposed_agency">
			<?php wp_nonce_field( 'formexposed_agency' ); ?>
			<input type="text" name="formexposed_code" class="regular-text code" style="max-width:100%" placeholder="fxc_..." autocomplete="off" spellcheck="false" maxlength="40">
			<button class="button"><?php esc_html_e( 'Connect with the code', 'formexposed' ); ?></button>
		</form>
		<p style="color:#50575e;font-size:12px"><?php esc_html_e( 'For many sites at once:', 'formexposed' ); ?> <code>wp formexposed connect fxc_...</code></p>
	</div>

	<p class="description"><?php echo esc_html( sprintf( /* translators: %s: host name */ __( 'Sent to formexposed.com when you click "Check now": the host name (%s), the plugin and WordPress versions and the site language. The service reads your public pages the way a visitor would. Nothing is submitted. Once a day, and only while this site is connected to an account: the number of blocked submissions and of sent and failed e-mails - counts, no content.', 'formexposed' ), Formexposed_Core::host() ) ); ?>
		<?php esc_html_e( 'Since 1.4.0, while connected, also: the versions of the form, CAPTCHA and mail plugins; the numbers of the form settings check and of stored enquiries; right away, the kind of error when a form e-mail fails; and whether the site is in maintenance mode. Never addresses, names or message content.', 'formexposed' ); ?>
		<a href="<?php echo esc_url( FORMEXPOSED_API . '/privacy' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy', 'formexposed' ); ?></a> ·
		<a href="<?php echo esc_url( FORMEXPOSED_API . '/bot' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'About the crawler', 'formexposed' ); ?></a></p>
<h2 style="margin-top:28px"><?php esc_html_e( 'Optional form tools', 'formexposed' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:12px;border:1px solid #dcdcde;background:#fff">
<input type="hidden" name="action" value="formexposed_metrics"><?php wp_nonce_field( 'formexposed_metrics' ); ?>
<label><input type="checkbox" name="metrics_on" value="1" <?php checked( get_option( 'formexposed_metrics_on' ) ); ?>> <?php esc_html_e( 'Optional form event counters (off by default)', 'formexposed' ); ?></label>
<p><?php esc_html_e( 'Counts per form: seen, started, validation error and submit attempt. No input content or visitor identifiers. Contact Form 7 client signals are shown separately; they do not prove mailbox or CRM delivery. Only connected sites send daily counts to formexposed. Add this optional processing to your privacy information before enabling it.', 'formexposed' ); ?></p>
<label><input type="checkbox" name="field_metrics_on" value="1" <?php checked( get_option( 'formexposed_field_metrics_on' ) ); ?>> <?php esc_html_e( 'Also count validation categories by field position (optional, no values or field names).', 'formexposed' ); ?></label><br>
<button class="button"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:12px;border:1px solid #dcdcde;background:#fff">
<input type="hidden" name="action" value="formexposed_recovery"><?php wp_nonce_field( 'formexposed_recovery' ); ?>
<label><input type="checkbox" name="recovery_on" value="1" <?php checked( get_option( 'formexposed_recovery_on' ) ); ?>> <?php esc_html_e( 'Help visitors after a failed submission (optional)', 'formexposed' ); ?></label>
<p><?php esc_html_e( 'Keeps the existing open form and offers a return link after offline or Contact Form 7 mail failures. No draft storage or automatic resend. Unclear delivery is labelled separately. Other integrations can emit formexposed:submissionfailed on their form after a confirmed failure.', 'formexposed' ); ?></p>
<label><?php esc_html_e( 'Alternative contact email (optional)', 'formexposed' ); ?> <input type="email" name="recovery_email" value="<?php echo esc_attr( get_option( 'formexposed_recovery_email', '' ) ); ?>"></label>
<button class="button"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
</form>
</div>
