<?php
/**
 * formexposed - stored enquiries and storage limit (view). Settings -> formexposed -> Stored enquiries.
 * Expects $msg (array(kind, text) or false) from Formexposed_Core::page().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$formexposed_tage   = Formexposed_Retention::days();
$formexposed_quelle = Formexposed_Retention::sources();
$formexposed_weg    = Formexposed_Retention::deleted_30d();
$formexposed_alt    = $formexposed_tage > 0 ? $formexposed_tage : 365;
?>
<div class="wrap formexposed-wrap">
	<h1>formexposed · <?php esc_html_e( 'Stored enquiries', 'formexposed' ); ?></h1>
	<?php Formexposed_Core::nav( 'storage' ); ?>
	<p class="description"><?php esc_html_e( 'Many form plugins keep every enquiry in the database - names, e-mail addresses, messages - for years. Under the GDPR (Art. 5(1)(e), storage limitation) personal data may only be kept as long as it is needed. Here you see what is stored on this site and can have old enquiries deleted automatically.', 'formexposed' ); ?></p>

	<?php if ( is_array( $msg ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'ok' === $msg[0] ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fx-card <?php echo esc_attr( $formexposed_tage ? 'is-ok' : 'is-warn' ); ?>">
		<input type="hidden" name="action" value="formexposed_retention">
		<?php wp_nonce_field( 'formexposed_retention' ); ?>
		<label for="formexposed-retention"><strong><?php esc_html_e( 'Delete stored enquiries after', 'formexposed' ); ?></strong></label>
		<select id="formexposed-retention" name="retention_days">
			<option value="0" <?php selected( 0, $formexposed_tage ); ?>><?php esc_html_e( 'never (off)', 'formexposed' ); ?></option>
			<?php foreach ( array( 90, 180, 365 ) as $formexposed_d ) : ?>
				<option value="<?php echo (int) $formexposed_d; ?>" <?php selected( $formexposed_d, $formexposed_tage ); ?>><?php echo esc_html( sprintf( /* translators: %d: days */ __( '%d days', 'formexposed' ), $formexposed_d ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button button-primary"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
		<p class="fx-small"><?php esc_html_e( 'Once a day, through the plugins\' own functions: Flamingo (Contact Form 7), Gravity Forms and Ninja Forms. Other plugins are only counted - see the hints below. Deleted entries cannot be restored; make an export first if you need one. Only the number of deleted entries is recorded.', 'formexposed' ); ?></p>
	</form>

	<div class="fx-scroll" style="overflow-x:auto"><table class="widefat striped fx-table">
		<thead><tr>
			<th><?php esc_html_e( 'Where', 'formexposed' ); ?></th>
			<th style="width:90px"><?php esc_html_e( 'Stored', 'formexposed' ); ?></th>
			<th style="width:150px"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Older than %d days', 'formexposed' ), $formexposed_alt ) ); ?></th>
			<th style="width:150px"><?php esc_html_e( 'Deleted automatically', 'formexposed' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $formexposed_quelle as $formexposed_q => $formexposed_z ) : list( $formexposed_name, $formexposed_hint ) = Formexposed_Retention::source_text( $formexposed_q ); ?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Where', 'formexposed' ); ?>"><strong><?php echo esc_html( $formexposed_name ); ?></strong><?php if ( '' !== $formexposed_hint ) : ?><br><span class="fx-small"><?php echo esc_html( $formexposed_hint ); ?></span><?php endif; ?></td>
				<td data-label="<?php esc_attr_e( 'Stored', 'formexposed' ); ?>"><?php echo (int) $formexposed_z['total']; ?></td>
				<td data-label="<?php echo esc_attr( sprintf( /* translators: %d: days */ __( 'Older than %d days', 'formexposed' ), $formexposed_alt ) ); ?>"><?php if ( $formexposed_z['older'] ) : ?><strong class="fx-text-warn"><?php echo absint( $formexposed_z['older'] ); ?></strong><?php else : ?>0<?php endif; ?>
					<?php if ( (int) $formexposed_z['total'] > 0 ) : ?><span class="fx-mini" aria-hidden="true"><span style="<?php echo esc_attr( 'width:' . round( min( 1, (int) $formexposed_z['older'] / (int) $formexposed_z['total'] ) * 100 ) . '%' ); ?>"></span></span><?php endif; ?></td>
				<td data-label="<?php esc_attr_e( 'Deleted automatically', 'formexposed' ); ?>"><?php if ( $formexposed_z['auto'] ) : ?><span class="fx-badge is-ok"><?php esc_html_e( 'yes', 'formexposed' ); ?></span><?php else : ?><span class="fx-badge"><?php esc_html_e( 'no', 'formexposed' ); ?></span><?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<p class="fx-text-muted"><?php echo esc_html( sprintf( /* translators: %d: number of entries */ _n( '%d stored enquiry deleted automatically in the last 30 days (including quarantine and rescued enquiries).', '%d stored enquiries deleted automatically in the last 30 days (including quarantine and rescued enquiries).', $formexposed_weg, 'formexposed' ), $formexposed_weg ) ); ?></p>
</div>
