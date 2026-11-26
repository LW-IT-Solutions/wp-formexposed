<?php
/**
 * formexposed - the quarantine (view). Settings -> formexposed -> Quarantine.
 * Expects $msg (array(kind, text) or false) from Formexposed_Core::page().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$formexposed_seite  = max( 1, absint( filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT ) ) );
$formexposed_liste  = Formexposed_Quarantine::entries( 50, ( $formexposed_seite - 1 ) * 50 );
$formexposed_offen  = Formexposed_Quarantine::count_open();
$formexposed_quellen = array( 'cf7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'gravityforms' => 'Gravity Forms', 'elementor' => 'Elementor', 'fluentform' => 'Fluent Forms', 'formidable' => 'Formidable', 'ninjaforms' => 'Ninja Forms' );
?>
<div class="wrap formexposed-wrap">
	<h1>formexposed · <?php esc_html_e( 'Quarantine', 'formexposed' ); ?></h1>
	<?php Formexposed_Core::nav( 'quarantine' ); ?>
	<p class="description"><?php echo esc_html( sprintf( /* translators: 1: days, 2: e-mail address */ __( 'Messages the protection layer stopped, or the hosted form protection held back, stay here for %1$d days, then they are deleted. If a real person was stopped, "Deliver" sends the message to %2$s with the sender as Reply-To. The quarantine itself is never sent anywhere; with swarm protection on, only the domain names of links in stopped messages are shared.', 'formexposed' ), Formexposed_Quarantine::DAYS, Formexposed_Quarantine::recipient() ) ); ?></p>

	<?php if ( is_array( $msg ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'ok' === $msg[0] ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $formexposed_liste ) : ?>
		<div class="fx-card is-ok"><p class="fx-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'The quarantine is empty.', 'formexposed' ); ?></p></div>
	<?php else : ?>
		<p class="fx-inline-form"><span class="fx-badge <?php echo esc_attr( $formexposed_offen ? 'is-warn' : 'is-ok' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: number of entries */ _n( '%d message not delivered.', '%d messages not delivered.', $formexposed_offen, 'formexposed' ), $formexposed_offen ) ); ?></span>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_q_delete&all=1' ), 'formexposed_q_delete' ) ); ?>" data-formexposed-confirm="<?php esc_attr_e( 'Delete all entries?', 'formexposed' ); ?>"><?php esc_html_e( 'Delete all', 'formexposed' ); ?></a></p>
		<div class="fx-scroll" style="overflow-x:auto"><table class="widefat striped fx-table">
			<thead><tr>
				<th style="width:130px"><?php esc_html_e( 'Received', 'formexposed' ); ?></th>
				<th><?php esc_html_e( 'Form and content', 'formexposed' ); ?></th>
				<th style="width:260px"><?php esc_html_e( 'Why it was stopped', 'formexposed' ); ?></th>
				<th style="width:170px"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'formexposed' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $formexposed_liste as $formexposed_e ) : $formexposed_felder = json_decode( (string) $formexposed_e['fields'], true ); $formexposed_felder = is_array( $formexposed_felder ) ? $formexposed_felder : array(); ?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Received', 'formexposed' ); ?>"><?php echo esc_html( get_date_from_gmt( $formexposed_e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Form and content', 'formexposed' ); ?>">
						<strong><?php echo esc_html( $formexposed_e['form'] ); ?></strong> <span style="color:#646970">· <?php echo esc_html( isset( $formexposed_quellen[ $formexposed_e['source'] ] ) ? $formexposed_quellen[ $formexposed_e['source'] ] : $formexposed_e['source'] ); ?></span>
						<details style="margin-top:4px"><summary><?php echo esc_html( mb_substr( implode( ' · ', array_map( static function ( $f ) { return (string) $f[1]; }, array_slice( $formexposed_felder, 0, 2 ) ) ), 0, 120 ) ); ?></summary>
							<dl style="margin:8px 0 0">
							<?php foreach ( $formexposed_felder as $formexposed_f ) : ?>
								<dt style="font-weight:600"><?php echo esc_html( (string) $formexposed_f[0] ); ?></dt>
								<dd style="margin:0 0 6px;white-space:pre-wrap;word-break:break-word"><?php echo esc_html( (string) $formexposed_f[1] ); ?></dd>
							<?php endforeach; ?>
							</dl>
						</details>
					</td>
					<td data-label="<?php esc_attr_e( 'Why it was stopped', 'formexposed' ); ?>"><span class="fx-badge <?php echo esc_attr( 0 === strpos( (string) $formexposed_e['reason'], 'fc_' ) ? 'is-warn' : 'is-bad' ); ?>"><?php echo esc_html( Formexposed_Stats::reason_label( $formexposed_e['reason'] ) ); ?></span>
						<span class="fx-reason-text"><?php echo esc_html( Formexposed_Quarantine::reason_text( $formexposed_e['reason'] ) ); ?></span></td>
					<td class="fx-row-actions">
						<?php if ( $formexposed_e['released_at'] ) : ?>
							<span class="fx-badge is-ok"><?php esc_html_e( 'Delivered', 'formexposed' ); ?></span>
						<?php else : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_q_release&id=' . (int) $formexposed_e['id'] ), 'formexposed_q_release' ) ); ?>"><?php esc_html_e( 'Deliver', 'formexposed' ); ?></a>
						<?php endif; ?>
						<a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_q_delete&id=' . (int) $formexposed_e['id'] ), 'formexposed_q_delete' ) ); ?>" data-formexposed-confirm="<?php esc_attr_e( 'Delete this entry? It cannot be delivered afterwards.', 'formexposed' ); ?>"><?php esc_html_e( 'Delete', 'formexposed' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<p class="fx-pager">
			<?php if ( $formexposed_seite > 1 ) : ?><a class="button" href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'quarantine', 'paged' => $formexposed_seite - 1 ) ) ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Newer', 'formexposed' ); ?></a><?php endif; ?>
			<?php if ( 50 === count( $formexposed_liste ) ) : ?><a class="button" href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'quarantine', 'paged' => $formexposed_seite + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'formexposed' ); ?> <span aria-hidden="true">&rarr;</span></a><?php endif; ?>
		</p>
	<?php endif; ?>
</div>
