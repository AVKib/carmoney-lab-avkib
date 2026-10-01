# ДЗ.1 MILEAGE: фактические проверки

Дата: 01.10.2026. Ветка: `hw1/dz1-avkib`. Источники поведения — intent и spec.

## Среда и модели

Docker Desktop запущен; PHP 8.3.35, PHPUnit 11.5.56, зависимости из существующего
`composer.lock`. В образе есть PDO MySQL и PDO SQLite. Feature-тесты используют
SQLite in-memory; отдельная проверка поднятого сервиса должна подтвердить MySQL.

GLM 5.3 и MiniMax M3 в этой сессии недоступны и не запускались. План подготовлен
отдельным агентом planner. Использование моделей из задания не заявляется.
Модель отдельного судьи указана в его отчёте.

Исходная Docker-сборка не смогла загрузить Debian-пакеты через HTTP; проверка HTTPS
обнаружила отсутствие доверия к сетевому сертификату в контейнере. Для локальной
сборки использованы HTTPS и публичные корневые сертификаты, уже доверенные Windows.
TLS-проверка не отключалась. Изменения окружения находятся только в игнорируемых
`tmp/Dockerfile.hw1`, `tmp/hw1-ca-bundle.crt` и `docker-compose.override.yml`.
Production Dockerfile не изменён. Composer установил все 38 пакетов из lock;
во время проверки фильтра пакетов сообщил таймаут Packagist. Полнота этой сетевой
проверки не подтверждена; установленные зависимости и успешные прогоны — отдельные факты.

Каждая команда compose ниже передаёт пустой `tmp/compose-input` через `--env-file`,
чтобы не читать `.env*`. `scripts/reset_db.sh` не запускался.

## Исходный прогон

Новые классы MILEAGE уже подготовлены, но исключены из этого прогона; production
не изменён, setup старых классов ещё не адаптирован:

```bash
docker compose --env-file tmp/compose-input run --rm --no-deps backend \
  bash -lc 'vendor/bin/phpunit --colors=never --filter "/^(?!.*Mileage)/" && make lint'
```

Результат: `OK (26 tests, 34 assertions)`; `php -l: ошибок нет`; exit code `0`.

## Красный прогон до реализации

До production-кода добавлены три новых класса и только второй аргумент конструктора
в setup двух старых классов. Старые assertions, expected и payload сохранены.

Первый прогон обнаружил, что новый HTTP-тест ожидал целое `50`, а существующий
`Json::write` сохраняет дробную часть: `50.0`. Новый тест приведён к фактическому
JSON-контракту до фиксации красного коммита. Бизнес-ожидание не менялось.

```bash
docker compose --env-file tmp/compose-input run --rm --no-deps backend make test
```

Повторный результат перед коммитом:

```text
PHPUnit 11.5.56; PHP 8.3.35
Tests: 97, Assertions: 195, Failures: 11.
make: *** [Makefile:35: test] Error 1
```

Exit code команды make/compose: `2`. Все 11 провалов — assertions:
ожидалось `review`, получено `approve`. Это отсутствие правила для `400001` и
`500000`, а также отсутствие чтения тестового порога `100000`; ошибок среды,
драйвера SQLite и сигнатур в этом прогоне нет. Production diff пуст.

Красный коммит: `8c5bb41f9aad93c9ca7f132999a6aa6246ccb869`.

## Зелёный прогон и неизменность тестов

Зелёный коммит: `83df046899c287677cf0895d057f92a8f96d1dc3`. Изменены только четыре
production-файла из плана; новый порог `400000` добавлен из принятого intent.
Существующие пороги, лимиты, формулы и валидация не изменены.

```bash
docker compose --env-file tmp/compose-input run --rm --no-deps backend \
  bash -lc 'make test && make lint'
git diff 8c5bb41..83df046 -- tests
git rev-parse 8c5bb41:tests
git rev-parse 83df046:tests
git diff --check
```

Результат: `OK (97 tests, 206 assertions)`; `php -l: ошибок нет`; exit code `0`.
Diff каталога тестов пуст; Git tree обоих коммитов совпадает:
`32c889fefa372e0e08f3b3b196ece571f54ee8f9`. Тесты после красного коммита не менялись.
`git diff --check` прошёл.

## Поднятый сервис и браузер

Запуск через
`docker compose --env-file tmp/compose-input up -d --build` соответствует рецепту
`make up`; локального make на Windows нет. `make test` и `make lint` выше
выполнены буквально внутри PHP-контейнера.

Команда запуска завершилась с exit code `0`. `docker compose ... ps` подтверждает:
backend работает на `http://localhost:8080`, MySQL 8.0 — `healthy`.
`GET /health` вернул HTTP 200 и `status = ok`.

На поднятом сервисе проверены `POST /api/ltv`:

| Пробег | LTV 50% | LTV 75% | LTV 95% |
|---|---|---|---|
| 399999 | approve, лимит 450000 | review, лимит 0 | reject, лимит 0 |
| 400000 | approve, лимит 450000 | review, лимит 0 | reject, лимит 0 |
| 400001 | review, лимит 0 | review, лимит 0 | reject, лимит 0 |

Все девять ответов — HTTP 200. Пробег `500000` при LTV 50% также дал HTTP 200,
`review`, лимит `0`. Отсутствующее поле, `null`, `-1` и `500001` дали HTTP 422 и
существующую ошибку `Пробег от 0 до 500000 км`.

Форма проверена автоматически через Playwright 1.63.0 и установленный Chrome
154.0.8037.58, desktop viewport 1280×960. Это браузерная проверка агента;
человеческий ручной просмотр не заявляется. Мобильный сценарий ДЗ.2 не выполнялся.

Синтетическая заявка `CL-HW1-SYNTHETIC`: VIN из тестовой фикстуры, год 2022,
пробег 400001, стоимость 900000, сумма 450000, срок 24 месяца. Нажатие
«Отправить заявку» вызвало `POST /api/applications`: HTTP 201, id `25`,
`decision = review`, LTV `50.0`, `approved_limit = 0`. Форма показала `review`,
`50 %`, `0 ₽`, номер `25`; блок ошибок скрыт, JavaScript page errors отсутствуют.
`GET /api/applications/25` подтвердил сохранённые в MySQL пробег 400001,
решение `review` и лимит `0`.

Скриншот: [mileage_review.png](mileage_review.png). Локальный проверочный скрипт
и JSON-вывод сохранены в игнорируемых `tmp/check-mileage.cjs` и `tmp/live-checks.json`.
Frontend, схема БД, seed и валидатор не менялись.
