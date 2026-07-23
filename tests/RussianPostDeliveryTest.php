<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/upload/system/library/russian_post_delivery.php';

final class RussianPostDeliveryTest extends TestCase {
	public function testPackageUsesProductFieldsAndConfiguredFallbacks(): void {
		$products = [
			[
				'quantity' => 1,
				'weight' => 0.2,
				'weight_class_id' => 1,
				'length' => 150.0,
				'width' => 100.0,
				'height' => 50.0,
				'length_class_id' => 2,
			],
			[
				'quantity' => 2,
				'weight' => 0.0,
				'weight_class_id' => 1,
				'length' => 0.0,
				'width' => 0.0,
				'height' => 0.0,
				'length_class_id' => 2,
			],
		];
		$config = new class {
			public function get(string $key): mixed {
				return [
					'shipping_russian_post_default_weight' => '200',
					'shipping_russian_post_default_length' => '15',
					'shipping_russian_post_default_width' => '10',
					'shipping_russian_post_default_height' => '5',
				][$key] ?? null;
			}
		};
		$registry = new class($config, $products) {
			public function __construct(private readonly object $config, private readonly array $products) {
			}

			public function get(string $key): object {
				return [
					'config' => $this->config,
					'cart' => new class($this->products) {
						public function __construct(private readonly array $products) {
						}

						public function getProducts(): array {
							return $this->products;
						}
					},
					'weight' => new class {
						public function convert(float $value, int $from, int $to): float {
							return $value * 1000;
						}
					},
					'length' => new class {
						public function convert(float $value, int $from, int $to): float {
							return $value / 10;
						}
					},
					'log' => new stdClass(),
				][$key];
			}
		};

		$delivery = new RussianPostDelivery($registry);
		$package = $delivery->getPackage();

		self::assertSame([
			'weight' => 600,
			'length' => 15,
			'width' => 10,
			'height' => 15,
		], $package);
		self::assertSame([[
			'length' => 15,
			'width' => 10,
			'height' => 15,
		]], $delivery->getWidgetDimensions($package));
	}
}
