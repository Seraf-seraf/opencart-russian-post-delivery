<?php
class ControllerExtensionShippingRussianPost extends Controller {
	public function params() {
		$this->response->addHeader('Content-Type: application/json');

		if (empty($this->session->data['shipping_address'])) {
			$this->respond(array('error' => 'Не указан адрес доставки.'));
			return;
		}

		require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
		$delivery = new RussianPostDelivery($this->registry);
		$widget_id = (int)$this->config->get('shipping_russian_post_widget_id');
		$start_zip = trim((string)$this->config->get('shipping_russian_post_origin_postcode'));
		$shipping_address = $this->session->data['shipping_address'];

		if ($widget_id < 1) {
			$this->respond(array('error' => 'Не настроен идентификатор виджета Почты России.'));
			return;
		}

		if (!preg_match('/^\d{6}$/', $start_zip)) {
			$this->respond(array('error' => 'Не настроен индекс отправителя Почты России.'));
			return;
		}

		$start_location = $delivery->getStartLocation($shipping_address);

		if ($start_location === '') {
			$this->respond(array('error' => 'Не удалось определить адрес получателя для карты отделений.'));
			return;
		}

		$package = $delivery->getPackage();
		$lines = array();

		foreach ($this->cart->getProducts() as $product) {
			$lines[] = array('name' => $product['name'], 'quantity' => (int)$product['quantity'], 'value' => (int)round($product['price'] * 100));
		}

		$fingerprint = $delivery->getFingerprint($shipping_address, $package);
		$selection = isset($this->session->data['russian_post_delivery']) ? $this->session->data['russian_post_delivery'] : array();
		$verified = !empty($selection['verified']) && isset($selection['fingerprint']) && hash_equals($selection['fingerprint'], $fingerprint);

		if ($selection && !$verified) {
			unset($this->session->data['russian_post_delivery']);
		}

		$selected = $verified ? array(
			'office_id' => isset($selection['office_id']) ? (string)$selection['office_id'] : '',
			'index' => (string)$selection['index'],
			'address' => (string)$selection['address']
		) : null;

		$this->respond(array(
			'id' => $widget_id,
			'weight' => $package['weight'],
			'sumoc' => (int)round($this->cart->getSubTotal() * 100),
			'startZip' => $start_zip,
			'start_location' => $start_location,
			'dimensions' => $delivery->getWidgetDimensions($package),
			'order_lines' => $lines,
			'selected' => $selected
		));
	}

	public function select() {
		$this->response->addHeader('Content-Type: application/json');

		try {
			$data = json_decode(isset($this->request->post['selection']) ? htmlspecialchars_decode($this->request->post['selection'], ENT_COMPAT) : '', true);

			if (empty($this->session->data['shipping_address'])) {
				throw new RuntimeException('Не указан адрес доставки.');
			}

			require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
			$delivery = new RussianPostDelivery($this->registry);
			$selection = $delivery->normalizeWidgetSelection($data);
			$package = $delivery->getPackage();
			$fingerprint = $delivery->getFingerprint($this->session->data['shipping_address'], $package);
			$current = isset($this->session->data['russian_post_delivery']) ? $this->session->data['russian_post_delivery'] : array();

			if (!empty($current['verified']) && isset($current['fingerprint'], $current['index'], $current['office_id']) && hash_equals($current['fingerprint'], $fingerprint) && $current['index'] === $selection['index'] && $current['office_id'] === $selection['office_id']) {
				$this->respond(array('success' => true, 'selected' => array('index' => $current['index'], 'address' => $current['address'])));
				return;
			}

			$tariff = $delivery->calculateTariff($selection['index'], $package);
			$terms = '';

			if ($tariff['max_days']) {
				$terms = $tariff['min_days'] && $tariff['min_days'] !== $tariff['max_days'] ? $tariff['min_days'] . '-' . $tariff['max_days'] . ' дней' : 'до ' . $tariff['max_days'] . ' дней';
			}

			$address = trim(($selection['city'] !== '' ? $selection['city'] . ', ' : '') . $selection['address'], ', ');
			$this->session->data['russian_post_delivery'] = array(
				'verified' => true,
				'fingerprint' => $fingerprint,
				'office_id' => $selection['office_id'],
				'index' => $selection['index'],
				'address' => $address,
				'cost' => $tariff['cost'],
				'min_days' => $tariff['min_days'],
				'max_days' => $tariff['max_days'],
				'terms' => $terms
			);
			unset($this->session->data['shipping_method'], $this->session->data['shipping_methods']);
			$this->respond(array('success' => true, 'selected' => array('index' => $selection['index'], 'address' => $address)));
		} catch (Throwable $exception) {
			$this->respond(array('error' => $exception->getMessage()));
		}
	}

	public function reset() {
		$this->response->addHeader('Content-Type: application/json');
		unset($this->session->data['russian_post_delivery'], $this->session->data['shipping_methods']);

		if (isset($this->session->data['shipping_method']['code']) && $this->session->data['shipping_method']['code'] === 'russian_post.office') {
			unset($this->session->data['shipping_method']);
		}

		$this->respond(array('success' => true));
	}

	public function validateOffice() {
		if (!isset($this->request->post['shipping_method']) || $this->request->post['shipping_method'] !== 'russian_post.office') {
			return null;
		}
		if (!empty($this->session->data['russian_post_delivery']['verified']) && !empty($this->session->data['shipping_address'])) {
			require_once DIR_SYSTEM . 'library/russian_post_delivery.php';
			$delivery = new RussianPostDelivery($this->registry);
			$current = $delivery->getFingerprint($this->session->data['shipping_address'], $delivery->getPackage());
			if (isset($this->session->data['russian_post_delivery']['fingerprint']) && hash_equals($this->session->data['russian_post_delivery']['fingerprint'], $current)) {
				return null;
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('error' => array('warning' => 'Выберите отделение Почты России и дождитесь расчета стоимости.'))));
		return false;
	}

	public function saveOrder() {
		if (empty($this->session->data['order_id']) || empty($this->session->data['russian_post_delivery']['verified']) || empty($this->session->data['shipping_method']['code']) || $this->session->data['shipping_method']['code'] !== 'russian_post.office') {
			return;
		}
		$data = $this->session->data['russian_post_delivery'];
		$this->db->query("INSERT INTO `" . DB_PREFIX . "russian_post_order` SET order_id = '" . (int)$this->session->data['order_id'] . "', office_id = '" . $this->db->escape($data['office_id']) . "', postcode = '" . $this->db->escape($data['index']) . "', address = '" . $this->db->escape($data['address']) . "', cost = '" . (float)$data['cost'] . "', min_days = '" . (int)$data['min_days'] . "', max_days = '" . (int)$data['max_days'] . "', date_added = NOW() ON DUPLICATE KEY UPDATE office_id = VALUES(office_id), postcode = VALUES(postcode), address = VALUES(address), cost = VALUES(cost), min_days = VALUES(min_days), max_days = VALUES(max_days)");
	}

	public function addHeaderScript(&$route, &$data) {
		if ($this->config->get('shipping_russian_post_status')) {
			$data['scripts'][] = 'catalog/view/javascript/shipping/russian_post.js';
			$data['styles'][] = array(
				'href' => 'catalog/view/stylesheet/shipping/russian_post.css',
				'rel' => 'stylesheet',
				'media' => 'screen'
			);
		}
	}

	private function respond($data) {
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
