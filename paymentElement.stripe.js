var stripe, stripeElements;

/* Configurable variables. */
var stripeAmount;
var stripeDebug = false;

/* Internal variables and secrets passed between objects and AJAX rquests. */
var stripeClientSecret;
var stripeOrderId;
var stripeCartId;
var stripePaymentId;
var stripePaymentIntent;

/* Payment Element variables. */
var stripePaymentElement;
var stripePaymentElementInitialised = false;
var stripeCreditAccount = false;
var stripeWalletEnabled = false;
var stripePaymentMethodTypes = ['card'];
var stripePaymentMethodOrder = ['card'];
var stripeSelectedMethod = 'card';
var stripePrimaryColour = null;
var page = 'payment';

var stripeBillingDetails = {};
var stripeShippingDetails = {};

function stripeLog(log) {
	if(stripeDebug) {
		if(typeof log === 'string') {
			console.log('Stripe: ' + log);
		} else {
			console.log(log);
		}
	}
}

function stripeShowError(error) {
	var alert = new WebAlert(null, (error !== undefined) ? error : 'An error occurred, please try again.', 'danger');
	alert.show();

	let submitButtons = document.querySelectorAll('button[type="submit"]');
	submitButtons.forEach(function(button) {
		if (button.disabled = true) {
			button.disabled = false;
		}
	});

	let spinner = document.querySelector('#submit .fa-cog.fa-spin');
	if (!spinner.classList.contains('d-none')) {
		spinner.classList.add('d-none');
	}
}

function stripePaymentElementInitialise() {
	if(!stripePaymentElementInitialised) {
		stripePaymentElementInitialised = true;

		const options = {
			mode: 'payment',
			captureMethod: 'manual',
			paymentMethodTypes: Object.values(stripePaymentMethodTypes),
			currency: 'gbp',
			amount: stripeAmount,
			appearance: {
				theme: 'stripe',
				variables: {
					colorPrimary: stripePrimaryColour,
					colorDanger: '#dc3545',
					borderRadius: '6px',
				}
			},
		};

		const paymentOptions = {
			layout: {
				type: 'tabs',
				// radios: false,
				// spacedAccordionItems: true
			},
			defaultValues: {
				billingDetails: stripeBillingDetails
			},
			paymentMethodOrder: Object.values(stripePaymentMethodOrder),
		};

		if (stripeCreditAccount) {
			paymentOptions.layout.defaultCollapsed = true;
		} else {
			paymentOptions.layout.defaultCollapsed = false;
		}

		if (!stripeWalletEnabled) {
			paymentOptions.wallets = {
				applePay: 'never', 
				googlePay: 'never',
			};
		}

		stripeElements = stripe.elements(options);
		stripePaymentElement = stripeElements.create('payment', paymentOptions);
		stripePaymentElement.mount('#payment-element');

		let submitButton = document.querySelector('#submit');

		if (!stripeCreditAccount) {
			submitButton.classList.remove('d-none');
		}

		let billingFormSubmit = document.querySelector('input[name=selectBilling]');

		if (billingFormSubmit) {
			billingFormSubmit.addEventListener('click', function(event) {
				let postCode = document.querySelector('input[name=billingpostcode]').value;
				stripeBillingDetails.address.postal_code = postCode;
				stripePaymentElement.update({defaultValues: {billingDetails: stripeBillingDetails}});
			});
		}
	
		stripePaymentElement.addEventListener('ready', function(event) {
			stripePaymentElement.addEventListener('change', function(event) {
				stripeSelectedMethod = event.value.type;
			});

			setTimeout(() => {
				stripePaymentElement.addEventListener('change', function(event) {
					stripePaymentElement.focus();

					if (stripeCreditAccount && submitButton.classList.contains('d-none')) {
						let creditElement = document.querySelector('#credit-account-payment-method');
						creditElement.style.marginBottom = '20px';
						
						setTimeout(() => {
							submitButton.classList.remove('d-none');
						}, 100);
					}
				});
			}, 1000);
		});
	}
}

function stripeRegister() {
	var params = {
		type: 'paymentElement',
	};

	if (typeof stripeOrderId != 'undefined') {
		params.orderId = stripeOrderId;
	}

	if (typeof stripeCartId != 'undefined') {
		params.cartId = stripeCartId;
	}

	if (typeof stripeInvoiceId != 'undefined') {
		params.invoiceId = stripeInvoiceId;
	}

	var url = '/System/API/Stripe/Register.php';
	url += '?' + $.param(params);

	fetch(url, {
	}).then(async function(response) {
		if(response.status == 200) {
			response.json().then(function(data) {
				stripePaymentId = data.paymentId;
				stripeAmount = data.amount;
				stripeClientSecret = data.clientSecret;
				stripeOrderId = data.orderId;
				stripeCartId = data.cartId;
				stripeShippingDetails = data.shippingDetails;

				stripe.retrievePaymentIntent(stripeClientSecret).then(function(result) {
					if(result.error) {
						// stripePaymentElementResetButton()
						stripeShowError(result.error.message);
					} else {
						stripePaymentIntent = result.paymentIntent;
						stripePaymentElementInitialise();
					}
				});
			});
			
		} else {
			var errors = await response.json();

			return Promise.reject(errors.join('<br>'));
		}
	}).catch(function(error) {
		stripeLog('Error payment intent could not be registered.');

		// stripePaymentElementResetButton();
		stripeShowError(error);
	});
}

function stripeElementSubmit(event = null) {
	stripeElements.submit().then(function(result) {
		if(!result.error) {
			let submitButtons = document.querySelectorAll('button[type="submit"]');
			submitButtons.forEach(function(button) {
				button.disabled = true;
			});

			let spinner = document.querySelector('#submit .fa-cog.fa-spin');
			spinner.classList.remove('d-none');

			let params = {
				elements: stripeElements,
				
				clientSecret: stripeClientSecret,
				redirect: 'if_required',
				confirmParams: {
					return_url: `https://${window.location.hostname}/paymentconfirm?paymentid=${stripePaymentId}&paymentmethod=${stripeSelectedMethod}`
				}
			};

			stripe.confirmPayment(params).then(function(result) {
				if(result.error) {
					console.log(result);
					stripeShowError(result.error.message);
				} else {
					stripeAuthorise(event);
				}
			});
		}
	});
}

function stripeAuthorise(event) {
	if (typeof event == 'undefined' || event == null || event.target.id != 'remove') {
		var url = '/System/API/Stripe/Authorise.php';

		url += '?' + $.param({
			type: 'paymentElement',
			page: page,
			paymentId: stripePaymentId,
			clientSecret: stripeClientSecret,
			paymentMethod: stripeSelectedMethod,
			billingDetails: stripeBillingDetails,
		});

		fetch(url, {
		}).then(async function(response) {
			if(response.status == 200) {
				response.json().then(function(data) {
					redirect('/complete?orderid=' + data.orderId);
				});

			} else {
				stripeShowError();
			}

		}).catch(function(error) {
			stripeLog('Error payment intent could not be authorised.');
			stripeShowError(error);
		});
	}
}

function stripeInitialise() {
	if(!stripe) {
		stripe = Stripe(framework.application.key.stripe);
	}

	stripeRegister();

	document.querySelector('#submit').addEventListener('click', function(event) {
		event.preventDefault();
		stripeElementSubmit(event);
	});
}