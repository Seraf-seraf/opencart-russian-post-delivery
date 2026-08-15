<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/upload/system/library/russian_post_delivery.php';

final class RussianPostDeliveryTest extends TestCase {
	public function testWidgetScriptDoesNotUseVarDeclarations(): void {
		$script = file_get_contents(dirname(__DIR__) . '/upload/catalog/view/javascript/shipping/russian_post.js');

		self::assertIsString($script);
		self::assertDoesNotMatchRegularExpression('/\\bvar\\s+[A-Za-z_$]/', $script);
	}

	public function testWidgetImplementsRequiredStateMachineAndRetryContract(): void {
		$script = file_get_contents(dirname(__DIR__) . '/upload/catalog/view/javascript/shipping/russian_post.js');

		self::assertIsString($script);

		foreach (array('idle', 'loading-script', 'widget-ready', 'saving-selection', 'selected', 'error') as $state) {
			self::assertStringContainsString("'" . $state . "'", $script, $state);
		}

		self::assertStringContainsString('SCRIPT_TIMEOUT_MS = 15000', $script);
		self::assertStringContainsString('data-russian-post-retry', $script);
		self::assertStringContainsString("off('ajaxComplete.russianPost')", $script);
		self::assertStringContainsString("input.closest('.shipping-method-card, .radio')", $script);
		self::assertMatchesRegularExpression('/transition\(box, STATES\.SELECTED.*?refreshShippingMethods\(box\);/s', $script);
	}

	public function testControllerProvidesRecipientLocationAndIdempotentSelection(): void {
		$controller = file_get_contents(dirname(__DIR__) . '/upload/catalog/controller/extension/shipping/russian_post.php');
		$library = file_get_contents(dirname(__DIR__) . '/upload/system/library/russian_post_delivery.php');

		self::assertIsString($controller);
		self::assertIsString($library);
		self::assertStringContainsString("'start_location' => \$start_location", $controller);
		self::assertStringContainsString("'startZip' => \$start_zip", $controller);
		self::assertStringContainsString('normalizeWidgetSelection($data)', $controller);
		self::assertStringContainsString("\$current['index'] === \$selection['index']", $controller);
		self::assertStringContainsString('public function reset()', $controller);
		self::assertStringContainsString('catalog/view/theme/default/stylesheet/shipping/russian_post.css', $controller);
		self::assertStringNotContainsString('catalog/view/stylesheet/', $controller);
		self::assertStringContainsString('Не удалось проверить тариф Почты России. Попробуйте позже.', $library);
	}

	public function testRecipientLocationUsesRecipientAddressFields(): void {
		$delivery = new RussianPostDelivery($this->registry(array()));

		self::assertSame(
			'644000, Омская область, Омск, ул. Ленина, 1',
			$delivery->getStartLocation(array(
				'postcode' => '644000',
				'zone' => 'Омская область',
				'city' => 'Омск',
				'address_1' => 'ул. Ленина, 1',
				'address_2' => ''
			))
		);
	}

	public function testSelectionValidationAcceptsCallbackAndRejectsInvalidData(): void {
		$delivery = new RussianPostDelivery($this->registry(array()));
		$selection = $delivery->normalizeWidgetSelection(array(
			'id' => 'office-1',
			'indexTo' => '101000',
			'cityTo' => 'Москва',
			'addressTo' => 'ул. Мясницкая, 26',
			'pvzType' => 'russian_post'
		));

		self::assertSame('101000', $selection['index']);
		self::assertSame('office-1', $selection['office_id']);

		$this->expectException(RuntimeException::class);
		$delivery->normalizeWidgetSelection(array('indexTo' => 'bad'));
	}

	public function testFingerprintChangesWithAddressAndCart(): void {
		$address = array(
			'country_id' => 176,
			'zone_id' => 2761,
			'city' => 'Москва',
			'postcode' => '101000',
			'address_1' => 'ул. Мясницкая, 1',
			'address_2' => ''
		);
		$package = array('weight' => 200, 'length' => 15, 'width' => 10, 'height' => 5);
		$first = new RussianPostDelivery($this->registry(array(array('product_id' => 1, 'quantity' => 1, 'total' => 100.0))));
		$changedCart = new RussianPostDelivery($this->registry(array(array('product_id' => 1, 'quantity' => 2, 'total' => 200.0))));
		$changedAddress = $address;
		$changedAddress['address_1'] = 'ул. Мясницкая, 2';

		self::assertNotSame($first->getFingerprint($address, $package), $first->getFingerprint($changedAddress, $package));
		self::assertNotSame($first->getFingerprint($address, $package), $changedCart->getFingerprint($address, $package));
	}

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

	private function registry(array $products): object {
		$config = new class {
			public function get(string $key): mixed {
				return null;
			}
		};

		return new class($config, $products) {
			public function __construct(private readonly object $config, private readonly array $products) {
			}

			public function get(string $key): object {
				return array(
					'config' => $this->config,
					'cart' => new class($this->products) {
						public function __construct(private readonly array $products) {
						}

						public function getProducts(): array {
							return $this->products;
						}

						public function getSubTotal(): float {
							$total = 0.0;

							foreach ($this->products as $product) {
								$total += (float)($product['total'] ?? 0.0);
							}

							return $total;
						}
					},
					'weight' => new stdClass(),
					'length' => new stdClass(),
					'log' => new stdClass(),
				)[$key];
			}
		};
	}
}
