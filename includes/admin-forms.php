<?php
/**
 * formexposed - form settings check (view). Settings -> formexposed -> Form settings.
 * The details with form names stay here; formexposed.com only gets the counts.
 * Expects $msg (array(kind, text) or false) from Formexposed_Core::page().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$formexposed_scan    = Formexposed_Health::scan( 'check' );
$formexposed_c       = $formexposed_scan['counts'];
$formexposed_namen   = array( 'cf7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'gravityforms' => 'Gravity Forms', 'elementor' => 'Elementor Pro', 'fluentform' => 'Fluent Forms', 'formidable' => 'Formidable Forms' );
$formexposed_art     = array();
foreach ( $formexposed_scan['items'] as $formexposed_i ) {
	$formexposed_art[ $formexposed_i['kind'] ][] = $formexposed_i;
}
$formexposed_reihe   = array( 'from_visitor', 'notifications_off', 'recipient_freemail', 'upload_no_types', 'uploads_listing', 'autoresponder', 'recipient_external' );
$formexposed_rc      = Formexposed_Recaptcha::details();
?>
<div class="wrap formexposed-wrap">
	<h1>formexposed · <?php esc_html_e( 'Form settings', 'formexposed' ); ?></h1>
	<?php Formexposed_Core::nav( 'forms' ); ?>
	<p class="description"><?php esc_html_e( 'Settings of your forms that often cost enquiries or expose data - read on this site from Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms and Formidable Forms. The form names and details stay here; a connected formexposed account only receives the numbers.', 'formexposed' ); ?></p>

	<?php if ( is_array( $msg ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'ok' === $msg[0] ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
	<?php endif; ?>

	<div class="fx-card <?php echo esc_attr( $formexposed_scan['items'] ? 'is-warn' : 'is-ok' ); ?>">
		<p style="margin-top:0"><strong><?php echo esc_html( sprintf( /* translators: %d: number of forms */ _n( '%d form checked', '%d forms checked', (int) $formexposed_c['forms'], 'formexposed' ), (int) $formexposed_c['forms'] ) ); ?></strong>
			<?php if ( $formexposed_scan['plugins'] ) : ?><span class="fx-text-muted"> · <?php echo esc_html( implode( ', ', array_map( static function ( $p ) use ( $formexposed_namen ) { return isset( $formexposed_namen[ $p ] ) ? $formexposed_namen[ $p ] : $p; }, $formexposed_scan['plugins'] ) ) ); ?></span><?php endif; ?></p>
		<p><?php esc_html_e( 'E-mail is sent through:', 'formexposed' ); ?> <strong><?php echo esc_html( Formexposed_Health::mailer_text( $formexposed_scan['mailer'] ) ); ?></strong>
			<?php if ( 'php' === $formexposed_scan['mailer'] ) : ?><br><span class="fx-text-warn"><?php esc_html_e( 'Without an SMTP plugin WordPress hands every form e-mail to the web server\'s mail function - often without SPF/DKIM, so it lands in spam or is refused. An SMTP plugin with the mailbox of your domain (or a mail service) makes delivery reliable.', 'formexposed' ); ?></span><?php endif; ?></p>
		<p style="margin-bottom:0"><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_forms_check' ), 'formexposed_forms_check' ) ); ?>"><?php esc_html_e( 'Check again', 'formexposed' ); ?></a>
			<span class="fx-small"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Checked %s.', 'formexposed' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $formexposed_scan['at'] ) ) ); ?></span></p>
	</div>

	<?php
	// 1.12.0: all seven checks at a glance, each finding one click away.
	$formexposed_sauber = 0;
	foreach ( $formexposed_reihe as $formexposed_k ) {
		$formexposed_sauber += empty( $formexposed_art[ $formexposed_k ] ) ? 1 : 0;
	}
	$formexposed_umfang = 2 * M_PI * 24;
	$formexposed_anteil = $formexposed_sauber / count( $formexposed_reihe );
	?>
	<div class="fx-card fx-health">
		<div class="fx-health-head">
			<svg class="fx-health-ring" viewBox="0 0 60 60" aria-hidden="true" focusable="false">
				<circle cx="30" cy="30" r="24" class="fx-ring-track"></circle>
				<circle cx="30" cy="30" r="24" class="fx-health-value" stroke-dasharray="<?php echo esc_attr( round( $formexposed_umfang * $formexposed_anteil, 2 ) . ' ' . round( $formexposed_umfang, 2 ) ); ?>"></circle>
			</svg>
			<span class="fx-health-n" aria-hidden="true"><?php echo esc_html( $formexposed_sauber . '/' . count( $formexposed_reihe ) ); ?></span>
			<div>
				<h2><?php echo esc_html( sprintf( /* translators: 1: checks without findings, 2: all checks */ __( '%1$d of %2$d checks without findings', 'formexposed' ), $formexposed_sauber, count( $formexposed_reihe ) ) ); ?></h2>
				<p class="fx-small"><?php esc_html_e( 'Red costs enquiries or exposes data, yellow is worth a look, blue is only a note. A finding opens with one click.', 'formexposed' ); ?></p>
			</div>
		</div>
		<ol class="fx-health-list">
			<?php foreach ( $formexposed_reihe as $formexposed_k ) : ?>
				<?php
				$formexposed_titel_k = Formexposed_Health::kind_text( $formexposed_k )[0];
				$formexposed_n_k     = empty( $formexposed_art[ $formexposed_k ] ) ? 0 : count( $formexposed_art[ $formexposed_k ] );
				$formexposed_ton_k   = ! $formexposed_n_k ? 'is-ok' : ( 'recipient_external' === $formexposed_k ? 'is-info' : ( in_array( $formexposed_k, array( 'from_visitor', 'notifications_off', 'uploads_listing' ), true ) ? 'is-bad' : 'is-warn' ) );
				?>
				<li class="<?php echo esc_attr( $formexposed_ton_k ); ?>">
					<span class="dashicons <?php echo esc_attr( $formexposed_n_k ? ( 'is-info' === $formexposed_ton_k ? 'dashicons-info' : 'dashicons-warning' ) : 'dashicons-yes-alt' ); ?>" aria-hidden="true"></span>
					<?php if ( $formexposed_n_k ) : ?>
						<a href="<?php echo esc_attr( '#fx-k-' . $formexposed_k ); ?>" data-fx-jump><?php echo esc_html( $formexposed_titel_k ); ?></a>
						<span class="fx-count<?php echo esc_attr( 'is-info' === $formexposed_ton_k ? ' is-info' : '' ); ?>"><?php echo esc_html( number_format_i18n( $formexposed_n_k ) ); ?></span>
					<?php else : ?>
						<span class="fx-text-muted"><?php echo esc_html( $formexposed_titel_k ); ?></span>
						<span class="fx-health-ok"><?php esc_html_e( 'not found', 'formexposed' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>

	<?php if ( ! $formexposed_scan['items'] ) : ?>
		<div class="fx-card is-ok"><p class="fx-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Nothing found - the forms here are set up the way they should be.', 'formexposed' ); ?></p></div>
	<?php endif; ?>

	<?php
	foreach ( $formexposed_reihe as $formexposed_k ) :
		if ( empty( $formexposed_art[ $formexposed_k ] ) ) {
			continue;
		}
		list( $formexposed_titel, $formexposed_warum, $formexposed_fix ) = Formexposed_Health::kind_text( $formexposed_k );
		$formexposed_farbe                           = 'recipient_external' === $formexposed_k ? 'is-info' : ( in_array( $formexposed_k, array( 'from_visitor', 'notifications_off', 'uploads_listing' ), true ) ? 'is-bad' : 'is-warn' );
		?>
		<div class="fx-card <?php echo esc_attr( $formexposed_farbe ); ?>" id="<?php echo esc_attr( 'fx-k-' . $formexposed_k ); ?>" tabindex="-1">
			<h2><?php echo esc_html( $formexposed_titel ); ?> <span class="fx-count<?php echo esc_attr( 'is-info' === $formexposed_farbe ? ' is-info' : '' ); ?>"><?php echo (int) count( $formexposed_art[ $formexposed_k ] ); ?></span></h2>
			<p><?php echo esc_html( $formexposed_warum ); ?></p>
			<p><strong><?php esc_html_e( 'How to fix it:', 'formexposed' ); ?></strong> <?php echo esc_html( $formexposed_fix ); ?></p>
			<ul class="fx-list">
			<?php foreach ( $formexposed_art[ $formexposed_k ] as $formexposed_i ) : $formexposed_hint = Formexposed_Health::fix_hint( $formexposed_k, $formexposed_i['plugin'] ); ?>
				<li>
					<?php if ( '' !== $formexposed_i['edit'] ) : ?><a href="<?php echo esc_url( $formexposed_i['edit'] ); ?>"><strong><?php echo esc_html( $formexposed_i['form'] ); ?></strong></a><?php else : ?><strong><?php echo esc_html( $formexposed_i['form'] ); ?></strong><?php endif; ?>
					<?php if ( '' !== $formexposed_i['plugin'] ) : ?><span class="fx-text-muted"> · <?php echo esc_html( isset( $formexposed_namen[ $formexposed_i['plugin'] ] ) ? $formexposed_namen[ $formexposed_i['plugin'] ] : $formexposed_i['plugin'] ); ?></span><?php endif; ?>
					<?php if ( '' !== $formexposed_i['detail'] && 'upload_no_types' !== $formexposed_k ) : ?><span class="fx-text-muted"> · <?php echo esc_html( $formexposed_i['detail'] ); ?></span><?php endif; ?>
					<?php if ( 'upload_no_types' === $formexposed_k ) : ?><span class="fx-text-muted"> · <?php echo esc_html( sprintf( /* translators: %d: number of fields */ _n( '%d file field', '%d file fields', (int) $formexposed_i['detail'], 'formexposed' ), (int) $formexposed_i['detail'] ) ); ?></span><?php endif; ?>
					<?php if ( '' !== $formexposed_hint ) : ?><br><span class="fx-small"><?php echo esc_html( $formexposed_hint ); ?></span><?php endif; ?>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>

	<?php if ( $formexposed_scan['listing'] ) : ?>
		<h2><?php esc_html_e( 'Upload folders of form plugins', 'formexposed' ); ?></h2>
		<table class="widefat striped" style="max-width:720px">
			<tbody>
			<?php foreach ( $formexposed_scan['listing'] as $formexposed_d ) : ?>
				<tr><td><code><?php echo esc_html( Formexposed_Health::uploads_rel() . $formexposed_d['dir'] . '/' ); ?></code></td>
					<td><?php
					switch ( $formexposed_d['state'] ) {
						case 'listing':
							echo '<span class="fx-badge is-bad">' . esc_html__( 'lists its files', 'formexposed' ) . '</span>';
							break;
						case 'ok':
							echo '<span class="fx-badge is-ok">' . esc_html__( 'no listing', 'formexposed' ) . '</span>';
							break;
						case 'skipped':
							esc_html_e( 'not checked (uploads on another host)', 'formexposed' );
							break;
						default:
							esc_html_e( 'not checked (this site could not reach itself)', 'formexposed' );
					}
					?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( array_filter( $formexposed_rc ) ) : ?>
		<h2><?php esc_html_e( 'Google reCAPTCHA still in use', 'formexposed' ); ?></h2>
		<p><?php esc_html_e( 'See "Move away from reCAPTCHA" on the overview.', 'formexposed' ); ?></p>
		<ul class="fx-list">
			<?php if ( $formexposed_rc['cf7'] ) : ?><li>Contact Form 7 · <?php esc_html_e( 'integration keys (Contact -> Integration)', 'formexposed' ); ?></li><?php endif; ?>
			<?php if ( $formexposed_rc['wpforms'] ) : ?><li>WPForms · <?php esc_html_e( 'CAPTCHA provider reCAPTCHA (WPForms -> Settings -> CAPTCHA)', 'formexposed' ); ?></li><?php endif; ?>
			<?php foreach ( array( 'gravityforms', 'elementor' ) as $formexposed_p ) : ?>
				<?php foreach ( (array) $formexposed_rc[ $formexposed_p ] as $formexposed_f ) : ?>
					<li><a href="<?php echo esc_url( $formexposed_f['edit'] ); ?>"><?php echo esc_html( $formexposed_f['title'] ); ?></a> · <?php echo esc_html( $formexposed_namen[ $formexposed_p ] ); ?></li>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
