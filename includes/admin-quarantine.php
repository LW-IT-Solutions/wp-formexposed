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
		<?php
		// 1.12.0: the entries of this page on a 14-day line, the reasons as filters, and the search.
		$formexposed_jetzt   = time();
		$formexposed_spanne  = Formexposed_Quarantine::DAYS * DAY_IN_SECONDS;
		$formexposed_punkte  = array();
		$formexposed_zaehler = array();
		$formexposed_belegt  = array();
		foreach ( $formexposed_liste as $formexposed_e ) {
			$formexposed_zeit = (int) strtotime( $formexposed_e['created_at'] . ' UTC' );
			$formexposed_pos  = max( 0, min( 100, 100 - ( $formexposed_jetzt - $formexposed_zeit ) / $formexposed_spanne * 100 ) );
			$formexposed_slot = (int) round( $formexposed_pos / 3 );
			$formexposed_hoch = isset( $formexposed_belegt[ $formexposed_slot ] ) ? $formexposed_belegt[ $formexposed_slot ] + 1 : 0;
			$formexposed_belegt[ $formexposed_slot ] = $formexposed_hoch;
			$formexposed_art  = $formexposed_e['released_at'] ? 'ok' : ( 0 === strpos( (string) $formexposed_e['reason'], 'fc_' ) ? 'warn' : 'bad' );
			$formexposed_punkte[] = array( (int) $formexposed_e['id'], $formexposed_pos, min( 3, $formexposed_hoch ), $formexposed_art, $formexposed_zeit, Formexposed_Stats::reason_label( $formexposed_e['reason'] ) );
			$formexposed_l = Formexposed_Stats::reason_label( $formexposed_e['reason'] );
			$formexposed_zaehler[ $formexposed_l ] = ( isset( $formexposed_zaehler[ $formexposed_l ] ) ? $formexposed_zaehler[ $formexposed_l ] : 0 ) + 1;
		}
		arsort( $formexposed_zaehler );
		$formexposed_arten = array(
			'bad'  => __( 'Stopped by the protection layer', 'formexposed' ),
			'warn' => __( 'Held by the hosted form protection', 'formexposed' ),
			'ok'   => __( 'Delivered', 'formexposed' ),
		);
		?>
		<div class="fx-card is-quiet fx-timeline">
			<h2><?php esc_html_e( 'When the messages arrived', 'formexposed' ); ?></h2>
			<div class="fx-tl-track">
				<?php foreach ( array( 0, 50, 100 ) as $formexposed_t ) : ?>
					<span class="fx-tl-tick" style="<?php echo esc_attr( 'left:' . $formexposed_t . '%' ); ?>" aria-hidden="true"></span>
				<?php endforeach; ?>
				<?php foreach ( $formexposed_punkte as $formexposed_pt ) : ?>
					<?php $formexposed_pt_text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $formexposed_pt[4] ) . ' · ' . $formexposed_pt[5] . ' · ' . $formexposed_arten[ $formexposed_pt[3] ]; ?>
					<a class="fx-tl-dot is-<?php echo esc_attr( $formexposed_pt[3] ); ?>" href="<?php echo esc_attr( '#fx-q-' . $formexposed_pt[0] ); ?>" style="<?php echo esc_attr( 'left:' . round( $formexposed_pt[1], 2 ) . '%;bottom:' . ( 6 + $formexposed_pt[2] * 15 ) . 'px' ); ?>" title="<?php echo esc_attr( $formexposed_pt_text ); ?>" aria-label="<?php echo esc_attr( $formexposed_pt_text ); ?>"></a>
				<?php endforeach; ?>
			</div>
			<div class="fx-axis" aria-hidden="true"><span><?php echo esc_html( sprintf( /* translators: %d: number of days */ _n( '%d day ago', '%d days ago', Formexposed_Quarantine::DAYS, 'formexposed' ), Formexposed_Quarantine::DAYS ) ); ?></span><span><?php echo esc_html( sprintf( /* translators: %d: number of days */ _n( '%d day ago', '%d days ago', (int) ( Formexposed_Quarantine::DAYS / 2 ), 'formexposed' ), (int) ( Formexposed_Quarantine::DAYS / 2 ) ) ); ?></span><span><?php esc_html_e( 'now', 'formexposed' ); ?></span></div>
			<ul class="fx-tl-legend">
				<?php foreach ( $formexposed_arten as $formexposed_k => $formexposed_txt ) : ?>
					<li><span class="fx-tl-key is-<?php echo esc_attr( $formexposed_k ); ?>" aria-hidden="true"></span><?php echo esc_html( $formexposed_txt ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div class="fx-q-tools" data-fx-qtools hidden>
			<label class="fx-search">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Search the quarantine', 'formexposed' ); ?></span>
				<input type="search" data-fx-qsearch placeholder="<?php esc_attr_e( 'Search name, e-mail, text', 'formexposed' ); ?>" aria-keyshortcuts="/" autocomplete="off">
				<kbd class="fx-kbd" aria-hidden="true">/</kbd>
			</label>
			<div class="fx-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by reason', 'formexposed' ); ?>">
				<button type="button" class="fx-chip" data-fx-qreason="" aria-pressed="true"><?php esc_html_e( 'All', 'formexposed' ); ?> <span class="fx-chip-n"><?php echo esc_html( number_format_i18n( count( $formexposed_liste ) ) ); ?></span></button>
				<?php foreach ( $formexposed_zaehler as $formexposed_l => $formexposed_n ) : ?>
					<button type="button" class="fx-chip" data-fx-qreason="<?php echo esc_attr( $formexposed_l ); ?>" aria-pressed="false"><?php echo esc_html( $formexposed_l ); ?> <span class="fx-chip-n"><?php echo esc_html( number_format_i18n( $formexposed_n ) ); ?></span></button>
				<?php endforeach; ?>
			</div>
			<p class="fx-q-shown" data-fx-qshown role="status"></p>
		</div>
		<div class="fx-card fx-q-nomatch" data-fx-qnomatch hidden>
			<p class="fx-empty"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( 'No message on this page matches.', 'formexposed' ); ?> <button type="button" class="button button-small" data-fx-qreset><?php esc_html_e( 'Show all', 'formexposed' ); ?></button></p>
		</div>
		<div class="fx-scroll" style="overflow-x:auto"><table class="widefat striped fx-table">
			<thead><tr>
				<th style="width:150px"><?php esc_html_e( 'Received', 'formexposed' ); ?></th>
				<th><?php esc_html_e( 'Form and content', 'formexposed' ); ?></th>
				<th style="width:260px"><?php esc_html_e( 'Why it was stopped', 'formexposed' ); ?></th>
				<th style="width:170px"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'formexposed' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $formexposed_liste as $formexposed_e ) : $formexposed_felder = json_decode( (string) $formexposed_e['fields'], true ); $formexposed_felder = is_array( $formexposed_felder ) ? $formexposed_felder : array(); ?>
				<?php $formexposed_rest = max( 0, Formexposed_Quarantine::DAYS - (int) floor( max( 0, time() - (int) strtotime( $formexposed_e['created_at'] . ' UTC' ) ) / DAY_IN_SECONDS ) ); ?>
				<tr id="<?php echo esc_attr( 'fx-q-' . (int) $formexposed_e['id'] ); ?>" data-fx-qrow data-fx-reason="<?php echo esc_attr( Formexposed_Stats::reason_label( $formexposed_e['reason'] ) ); ?>">
					<td data-label="<?php esc_attr_e( 'Received', 'formexposed' ); ?>"><?php echo esc_html( get_date_from_gmt( $formexposed_e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
						<?php if ( ! $formexposed_e['released_at'] ) : ?>
							<span class="fx-keep<?php echo $formexposed_rest <= 3 ? ' is-soon' : ''; ?>"><span class="fx-keep-bar" aria-hidden="true"><span style="<?php echo esc_attr( 'width:' . round( $formexposed_rest / Formexposed_Quarantine::DAYS * 100 ) . '%' ); ?>"></span></span><?php echo esc_html( $formexposed_rest > 0 ? sprintf( /* translators: %d: number of days until the entry is deleted */ _n( 'Deleted in %d day', 'Deleted in %d days', $formexposed_rest, 'formexposed' ), $formexposed_rest ) : __( 'Deleted with the next cleanup', 'formexposed' ) ); ?></span>
						<?php endif; ?></td>
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
					<td data-label="<?php esc_attr_e( 'Why it was stopped', 'formexposed' ); ?>"><span class="fx-badge <?php echo esc_attr( 0 === strpos( (string) $formexposed_e['reason'], 'fc_' ) ? 'is-warn' : 'is-bad' ); ?>" data-fx-qlabel><?php echo esc_html( Formexposed_Stats::reason_label( $formexposed_e['reason'] ) ); ?></span>
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
