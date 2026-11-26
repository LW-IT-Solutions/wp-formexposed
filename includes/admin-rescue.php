<?php
/**
 * formexposed - rescued enquiries (view). Settings -> formexposed -> Rescued enquiries.
 * Expects $msg (array(kind, text) or false) from Formexposed_Core::page().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$formexposed_seite   = max( 1, absint( filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT ) ) );
$formexposed_liste   = Formexposed_Rescue::entries( 50, ( $formexposed_seite - 1 ) * 50 );
$formexposed_zahl    = Formexposed_Rescue::count_waiting();
$formexposed_quellen = array( 'cf7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'gravityforms' => 'Gravity Forms', 'elementor' => 'Elementor' );
$formexposed_bericht = get_option( Formexposed_Rescue::OPT_LAST );
?>
<div class="wrap" style="max-width:980px">
	<h1>formexposed · <?php esc_html_e( 'Rescued enquiries', 'formexposed' ); ?></h1>
	<?php Formexposed_Core::nav( 'rescue' ); ?>
	<p class="description"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'When the e-mail a form sends to you could not be handed over (wrong SMTP login, mail server down, no mail function on the server), the enquiry would be lost. formexposed keeps a copy of that e-mail here for %d days: recipient, subject, text, headers - attached files only by name. "Send again" hands it to WordPress once more; when that works, the copy is removed. Nothing of this leaves your server.', 'formexposed' ), Formexposed_Rescue::DAYS ) ); ?></p>

	<?php if ( is_array( $msg ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'ok' === $msg[0] ? 'success' : 'error' ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde">
		<input type="hidden" name="action" value="formexposed_r_setting">
		<?php wp_nonce_field( 'formexposed_r_setting' ); ?>
		<label><input type="checkbox" name="keep_on" value="1" <?php checked( Formexposed_Rescue::on() ); ?>>
			<strong><?php esc_html_e( 'Keep enquiries whose e-mail failed', 'formexposed' ); ?></strong> - <?php esc_html_e( 'Contact Form 7, WPForms, Gravity Forms and Elementor Pro forms.', 'formexposed' ); ?></label>
		<button class="button button-small" style="margin-left:6px"><?php esc_html_e( 'Save', 'formexposed' ); ?></button>
		<?php if ( Formexposed_Core::linked() ) : ?>
			<br><span style="color:#50575e;font-size:12px"><?php esc_html_e( 'formexposed.com is told at once when a form e-mail fails (at most once an hour; only the kind of error, no content), so you hear about it even without looking here.', 'formexposed' ); ?>
			<?php if ( is_array( $formexposed_bericht ) && ! empty( $formexposed_bericht['at'] ) ) : ?>
				<?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Last report: %s.', 'formexposed' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $formexposed_bericht['at'] ) ) ); ?>
			<?php endif; ?></span>
		<?php endif; ?>
	</form>

	<?php if ( ! $formexposed_liste ) : ?>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0"><p style="margin:0"><?php esc_html_e( 'No enquiry is waiting. Every form e-mail went out.', 'formexposed' ); ?></p></div>
	<?php else : ?>
		<p><?php echo esc_html( sprintf( /* translators: %d: number of entries */ _n( '%d enquiry waiting.', '%d enquiries waiting.', $formexposed_zahl, 'formexposed' ), $formexposed_zahl ) ); ?>
			<a class="button button-primary" style="margin-left:8px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_r_resend&all=1' ), 'formexposed_r_resend' ) ); ?>"><?php esc_html_e( 'Send all again', 'formexposed' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_r_delete&all=1' ), 'formexposed_r_delete' ) ); ?>" data-formexposed-confirm="<?php esc_attr_e( 'Delete all rescued enquiries? They cannot be sent again afterwards.', 'formexposed' ); ?>"><?php esc_html_e( 'Delete all', 'formexposed' ); ?></a></p>
		<p style="color:#50575e;font-size:12px"><?php esc_html_e( 'Fix the mail settings first (an SMTP plugin with a working login, for example) - otherwise "Send again" fails the same way.', 'formexposed' ); ?></p>
		<div style="overflow-x:auto"><table class="widefat striped">
			<thead><tr>
				<th style="width:130px"><?php esc_html_e( 'Received', 'formexposed' ); ?></th>
				<th><?php esc_html_e( 'Form, recipient and content', 'formexposed' ); ?></th>
				<th style="width:220px"><?php esc_html_e( 'Why it failed', 'formexposed' ); ?></th>
				<th style="width:170px"></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $formexposed_liste as $formexposed_e ) :
				$formexposed_an     = Formexposed_Rescue::row_list( $formexposed_e, 'recipient' );
				$formexposed_datei  = Formexposed_Rescue::row_list( $formexposed_e, 'attachments' );
				$formexposed_antw   = Formexposed_Rescue::reply_to( $formexposed_e );
				$formexposed_html   = (bool) preg_match( '/<(p|br|div|table)[\s>\/]/i', (string) $formexposed_e['message'] );
				$formexposed_text   = $formexposed_html ? wp_strip_all_tags( preg_replace( '~<(br|/p|/div|/tr)\s*/?>~i', "\n", (string) $formexposed_e['message'] ) ) : (string) $formexposed_e['message'];
				?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $formexposed_e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $formexposed_e['form'] ); ?></strong> <span style="color:#646970">· <?php echo esc_html( isset( $formexposed_quellen[ $formexposed_e['source'] ] ) ? $formexposed_quellen[ $formexposed_e['source'] ] : $formexposed_e['source'] ); ?></span><br>
						<?php esc_html_e( 'To:', 'formexposed' ); ?> <?php echo esc_html( implode( ', ', $formexposed_an ) ); ?>
						<?php if ( '' !== $formexposed_antw ) : ?><br><?php esc_html_e( 'Reply-To:', 'formexposed' ); ?> <?php echo esc_html( $formexposed_antw ); ?><?php endif; ?>
						<details style="margin-top:4px"><summary><?php echo esc_html( mb_substr( (string) $formexposed_e['subject'], 0, 120 ) ); ?></summary>
							<div style="margin:8px 0 0;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;background:#f6f7f7;padding:8px"><?php echo esc_html( trim( html_entity_decode( $formexposed_text, ENT_QUOTES, 'UTF-8' ) ) ); ?></div>
							<?php if ( $formexposed_datei ) : ?>
								<p style="margin:6px 0 0;color:#996800"><?php echo esc_html( sprintf( /* translators: %s: file names */ __( 'Attached files (not kept - ask the sender again): %s', 'formexposed' ), implode( ', ', $formexposed_datei ) ) ); ?></p>
							<?php endif; ?>
						</details>
					</td>
					<td><?php echo esc_html( ucfirst( Formexposed_Rescue::kind_text( $formexposed_e['error_kind'] ) ) ); ?>
						<?php if ( (int) $formexposed_e['attempts'] > 0 ) : ?>
							<br><span style="color:#646970;font-size:12px"><?php echo esc_html( sprintf( /* translators: %d: number of attempts */ _n( '%d more attempt failed', '%d more attempts failed', (int) $formexposed_e['attempts'], 'formexposed' ), (int) $formexposed_e['attempts'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_r_resend&id=' . (int) $formexposed_e['id'] ), 'formexposed_r_resend' ) ); ?>"><?php esc_html_e( 'Send again', 'formexposed' ); ?></a>
						<a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formexposed_r_delete&id=' . (int) $formexposed_e['id'] ), 'formexposed_r_delete' ) ); ?>"><?php esc_html_e( 'Delete', 'formexposed' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<p>
			<?php if ( $formexposed_seite > 1 ) : ?><a class="button" href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'rescue', 'paged' => $formexposed_seite - 1 ) ) ); ?>">&larr;</a><?php endif; ?>
			<?php if ( 50 === count( $formexposed_liste ) ) : ?><a class="button" href="<?php echo esc_url( Formexposed_Core::page_url( array( 'tab' => 'rescue', 'paged' => $formexposed_seite + 1 ) ) ); ?>">&rarr;</a><?php endif; ?>
		</p>
	<?php endif; ?>
</div>
