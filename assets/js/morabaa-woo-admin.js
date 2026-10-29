(function ($) {
	$(function () {
		var busyTimer = null;
		var busyProgress = 0;
		var busyStepIndex = 0;
		var busySteps = [];

		function ensureBusyOverlay() {
			var $overlay = $('#morabaa-progress-overlay');
			if ($overlay.length) {
				return $overlay;
			}

			$overlay = $(
				'<div id="morabaa-progress-overlay" class="morabaa-progress-overlay" style="display:none;">' +
					'<div class="morabaa-progress-card">' +
						'<div class="morabaa-progress-title">جاري تنفيذ الطلب...</div>' +
						'<div class="morabaa-progress-text">يرجى الانتظار، لا تغلق الصفحة الآن.</div>' +
						'<div class="morabaa-progress-bar"><span class="morabaa-progress-bar-fill"></span></div>' +
						'<div class="morabaa-progress-percent">0%</div>' +
						'<div class="morabaa-progress-step">بدء العملية...</div>' +
					'</div>' +
				'</div>'
			);

			$('body').append($overlay);
			return $overlay;
		}

		function getBusySteps(actionName, labelText) {
			var label = (labelText || '').toString();
			var action = (actionName || '').toString();

			if (action === 'manual_sync') {
				return ['جاري الاتصال بـ Morabaa...', 'جاري سحب بيانات المنتجات...', 'جاري مطابقة المنتجات المرتبطة...', 'جاري تحديث الاسم والسعر والمخزون...'];
			}
			if (action === 'refresh_items_cache') {
				return ['جاري تحديث الكاش...', 'جاري تحميل دفعات المنتجات من Morabaa...', 'جاري تجهيز النتائج للعرض...'];
			}
			if (action === 'import_items') {
				return ['جاري قراءة المنتجات المحددة...', 'جاري إنشاء المنتجات في WooCommerce...', 'جاري ربطها وحفظ البيانات...'];
			}
			if (action === 'pull_status') {
				return ['جاري فحص حالة الربط...', 'جاري مقارنة المنتجات...', 'جاري تحديث الملخص...'];
			}
			if (action === 'link_all_by_sku' || action === 'link_all_exact' || action === 'link_pair') {
				return ['جاري فحص المطابقات...', 'جاري تنفيذ الربط...', 'جاري حفظ النتيجة...'];
			}
			if (label.indexOf('تحميل') !== -1 || label.indexOf('عرض') !== -1) {
				return ['جاري تحميل البيانات...', 'جاري تجهيز القائمة...', 'جاري عرض النتائج...'];
			}
			return ['جاري تنفيذ الطلب...', 'جاري معالجة البيانات...', 'جاري إنهاء العملية...'];
		}

		function getBusyHint(actionName) {
			var action = (actionName || '').toString();
			if (action === 'manual_sync' || action === 'refresh_items_cache' || action === 'import_items' || action === 'page_load') {
				return 'إذا كان عدد المنتجات كبيراً، فقد تستغرق العملية بعض الوقت. هذا طبيعي ولا يعني أن الموقع توقف.';
			}
			return 'يرجى الانتظار، لا تغلق الصفحة الآن.';
		}

		function stopBusyOverlay(finalText) {
			var $overlay = $('#morabaa-progress-overlay');
			if (busyTimer) {
				clearInterval(busyTimer);
				busyTimer = null;
			}

			if (!$overlay.length) {
				$('body').removeClass('morabaa-page-busy');
				return;
			}

			if (finalText) {
				$overlay.find('.morabaa-progress-step').text(finalText);
				$overlay.find('.morabaa-progress-percent').text('100%');
				$overlay.find('.morabaa-progress-bar-fill').css('width', '100%');
			}

			setTimeout(function () {
				$overlay.fadeOut(180, function () {
					$('body').removeClass('morabaa-page-busy');
				});
			}, finalText ? 350 : 0);
		}

		function startBusyOverlay(actionName, labelText) {
			var $overlay = ensureBusyOverlay();
			busySteps = getBusySteps(actionName, labelText);
			busyProgress = 6;
			busyStepIndex = 0;

			$overlay.find('.morabaa-progress-title').text(labelText || 'جاري تنفيذ الطلب...');
			$overlay.find('.morabaa-progress-text').text(getBusyHint(actionName));
			$overlay.find('.morabaa-progress-step').text(busySteps[0] || 'بدء العملية...');
			$overlay.find('.morabaa-progress-percent').text(busyProgress + '%');
			$overlay.find('.morabaa-progress-bar-fill').css('width', busyProgress + '%');
			$('body').addClass('morabaa-page-busy');
			$overlay.stop(true, true).fadeIn(120);

			if (busyTimer) {
				clearInterval(busyTimer);
			}

			busyTimer = setInterval(function () {
				if (busyProgress < 92) {
					busyProgress += (busyProgress < 40 ? 9 : (busyProgress < 70 ? 6 : 3));
					busyProgress = Math.min(92, busyProgress);
				}

				if (busyStepIndex < busySteps.length - 1 && busyProgress >= (busyStepIndex + 1) * 28) {
					busyStepIndex++;
				}

				$overlay.find('.morabaa-progress-step').text(busySteps[busyStepIndex] || 'جاري المعالجة...');
				$overlay.find('.morabaa-progress-percent').text(busyProgress + '%');
				$overlay.find('.morabaa-progress-bar-fill').css('width', busyProgress + '%');
			}, 700);
		}

		function setBusyOverlayProgress(percent, stepText, hintText) {
			var $overlay = ensureBusyOverlay();
			var safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
			$overlay.find('.morabaa-progress-percent').text(safePercent + '%');
			$overlay.find('.morabaa-progress-bar-fill').css('width', safePercent + '%');
			if (stepText) {
				$overlay.find('.morabaa-progress-step').text(stepText);
			}
			if (hintText) {
				$overlay.find('.morabaa-progress-text').text(hintText);
			}
		}

		function renderTopNotice(message, isSuccess) {
			var $wrap = $('.morabaa-woo-wrap').first();
			if (!$wrap.length || !message) return;

			$wrap.find('.morabaa-dynamic-notice').remove();
			var cls = isSuccess ? 'morabaa-notice-success' : 'morabaa-notice-error';
			var icon = isSuccess ? 'dashicons-yes-alt' : 'dashicons-warning';
			var $notice = $('<div class="morabaa-notice morabaa-dynamic-notice ' + cls + '"><span class="dashicons ' + icon + '"></span><span>' + escapeHtml(message) + '</span></div>');
			$wrap.find('.morabaa-tabs').after($notice);
		}

		$(window).on('pageshow load', function () {
			stopBusyOverlay();
		});

		$(document).on('click', '.morabaa-woo-wrap button[type="submit"], .morabaa-woo-wrap input[type="submit"]', function () {
			var $btn = $(this);
			var $form = $btn.closest('form');
			var labelText = ($btn.attr('data-loading-label') || $.trim($btn.clone().children().remove().end().text()) || $btn.val() || 'جاري تنفيذ الطلب...').toString();

			if ($form.length) {
				$form.data('morabaa-submit-label', labelText);
			}

			setTimeout(function () {
				$btn.addClass('is-loading');
			}, 10);
		});

		$('form').on('submit', function () {
			var $form = $(this);
			if (!$form.closest('.morabaa-woo-wrap').length) {
				return;
			}
			if ($form.hasClass('morabaa-barcode-diagnose-form')) {
				return;
			}

			var actionName = ($form.find('input[name="morabaa_action"]').val() || '').toString();
			var labelText = ($form.data('morabaa-submit-label') || 'جاري تنفيذ الطلب...').toString();
			startBusyOverlay(actionName, labelText);
		});

		$('form').on('submit', function (e) {
			var $form = $(this);
			if (!$form.closest('.morabaa-woo-wrap').length) {
				return;
			}

			var actionName = ($form.find('input[name="morabaa_action"]').val() || '').toString();
			if (actionName !== 'manual_sync' || !window.morabaaWooAdmin || !morabaaWooAdmin.ajaxUrl || !morabaaWooAdmin.syncNonce) {
				return;
			}

			e.preventDefault();
			startBusyOverlay('manual_sync', $form.data('morabaa-submit-label') || 'جاري تنفيذ المزامنة اليدوية على دفعات');
			setBusyOverlayProgress(3, 'بدء المزامنة اليدوية...', 'تجري المزامنة على دفعات حقيقية مع عدّاد تقدم حتى لا تشعر أن الصفحة توقفت.');

			var runBatch = function (offset, updated, matched) {
				$.ajax({
					url: morabaaWooAdmin.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'morabaa_manual_sync_batch',
						nonce: morabaaWooAdmin.syncNonce,
						offset: offset || 0,
						updated: updated || 0,
						matched: matched || 0,
						batchSize: 40
					}
				})
					.done(function (resp) {
						if (!(resp && resp.success && resp.data)) {
							var failMsg = (resp && resp.data && resp.data.message) ? resp.data.message : (morabaaWooAdmin.syncLoadError || 'تعذر إكمال المزامنة حالياً. حاول مرة أخرى.');
							stopBusyOverlay();
							renderTopNotice(failMsg, false);
							return;
						}

						setBusyOverlayProgress(resp.data.progress || 0, resp.data.step || 'جاري المزامنة...', resp.data.hint || '');

						if (resp.data.complete) {
							stopBusyOverlay('اكتملت المزامنة بنجاح');
							renderTopNotice(resp.data.message || 'اكتملت المزامنة اليدوية بنجاح.', true);
							setTimeout(function () {
								window.location.reload();
							}, 900);
							return;
						}

						runBatch(resp.data.nextOffset || 0, resp.data.updated || 0, resp.data.matched || 0);
					})
					.fail(function () {
						stopBusyOverlay();
						renderTopNotice(morabaaWooAdmin.syncLoadError || 'تعذر إكمال المزامنة حالياً. حاول مرة أخرى.', false);
					});
			};

			runBatch(0, 0, 0);
		});

		$('a.morabaa-button, .morabaa-tabs a.nav-tab').on('click', function () {
			var $link = $(this);
			if ($link.is('[data-toggle-target]')) {
				return;
			}

			var href = ($link.attr('href') || '').toString();
			if (!href || href.indexOf('page=morabaa-woo-sync') === -1) {
				return;
			}

			startBusyOverlay('page_load', $.trim($link.text()) || 'جاري تحميل البيانات...');
		});

		function escapeHtml(str) {
			return (str || '').toString()
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function renderBarcodeDiagNotice(message, isSuccess, samples) {
			var $box = $('#morabaa-barcode-diagnose-result');
			if (!$box.length) return;
			$box.removeClass('morabaa-notice-success morabaa-notice-error');
			$box.addClass(isSuccess ? 'morabaa-notice-success' : 'morabaa-notice-error');

			var html = '<span class="dashicons ' + (isSuccess ? 'dashicons-yes-alt' : 'dashicons-warning') + '"></span><span>' + escapeHtml(message) + '</span>';

			$box.html(html);
			$box.show();
		}

		$('.morabaa-barcode-diagnose-form').on('submit', function (e) {
			if (!window.morabaaWooAdmin || !morabaaWooAdmin.ajaxUrl || !morabaaWooAdmin.barcodeNonce) {
				return;
			}

			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('.morabaa-barcode-diagnose-btn');
			$btn.addClass('is-loading');
			startBusyOverlay('barcode_diagnose', $.trim($btn.text()) || 'فحص الباركود من السيرفر');

			$.ajax({
				url: morabaaWooAdmin.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'morabaa_barcode_diagnose',
					nonce: morabaaWooAdmin.barcodeNonce
				}
			})
				.done(function (resp) {
					if (resp && resp.success && resp.data && resp.data.message) {
						renderBarcodeDiagNotice(resp.data.message, true, resp.data.samples || []);
						return;
					}
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : (morabaaWooAdmin.diagLoadError || 'تعذر إكمال فحص الباركود حالياً. حاول مرة أخرى.');
					renderBarcodeDiagNotice(msg, false, (resp && resp.data && resp.data.samples) ? resp.data.samples : []);
				})
				.fail(function () {
					renderBarcodeDiagNotice(morabaaWooAdmin.diagLoadError || 'تعذر إكمال فحص الباركود حالياً. حاول مرة أخرى.', false, []);
				})
				.always(function () {
					$btn.removeClass('is-loading');
					stopBusyOverlay('اكتمل فحص الباركود');
				});
		});

		function toggleAllCheckboxes(source) {
			var checked = $(source).is(':checked');
			var $form = $(source).closest('form');
			if ($form.length) {
				$form.find('.morabaa-item-checkbox, #morabaa-select-all, #morabaa-select-all-header').prop('checked', checked);
				return;
			}
			$('.morabaa-item-checkbox, #morabaa-select-all, #morabaa-select-all-header').prop('checked', checked);
		}

		$('#morabaa-select-all, #morabaa-select-all-header').on('change', function () {
			toggleAllCheckboxes(this);
		});

		$('[data-toggle-target]').on('click', function () {
			var target = $(this).attr('data-toggle-target');
			if (!target) return;
			$(target).slideToggle(140);
		});

		$('.js-load-more-table').each(function (index) {
			var $table = $(this);
			var $rows = $table.find('tbody tr');
			if ($rows.length <= 1) return;

			var initialRows = parseInt($table.attr('data-initial-rows'), 10) || 250;
			var stepRows = parseInt($table.attr('data-step-rows'), 10) || initialRows;

			var visible = initialRows;
			$rows.each(function (i) {
				if (i >= visible) {
					$(this).hide().addClass('morabaa-hidden-row');
				}
			});

			if ($rows.length <= visible) return;

			var btnId = 'morabaa-load-more-' + index;
			var $btnWrap = $('<div class="morabaa-load-more-wrap"></div>');
			var $btn = $('<button type="button" class="morabaa-button morabaa-button-secondary" id="' + btnId + '"><span class="dashicons dashicons-arrow-down-alt2"></span><span>تحميل المزيد</span></button>');
			$btnWrap.append($btn);
			$table.closest('.morabaa-table-wrapper').after($btnWrap);

			var loadNextBatch = function () {
				visible += stepRows;
				$rows.each(function (i) {
					if (i < visible) {
						$(this).show().removeClass('morabaa-hidden-row');
					}
				});
				if (visible >= $rows.length) {
					$btnWrap.remove();
				}
			};

			$btn.on('click', function () {
				loadNextBatch();
			});
		});

		$('.morabaa-live-search').on('input', function () {
			var $input = $(this);
			if (($input.attr('data-remote-search') || '').toString() === 'import') {
				return;
			}

			var q = ($input.val() || '').toString().trim().toLowerCase();
			var $scope = $input.closest('.morabaa-card');
			var $table = $input.nextAll('.morabaa-table-wrapper').first().find('table.morabaa-table').first();
			if (!$table.length) {
				$table = $scope.find('table.morabaa-table').first();
			}
			if (!$table.length) return;

			var $rows = $table.find('tbody tr');
			$rows.each(function () {
				var $row = $(this);
				if ($row.find('.morabaa-table-empty').length) return;

				var text = $row.text().toLowerCase();
				var match = q === '' || text.indexOf(q) !== -1;
				$row.toggle(match);
			});
		});

		function initRemoteImportSearch() {
			var $input = $('#morabaa-import-search');
			var $table = $('#morabaa-import-table');
			var $status = $('#morabaa-import-search-status');
			if (!$input.length || !$table.length || !$status.length || !window.morabaaWooAdmin || !morabaaWooAdmin.ajaxUrl || !morabaaWooAdmin.searchNonce) {
				return;
			}

			var $tbody = $table.find('tbody');
			var initialHtml = $tbody.html();
			var initialStatus = $status.text();
			var timer = null;
			var xhr = null;

			function renderRows(rows) {
				if (!rows || !rows.length) {
					$tbody.html('<tr><td colspan="6" class="morabaa-table-empty">لا توجد نتائج مطابقة حالياً.</td></tr>');
					return;
				}

				var html = '';
				rows.forEach(function (row) {
					var value = row.item_id ? row.item_id : (row.row_key || '');
					html += '<tr>' +
						'<td><input type="checkbox" class="morabaa-checkbox morabaa-item-checkbox" name="morabaa_import_items[]" value="' + escapeHtml(value) + '" /></td>' +
						'<td>' + escapeHtml(row.item_id ? row.item_id : '—') + '</td>' +
						'<td>' + escapeHtml(row.name ? row.name : '—') + '</td>' +
						'<td>' + escapeHtml(row.sku ? row.sku : '—') + '</td>' +
						'<td>' + escapeHtml((row.price || row.price === 0) ? row.price : '—') + '</td>' +
						'<td>' + escapeHtml((row.stock || row.stock === 0) ? row.stock : '0') + '</td>' +
					'</tr>';
				});
				$tbody.html(html);
			}

			$input.on('input', function () {
				var query = ($input.val() || '').toString().trim();
				if (timer) {
					clearTimeout(timer);
				}
				if (xhr && xhr.readyState !== 4) {
					xhr.abort();
				}

				if (!query) {
					$tbody.html(initialHtml);
					$status.removeClass('is-loading').text(initialStatus);
					return;
				}

				$status.addClass('is-loading').text(morabaaWooAdmin.searchingText || 'جاري البحث في كل المنتجات غير المرتبطة...');
				timer = setTimeout(function () {
					startBusyOverlay('page_load', 'جاري البحث في كل المنتجات غير المرتبطة');
					setBusyOverlayProgress(15, 'جاري فحص جميع النتائج المخزنة...', 'لا حاجة للتنقل بين الصفحات، يتم البحث في كل المنتجات غير المرتبطة الآن.');

					xhr = $.ajax({
						url: morabaaWooAdmin.ajaxUrl,
						type: 'POST',
						dataType: 'json',
						data: {
							action: 'morabaa_import_search',
							nonce: morabaaWooAdmin.searchNonce,
							query: query
						}
					})
						.done(function (resp) {
							if (resp && resp.success && resp.data) {
								renderRows(resp.data.rows || []);
								$status.removeClass('is-loading').text(resp.data.message || 'اكتمل البحث.');
								stopBusyOverlay('اكتمل البحث');
								return;
							}

							$status.removeClass('is-loading').text((resp && resp.data && resp.data.message) ? resp.data.message : (morabaaWooAdmin.searchLoadError || 'تعذر إكمال البحث حالياً. حاول مرة أخرى.'));
							renderRows([]);
							stopBusyOverlay();
						})
						.fail(function (jqXHR, textStatus) {
							if (textStatus === 'abort') {
								return;
							}
							$status.removeClass('is-loading').text(morabaaWooAdmin.searchLoadError || 'تعذر إكمال البحث حالياً. حاول مرة أخرى.');
							renderRows([]);
							stopBusyOverlay();
						})
						.always(function () {
							xhr = null;
						});
				}, 350);
			});
		}

		initRemoteImportSearch();

		function formatRemainingTime(seconds) {
			var total = Math.max(0, parseInt(seconds, 10) || 0);
			var days = Math.floor(total / 86400);
			var hours = Math.floor((total % 86400) / 3600);
			var minutes = Math.floor((total % 3600) / 60);
			var secs = total % 60;

			var hh = String(hours).padStart(2, '0');
			var mm = String(minutes).padStart(2, '0');
			var ss = String(secs).padStart(2, '0');

			if (days > 0) {
				return days + 'd ' + hh + ':' + mm + ':' + ss;
			}

			return hh + ':' + mm + ':' + ss;
		}

		function initCronCountdown() {
			var $countdowns = $('.morabaa-cron-next[data-next-cron-ts]');
			if (!$countdowns.length) return;

			var tick = function () {
				var now = Math.floor(Date.now() / 1000);
				$countdowns.each(function () {
					var $box = $(this);
					var target = parseInt($box.attr('data-next-cron-ts'), 10) || 0;
					var $label = $box.find('.js-morabaa-cron-remaining');
					if (!$label.length || !target) return;

					var diff = target - now;
					if (diff <= 0) {
						$box.addClass('is-due');
						$label.text('جاهز للتنفيذ الآن');
						return;
					}

					$box.removeClass('is-due');
					$label.text('متبقي: ' + formatRemainingTime(diff));
				});
			};

			tick();
			setInterval(tick, 1000);
		}

		initCronCountdown();

		var $backTop = $('<button type="button" class="morabaa-back-top" title="العودة للأعلى"><span class="dashicons dashicons-arrow-up-alt2"></span></button>');
		$('body').append($backTop);

		$(window).on('scroll', function () {
			if ($(window).scrollTop() > 350) {
				$backTop.addClass('is-visible');
			} else {
				$backTop.removeClass('is-visible');
			}
		});

		$backTop.on('click', function () {
			$('html, body').animate({ scrollTop: 0 }, 240);
		});
	});
})(jQuery);

