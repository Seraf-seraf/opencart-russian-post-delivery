<?php

class RussianPostShipment {
	private const MIN_TIMEOUT_SECONDS = 3;
	private const DEFAULT_TIMEOUT_SECONDS = 10;

	private Config $config;

	public function __construct(Config $config) {
		$this->config = $config;
	}

	public function create(array $payload): string {
		$response = $this->readJson($this->request('PUT', '/1.0/user/backlog', array($payload)));
		$ids = $response['result-ids'] ?? null;

		if (is_array($ids) && count($ids) === 1) {
			return $this->remoteId(reset($ids));
		}

		if (empty($ids) && !empty($response['errors']) && is_array($response['errors'])) {
			throw new UnexpectedValueException('Почта России отклонила отправление. Проверьте получателя, вес и размеры.');
		}

		throw new RuntimeException('Почта России не подтвердила создание отправления.');
	}

	public function find(string $reference): ?array {
		$body = $this->request('GET', '/1.0/backlog/search?query=' . rawurlencode($reference));
		if (strncmp(ltrim($body), '[', 1) !== 0) {
			throw new RuntimeException('Почта России вернула некорректный результат поиска.');
		}
		$response = $this->readJson($body);
		$match = null;

		foreach ($response as $entry) {
			if (!is_array($entry) || !is_string($entry['order-num'] ?? null)) {
				throw new RuntimeException('Почта России вернула некорректный результат поиска.');
			}

			if ($entry['order-num'] !== $reference) {
				continue;
			}

			if ($match !== null) {
				throw new RuntimeException('Почта России вернула несколько отправлений с этим номером заказа.');
			}

			$match = $this->shipment($entry);
		}

		return $match;
	}

	public function get(string $remoteId, string $reference): array {
		$response = $this->readJson($this->request('GET', '/1.0/backlog/' . $this->remoteId($remoteId)));
		$shipment = $this->shipment($response);

		if ($shipment['remote_id'] !== $remoteId || ($response['order-num'] ?? null) !== $reference) {
			throw new RuntimeException('Почта России вернула данные другого отправления.');
		}

		return $shipment;
	}

	public function label(string $remoteId): string {
		$pdf = $this->request('GET', '/1.0/forms/' . $this->remoteId($remoteId) . '/f7pdf');

		if (strncmp($pdf, '%PDF-', 5) !== 0) {
			throw new RuntimeException('Почта России не вернула ярлык в формате PDF.');
		}

		return $pdf;
	}

	private function shipment(array $entry): array {
		$barcode = $entry['barcode'] ?? '';

		if (!is_string($barcode) || ($barcode !== '' && !preg_match('/^[A-Z0-9]{8,32}$/D', $barcode))) {
			throw new RuntimeException('Почта России вернула некорректный трек-номер.');
		}

		return array(
			'remote_id' => $this->remoteId($entry['id'] ?? null),
			'barcode' => $barcode,
		);
	}

	private function remoteId($value): string {
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$value)) {
			throw new RuntimeException('Почта России вернула некорректный идентификатор отправления.');
		}

		return (string)$value;
	}

	private function readJson(string $body): array {
		$data = json_decode($body, true);

		if (!is_array($data)) {
			throw new RuntimeException('Почта России вернула некорректный ответ.');
		}

		return $data;
	}

	protected function request(string $method, string $path, ?array $payload = null): string {
		$base = (string)$this->config->get('shipping_russian_post_api_url');
		$token = (string)$this->config->get('shipping_russian_post_token');
		$login = (string)$this->config->get('shipping_russian_post_login');
		$password = (string)$this->config->get('shipping_russian_post_password');

		if (parse_url($base, PHP_URL_SCHEME) !== 'https' || !$token || !$login || !$password) {
			throw new RuntimeException('Проверьте HTTPS-адрес и доступ к API «Отправка».');
		}

		$handle = curl_init(rtrim($base, '/') . $path);
		$options = array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::MIN_TIMEOUT_SECONDS,
			CURLOPT_TIMEOUT => max(
				self::MIN_TIMEOUT_SECONDS,
				(int)($this->config->get('shipping_russian_post_timeout') ?: self::DEFAULT_TIMEOUT_SECONDS)
			),
			CURLOPT_HTTPHEADER => array(
				'Accept: application/json, application/pdf',
				'Content-Type: application/json;charset=UTF-8',
				'Authorization: AccessToken ' . $token,
				'X-User-Authorization: Basic ' . base64_encode($login . ':' . $password),
			),
		);

		if ($payload !== null) {
			$options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
		}

		curl_setopt_array($handle, $options);
		$result = curl_exec($handle);
		$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);

		if (!is_string($result) || $status < 200 || $status >= 300) {
			throw new RuntimeException('Запрос к API «Отправка» не завершён успешно. HTTP ' . $status . '.');
		}

		return $result;
	}
}
