( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.notice-dismiss', function ( e ) {
		var noticeID = $( this ).closest( '.notice' ).data( 'dismissible' );
		if ( ! noticeID ) {
			return;
		}

		$.ajax( {
			url: woocommerce_bluesnap_gateway_admin_params.ajax_url,
			data: {
				security:
					woocommerce_bluesnap_gateway_admin_params.nonces
						.dismiss_admin_notice,
				action: 'bluesnap_dismiss_admin_notice',
				notice_id: noticeID,
			},
		} );
	} );

	$( document ).on(
		'click',
		'.bluesnap-review-prompt-dismiss',
		function ( e ) {
			e.preventDefault();

			$.ajax( {
				url: woocommerce_bluesnap_gateway_admin_params.ajax_url,
				data: {
					security:
						woocommerce_bluesnap_gateway_admin_params.nonces
							.dismiss_prompt_review,
					action: 'bluesnap_dismiss_review_prompt',
				},
				success: function () {
					$( '.bluesnap-review-prompt' ).remove();
				}
			} );
		}
	);
} )( jQuery );
