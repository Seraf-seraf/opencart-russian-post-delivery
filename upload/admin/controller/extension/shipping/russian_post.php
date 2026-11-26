<?php
class ControllerExtensionShippingRussianPost extends Controller {
	private $error = array();
	private const SHIPMENT_MESSAGE = 'russian_post_shipment_message';

	public function install() {
		$this->load->model('setting/setting');
		$this->load->model('setting/event');

		if (!$this->model_setting_setting->getSetting('shipping_russian_post', 0)) {
			$this->model_setting_setting->editSetting('shipping_russian_post', array(
				'shipping_russian_post_widget_id' => '',
				'shipping_russian_post_origin_postcode' => '',
				'shipping_russian_post_api_url' => 'https://otpravka-api.pochta.ru',
				'shipping_russian_post_token' => '',
				'shipping_russian_post_login' => '',
				'shipping_russian_post_password' => '',
				'shipping_russian_post_default_weight' => '200',
				'shipping_russian_post_default_length' => '15',
				'shipping_russian_post_default_width' => '10',
				'shipping_russian_post_default_height' => '5',
				'shipping_russian_post_timeout' => '10',
				'shipping_russian_post_status' => '0',
				'shipping_russian_post_sort_order' => '2'
			), 0);
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "russian_post_order` (`order_id` INT(11) NOT NULL, `office_id` VARCHAR(64) NOT NULL DEFAULT '', `postcode` VARCHAR(6) NOT NULL, `address` VARCHAR(255) NOT NULL, `cost` DECIMAL(15,4) NOT NULL, `min_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `max_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `date_added` DATETIME NOT NULL, PRIMARY KEY (`order_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$events = array(
			'russian_post_validate_office' => array('catalog/controller/checkout/shipping_method/save/before', 'extension/shipping/russian_post/validateOffice'),
			'russian_post_save_order' => array('catalog/controller/checkout/confirm/after', 'extension/shipping/russian_post/saveOrder'),
			'russian_post_header_script' => array('catalog/view/common/header/before', 'extension/shipping/russian_post/addHeaderScript'),
			'russian_post_admin_order' => array('admin/view/sale/order_info/before', 'extension/shipping/russian_post/orderInfo')
		);

		foreach ($events as $code => $event) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent($code, $event[0], $event[1]);
		}
	}

	public function uninstall() {
		$this->load->model('setting/event');

		foreach (array('russian_post_validate_office', 'russian_post_save_order', 'russian_post_header_script', 'russian_post_admin_order') as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}
	}

	public function index() {
		$this->load->language('extension/shipping/russian_post');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
			$post = $this->request->post;

			foreach (array('token', 'login', 'password') as $secret) {
				$key = 'shipping_russian_post_' . $secret;

				if (empty($post[$key])) {
					$post[$key] = $this->config->get($key);
				}
			}
			$this->model_setting_setting->editSetting('shipping_russian_post', $post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true));
		}

		$data = array();
		foreach ($this->language->all() as $key => $value) $data[$key] = $value;
		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['action'] = $this->url->link('extension/shipping/russian_post', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true);
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $data['cancel']),
			array('text' => $this->language->get('heading_title'), 'href' => $data['action'])
		);
		$defaults = array(
			'widget_id' => '', 'origin_postcode' => '', 'api_url' => 'https://otpravka-api.pochta.ru',
			'default_weight' => 200, 'default_length' => 15, 'default_width' => 10, 'default_height' => 5,
			'timeout' => 10, 'status' => 0, 'sort_order' => 2
		);
		foreach ($defaults as $name => $default) {
			$key = 'shipping_russian_post_' . $name;
			$data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : ($this->config->get($key) !== null ? $this->config->get($key) : $default);
		}
		foreach (array('token', 'login', 'password') as $secret) {
			$key = 'shipping_russian_post_' . $secret;
			$data[$key . '_configured'] = (bool)$this->config->get($key);
			$data[$key] = '';
		}
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/shipping/russian_post', $data));
	}

	public function orderInfo(&$route, &$data) {
		$orderId = $this->orderId($this->request->get);
		$delivery = $this->getDelivery($orderId);
		if (!$delivery) {
			return;
		}

		$this->load->model('sale/order');
		$this->load->model('extension/shipping/russian_post_shipment');
		$order = $this->model_sale_order->getOrder($orderId);
		$shipment = $this->model_extension_shipping_russian_post_shipment->get($orderId);
		$canManage = $this->user->hasPermission('modify', 'extension/shipping/russian_post');
		$view = array(
			'delivery' => $delivery,
			'shipment' => $shipment,
			'message' => $this->session->data[self::SHIPMENT_MESSAGE][$orderId] ?? '',
			'create_url' => $canManage && $this->isPaidPostalOrder($order) ? $this->adminUrl('extension/shipping/russian_post/createShipment', $orderId) : '',
			'label_url' => $canManage && !empty($shipment['remote_id']) ? $this->adminUrl('extension/shipping/russian_post/label', $orderId) : '',
			'weight' => (int)$this->config->get('shipping_russian_post_default_weight'),
			'length' => (int)$this->config->get('shipping_russian_post_default_length'),
			'width' => (int)$this->config->get('shipping_russian_post_default_width'),
			'height' => (int)$this->config->get('shipping_russian_post_default_height'),
		);
		unset($this->session->data[self::SHIPMENT_MESSAGE][$orderId]);

		$data['tabs'][] = array(
			'code' => 'russian_post',
			'title' => 'Почта России',
			'content' => $this->load->view('extension/shipping/russian_post_order', $view),
		);
	}

	public function createShipment(): void {
		$orderId = $this->orderId($this->request->get);
		if (!$orderId || !$this->canManageShipment() || ($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('Оформление отправления недоступно.');
			return;
		}

		$this->load->model('sale/order');
		$delivery = $this->getDelivery($orderId);
		if (!$delivery) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			$this->response->setOutput('Доставка Почтой России не найдена.');
			return;
		}

		$api = $this->shipmentApi();
		$this->load->model('extension/shipping/russian_post_shipment');
		$model = $this->model_extension_shipping_russian_post_shipment;
		$message = 'Оформление уже выполняется. Обновите карточку заказа.';

		if ($model->acquire($orderId)) {
			try {
				$order = $this->model_sale_order->getOrder($orderId);
				if (!$this->isPaidPostalOrder($order)) {
					throw new RuntimeException('Оформление доступно для оплаченного заказа с доставкой Почтой России.');
				}

				$shipment = $this->submitShipment($orderId, $order, $delivery, $api);
				$message = $shipment['barcode'] !== ''
					? 'Отправление оформлено.'
					: 'Отправление оформлено. Трек-номер можно обновить после формирования ярлыка.';
			} catch (RuntimeException $error) {
				$this->log->write('Russian Post shipment order ' . $orderId . ': ' . $error->getMessage());
				$message = $error->getMessage();
			} finally {
				$model->release($orderId);
			}
		}

		$this->session->data[self::SHIPMENT_MESSAGE][$orderId] = $message;
		$this->response->redirect($this->adminUrl('sale/order/info', $orderId) . '#tab-russian_post');
	}

	private function submitShipment(int $orderId, array $order, array $delivery, RussianPostShipment $api): array {
		$model = $this->model_extension_shipping_russian_post_shipment;
		$saved = $model->get($orderId);
		$reference = $saved['reference'] ?? 'cbs-' . substr(hash('sha256', (string)$this->config->get('config_url')), 0, 12) . '-' . $orderId;

		if (!empty($saved['remote_id'])) {
			return $saved['barcode'] !== '' ? $saved : $this->refreshShipment($orderId, $saved, $api);
		}

		$remote = $api->find($reference);
		if ($remote !== null) {
			if (!$saved) {
				$model->rememberAttempt($orderId, $reference);
			}
			$model->rememberShipment($orderId, $remote);
			return $remote;
		}

		if ($saved) {
			throw new RuntimeException('Почта ещё не подтвердила предыдущий запрос. Проверьте номер ' . $reference . ' в «Отправке» и повторите проверку.');
		}

		$payload = $this->shipmentPayload($order, $delivery, $reference);
		$model->rememberAttempt($orderId, $reference);

		try {
			$remoteId = $api->create($payload);
		} catch (UnexpectedValueException $error) {
			$model->forgetRejectedAttempt($orderId);
			throw $error;
		}

		$saved = array(
			'reference' => $reference,
			'remote_id' => $remoteId,
			'barcode' => '',
		);
		$model->rememberShipment($orderId, $saved);

		return $this->refreshShipment($orderId, $saved, $api);
	}

	private function refreshShipment(int $orderId, array $saved, RussianPostShipment $api): array {
		$shipment = $api->get($saved['remote_id'], $saved['reference']);
		$this->model_extension_shipping_russian_post_shipment->rememberShipment($orderId, $shipment);

		return $shipment;
	}

	private function shipmentPayload(array $order, array $delivery, string $reference): array {
		$postcode = (string)$delivery['postcode'];
		$origin = trim((string)$this->config->get('shipping_russian_post_origin_postcode'));
		$firstName = trim($order['shipping_firstname']);
		$lastName = trim($order['shipping_lastname']);
		$phone = preg_replace('/[^0-9]/', '', (string)$order['telephone']);

		if (strlen($phone) === 10) {
			$phone = '7' . $phone;
		} elseif (strlen($phone) === 11 && $phone[0] === '8') {
			$phone = '7' . substr($phone, 1);
		}

		if (!preg_match('/^[0-9]{6}$/D', $origin)) {
			throw new RuntimeException('Проверьте индекс места приёма в настройках Почты России.');
		}
		if (!preg_match('/^[0-9]{6}$/D', $postcode) || $firstName === '' || $lastName === '' || !preg_match('/^7[0-9]{10}$/D', $phone)) {
			throw new RuntimeException('Проверьте имя, фамилию и телефон получателя, а также индекс отделения.');
		}

		$dimensions = array();
		foreach (array('length', 'width', 'height', 'weight') as $field) {
			$value = $this->request->post[$field] ?? null;
			if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,5}$/D', $value)) {
				throw new RuntimeException('Укажите фактический вес в граммах и размеры в сантиметрах.');
			}
			$dimensions[$field] = (int)$value;
		}
		$mass = $dimensions['weight'];
		unset($dimensions['weight']);

		return array(
			'order-num' => $reference,
			'address-type-to' => 'DEMAND',
			'index-to' => (int)$postcode,
			'postoffice-code' => $origin,
			'given-name' => $firstName,
			'surname' => $lastName,
			'recipient-name' => $lastName . ' ' . $firstName,
			'tel-address' => (int)$phone,
			'mail-category' => 'ORDINARY',
			'mail-type' => 'POSTAL_PARCEL',
			'mass' => $mass,
			'dimension' => $dimensions,
		);
	}

	public function label(): void {
		$orderId = $this->orderId($this->request->get);
		if (!$orderId || !$this->canManageShipment()) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('Скачивание ярлыка недоступно.');
			return;
		}

		$this->load->model('extension/shipping/russian_post_shipment');
		$shipment = $this->model_extension_shipping_russian_post_shipment->get($orderId);
		if (empty($shipment['remote_id'])) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			$this->response->setOutput('Отправление не найдено.');
			return;
		}

		try {
			$pdf = $this->shipmentApi()->label($shipment['remote_id']);
		} catch (RuntimeException $error) {
			$this->session->data[self::SHIPMENT_MESSAGE][$orderId] = $error->getMessage();
			$this->response->redirect($this->adminUrl('sale/order/info', $orderId) . '#tab-russian_post');
			return;
		}

		$this->response->addHeader('Cache-Control: private, no-store');
		$this->response->addHeader('Content-Type: application/pdf');
		$this->response->addHeader('Content-Disposition: attachment; filename="russian-post-' . $orderId . '.pdf"');
		$this->response->setOutput($pdf);
	}

	private function shipmentApi(): RussianPostShipment {
		require_once DIR_SYSTEM . 'library/russian_post_shipment.php';

		return new RussianPostShipment($this->config);
	}

	private function getDelivery(int $orderId): ?array {
		if (!$orderId) {
			return null;
		}

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "russian_post_order` WHERE order_id = " . $orderId);

		return $query->num_rows ? $query->row : null;
	}

	private function isPaidPostalOrder(?array $order): bool {
		$statusKey = array(
			'robokassa' => 'payment_robokassa_order_status_id',
			'payment_service' => 'payment_payment_service_success_status_id',
		)[$order['payment_code'] ?? ''] ?? '';
		$paidStatus = $statusKey ? (int)$this->config->get($statusKey) : 0;

		return ($order['shipping_code'] ?? '') === 'russian_post.office'
			&& $paidStatus > 0
			&& (int)$order['order_status_id'] === $paidStatus;
	}

	private function canManageShipment(): bool {
		$token = $this->request->get['user_token'] ?? null;
		$sessionToken = $this->session->data['user_token'] ?? '';

		return is_string($token)
			&& $sessionToken !== ''
			&& hash_equals($sessionToken, $token)
			&& $this->user->hasPermission('modify', 'extension/shipping/russian_post');
	}

	private function orderId(array $request): int {
		$value = $request['order_id'] ?? null;

		return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) ? (int)$value : 0;
	}

	private function adminUrl(string $route, int $orderId): string {
		return $this->url->link($route, 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $orderId, true);
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/shipping/russian_post')) $this->error['warning'] = $this->language->get('error_permission');
		if (!empty($this->request->post['shipping_russian_post_status'])) {
			foreach (array('widget_id', 'origin_postcode', 'token', 'login', 'password') as $field) {
				$key = 'shipping_russian_post_' . $field;
				if (empty($this->request->post[$key]) && !$this->config->get($key)) $this->error['warning'] = $this->language->get('error_required');
			}
		}
		return !$this->error;
	}
}
