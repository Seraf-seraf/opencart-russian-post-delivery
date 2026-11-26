<?php

class ModelExtensionShippingRussianPostShipment extends Model {
	public function get(int $orderId): ?array {
		$this->ensureTable();
		$result = $this->db->query("SELECT * FROM `" . DB_PREFIX . "russian_post_shipment` WHERE order_id = " . $orderId);

		return $result->num_rows ? $result->row : null;
	}

	public function rememberAttempt(int $orderId, string $reference): void {
		$this->db->query(
			"INSERT INTO `" . DB_PREFIX . "russian_post_shipment` SET order_id = " . $orderId .
			", reference = '" . $this->db->escape($reference) . "'"
		);
	}

	public function rememberShipment(int $orderId, array $shipment): void {
		$this->db->query(
			"UPDATE `" . DB_PREFIX . "russian_post_shipment` SET remote_id = '" . $this->db->escape($shipment['remote_id']) .
			"', barcode = '" . $this->db->escape($shipment['barcode']) . "' WHERE order_id = " . $orderId
		);
	}

	public function forgetRejectedAttempt(int $orderId): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "russian_post_shipment` WHERE order_id = " . $orderId . " AND remote_id = ''");
	}

	public function acquire(int $orderId): bool {
		$result = $this->db->query("SELECT GET_LOCK('" . $this->lockName($orderId) . "', 0) AS acquired");

		return (int)($result->row['acquired'] ?? 0) === 1;
	}

	public function release(int $orderId): void {
		$this->db->query("SELECT RELEASE_LOCK('" . $this->lockName($orderId) . "')");
	}

	private function lockName(int $orderId): string {
		return 'rps_' . substr(hash('sha256', DB_DATABASE . ':' . DB_PREFIX . ':' . $orderId), 0, 60);
	}

	private function ensureTable(): void {
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "russian_post_shipment` (" .
			"order_id INT NOT NULL PRIMARY KEY, reference VARCHAR(64) NOT NULL, " .
			"remote_id VARCHAR(20) NOT NULL DEFAULT '', barcode VARCHAR(32) NOT NULL DEFAULT ''" .
			") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
	}
}
