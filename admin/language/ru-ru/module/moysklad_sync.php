<?php
// Heading
$_['heading_title'] = 'Синхронизация с МойСклад';

// Text
$_['text_extension'] = 'Расширения';
$_['text_success'] = 'Настройки модуля успешно сохранены.';
$_['text_edit'] = 'Настройки синхронизации с МойСклад';
$_['text_enabled'] = 'Включено';
$_['text_disabled'] = 'Отключено';
$_['text_tab_settings'] = 'Настройки';
$_['text_tab_sync'] = 'Синхронизация';
$_['text_tab_logs'] = 'Логи';
$_['text_placeholder_stage'] = 'Кнопки запускают пошаговые задачи. Импорт категорий, базовых товаров и точных остатков по выбранному складу уже подключен. Изображения будут добавлены отдельным этапом.';
$_['text_action_none'] = 'Ничего не делать';
$_['text_action_disable'] = 'Отключать';
$_['text_action_delete'] = 'Удалять';
$_['text_seo_new_only'] = 'Генерировать только для новых товаров и категорий';
$_['text_log_error_warning'] = 'Ошибки и предупреждения';
$_['text_log_info'] = 'Информационный';
$_['text_log_debug'] = 'Отладочный';
$_['text_no_logs'] = 'Логи появятся после запуска задач.';
$_['text_connection_success'] = 'Подключение к МойСклад успешно проверено.';
$_['text_dictionaries_loaded'] = 'Справочники МойСклад успешно загружены.';
$_['text_select_warehouse'] = 'Выберите склад';
$_['text_select_price_type'] = 'Выберите тип цены';
$_['text_current_value'] = 'Текущее значение';
$_['text_loading'] = 'Загрузка...';

// Entry
$_['entry_status'] = 'Статус модуля';
$_['entry_api_token'] = 'API-токен МойСклад';
$_['entry_warehouse_id'] = 'Склад МойСклад';
$_['entry_price_type_id'] = 'Тип цены';
$_['entry_missing_product_action'] = 'Если товара нет в МойСклад';
$_['entry_missing_category_action'] = 'Если категории нет в МойСклад';
$_['entry_zero_stock_action'] = 'Если остаток товара 0';
$_['entry_category_batch_size'] = 'Батч категорий';
$_['entry_product_batch_size'] = 'Батч товаров';
$_['entry_stock_batch_size'] = 'Батч остатков';
$_['entry_image_batch_size'] = 'Батч изображений';
$_['entry_max_images_per_product'] = 'Макс. изображений на товар за проход';
$_['entry_max_image_bytes'] = 'Макс. размер изображения, байт';
$_['entry_log_level'] = 'Уровень логирования';
$_['entry_seo_mode'] = 'SEO URL';
$_['entry_clear_empty_description'] = 'Очищать описание, если в МойСклад пусто';

// Help
$_['help_api_token'] = 'Токен будет сохранен в настройках модуля. После сохранения он не выводится обратно в форму.';
$_['help_warehouse_id'] = 'Нажмите «Загрузить склады и типы цен», затем выберите один склад. Остатки будут считаться только по нему.';
$_['help_price_type_id'] = 'Выберите тип цены продажи из МойСклад. Эту цену будем записывать в стандартное поле цены товара ocStore.';
$_['help_batches'] = 'Для слабого сервера лучше оставлять небольшие значения. Рекомендации: категории 30, товары 20, остатки 50, изображения 3.';
$_['help_images_limits'] = 'Ограничения защищают слабый сервер от слишком тяжелой загрузки картинок. Рекомендуемые значения: 5 изображений на товар, 10485760 байт на файл.';
$_['help_dictionaries'] = 'Сначала введите API-токен. Можно загрузить справочники до сохранения токена.';

// Buttons
$_['button_save'] = 'Сохранить';
$_['button_back'] = 'Назад';
$_['button_import'] = 'Импорт товаров';
$_['button_stock'] = 'Обновить остатки';
$_['button_images'] = 'Загрузить картинки';
$_['button_test_connection'] = 'Проверить подключение';
$_['button_load_dictionaries'] = 'Загрузить склады и типы цен';

// Error
$_['error_permission'] = 'У вас нет прав на изменение модуля синхронизации с МойСклад.';
$_['error_batch_category'] = 'Батч категорий должен быть от 1 до 500.';
$_['error_batch_product'] = 'Батч товаров должен быть от 1 до 200.';
$_['error_batch_stock'] = 'Батч остатков должен быть от 1 до 500.';
$_['error_batch_image'] = 'Батч изображений должен быть от 1 до 50.';
$_['error_max_images_per_product'] = 'Максимум изображений на товар должен быть от 1 до 20.';
$_['error_max_image_bytes'] = 'Максимальный размер изображения должен быть от 1048576 до 31457280 байт.';
$_['error_unexpected'] = 'Непредвиденная ошибка:';

// Task texts
$_['text_task_created'] = 'Задача создана. Запускаю пошаговое выполнение.';
$_['text_task_step_done'] = 'Шаг задачи выполнен.';
$_['text_task_stopped'] = 'Задача остановлена.';
$_['text_task_panel_empty'] = 'Активных задач пока нет.';
$_['text_task_panel_title'] = 'Текущая задача';
$_['text_task_type_import'] = 'Импорт товаров';
$_['text_task_type_stock'] = 'Обновление остатков';
$_['text_task_type_images'] = 'Загрузка изображений';
$_['text_task_status_new'] = 'Создана';
$_['text_task_status_running'] = 'Выполняется';
$_['text_task_status_paused'] = 'На паузе';
$_['text_task_status_finished'] = 'Завершена';
$_['text_task_status_finished_with_errors'] = 'Завершена с ошибками';
$_['text_task_status_failed'] = 'Ошибка';
$_['text_task_status_stopped'] = 'Остановлена';
$_['text_step_init'] = 'Инициализация';
$_['text_step_sync_categories'] = 'Синхронизация категорий';
$_['text_step_rebuild_category_tree'] = 'Построение дерева категорий';
$_['text_step_sync_products'] = 'Синхронизация товаров';
$_['text_step_missing_categories'] = 'Обработка отсутствующих категорий';
$_['text_step_missing_products'] = 'Обработка отсутствующих товаров';
$_['text_step_sync_stock'] = 'Обновление остатков';
$_['text_step_sync_images'] = 'Загрузка изображений';
$_['text_step_finish'] = 'Завершение';
$_['text_logs_latest'] = 'Последние события';
$_['text_counter_processed'] = 'Обработано';
$_['text_counter_created'] = 'Создано';
$_['text_counter_updated'] = 'Обновлено';
$_['text_counter_skipped'] = 'Пропущено';
$_['text_counter_disabled'] = 'Отключено';
$_['text_counter_deleted'] = 'Удалено';
$_['text_counter_errors'] = 'Ошибок';
$_['text_stage3_notice'] = 'Этап 7: импорт категорий, базовый импорт товаров, точное обновление остатков и отдельная загрузка изображений уже подключены. Изображения скачиваются только по кнопке “Загрузить картинки” маленькими пакетами.';

// Task buttons
$_['button_stop_task'] = 'Остановить задачу';
$_['button_refresh_status'] = 'Обновить статус';

// Task errors
$_['error_no_active_task'] = 'Нет активной задачи.';
$_['error_task_locked'] = 'Задача уже выполняется другим запросом. Повторите через несколько секунд.';
$_['error_task_failed'] = 'Задача завершилась с ошибкой:';
$_['error_module_disabled'] = 'Модуль отключен. Включите его в настройках и сохраните.';
$_['error_api_token_required'] = 'Укажите API-токен МойСклад и сохраните настройки.';
$_['error_warehouse_required'] = 'Выберите склад МойСклад и сохраните настройки.';
$_['error_price_type_required'] = 'Выберите тип цены МойСклад и сохраните настройки.';
$_['error_unknown_ajax_action'] = 'Неизвестное AJAX-действие:';
