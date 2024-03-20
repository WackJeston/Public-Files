<?php
class StripePaymentProvider extends PaymentProvider {
	private const ERROR_STATUS = 'error';

	private $errorObject;

	public function __construct() {
		parent::__construct();

		\Stripe\Stripe::setApiKey($_SERVER['STRIPE_SECRET_KEY']);

		\Stripe\ApplePayDomain::create([
			'domain_name' => Application::getWebsite()->getValue('domain'),
		]);
	}

	public function getStripeCustomer(array $dataArray) {
		$contact = new ContactDataObject();

		if (!empty($dataArray['contact']['ID']) && $contact->select($dataArray['contact']['ID'])) {
			if (!empty($dataArray['contact']['stripeId'])) {
				try {
					$customer = \Stripe\Customer::retrieve($dataArray['contact']['stripeId']);

				} catch (Exception $e) {
					$this->errorObject = $e->getError();
				
				} finally {
					if ($customer['deleted'] == true) {
						$contact->setValue('stripeId', null);
						$contact->save();

						$customer = $this->createStripeCustomer($dataArray);

					} else {
						$customer = $this->createStripeCustomer($dataArray, $dataArray['contact']['stripeId']);
					}
				}
			
			} else {
				$customer = $this->createStripeCustomer($dataArray);
			}

			return $customer;
		}

		return false;
	}

	public function createStripeCustomer(array $dataArray, string $stripeId = null) {
		try {
			$this->customerRequest = [
				'name' => sprintf('%s %s', $dataArray['person']['Name'], $dataArray['person']['LastName']),
				'email' => $dataArray['person']['Email'],
				'phone' => $dataArray['person']['Phone1'],
				'metadata' => [
					'ignition_contactId' => $dataArray['contact']['ID'],
				],
			];

			if (!empty($dataArray['billingAddress'])) {
				$this->customerRequest['address'] = [
					'city' => $dataArray['billingAddress']['city'],
					'country' => $dataArray['billingAddress']['country'],
					'line1' => $dataArray['billingAddress']['addressLine1'],
					'line2' => $dataArray['billingAddress']['addressLine2'],
					'postal_code' => $dataArray['billingAddress']['zip'],
					'state' => $dataArray['billingAddress']['region'],
				];
			}

			if (is_null($stripeId)) {
				$customer = \Stripe\Customer::create($this->customerRequest);
			} else {
				$customer = \Stripe\Customer::update($stripeId, $this->customerRequest);
			}

		} catch (Exception $e) {
			$this->errorObject = $e->getError();

		} finally {
			if (is_null($stripeId)) {
				$contact = new ContactDataObject();

				if ($contact->select($dataArray['contact']['ID'])) {
					$contact->setValue('stripeId', $customer['id']);
					$contact->save();
				}
			}

			return $customer;
		}
	}

	public function getPaymentMethods(string $customerId) {
		if (!is_null($customerId)) {
			$this->request = [
				'type' => 'card',
			];
	
			try {
				$result = \Stripe\Customer::allPaymentMethods($customerId, $this->request);
	
			} catch(Exception $e) {
				$this->errorObject = $e->getError();
	
			} finally {
				return $result;
			}

		} else {
			return false;
		}
	}

	public function removePaymentMethod(string $paymentMethodId) {
		if (!is_null($paymentMethodId)) {
			try {
				$paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
	
			} catch(Exception $e) {
				$this->errorObject = $e->getError();

			} finally {
				$result = $paymentMethod->detach();

				return $result;
			}

		} else {
			return false;
		}
	}

	/**
	 * Registers a new Payment Intent with this payment provider for a particular Order.
	 * 
	 *
	 * @param OrdersDataObject $order The DataObject representing the Order to register a Payment Intent for.
	 * @param string|null $paymentMethodId An optional Payment Method ID for MOTO transactions.
	 * @param string|null $returnUrl The URL to return the user to after using a Payment Method that take the user to an external address.
	 * @return bool Whether the registration of a Payment Intent succedded.
	 */
	public function register(array $dataArray, ?string $paymentMethodId = null, ?string $returnUrl = null): bool {
		$transactionType = PaymentCommon::TYPE_REGISTER;
		$requestAmount = round($dataArray['total']*100);

		$this->request = [
			'amount' => $requestAmount,
			'currency' => 'gbp',
			'capture_method' => 'automatic',
			// 'capture_method' => 'manual',
			'automatic_payment_methods' => ['enabled' => false],
			'metadata' => [
				'order_id' => $dataArray['orderId'],
				'invoice_id' => $dataArray['invoiceId'],
			],
		];

		if ($customer = $this->getStripeCustomer($dataArray)) {
			$this->request['customer'] = $customer['id'];
		}

		if (!empty($dataArray['orderId'])) {
			$existingPayment = DataRecord::fetchRecord('SELECT
				Payment_ID AS id,
				Transaction_Type AS type
				FROM payment
				WHERE Order_ID = ?
				ORDER BY Payment_ID DESC
				LIMIT 1', 
				[
					$dataArray['orderId'],
				]
			);
		
		} elseif (!empty($dataArray['invoiceId'])) {
			$existingPayment = DataRecord::fetchRecord('SELECT
				Payment_ID AS id,
				Transaction_Type AS type
				FROM payment
				WHERE Invoice_ID = ?
				ORDER BY Payment_ID DESC
				LIMIT 1', 
				[
					$dataArray['invoiceId'],
				]
			);
		}

		$this->payment = new PaymentDataObject();

		if (!empty($existingPayment) && $existingPayment['type'] == $transactionType) {
			$this->payment->select($existingPayment['id']);
		}

		$this->payment->setValue('transactionType', $transactionType);

		// Specifies required flags for creating a MOTO Payment Intent.
		// https://stripe.com/docs/payments/payment-intents/moto
		if($this->moto) {
			$this->payment->setValue('moto', true);

			if(!empty($paymentMethodId)) {
				$this->request['payment_method'] = $paymentMethodId;
				$this->request['confirm'] = true;
			}

			$this->request['setup_future_usage'] = 'off_session';
			$this->request['payment_method_types'] = ['card'];
			
		} else {
			$this->request['payment_method_types'] = PaymentMethodCommon::availablePaymentMethods();
		}

		if(!empty($returnUrl)) {
			// Only required when "confirm" is "true" with "automatic_payment_methods.allow_redirects" defaulted to "always".
			//$this->request['return_url'] = $returnUrl;
		}

		try {
			if (!empty($this->payment->getValue('reference'))) {
				$intent = \Stripe\PaymentIntent::retrieve($this->payment->getValue('reference'));
			} else {
				$intent = \Stripe\PaymentIntent::create($this->request);
			}

			$this->response = $intent;

			$this->payment->setValue('status', $this->response->status);
			$this->payment->setValue('reference', $this->response->id);

			$this->populateMetaData($dataArray);

		} catch(Exception $e) {
			$this->errorObject = $e->getError();

			$this->payment->setValue('status', self::ERROR_STATUS);
			$this->payment->setValue('statusDetail', $e->getMessage());
		}

		$this->payment->setValue('provider', PaymentCommon::PROVIDER_STRIPE);
		$this->payment->setValue('amount', $dataArray['total']);
		$this->payment->setValue('orderId', $dataArray['orderId']);
		$this->payment->setValue('invoiceId', $dataArray['invoiceId']);
		$this->payment->save();

		return $this->resolve($transactionType);
	}

	public function authorise(array $dataArray, int $paymentId, string $paymentMethodId = null, string $returnUrl = null) {
		$payment = new PaymentDataObject();
		$payment->setId($paymentId);

		if($payment->select()) {
			$transactionType = PaymentCommon::TYPE_AUTHORISE;

			$this->payment = new PaymentDataObject();
			$this->payment->setValue('transactionType', $transactionType);

			try {
				$request = [];

				if (!is_null($paymentMethodId)) {
					$request['payment_method'] = $paymentMethodId;
					$request['confirm'] = true;
				}

				if (!is_null($returnUrl)) {
					$request['return_url'] = $returnUrl;
				}

				if (!empty($request)) {
					$intent = \Stripe\PaymentIntent::update($payment->getValue('reference'), $request);
					$intent->confirm();
				
				} else {
					$intent = \Stripe\PaymentIntent::retrieve($payment->getValue('reference'));
				}

				$this->response = $intent;

				$this->payment->setValue('status', $this->response->status);
				$this->payment->setValue('reference', $this->response->id);
				$this->payment->setValue('amount', $this->response->amount/100);

				$this->populateMetaData($dataArray);

			} catch(Exception $e) {
				$this->errorObject = $e->getError();

				$this->payment->setValue('status', self::ERROR_STATUS);
				$this->payment->setValue('statusDetail', $e->getMessage());
			}

			$this->payment->setValue('provider', PaymentCommon::PROVIDER_STRIPE);

			// Maintain related payment data.
			if (!empty($payment->getValue('orderId'))) {
				$this->payment->setValue('orderId', $payment->getValue('orderId'));

			} elseif (!empty($payment->getValue('invoiceId'))) {
				$this->payment->setValue('invoiceId', $payment->getValue('invoiceId'));
			}

			$this->payment->setValue('moto', $payment->getValue('moto'));

			$this->payment->insert();

			return $this->resolve($transactionType);
		}

		return false;
	}

	// TODO: convert to using OrdersDataObject
	public function capture(array $dataArray, int $paymentId, float $amount) {
		$payment = new PaymentDataObject();
		$payment->setId($paymentId);

		if($payment->select()) {
			$transactionType = PaymentCommon::TYPE_CAPTURE;
			$requestAmount = round($amount*100);

			$this->request = [
				'amount_to_capture' => $requestAmount,
			];

			$this->payment = new PaymentDataObject();
			$this->payment->setValue('transactionType', $transactionType);

			try {
				$intent = \Stripe\PaymentIntent::retrieve($payment->getValue('reference'));

				$this->response = $intent;
				$this->response->capture($this->request);

				$this->payment->setValue('status', $this->response->status);
				$this->payment->setValue('reference', $this->response->latest_charge);
				$this->payment->setValue('amount', $this->response->amount_received/100);

				$this->populateMetaData($dataArray);

			} catch(Exception $e) {
				$this->errorObject = $e->getError();

				$this->payment->setValue('status', self::ERROR_STATUS);
				$this->payment->setValue('statusDetail', $e->getMessage());
			}

			$this->payment->setValue('provider', PaymentCommon::PROVIDER_STRIPE);
			$this->payment->setValue('amount', $amount);

			// Maintain related payment data.
			$this->payment->setValue('orderId', $payment->getValue('orderId'));
			$this->payment->setValue('invoiceId', $payment->getValue('invoiceId'));
			$this->payment->setValue('moto', $payment->getValue('moto'));

			$this->payment->insert();

			return $this->resolve($transactionType);
		}

		return false;
	}

	// TODO: convert to using OrdersDataObject
	public function refund(array $dataArray, int $paymentId, float $amount) {
		$payment = new PaymentDataObject();
		$payment->setId($paymentId);

		if($payment->select()) {
			$transactionType = PaymentCommon::TYPE_REFUND;
			$requestAmount = round($amount*100);

			$this->request = [
				'amount' => $requestAmount,
				'charge' => $payment->getValue('reference'),
			];

			$this->payment = new PaymentDataObject();
			$this->payment->setValue('transactionType', $transactionType);

			try {
				$refund = \Stripe\Refund::create($this->request);

				$this->response = $refund;

				$this->payment->setValue('reference', $this->response->id);
				$this->payment->setValue('status', $this->response->status);
				$this->payment->setValue('amount', ($this->response->amount/100)*-1);

				//populateMetaData

			} catch(Exception $e) {
				$this->errorObject = $e->getError();

				$this->payment->setValue('status', self::ERROR_STATUS);
				$this->payment->setValue('statusDetail', $e->getMessage());
			}

			$this->payment->setValue('provider', PaymentCommon::PROVIDER_STRIPE);

			// Maintain related payment data.
			$this->payment->setValue('orderId', $payment->getValue('orderId'));

			$this->payment->insert();

			return $this->resolve($transactionType);
		}

		return false;
	}

	// TODO: convert to using OrdersDataObject
	public function cancel(array $dataArray, int $paymentId) {
		$payment = new PaymentDataObject();
		$payment->setId($paymentId);

		if($payment->select()) {
			$transactionType = PaymentCommon::TYPE_CANCEL;

			$this->payment = new PaymentDataObject();
			$this->payment->setValue('transactionType', $transactionType);

			try {
				$intent = \Stripe\PaymentIntent::retrieve($payment->getValue('reference'));

				$this->response = $intent;
				$this->response->cancel();

				$this->payment->setValue('status', $this->response->status);
				$this->payment->setValue('reference', $this->response->id);

				$payment->setValue('cancelled', true);
				$payment->update();

			} catch(Exception $e) {
				$this->errorObject = $e->getError();

				$this->payment->setValue('status', self::ERROR_STATUS);
				$this->payment->setValue('statusDetail', $e->getMessage());
			}

			$this->payment->setValue('provider', PaymentCommon::PROVIDER_STRIPE);

			// Maintain related payment data.
			$this->payment->setValue('amount', $payment->getValue('total'));
			$this->payment->setValue('orderId', $payment->getValue('orderId'));

			$this->payment->insert();

			return $this->resolve($transactionType);
		}

		return false;
	}

	// TODO: convert to using OrdersDataObject
	public function void(array $dataArray, int $paymentId) {
		return false;
	}

	/**
	 * Resolves the outcome of a Payment Transaction to determine whether the transaction was a succes.
	 * Collects any human readable error messages for outputting.
	 *
	 * @param string $type The type of Payment Transaction being resolved from PaymentCommon, e.g TYPE_CAPTURE.
	 * @return bool Whether the transaction was successful or not.
	 */
	private function resolve(string $type): bool {
		$success = false;

		if($this->payment->getValue('status') != self::ERROR_STATUS) {
			if(in_array($type, [PaymentCommon::TYPE_UPDATE, PaymentCommon::TYPE_REGISTER, PaymentCommon::TYPE_CANCEL])) {
				$success = true;

			} elseif(in_array($this->payment->getValue('status'), PaymentCommon::getTypeStatus(PaymentCommon::PROVIDER_STRIPE, $type))) {
				$success = true;
			}
		}

		LoggerCommon::log(sprintf('Stripe (%s)', $type), $success ? LoggerCommon::LOGGER_DEBUG : LoggerCommon::LOGGER_ERROR, [
			'request' => $this->request,
			'response' => $this->response,
			'error' => $this->errorObject,
		]);

		if($success) {
			return true;
		}

		$errorMessage = $this->getDefaultError();

		if($this->payment->getValue('status') == self::ERROR_STATUS) {
			switch($this->errorObject->code) {
				default:
					$errorMessage = $this->errorObject->message;
					break;
			}
		}

		$this->errors[] = $errorMessage;

		return false;
	}

	/**
	 * Populate common meta data associated with this Payment transaction and optionally the associated Order.
	 *
	 * @param OrdersDataObject $order The optional DataObject representing the Order atatched to this Payment transaction.
	 */
	private function populateMetaData(array $dataArray = null): void {

		// Extract the first record of the associated Charge for the Payment Intent and break after first data result.
		if(!empty($this->response->latest_charge)) {

			$charge = \Stripe\Charge::retrieve($this->response->latest_charge);

			// TODO: validate fee's actually capture for non IC++ pricing.
			//$balance = \Stripe\BalanceTransaction::retrieve($charge->balance_transaction);

			// TODO: *-1 when this is a refund, second parameter?
			//$this->payment->setValue('fee', $balance->fee/100);

			// Store fraud results from Charge data.
			$this->payment->setValue('fraudScreened', true);
			$this->payment->setValue('fraudTotalScore', $charge->outcome->risk_score);
			$this->payment->setValue('fraudScreenResult', $charge->outcome->risk_level);

			if(!empty($charge->payment_method_details->card)) {
				$card = $charge->payment_method_details->card;

				// Store check results from Charge data.
				$this->payment->setValue('addressResult', $card->checks->address_line1_check ?? null);
				$this->payment->setValue('postcodeResult', $card->checks->address_postal_code_check ?? null);
				$this->payment->setValue('cv2Result', $card->checks->cvc_check ?? null);
				$this->payment->setValue('cardType', ucwords($card->brand));
				$this->payment->setValue('cardNumber', $card->last4);
				$this->payment->setValue('cardExpires', sprintf('%s/%s', str_pad($card->exp_month, 2, '0', STR_PAD_LEFT), substr($card->exp_year, 2)));
				$this->payment->setValue('3dSecure', (($card->three_d_secure->result ?? null) == 'authenticated'));

				// Only capture the Expiry date of the transactions if of type Authorise, otherwise has no meaning.
				if($this->payment->getValue('transactionType') == PaymentCommon::TYPE_AUTHORISE) {
					$this->payment->setValue('expiresOn', !empty($card->capture_before) ? date('Y-m-d H:i:s', $card->capture_before) : null);
				}

				if(!is_null($dataArray)) {
					$order = new OrdersDataObject();
					$order->select($dataArray['orderId']);

					// Store card details against order as meta data.
					$order->setValue('cardType', $this->payment->getValue('cardType'));
					$order->setValue('cardNumber', $this->payment->getValue('cardNumber'));
					$order->setValue('cardExpires', $this->payment->getValue('cardExpires'));
					$order->update();
				}
			}
		}
	}
}