# CRM-STARCOMP

## Как работать с репозиторием

Рабочая CRM находится по адресу **https://crm.star-comp.ru/**.

- `index.html` — единственный рабочий файл CRM. Редактируйте его здесь, в GitHub.
- `api.php` — передаточник между страницей и MySQL. Тоже хранится здесь и заливается на хостинг автоматически.
- `db.php` — настройки подключения к базе с реальными паролями. Хранится **только на хостинге** и в репозиторий не добавляется. Шаблон — `db.example.php`.
- `legacy/` — старая версия на Supabase и страница выгрузки для неё. В деплое не участвует.

После коммита и `push` в ветку `main` GitHub Actions автоматически загружает `index.html` и `api.php` в папку `/www/crm.star-comp.ru/` на хостинг reg.ru.

GitHub Pages (`https://sirlondog.github.io/CRM_OPENCODE/`) нужен только для просмотра исходников: он перенаправляет на рабочую CRM. Статический GitHub Pages не может запускать PHP, поэтому не может работать с базой хостинга напрямую.

## База данных

Одна база — MySQL на хостинге. К ней обращается `api.php`, который лежит рядом с `index.html` на `crm.star-comp.ru`.

Данные склада и чеков хранятся в таблице `settings` (ключи `stock_items`, `stock_receipts`, `archive_dates`), заказы — в `orders`, детали — в `parts`, расходы — в таблице `expenses`.

Для защиты от одновременного редактирования заказа двумя пользователями в `orders` должна быть колонка `updated_at` (TIMESTAMP/DATETIME, обновляется при сохранении). Если колонки нет, добавьте, например:

```sql
ALTER TABLE orders ADD COLUMN updated_at DATETIME NULL DEFAULT NULL;
```

Таблица расходов (выполнить один раз):

```sql
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dt DATE NOT NULL,
  category VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

Резервная копия БД создаётся на сервере вне публичной папки:

```
/var/www/u2959673/data/crm_backups/
```

Дебаг-логи CRM пишутся на сервер по одному файлу на день:

```
/var/www/u2959673/data/crm_logs/YYYY-MM-DD.log
```

Открыть лог можно кнопкой 🐞 в CRM → «С сервера». Запись логов включается после входа в CRM.

## Важно

Никогда не добавляйте в репозиторий пароли SFTP и пароль базы данных. Они хранятся в GitHub Secrets и в файле `db.php` на хостинге.
