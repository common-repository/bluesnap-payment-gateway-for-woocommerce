(function( $ ) {
	'use strict';

	var card_thumbs = {
		'AMEX': woocommerce_bluesnap_gateway_general_params.images_url + 'amex.png',
		'DINERS': woocommerce_bluesnap_gateway_general_params.images_url + 'diners.png',
		'DISCOVER': woocommerce_bluesnap_gateway_general_params.images_url + 'discover.png',
		'JCB': woocommerce_bluesnap_gateway_general_params.images_url + 'jcb.png',
		'MaestroUK': woocommerce_bluesnap_gateway_general_params.images_url + 'maestro.png',
		'MASTERCARD': woocommerce_bluesnap_gateway_general_params.images_url + 'mastercard.png',
		'VISA': woocommerce_bluesnap_gateway_general_params.images_url + 'visa.png',
		'Solo': woocommerce_bluesnap_gateway_general_params.images_url + 'solo.png',
		'CarteBleue': woocommerce_bluesnap_gateway_general_params.images_url + 'cb.png',
		'generic' : woocommerce_bluesnap_gateway_general_params.generic_card_url
	};

	// Holds all validation messages from Bluesnap.
	var validation_messages = {};

	var reset_token_fn = debounce(function(){

		// Block early, before the ajax call that resets the token, but not before the debounce routine in submit_error_efficient() runs.
		if ( ! wc_bluesnap_form.is_checkout && wc_bluesnap_form.is_bluesnap_selected() ) {
			setTimeout( function () {
				wc_bluesnap_form.block_form();
			}, 150);
		}

		$.ajax(
			{
				url: getAjaxUrl('reset_hpf'),
				method: "POST",
			}
		).done(function( res ){
			woocommerce_bluesnap_gateway_general_params.token = res;
		}
		).always(function(){
			wc_bluesnap_form.credentials_requested = false;
			$( document.body ).trigger( 'update_checkout' );

			if ( ! wc_bluesnap_form.is_checkout && wc_bluesnap_form.is_bluesnap_selected() ) {
				wc_bluesnap_form.init_bluesnap();
			}
		});
	}, 100);

	var bsObj = {
		onFieldEventHandler: {
			setupComplete: function () {
				wc_bluesnap_form.unblock_form();
			},
			onFocus: function(tagId) {
				$( "[data-bluesnap='" + tagId + "']" ).removeClass('input-div-error input-div-valid');
			},
			onBlur: function(tagId) {
			},
			onError: function(tagId, errorCode, errorDescription) {
				var expiredToken = [ '400', '14040', '14041', '14042' ];
	
				$( "[data-bluesnap='" + tagId + "']" ).addClass('input-div-error').removeClass('input-div-valid');

				var isObject = ( 'object' === typeof( woocommerce_bluesnap_gateway_general_params.errors[errorCode] ) ),
					key = isObject ? errorCode + tagId : errorCode;
				validation_messages[ key ] = {
					tagId: tagId,
					message: isObject ? woocommerce_bluesnap_gateway_general_params.errors[errorCode][tagId] : woocommerce_bluesnap_gateway_general_params.errors[errorCode]
				};

				if ( -1 < expiredToken.indexOf( errorCode ) ) {
					reset_token_fn();
				}

				submit_error_efficient();
			},
			onType: function(tagId, cardType) {
				var card_url = card_thumbs[cardType];
				$( "#card-logo > img" ).attr( "src", (card_url) ? card_url : card_thumbs['generic'] );
			},
			onValid: function(tagId) {
				$( "[data-bluesnap='" + tagId + "']" ).removeClass('input-div-error').addClass('input-div-valid');
				wc_bluesnap_form.get_form().find( '.woocommerce-NoticeGroup-checkout[data-bluesnap-tag="' + tagId + '"]' ).remove();
				$.each(validation_messages, function(errorCode, data) {
					if(data.tagId === tagId) {
						delete validation_messages[errorCode];
					}
				});
			},
		},
		style: {
			".invalid": {
				"color": "red"
			},
			":focus": {
				"color": "inherit"
			}
		},
		ccnPlaceHolder: "•••• •••• •••• ••••",
		cvvPlaceHolder: "CVC",
		expDropDownSelector: false,
		'3DS': is_3d_secure()
	};

	if ( 'undefined' !== typeof( bluesnapStyleOverrides ) ) {
		bsObj.style = bluesnapStyleOverrides;
	}

	// On Checkout form.
	$( document.body ).on(
		'updated_checkout', function(e, data) {
			var varKey = '#bluesnap_relocalized_cart_data';

			wc_bluesnap_form.on_bluesnap_selected( 'updated_checkout' );

			// Update order total in our localized var.
			if ( 'object' === typeof( data ) && data.fragments && data.fragments[varKey] && woocommerce_bluesnap_gateway_general_params ) {

				var cart_data = JSON.parse( data.fragments[varKey] );

				woocommerce_bluesnap_gateway_general_params.total_amount = cart_data['total'];
				woocommerce_bluesnap_gateway_general_params.currency     = cart_data['currency'];
			}
		}
	);

	// On Checkout form error, reset the type so next time updated_checkout is called will re-initialize BS SDK
	$( document.body ).on(
		'checkout_error', function(e, data) {
			wc_bluesnap_form.credentials_requested_type = '';
		}
	);

	// On Add Payment Method form.
	$( 'form#add_payment_method' ).on(
		'click payment_methods', function() {
			if (wc_bluesnap_form.is_bluesnap_selected()) {
				wc_bluesnap_form.init_bluesnap();
			}
		}
	);

	// On Pay for order form.
	$('form#order_review input[type=radio][name=wc-bluesnap-payment-token]').change(function() {
		if (wc_bluesnap_form.is_bluesnap_selected()) {
			wc_bluesnap_form.init_bluesnap('order_review');
		}
	} );

	function debounce(func, wait, immediate) {
		var timeout;
		return function() {
			var context = this, args = arguments;
			var later = function() {
				timeout = null;
				if (!immediate) func.apply(context, args);
			};
			var callNow = immediate && !timeout;
			clearTimeout(timeout);
			timeout = setTimeout(later, wait);
			if (callNow) func.apply(context, args);
		};
	};

	function getAjaxUrl(method) {
		return woocommerce_bluesnap_gateway_general_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'bluesnap_' + method )
	}

	function clear_errors() {
		var errors = wc_bluesnap_form.get_form().find( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' );
		errors = errors.add( wc_bluesnap_form.get_form().closest('.woocommerce').find( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ) );
		var code = {};
		errors.filter("[data-bluesnap-error]").each(function(){
			code[$(this).data('bluesnap-error')] = true;
		})
		errors.remove();
		return code;
	}

	function submit_error() {
		var codes = clear_errors();
		var scroll = false;
		$.each(
			validation_messages, function(errorCode, data) {
				if( typeof codes[errorCode] === "undefined" ) {
					scroll = true;
				}
				wc_bluesnap_form.get_form().prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout" data-bluesnap-error="' + errorCode + '" data-bluesnap-tag="' + data.tagId + '"><div class="woocommerce-error">' + data.message + '</div></div>' );
			}
		);
		wc_bluesnap_form.get_form().removeClass( 'processing' ).unblock();
		wc_bluesnap_form.get_form().find( '.input-text, select, input:checkbox' ).trigger( 'validate' ).blur();
		if( scroll ) {
			scroll_to_notices();
		}
		$( document.body ).trigger( 'checkout_error' );
	}

	var submit_error_efficient = debounce(submit_error, 100);

	function scroll_to_notices() {
		var scroll_element = $( '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout' );
		if ( ! scroll_element.length ) {
			scroll_element = $( '.form.checkout' );
		}
		$.scroll_to_notices( scroll_element );
	}

	/**
	 * Object to handle bluesnap wc forms.
	 */
	var wc_bluesnap_form = {
		input_card_info_id : '#bluesnap_card_info',
		input_3ds_ref_id : '#bluesnap_3ds_reference_id',
		$checkout_form: $( 'form.woocommerce-checkout' ),
		$add_payment_form: $( 'form#add_payment_method' ),
		$order_review_form: $( 'form#order_review' ),
		credentials_requested: false,
		credentials_requested_type: '',
		is_checkout: false,
		is_order_review_form: false,
		form: null,
		submitted: false,
		force_submit: false,
		/**
		 * Interrupts normal checkout flow. Delay submission.
		 */
		init: function() {
			// Checkout Page
			if ( this.$checkout_form.length ) {
				this.form        = this.$checkout_form;
				this.is_checkout = true;
				this.form.on( 'checkout_place_order', this.submit_credentials );
			}

			// Add payment Page
			if ( this.$add_payment_form.length ) {
				this.form                  = this.$add_payment_form;
				this.is_add_payment_method = true;
				this.form.on( 'add_payment_method', this.submit_credentials );
				this.$add_payment_form.on( 'submit', this.add_payment_method_trigger );
			}

			// Pay for order Page (change subs method)
			if ( this.$order_review_form.length ) {
				this.form                 = this.$order_review_form;
				this.is_order_review_form = true;
				this.form.on( 'add_payment_method', this.submit_credentials );
				this.$order_review_form.on( 'submit', this.add_payment_method_trigger );
				if (wc_bluesnap_form.is_bluesnap_selected()) {
					wc_bluesnap_form.init_bluesnap();
				}
			}

			// For returning shoppers
			if ( $( 'input[name="wc-bluesnap-payment-token"]:radio' ).length > 1 ) {
				this.$checkout_form.on( 'click', function(e) {
					var $target = $( e.target );
					if ( ! $target.is( 'input[name="wc-bluesnap-payment-token"]:radio' ) ) {
						return;
					}
					jQuery( document.body ).trigger( 'update_checkout' );
				} );
			}

			// tokenization script initiation for change payment method page and pay for order page.
			if (this.is_add_payment_method || this.is_order_review_form) {
				$( document.body ).trigger( 'wc-credit-card-form-init' );
			}

			$('body').on('click', function(e) {

				var $target = $( e.target );

				if( $target.parents().addBack().filter('.bluesnap-cvc-tooltip-trigger').length ) {
					return;
				}

				var $tooltips = $('.bluesnap-cvc-tooltip'),
					$thisParents = $target.parents( $tooltips );

				$tooltips.not( $thisParents ).removeClass('bs-tooltip-visible');
			});

			if ( $( 'form#order_review' ).length ) {
				// Run on init to handle BS selected as default.
				wc_bluesnap_form.on_bluesnap_selected();
			}
		},
		/**
		 * Checks if input bluesnap is checked.
		 * @returns bool
		 */
		is_bluesnap_selected: function() {
			return $( '#payment_method_bluesnap' ).is( ':checked' );
		},

		is_bluesnap_ach_selected: function() {
			return $( '#payment_method_bluesnap_ach' ).is( ':checked' );
		},

		is_bluesnap_saved_token_selected: function() {
			return ( $( '#payment_method_bluesnap' ).is( ':checked' ) && ( $( 'input[name="wc-bluesnap-payment-token"]' ).is( ':checked' ) && 'new' !== $( 'input[name="wc-bluesnap-payment-token"]:checked' ).val() ) );
		},
		/**
		 * Trigger BlueSnap authentication.
		 * @param event
		 */
		init_bluesnap: function( event ) {

			// Only add attribute first time we show the form
			if (is_3d_secure() && ! wc_bluesnap_form.is_bluesnap_saved_token_selected() && 'submitButton' !== $( "[name='woocommerce_checkout_place_order']" ).attr( 'data-bluesnap' ) ) {
				$( "[name='woocommerce_checkout_place_order']" ).attr( 'data-bluesnap', 'submitButton' );
			}

			if ( ! this.credentials_requested || 'updated_checkout' === event || 'order_review' === event ) {

				this.credentials_requested = true;

				bsObj["token"] = woocommerce_bluesnap_gateway_general_params.token;
				if ( ( 'updated_checkout' === event || 'hpf' !== this.credentials_requested_type ) && ! wc_bluesnap_form.is_bluesnap_saved_token_selected() ) {
					this.credentials_requested_type = 'hpf';
					bluesnap.hostedPaymentFieldsCreate( bsObj );
				}

				if ( 'savedToken' !== this.credentials_requested_type && wc_bluesnap_form.is_bluesnap_saved_token_selected() ) {
					if ( is_3d_secure() ) {
						this.credentials_requested_type = 'savedToken';
						bluesnap.threeDsPaymentsSetup( woocommerce_bluesnap_gateway_general_params.token, function( res ) {
							if ( res.code == 1 && res.cardData && res.threeDSecure && res.threeDSecure.threeDSecureReferenceId ) {
								var threeDSecure = res.threeDSecure;
								wc_bluesnap_form.success_callback( res.cardData, res.threeDSecure.threeDSecureReferenceId );
							} else {
								bsObj.onFieldEventHandler.onError.apply(bsObj.onFieldEventHandler, ['', res.code, res.info.errors[0]]);
							}
						} );
					}
				}
			}

			$('.bluesnap-cvc-tooltip-trigger').not('.bs-tooltip-initalized').on('click', function(e){
				e.preventDefault();
				$(this).next('.bluesnap-cvc-tooltip').toggleClass('bs-tooltip-visible');
			});
		},
		/**
		 * Triggered when BlueSnap is selected from the payment list.
		 */
		on_bluesnap_selected: function( event ) {

			if (wc_bluesnap_form.is_bluesnap_selected() ) {
				if ( wc_bluesnap_form.is_bluesnap_saved_token_selected() ) {
					$( "[name='woocommerce_checkout_place_order']" ).removeAttr( 'data-bluesnap' );
				}

				event = 'undefined' !== typeof( event ) && 'undefined' !== typeof( event.type ) && 'change' === event.type ? 'updated_checkout' : event;
				wc_bluesnap_form.init_bluesnap( event );
			} else {
				// Init on PM method change - if BS selected.
				$( "[name='payment_method']" ).off( 'change', wc_bluesnap_form.on_bluesnap_selected).on( 'change', wc_bluesnap_form.on_bluesnap_selected);

				$( "[name='woocommerce_checkout_place_order']" ).removeAttr( 'data-bluesnap' );
			}
		},

		secure_3d_field: function(id) {
			var field = $( '#' + id );
			if( field.length && $.trim( field.val() ).length ) {
				return field.val();
			} else if (typeof woocommerce_bluesnap_gateway_general_params[id] !== 'undefined' ) {
				return woocommerce_bluesnap_gateway_general_params[id];
			} else {
				return '';
			}
		},
		/**
		 * 3D Secure Object with transaction data.
		 */
		secure_3d_object: function() {
			var threeDSecureObj = {};
			if (is_3d_secure()) {
				threeDSecureObj = {
					'amount' : parseFloat( woocommerce_bluesnap_gateway_general_params.total_amount ),
					'currency' : woocommerce_bluesnap_gateway_general_params.currency,
					'billingFirstName' : wc_bluesnap_form.secure_3d_field( 'billing_first_name' ),
					'billingLastName' : wc_bluesnap_form.secure_3d_field( 'billing_last_name' ),
					'billingCountry' : wc_bluesnap_form.secure_3d_field( 'billing_country' ),
					'billingState' : '',
					'billingCity' : wc_bluesnap_form.secure_3d_field( 'billing_city' ),
					'billingAddress' : $.trim( wc_bluesnap_form.secure_3d_field( 'billing_address_1' ) + ' ' + wc_bluesnap_form.secure_3d_field( 'billing_address_2' ) ),
					'billingZip' : wc_bluesnap_form.secure_3d_field( 'billing_postcode' ),
					'email' : wc_bluesnap_form.secure_3d_field( 'billing_email' ),
				};

				switch( threeDSecureObj.billingCountry ) {
					case 'US':
					case 'CA':
						threeDSecureObj.billingState = wc_bluesnap_form.secure_3d_field( 'billing_state' );
						break;
				}
			}
			return threeDSecureObj;
		},
		/**
		 * Submitting Credentials to BlueSnap.
		 * @returns {boolean}
		 */
		submit_credentials: function(e) {

			if( wc_bluesnap_form.force_submit ) {
				return;
			}

			e.preventDefault();

			if ( wc_bluesnap_form.is_bluesnap_ach_selected() && ( wc_bluesnap_form.is_add_payment_method || wc_bluesnap_form.is_order_review_form ) ) {
				wc_bluesnap_form.submit_form();
			}

			if ( ! wc_bluesnap_form.is_bluesnap_selected() ) {
				return;
			}

			wc_bluesnap_form.block_form();
			clear_errors();

			if ( ! wc_bluesnap_form.is_bluesnap_saved_token_selected() ) {

				bluesnap.hostedPaymentFieldsSubmitData(
					function(res) {
						if ( res.cardData && ( ! is_3d_secure() || ( res.threeDSecure && res.threeDSecure.threeDSecureReferenceId ) ) ) {
							var threeDSecureReferenceId = '';
							if ( is_3d_secure() ) {
								threeDSecureReferenceId = res.threeDSecure.threeDSecureReferenceId;
							}
							wc_bluesnap_form.success_callback( res.cardData, threeDSecureReferenceId );
						} else if( res.error ) {
							var errorArray = res.error;
							for (var i in errorArray) {
								bsObj.onFieldEventHandler.onError.apply(bsObj.onFieldEventHandler, [errorArray[i].tagId, errorArray[i].errorCode, errorArray[i].errorDescription])
							}
						} else {
							wc_bluesnap_form.error_callback();
						}
					}, wc_bluesnap_form.secure_3d_object()
				);

			} else {

				if ( woocommerce_bluesnap_gateway_general_params.stokens ) {

					var selectedToken = $('input[name="wc-bluesnap-payment-token"]:radio:checked').val();
					if ( ! selectedToken ) {
						return false;
					}

					var selectedCard = woocommerce_bluesnap_gateway_general_params.stokens[ selectedToken ];
					if ( ! selectedCard ) {
						return false;
					}

					if ( ! is_3d_secure() ) {
						if ( wc_bluesnap_form.is_order_review_form ) {
							wc_bluesnap_form.submit_form();
						}
						return true;
					}

					var previouslyUsedCard = {
						'last4Digits': selectedCard.last4,
						'ccType': selectedCard.type,
						'amount': parseFloat( woocommerce_bluesnap_gateway_general_params.total_amount ),
						'currency': woocommerce_bluesnap_gateway_general_params.currency
					};
					bluesnap.threeDsPaymentsSubmitData(previouslyUsedCard);
				}
			}

			validation_messages = {};
			return false;
		},
		add_payment_method_trigger: function() {
			if( wc_bluesnap_form.force_submit || ( ! wc_bluesnap_form.is_bluesnap_selected() && ! wc_bluesnap_form.is_bluesnap_ach_selected() ) ) {
				return;
			}

			wc_bluesnap_form.form.trigger( 'add_payment_method' );
			return false;
		},
		/**
		 * Callback on Success to set credentials into checkout form.
		 * @param data
		 */
		success_callback : function( data, threeDSecureReferenceId ) {
			$( this.input_card_info_id ).val( JSON.stringify( data ) );
			if ( ! threeDSecureReferenceId ) {
				threeDSecureReferenceId = '';
			}
			$( this.input_3ds_ref_id ).val( threeDSecureReferenceId );
			wc_bluesnap_form.submit_form();
		},
		/**
		 * Callback on Failure.
		 * Submit formal as normal and reset it.
		 */
		error_callback: function() {
			wc_bluesnap_form.submit_form();
			wc_bluesnap_form.unblock_form();
		},
		/**
		 * Deactivate submit_credentials function event and submit the form again.
		 */
		submit_form: function() {
			wc_bluesnap_form.force_submit = true;
			wc_bluesnap_form.form.submit();
			wc_bluesnap_form.force_submit = false;
		},
		block_form: function() {
			wc_bluesnap_form.form.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},
		unblock_form: function() {
			wc_bluesnap_form.form.unblock();
		},
		/**
		 * Form Element getter.
		 * @returns {*|HTMLElement}
		 */
		get_form: function() {
			return this.form;
		}
	};

	$(
		function() {
				wc_bluesnap_form.init();
		}
	);

	/**
	 * @returns {boolean}
	 */
	function is_3d_secure() {
		return ! ! + woocommerce_bluesnap_gateway_general_params._3d_secure;
	}

	/**
	 * @returns {boolean}
	 */
	function is_sandbox() {
		return ! ! + woocommerce_bluesnap_gateway_general_params.is_sandbox;
	}

})( jQuery );
