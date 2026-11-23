'use strict';
(function(window, document, $) {
	const STATES = Object.freeze({
		IDLE: 'idle',
		LOADING_SCRIPT: 'loading-script',
		WIDGET_READY: 'widget-ready',
		SAVING_SELECTION: 'saving-selection',
		SELECTED: 'selected',
		ERROR: 'error'
	});
	const SCRIPT_URL = 'https://widget.pochta.ru/map/widget/widget.js';
	const SCRIPT_TIMEOUT_MS = 15000;
	const endpoints = {
		params: 'index.php?route=extension/shipping/russian_post/params',
		select: 'index.php?route=extension/shipping/russian_post/select',
		reset: 'index.php?route=extension/shipping/russian_post/reset',
		shippingMethods: 'index.php?route=checkout/shipping_method'
	};
	let scriptPromise = null;

	function transition(box, state, message) {
		const status = box.find('[data-russian-post-status]');
		box.attr('data-state', state);
		box.attr('aria-busy', state === STATES.LOADING_SCRIPT || state === STATES.SAVING_SELECTION ? 'true' : 'false');
		status.text(message || '');
		status.prop('hidden', !message);
		box.find('[data-russian-post-retry]').prop('hidden', state !== STATES.ERROR);
	}

	function ensureBox(input) {
		let box = $('#russian-post-widget-wrap');

		if (box.length) {
			return box;
		}

		box = $('<div id="russian-post-widget-wrap" class="russian-post-widget" data-state="idle" aria-busy="false">' +
			'<div class="russian-post-widget__status" data-russian-post-status role="status" aria-live="polite" hidden></div>' +
			'<button class="russian-post-widget__retry" type="button" data-russian-post-retry hidden>Повторить загрузку</button>' +
			'<div class="russian-post-widget__map" id="russian-post-widget"></div>' +
			'</div>');
		input.closest('.shipping-method-card, .radio').after(box);
		input.closest('.panel').addClass('russian-post-widget-active');

		return box;
	}

	function loadWidgetScript() {
		if (typeof window.ecomStartWidget === 'function') {
			return Promise.resolve();
		}

		if (scriptPromise) {
			return scriptPromise;
		}

		scriptPromise = new Promise(function(resolve, reject) {
			let script = document.querySelector('script[data-russian-post-widget]');
			const timeout = window.setTimeout(function() {
				if (script) script.remove();
				scriptPromise = null;
				reject(new Error('Время загрузки карты Почты России истекло.'));
			}, SCRIPT_TIMEOUT_MS);

			function complete(error) {
				window.clearTimeout(timeout);

				if (error || typeof window.ecomStartWidget !== 'function') {
					if (script) script.remove();
					scriptPromise = null;
					reject(error || new Error('Карта Почты России не запустилась.'));
					return;
				}

				resolve();
			}

			if (!script) {
				script = document.createElement('script');
				script.src = SCRIPT_URL;
				script.async = true;
				script.setAttribute('data-russian-post-widget', '');
				document.head.appendChild(script);
			}

			script.addEventListener('load', function() { complete(null); }, {once: true});
			script.addEventListener('error', function() { complete(new Error('Не удалось загрузить карту Почты России.')); }, {once: true});
		});

		return scriptPromise;
	}

	function selectedMessage(selected) {
		if (!selected || !selected.index) {
			return '';
		}

		return 'Выбрано отделение ' + selected.index + (selected.address ? ', ' + selected.address : '');
	}

	function refreshShippingMethods(box) {
		$.get(endpoints.shippingMethods).done(function(html) {
			const panel = $('#collapse-shipping-method .panel-body');
			const method = 'input[name="shipping_method"]';
			const commentField = 'textarea[name="comment"]';
			const selectedMethod = panel.find(method + ':checked').val();
			const comment = panel.find(commentField).val();

			panel.html(html);
			panel.find(method).filter(function() {
				return this.value === selectedMethod;
			}).prop('checked', true);
			if (comment !== undefined) {
				panel.find(commentField).val(comment);
			}
		}).fail(function() {
			transition(box, STATES.ERROR, 'Отделение сохранено, но варианты доставки не обновились. Повторите загрузку.');
		});
	}

	function saveSelection(box, selection) {
		transition(box, STATES.SAVING_SELECTION, 'Сохраняем выбранное отделение...');
		$.ajax({
			url: endpoints.select,
			type: 'post',
			dataType: 'json',
			data: {selection: JSON.stringify(selection)}
		}).done(function(result) {
			if (!result || result.error) {
				transition(box, STATES.ERROR, result && result.error ? result.error : 'Не удалось сохранить отделение Почты России.');
				return;
			}

			transition(box, STATES.SELECTED, selectedMessage(result.selected));
			refreshShippingMethods(box);
		}).fail(function() {
			transition(box, STATES.ERROR, 'Не удалось сохранить отделение Почты России. Проверьте соединение и повторите попытку.');
		});
	}

	function startWidget(box, params) {
		box.find('#russian-post-widget').empty();

		try {
			window.ecomStartWidget($.extend({}, params, {
				callbackFunction: function(selection) {
					saveSelection(box, selection);
				},
				containerId: 'russian-post-widget'
			}));
			transition(box, params.selected ? STATES.SELECTED : STATES.WIDGET_READY, selectedMessage(params.selected));
		} catch (error) {
			transition(box, STATES.ERROR, 'Карта Почты России не запустилась. Повторите попытку.');
		}
	}

	function showWidget(force) {
		const input = $('input[value="russian_post.office"]');

		if (!input.length || !input.is(':checked')) {
			return;
		}

		const box = ensureBox(input);
		const state = box.attr('data-state');

		if (!force && state && state !== STATES.IDLE) {
			return;
		}

		transition(box, STATES.LOADING_SCRIPT, 'Загружаем карту отделений Почты России...');
		$.getJSON(endpoints.params).done(function(params) {
			if (!params || params.error) {
				transition(box, STATES.ERROR, params && params.error ? params.error : 'Не удалось подготовить карту Почты России.');
				return;
			}

			loadWidgetScript().then(function() {
				startWidget(box, params);
			}).catch(function(error) {
				transition(box, STATES.ERROR, error.message || 'Не удалось загрузить карту Почты России.');
			});
		}).fail(function() {
			transition(box, STATES.ERROR, 'Не удалось получить параметры карты Почты России. Проверьте соединение и повторите попытку.');
		});
	}

	function resetSelection() {
		const box = $('#russian-post-widget-wrap');
		transition(box, STATES.IDLE, '');
		box.remove();
		$.post(endpoints.reset);
	}

	function bind() {
		const input = $('input[value="russian_post.office"]');

		if (!input.length) {
			$('#russian-post-widget-wrap').remove();
			return;
		}

		if (input.is(':checked')) {
			showWidget(false);
		}
	}

	$(document)
		.off('change.russianPost', 'input[name="shipping_method"]')
		.on('change.russianPost', 'input[name="shipping_method"]', function() {
			if (this.value === 'russian_post.office' && this.checked) {
				showWidget(false);
			} else {
				$('#russian-post-widget-wrap').remove();
			}
		})
		.off('ajaxComplete.russianPost')
		.on('ajaxComplete.russianPost', bind)
		.off('click.russianPostRetry', '[data-russian-post-retry]')
		.on('click.russianPostRetry', '[data-russian-post-retry]', function() { showWidget(true); })
		.off('change.russianPostReset', '#collapse-payment-address :input, #collapse-shipping-address :input, #collapse-checkout-option :input, .cart-item input[name^="quantity"]')
		.on('change.russianPostReset', '#collapse-payment-address :input, #collapse-shipping-address :input, #collapse-checkout-option :input, .cart-item input[name^="quantity"]', function(event) {
			if (event.originalEvent) {
				resetSelection();
			}
		})
		.off('click.russianPostReset', '[data-cart-remove], [data-voucher-remove]')
		.on('click.russianPostReset', '[data-cart-remove], [data-voucher-remove]', resetSelection);

	$(bind);
})(window, document, window.jQuery);
