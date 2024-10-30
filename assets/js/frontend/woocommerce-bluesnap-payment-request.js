( function ( $ ) {
	var wc_bluesnap_payment_request = {
		type: null, //
		initialized: false,
		session: null,
		source: woocommerce_bluesnap_payment_request_params.request_info_source,
		googlePayParams: {
			baseRequest: {
				apiVersion: 2,
				apiVersionMinor: 0,
			},
			allowedCardNetworks: [
				'AMEX',
				'DISCOVER',
				'INTERAC',
				'JCB',
				'MASTERCARD',
				'MIR',
				'VISA',
			],
			allowedCardAuthMethods: [ 'PAN_ONLY', 'CRYPTOGRAM_3DS' ],
		},

		init: function () {
			if ( wc_bluesnap_payment_request.initialized ) {
				return;
			}

			wc_bluesnap_payment_request.type = wc_bluesnap_payment_request.getSupportedPaymentRequestType();

			if ( ! wc_bluesnap_payment_request.type ) {
				// Device doesn't support any PaymentRequest method
				return;
			}

			var cartOrCheckout = $(
				'form.woocommerce-cart-form, form.checkout, form#order_review'
			);
			if ( ! cartOrCheckout.length ) {
				// not in cart, pay order or checkout
				return;
			}

			wc_bluesnap_payment_request.initialized = true;

			if (
				wc_bluesnap_payment_request.source == 'cart' &&
				0 == woocommerce_bluesnap_payment_request_params.cart_compatible
			) {
				wc_bluesnap_payment_request.showError(
					'<div class="woocommerce-info">' +
						wc_bluesnap_payment_request.getErrorMessage(
							'not_compatible_with_cart'
						) +
						'</div>'
				);
				return;
			}

			if (
				! wc_bluesnap_payment_request.cartCompatibleWithCurrentType()
			) {
				wc_bluesnap_payment_request.showError(
					'<div class="woocommerce-info">' +
						wc_bluesnap_payment_request.getErrorMessage(
							'device_not_compat_with_cart'
						) +
						'</div>'
				);
				return;
			}

			switch ( wc_bluesnap_payment_request.type ) {
				case 'apple_pay':
					wc_bluesnap_payment_request.createButton();

					break;
				case 'google_pay':
					if ( ! $( '#wc-bluesnap-google-pay-wrapper' ).length ) {
						return; // No point going further.
					}

					if (
						$( '#wc-bluesnap-google-pay-wrapper.requires-account' )
							.length
					) {
						$(
							'#wc-bluesnap-google-pay-wrapper, #wc-bluesnap-google-pay-button-separator'
						).show();
						return;
					}

					wc_bluesnap_payment_request.loadJS(
						'https://pay.google.com/gp/p/js/pay.js',
						function () {
							wc_bluesnap_payment_request.createButton();

							$( document.body ).on(
								'updated_cart_totals',
								function () {
									wc_bluesnap_payment_request.createButton();
								}
							);
						}
					);

					break;
			}
		},

		loadJS: function ( src, callback ) {
			var script = document.createElement( 'script' );

			script.onload = function () {
				callback();
			};

			script.src = src;
			document.head.appendChild( script );
		},

		createButton: function () {
			switch ( wc_bluesnap_payment_request.type ) {
				case 'apple_pay':
					if ( ApplePaySession.canMakePayments() ) {
						$( '<style></style>' )
							.attr( 'id', 'wc-bluesnap-apple-pay-css' )
							.html(
								'#wc-bluesnap-apple-pay-wrapper, #wc-bluesnap-apple-pay-button-separator { display: block !important; }'
							)
							.appendTo( 'body' );

						$( document ).on(
							'click',
							'#wc-bluesnap-apple-pay-wrapper a.apple-pay-button',
							wc_bluesnap_payment_request.applePayClicked
						);
					} else {
						wc_bluesnap_payment_request.showError(
							'<div class="woocommerce-info">' +
								wc_bluesnap_payment_request.getErrorMessage(
									'not_able_to_make_payments'
								) +
								'</div>'
						);
					}

					break;
				case 'google_pay':
					const paymentsClient = wc_bluesnap_payment_request.googlePayGetPaymentsClient();

					const isReadyToPayRequest = Object.assign(
						{},
						wc_bluesnap_payment_request.googlePayParams.baseRequest,
						{
							allowedPaymentMethods: [
								wc_bluesnap_payment_request.googlePayGetBaseCardPaymentMethod(),
							],
						}
					);

					paymentsClient
						.isReadyToPay( isReadyToPayRequest )
						.then( function ( response ) {
							if ( response.result ) {
								$(
									'#wc-bluesnap-google-pay-wrapper, #wc-bluesnap-google-pay-button-separator'
								).show();

								wc_bluesnap_payment_request.googlePayCreateButton(
									paymentsClient
								);
							}
						} )
						.catch( function ( err ) {
							// show error in developer console for debugging
							console.error( err );
						} );

					break;
			}
		},

		googlePayGetCardPaymentMethod: function () {
			const tokenizationSpecification = {
				type: 'PAYMENT_GATEWAY',
				parameters: {
					gateway: 'bluesnap',
					gatewayMerchantId:
						woocommerce_bluesnap_payment_request_params.merchant_id,
				},
			};

			return Object.assign(
				{},
				wc_bluesnap_payment_request.googlePayGetBaseCardPaymentMethod(),
				{
					tokenizationSpecification: tokenizationSpecification,
				}
			);
		},

		googlePayGetBaseCardPaymentMethod: function () {
			return {
				type: 'CARD',
				parameters: {
					allowedAuthMethods:
						wc_bluesnap_payment_request.googlePayParams
							.allowedCardAuthMethods,
					allowedCardNetworks:
						wc_bluesnap_payment_request.googlePayParams
							.allowedCardNetworks,
				},
			};
		},

		googlePayInit: function ( paymentDataRequest ) {
			const paymentsClientRequest = {
				environment: woocommerce_bluesnap_payment_request_params.test_mode
					? 'TEST'
					: 'PRODUCTION',
				merchantInfo: {
					merchantName:
						woocommerce_bluesnap_payment_request_params.merchant_soft_descriptor,
					merchantId:
						woocommerce_bluesnap_payment_request_params.google_pay_merchant_id,
				},
				paymentDataCallbacks: {
					onPaymentAuthorized:
						wc_bluesnap_payment_request.googlePayPaymentAuthorized,
				},
			};

			if (
				'undefined' !== typeof paymentDataRequest &&
				paymentDataRequest.shippingAddressRequired
			) {
				Object.assign( paymentsClientRequest.paymentDataCallbacks, {
					onPaymentDataChanged:
						wc_bluesnap_payment_request.googlePayOnPaymentDataChanged,
				} );
			}

			return new google.payments.api.PaymentsClient(
				paymentsClientRequest
			);
		},

		/**
		 * Handles dynamic buy flow shipping address and shipping options callback intents.
		 *
		 * @param {object} itermediatePaymentData response from Google Pay API a shipping address or shipping option is selected in the payment sheet.
		 * @see {@link https://developers.google.com/pay/api/web/reference/response-objects#IntermediatePaymentData|IntermediatePaymentData object reference}
		 *
		 * @see {@link https://developers.google.com/pay/api/web/reference/response-objects#PaymentDataRequestUpdate|PaymentDataRequestUpdate}
		 * @returns Promise<{object}> Promise of PaymentDataRequestUpdate object to update the payment sheet.
		 */
		googlePayOnPaymentDataChanged: function ( intermediatePaymentData ) {
			return new Promise( function ( resolve, reject ) {
				let shippingAddress = intermediatePaymentData.shippingAddress;
				let shippingOptionData =
					intermediatePaymentData.shippingOptionData;
				let paymentDataRequestUpdate = {};
				let selectedShippingOptionId = null;

				if (
					'SHIPPING_OPTION' ===
					intermediatePaymentData.callbackTrigger
				) {
					selectedShippingOptionId = shippingOptionData.id;
				}

				try {
					paymentDataRequestUpdate.newTransactionInfo = wc_bluesnap_payment_request.googlePayCalculateNewTransactionInfo(
						shippingAddress,
						selectedShippingOptionId
					);

					paymentDataRequestUpdate.newShippingOptionParameters = wc_bluesnap_payment_request.transformShippingForGpay(
						paymentDataRequestUpdate.newTransactionInfo
							.shippingMethods
					);

					delete paymentDataRequestUpdate
						.newTransactionInfo.shippingMethods; // This is already in newShippingOptionParameters and leaving it here throws an error.

					resolve( paymentDataRequestUpdate );
				} catch ( error ) {
					if ( 'object' !== typeof error || ! error.intent ) {
						error = {
							reason: 'OTHER_ERROR',
							message:
								'Could not get shipping information from the server',
							intent: 'SHIPPING_ADDRESS',
						};
					}

					// GooglePay expects a sligtly different error code in this case.
					error.reason =
						error.reason &&
						error.reason === 'invalid_shipping_address'
							? 'SHIPPING_ADDRESS_INVALID'
							: error.reason;

					reject( error );
				}
			} );
		},

		transformShippingForGpay: function ( shippingMethods ) {
			if ( ! shippingMethods || 0 === shippingMethods.length ) {
				return {};
			}

			shippingOptions = [];

			shippingMethods.forEach( ( shippingMethod ) => {
				shippingOptions.push( {
					id: shippingMethod.identifier,
					label:
						shippingMethod.label +
						'(' +
						shippingMethod.amount +
						')',
					description: shippingMethod.detail,
				} );
			} );

			return {
				defaultSelectedOptionId: shippingOptions[ 0 ].id,
				shippingOptions: shippingOptions,
			};
		},

		googlePayCalculateNewTransactionInfo: function (
			shippingAddress,
			shippingOptionId
		) {
			if ( null !== shippingOptionId ) {
				wc_bluesnap_payment_request.updateShippingMethod( {
					identifier: shippingOptionId,
				} );
			}

			return wc_bluesnap_payment_request.getShippingOptions(
				shippingAddress
			);
		},

		googlePayGetPaymentsClient: function ( paymentDataRequest ) {
			/**
			 * The output of this method is intentionally not cached
			 * as paymentDataCallbacks may need to change depending on
			 * the current cart's content (requires shipping or not, etc).
			 */
			return wc_bluesnap_payment_request.googlePayInit(
				paymentDataRequest
			);
		},

		googlePayCreateButton: function ( paymentsClient ) {
			const button = paymentsClient.createButton( {
				onClick: wc_bluesnap_payment_request.googlePayClicked,
			} );

			document
				.getElementById( 'wc-bluesnap-google-pay-button-cont' )
				.appendChild( button );
		},

		getSupportedPaymentRequestType: function () {
			if (
				'undefined' !== typeof window.ApplePaySession &&
				woocommerce_bluesnap_payment_request_params.apple_pay_enabled
			) {
				return 'apple_pay';
			}

			if (
				'undefined' !== typeof window.PaymentRequest &&
				woocommerce_bluesnap_payment_request_params.google_pay_enabled
			) {
				/**
				 * Note that:
				 * PaymentRequest is not GooglePay, but its unlikely that a browser that supports GooglePay doesn't support PaymentRequest.
				 * Any edge case where a browser does support PaymentRequest but doesn't support GooglePay will be handled by
				 * paymentsClient.isReadyToPay().
				 */
				return 'google_pay';
			}

			return false;
		},

		cartCompatibleWithCurrentType: function () {
			switch ( wc_bluesnap_payment_request.type ) {
				case 'apple_pay':
					return (
						window.ApplePaySession !== undefined &&
						ApplePaySession.supportsVersion(
							parseInt(
								woocommerce_bluesnap_payment_request_params
									.version_required[
									wc_bluesnap_payment_request.type
								]
							)
						)
					);
				case 'google_pay':
					return true;
				default:
					return false;
			}
		},

		googlePayClicked: function ( e ) {
			e.preventDefault();

			const paymentDataRequest = wc_bluesnap_payment_request.googlePayGetPaymentDataRequest();
			const paymentsClient = wc_bluesnap_payment_request.googlePayGetPaymentsClient(
				paymentDataRequest
			);
			paymentsClient.loadPaymentData( paymentDataRequest ).catch( function ( err ) {
				// Catch exiting payment sheet without proceeding.
			});
		},

		googlePayGetPaymentDataRequest: function () {
			var request = wc_bluesnap_payment_request.getRequestData();

			let paymentDataRequest = Object.assign(
				{},
				wc_bluesnap_payment_request.googlePayParams.baseRequest
			);

			const cardPaymentMethod = wc_bluesnap_payment_request.googlePayGetCardPaymentMethod();

			cardPaymentMethod.parameters = Object.assign(
				cardPaymentMethod.parameters,
				request.billingAddressInfo
			);

			paymentDataRequest.allowedPaymentMethods = [ cardPaymentMethod ];
			paymentDataRequest.transactionInfo = request.transactionInfo;
			paymentDataRequest.merchantInfo = {
				merchantId:
					woocommerce_bluesnap_payment_request_params.google_pay_merchant_id,
				merchantName:
					woocommerce_bluesnap_payment_request_params.merchant_soft_descriptor,
			};

			paymentDataRequest = Object.assign(
				paymentDataRequest,
				request.shippingAddressInfo
			);

			paymentDataRequest.callbackIntents = [ 'PAYMENT_AUTHORIZATION' ];

			paymentDataRequest.shippingOptionRequired =
				paymentDataRequest.shippingAddressRequired;

			if ( paymentDataRequest.shippingOptionRequired ) {
				paymentDataRequest.callbackIntents = paymentDataRequest.callbackIntents.concat(
					[ 'SHIPPING_ADDRESS', 'SHIPPING_OPTION' ]
				);
			}

			paymentDataRequest.emailRequired = true;

			return paymentDataRequest;
		},

		applePayClicked: function ( e ) {
			e.preventDefault();

			if ( wc_bluesnap_payment_request.session !== null ) {
				return;
			}

			wc_bluesnap_payment_request.blockForm();

			var request = wc_bluesnap_payment_request.getRequestData();

			if ( false !== request ) {
				try {
					var apple_session = ( wc_bluesnap_payment_request.session = new ApplePaySession(
						woocommerce_bluesnap_payment_request_params.version_required[
							wc_bluesnap_payment_request.type
						],
						request
					) );
				} catch ( error ) {
					wc_bluesnap_payment_request.showError(
						'<div class="woocommerce-error">' +
							wc_bluesnap_payment_request.getErrorMessage(
								'device_not_compat_with_cart'
							) +
							'</div>'
					);
					$( '#wc-bluesnap-apple-pay-css' ).html(
						'#wc-bluesnap-apple-pay-wrapper, #wc-bluesnap-apple-pay-button-separator { display: none !important; }'
					);
					wc_bluesnap_payment_request.unblockForm();
					return;
				}

				apple_session.onvalidatemerchant =
					wc_bluesnap_payment_request.applePayValidateMerchant;

				apple_session.onshippingcontactselected =
					wc_bluesnap_payment_request.applePayUpdateShippingInfo;

				apple_session.onshippingmethodselected =
					wc_bluesnap_payment_request.applePayUpdateShippingMethod;

				apple_session.onpaymentauthorized =
					wc_bluesnap_payment_request.applePayPaymentAuthorized;

				apple_session.oncancel =
					wc_bluesnap_payment_request.handleCancel;

				apple_session.begin();
			} else {
				wc_bluesnap_payment_request.unblockForm();
				alert(
					'Apple Pay could not be initialized. Try an alternate form of payment'
				);
			}
		},

		applePayValidateMerchant: function ( event ) {
			$.ajax( {
				url: wc_bluesnap_payment_request.getAjaxUrl(
					'create_apple_wallet'
				),
				method: 'POST',
				dataType: 'json',
				data: {
					security:
						woocommerce_bluesnap_payment_request_params.nonces
							.create_apple_wallet,
					validation_url: event.validationURL,
					payment_request_type: wc_bluesnap_payment_request.type,
					payment_request_source: wc_bluesnap_payment_request.source,
				},
			} ).then( function ( res ) {
				if ( ! res.success ) {
					wc_bluesnap_payment_request.handleError( res );
					return;
				}
				var decoded_token = window.atob( res.data.walletToken );
				wc_bluesnap_payment_request.session.completeMerchantValidation(
					JSON.parse( decoded_token )
				);
			}, wc_bluesnap_payment_request.handleError );
		},

		getShippingOptions: function ( address, callback ) {
			var ret = false;

			$.ajax( {
				url: wc_bluesnap_payment_request.getAjaxUrl(
					'get_shipping_options'
				),
				method: 'POST',
				dataType: 'json',
				async: 'apple_pay' === wc_bluesnap_payment_request.type,
				data: {
					security:
						woocommerce_bluesnap_payment_request_params.nonces
							.get_shipping_options,
					address: window.btoa( unescape( encodeURIComponent( ( JSON.stringify( address ) ) ) ) ),
					payment_request_type: wc_bluesnap_payment_request.type,
					payment_request_source: wc_bluesnap_payment_request.source,
				},
			} )
				.done(
					callback ||
						function ( res ) {
							if ( res.success ) {
								ret = res.data;
							} else if ( res.data && res.data.errorCode ) {
								throw {
									reason: res.data.errorCode,
									message: res.data.message || '',
									intent: 'SHIPPING_ADDRESS',
								};
							}
						}
				)
				.fail( wc_bluesnap_payment_request.handleError );

			return ret;
		},

		applePayUpdateShippingInfo: function ( event ) {
			var address = event.shippingContact;

			wc_bluesnap_payment_request.getShippingOptions(
				address,
				function ( res ) {
					var data = res.data;
					if ( ! res.success ) {
						var err = new ApplePayError(
							'shippingContactInvalid',
							'postalAddress',
							data.message
						);
						wc_bluesnap_payment_request.session.completeShippingContactSelection(
							{
								status: ApplePaySession.STATUS_FAILURE,
								errors: [ err ],
								newShippingMethods: [],
								newLineItems: data.lineItems,
								newTotal: data.total,
							}
						);
						return;
					}

					wc_bluesnap_payment_request.session.completeShippingContactSelection(
						{
							status: ApplePaySession.STATUS_SUCCESS,
							newShippingMethods: data.shippingMethods,
							newLineItems: data.lineItems,
							newTotal: data.total,
						}
					);
				}
			);
		},

		applePayUpdateShippingMethod: function ( event ) {
			var method = event.shippingMethod;

			wc_bluesnap_payment_request.updateShippingMethod(
				method,
				function ( res ) {
					var data = res.data;
					if ( ! res.success ) {
						wc_bluesnap_payment_request.session.completeShippingMethodSelection(
							{
								status: ApplePaySession.STATUS_FAILURE,
								newLineItems: data.lineItems,
								newTotal: data.total,
							}
						);
						return;
					}

					wc_bluesnap_payment_request.session.completeShippingMethodSelection(
						{
							status: ApplePaySession.STATUS_SUCCESS,
							newLineItems: data.lineItems,
							newTotal: data.total,
						}
					);
				}
			);
		},

		updateShippingMethod: function ( method, callback ) {
			$.ajax( {
				url: wc_bluesnap_payment_request.getAjaxUrl(
					'update_shipping_method'
				),
				method: 'POST',
				dataType: 'json',
				async: 'apple_pay' === wc_bluesnap_payment_request.type,
				data: {
					security:
						woocommerce_bluesnap_payment_request_params.nonces
							.update_shipping_method,
					method: [ method.identifier ],
					payment_request_type: wc_bluesnap_payment_request.type,
					payment_request_source: wc_bluesnap_payment_request.source,
				},
			} )
				.done( callback )
				.fail( wc_bluesnap_payment_request.handleError );
		},

		googlePayPaymentAuthorized: function ( data ) {
			return new Promise( function ( resolve, reject ) {
				wc_bluesnap_payment_request.paymentRequestPaymentAuthorized(
					{
						payment: data,
					},
					function ( res ) {
						res.resolveFn = resolve;

						try {
							if (
								typeof res.success !== 'undefined' &&
								false === res.success
							) {
								wc_bluesnap_payment_request.handleError( res );
								return;
							}
							if ( typeof res.result !== 'undefined' ) {
								wc_bluesnap_payment_request.handleWCResponse(
									res
								);
								return;
							}
						} catch ( error ) {
							doReject( error );
						}
					},
					function ( res ) {
						res.resolveFn = resolve;

						try {
							wc_bluesnap_payment_request.handleError( res );
						} catch ( error ) {
							doReject( error );
						}
					}
				);

				function doReject( error ) {
					let errorMessage;

					errorMessage =
						error && error.data && 'string' === typeof error.data
							? error.data
							: wc_bluesnap_payment_request.getErrorMessage(
									'checkout_error'
							  );

					reject( {
						intent: 'PAYMENT_AUTHORIZATION',
						message: errorMessage,
						reason: 'PAYMENT_DATA_INVALID',
					} );
				}
			} );
		},

		applePayPaymentAuthorized: function ( data ) {
			wc_bluesnap_payment_request.paymentRequestPaymentAuthorized(
				data,
				function ( res ) {
					if (
						typeof res.success !== 'undefined' &&
						false === res.success
					) {
						wc_bluesnap_payment_request.handleError( res );
						return;
					}
					if ( typeof res.result !== 'undefined' ) {
						wc_bluesnap_payment_request.handleWCResponse( res );
						return;
					}
				},
				wc_bluesnap_payment_request.handleError
			);
		},

		paymentRequestPaymentAuthorized: function (
			event,
			onSuccess,
			onError
		) {
			var paymentToken = event.payment;

			$.ajax( {
				url: wc_bluesnap_payment_request.getAjaxUrl(
					'create_pmr_payment'
				),
				method: 'POST',
				dataType: 'json',
				data: {
					_wpnonce:
						woocommerce_bluesnap_payment_request_params.nonces
							.checkout,
					payment_token: btoa(
						encodeURIComponent(
							JSON.stringify( paymentToken )
						).replace(
							/%([0-9A-F]{2})/g,
							function toSolidBytes( match, p1 ) {
								return String.fromCharCode( '0x' + p1 );
							}
						)
					),
					payment_request_type: wc_bluesnap_payment_request.type,
					payment_request_source: wc_bluesnap_payment_request.source,
					is_change_payment_method: woocommerce_bluesnap_payment_request_params.change_payment_page,
				},
			} ).then( onSuccess, onError );
		},

		getAjaxUrl: function ( method ) {
			return woocommerce_bluesnap_payment_request_params.wc_ajax_url
				.toString()
				.replace( '%%endpoint%%', 'bluesnap_' + method );
		},

		getRequestData: function () {
			var ret = false;
			$.ajax( {
				url: wc_bluesnap_payment_request.getAjaxUrl(
					'get_payment_request'
				),
				method: 'POST',
				dataType: 'json',
				data: {
					security:
						woocommerce_bluesnap_payment_request_params.nonces
							.get_payment_request,
					payment_request_type: wc_bluesnap_payment_request.type,
					payment_request_source: wc_bluesnap_payment_request.source,
					is_change_payment_method:
						woocommerce_bluesnap_payment_request_params.change_payment_page,
				},
				async: false,
			} )
				.done( function ( res ) {
					if ( res.success ) {
						ret = res.data;
					}
				} )
				.fail( function () {} );

			return ret;
		},

		handleError: function ( error ) {
			switch ( wc_bluesnap_payment_request.type ) {
				case 'apple_pay':
					wc_bluesnap_payment_request.session.completePayment(
						ApplePaySession.STATUS_FAILURE
					);
					break;
				case 'google_pay':
					throw error;
					break;
			}

			wc_bluesnap_payment_request.session = null;
			wc_bluesnap_payment_request.unblockForm();
			$( document.body ).trigger( 'update_checkout' );
		},

		handleSuccess: function ( result ) {
			switch ( wc_bluesnap_payment_request.type ) {
				case 'apple_pay':
					wc_bluesnap_payment_request.session.completePayment(
						ApplePaySession.STATUS_SUCCESS
					);
					break;
				case 'google_pay':
					if ( result && 'function' === typeof result.resolveFn ) {
						result.resolveFn( {
							transactionState: 'SUCCESS',
						} );
					}

					break;
			}
			wc_bluesnap_payment_request.session = null;
		},

		handleCancel: function () {
			switch ( wc_bluesnap_payment_request.type ) {
				case 'apple_pay':
					break;
			}
			wc_bluesnap_payment_request.session = null;
			wc_bluesnap_payment_request.unblockForm();
			$( document.body ).trigger( 'update_checkout' );
		},

		handleWCResponse: function ( result ) {
			try {
				if ( 'success' === result.result ) {
					wc_bluesnap_payment_request.handleSuccess( result );

					if (
						-1 === result.redirect.indexOf( 'https://' ) ||
						-1 === result.redirect.indexOf( 'http://' )
					) {
						window.location = result.redirect;
					} else {
						window.location = decodeURI( result.redirect );
					}
				} else if ( 'failure' === result.result ) {
					throw 'Result failure';
				} else {
					throw 'Invalid response';
				}
			} catch ( err ) {
				// Reload page
				if ( true === result.reload ) {
					window.location.reload();
					return;
				}

				// Trigger update in case we need a fresh nonce
				if ( true === result.refresh ) {
					$( document.body ).trigger( 'update_checkout' );
				}

				// Add new errors
				if ( result.messages ) {
					wc_bluesnap_payment_request.showError( result.messages );
				} else {
					wc_bluesnap_payment_request.showError(
						'<div class="woocommerce-error">' +
							wc_bluesnap_payment_request.getErrorMessage(
								'checkout_error'
							) +
							'</div>'
					);
				}

				if ( ! 'google_pay' === wc_bluesnap_payment_request.type ) {
					return;
				}

				if ( result && 'function' === typeof result.resolveFn ) {
					let errorMessage = result.messages
						? parseWCErrors( result.messages )
						: err;

					result.resolveFn( {
						transactionState: 'ERROR',
						error: {
							intent: 'PAYMENT_AUTHORIZATION',
							message: errorMessage,
							reason: 'PAYMENT_DATA_INVALID',
						},
					} );
				} else {
					throw err;
				}
			}

			function parseWCErrors( html ) {
				var div = document.createElement( 'div' );
				div.innerHTML = html;
				var text = div.textContent || div.innerText || '';

				return text.trim();
			}
		},

		getForm: function () {
			var form = $( 'form.woocommerce-checkout' );
			if ( ! form.length ) {
				form = $( 'form#add_payment_method' );
			}
			if ( ! form.length ) {
				form = $( 'form#order_review' );
			}
			if ( ! form.length ) {
				form = $( 'form.woocommerce-cart-form' );
				if ( form.length ) {
					form = form.closest( '.woocommerce' );
				}
			}
			return form;
		},

		blockForm: function () {
			wc_bluesnap_payment_request
				.getForm()
				.addClass( 'processing' )
				.block( {
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6,
					},
				} );
		},

		unblockForm: function () {
			wc_bluesnap_payment_request
				.getForm()
				.removeClass( 'processing' )
				.unblock();
		},

		showError: function ( error_message ) {
			var form = wc_bluesnap_payment_request.getForm();
			if ( ! form.length ) {
				console.error( error_message );
				return;
			}

			$(
				'.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message'
			).remove();
			form.prepend(
				'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
					error_message +
					'</div>'
			);
			wc_bluesnap_payment_request.unblockForm();

			var scroll_element = $(
				'.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout'
			);
			if ( ! scroll_element.length ) {
				scroll_element = $( form );
			}
			$.scroll_to_notices( scroll_element );

			$( document.body ).trigger( 'checkout_error' );
		},

		getErrorMessage: function ( errorCode ) {
			let errorMessage =
				( woocommerce_bluesnap_payment_request_params.i18n[
					wc_bluesnap_payment_request.type
				]
					? woocommerce_bluesnap_payment_request_params.i18n[
							wc_bluesnap_payment_request.type
					  ][ errorCode ]
					: false ) ||
				woocommerce_bluesnap_payment_request_params.i18n[ errorCode ] ||
				null;

			if ( ! errorMessage ) {
				errorMessage =
					'An unknown error prevented the payment method from loading (' +
					wc_bluesnap_payment_request.type +
					':' +
					errorCode +
					')';
			}

			return errorMessage;
		},
	};

	// On Checkout form.
	$( document.body ).on( 'updated_checkout', function () {
		wc_bluesnap_payment_request.init();
	} );

	$( wc_bluesnap_payment_request.init ); // on ready
} )( jQuery );

//# sourceMappingURL=../../source/_maps/js/frontend/woocommerce-bluesnap-payment-request.js.map
