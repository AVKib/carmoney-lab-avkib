# План: пробег ≤ 400 000 км, иначе `review` (MILEAGE)

Контекст: сейчас `ApplicationValidator` отсекает пробег вне `0..max_mileage_km` (500000)
как ошибку валидации — заявка даже не доходит до `DecisionEngine`. Внутри
`DecisionEngine::decide(float $ltv)` пробег не учитывается. Нужно добавить правило:
если пробег > порога — решение не выше `review` (т.е. не `approve`).

## 1) Файлы — что и где меняем (по строке на файл)

- `backend/config/rules.php:20-24` — добавить в блок `vehicle` ключ `review_max_mileage_km` со
  значением `400000` (числа в конфиге, согласно `docs/setup/code_map.md:67-71` и конвенциям).
- `backend/src/Domain/DecisionEngine.php:20-28` — добавить поле `private readonly int $reviewMaxMileage;`
  и принимать его в массиве порогов (`['approve_max','review_max','review_max_mileage']`).
- `backend/src/Domain/DecisionEngine.php:30-41` — расширить сигнатуру до
  `decide(float $ltv, int $mileage): string`; если `mileage > $this->reviewMaxMileage`,
  вернуть `self::REVIEW` (ограничитель поверх LTV-логики).
- `backend/src/AppFactory.php:37` — в `new DecisionEngine($rules['ltv'])` заменить на
  `new DecisionEngine($rules['ltv'] + ['review_max_mileage' => $rules['vehicle']['review_max_mileage_km']])`
  или передать отдельной картой, чтобы порог пробега пришёл из `vehicle`.
- `backend/src/Domain/AssessmentService.php:33` — в вызов
  `$this->decisionEngine->decide($ltv)` добавить второй аргумент `$input['mileage']`
  (поле уже есть в нормализованном выходе валидатора, см. `ApplicationValidator.php:78`).
- `tests/Unit/DecisionEngineTest.php:17` — расширить `setUp()`: в пороги добавить
  `'review_max_mileage' => 400000`. Текущий data provider `ltvValues()` (строки 27–37)
  остаётся — он не передаёт mileage, значит все его случаи должны давать тот же
  результат, что и раньше (mileage=0 ниже порога, ограничитель не сработает). Чтобы
  не править сигнатуру старых кейсов, ввести второй data provider `mileageValues()`
  с ключевыми 399999 / 400000 / 400001 и комбинациями с LTV.
- `tests/Unit/AssessmentServiceTest.php:27` — в `setUp()` пробросить тот же порог в
  `DecisionEngine`. В фикстуре `payload()` (строка 33) оставить `mileage: 96000` —
  он не должен ломать существующие три теста. Добавить отдельные тесты на пробег.
- `docs/setup/code_map.md:42-74` (опционально, после реализации) — обновить раздел
  «Куда встанет правило»: правило уже встало, описать фактическое размещение.

Ничего не меняем в:

- `backend/src/Domain/ApplicationValidator.php` — существующая проверка
  `0..max_mileage_km` остаётся как есть (см. AGENTS.md: «существующая проверка
  max_mileage_km не трогать ради зелёного теста»).
- `backend/src/Repository/ApplicationRepository.php`, `db/schema.sql`, `db/seed.sql` —
  колонка `mileage_km` уже есть, тип `INT UNSIGNED`, диапазон остаётся валидационным.
- `frontend/*` — форма уже шлёт `mileage` как число, дополнительной валидации/сообщения
  на клиенте не требуется (сообщение об `review` приходит из ответа сервиса).

## 2) Шаги реализации по порядку

1. Добавить в `backend/config/rules.php:23-24` (новой строкой после `max_mileage_km`)
   `'review_max_mileage_km' => 400000`.
2. В `backend/src/Domain/DecisionEngine.php`:
   - добавить `private readonly int $reviewMaxMileage;`,
   - в конструкторе (строки 24–28) читать `$thresholds['review_max_mileage']`,
   - сменить сигнатуру `decide(float $ltv): string` → `decide(float $ltv, int $mileage): string`,
   - в начале метода вставить ограничитель: `if ($mileage > $this->reviewMaxMileage) return self::REVIEW;`.
3. В `backend/src/AppFactory.php:37` пробросить новый порог из `rules['vehicle']` в
   `DecisionEngine` (сохранив LTV-пороги из `rules['ltv']`).
4. В `backend/src/Domain/AssessmentService.php:33` передать `$input['mileage']` вторым
   аргументом в `decide()`.
5. Обновить `tests/Unit/DecisionEngineTest.php`:
   - в `setUp()` дополнить пороги `'review_max_mileage' => 400000`;
   - в `ltvValues()` все вызовы `decide($ltv)` заменить на `decide($ltv, 0)` — это
     не меняет поведения (mileage=0 ниже порога);
   - добавить data provider `mileageAtBoundary()` с кейсами:
     `399999` (при любом LTV, дающем `approve`/`reject`, не должен понижаться до `review`
     только из-за mileage), `400000` (граничный — должно сохраниться LTV-решение),
     `400001` (должно стать `review` даже при LTV<60).
6. Обновить `tests/Unit/AssessmentServiceTest.php`:
   - в `setUp()` (строка 27) пробросить `review_max_mileage` в `DecisionEngine`;
   - добавить тесты:
     - `mileage_just_below_threshold_keeps_approve` — `mileage=399999`, низкий LTV → `approve`;
     - `mileage_at_threshold_keeps_approve` — `mileage=400000`, низкий LTV → `approve`;
     - `mileage_above_threshold_demotes_to_review` — `mileage=400001`, низкий LTV → `review`;
     - `mileage_above_threshold_cannot_promote_reject_to_approve` — `mileage=400001`, очень
       низкий LTV (например, 10%) → `review` (не `approve`);
     - `empty_mileage_is_rejected_by_validator` — `mileage` отсутствует в payload →
       `ValidationException` с `errors['mileage']` (поле всё ещё обязательно,
       валидатор уже трактует отсутствие как `-1` и бросает исключение — это и есть
       «пустой пробег»).
7. Запустить `make test` и `make lint`. Если падают ранее зелёные тесты —
   остановиться, не править пороги/формулы ради зелёного прогона (AGENTS.md).

## 3) Тесты — ключевые кейсы

Граничные значения (требование AC-MILEAGE-01 в `docs/spec/README.md:8`):

- `mileage = 399999` — решение по LTV не меняется: низкий LTV → `approve`, высокий →
  `review`/`reject`. mileage ниже порога не должен вмешиваться.
- `mileage = 400000` — граница принадлежит «зелёной» зоне пробега: LTV-логика
  работает как раньше. Низкий LTV → `approve`.
- `mileage = 400001` — превышение порога: при любом LTV, который без mileage-правила
  дал бы `approve`, должно получиться `review`. При LTV, который без mileage дал бы
  `reject`, остаётся `reject` (review хуже approve, но не лучше reject — это
  ограничитель, а не понижатель).
- `mileage` отсутствует в payload — `ValidationException`, до `DecisionEngine` не
  доходим (это контракт существующего валидатора, фиксируем тестом).

Покрытие по слоям:

- Unit `DecisionEngineTest`: чистая логика `decide($ltv, $mileage)` с провайдерами
  данных (LTV × mileage).
- Unit `AssessmentServiceTest`: сквозной сценарий через валидатор — убеждаемся, что
  `mileage` корректно доезжает до `DecisionEngine` и что пустой пробег
  отбрасывается валидатором.

## 4) Риски — что может сломаться и что не входит

Что может сломаться:

- **Существующая проверка `max_mileage_km = 500000`** (`ApplicationValidator.php:44`)
  остаётся как есть. Риск — соблазн «унифицировать» пороги или понизить
  `max_mileage_km` до 400000, чтобы новый порог был единственным. Так делать
  нельзя: `max_mileage_km` — это верхняя граница допустимого ввода (валидация),
  а `review_max_mileage_km` — это порог ужесточения решения. Они про разное. Если
  их слить — заявки с пробегом 400001..500000 начнут падать как «некорректные»
  вместо того, чтобы идти в `review`, а это меняет бизнес-контракт.
- **Сигнатура `DecisionEngine::decide()`** — все существующие вызовы должны быть
  обновлены. В репозитории прямых вызовов, кроме `AssessmentService.php:33`, нет
  (см. `grep "decisionEngine->decide"`), но стоит прогнать `make test`.
- **Обратная совместимость решений.** До правки при `LTV<60` и `mileage=400001`
  заявка получала `approve`. После — `review`. Это и есть требуемое поведение,
  но любые существующие тесты/фикстуры/seed-данные с пробегом > 400000 и низким
  LTV могут начать падать. Перед коммитом прогнать весь `make test`.
- **Тип `mileage` в `vehicles.mileage_km INT UNSIGNED`** (`db/schema.sql:22`):
  значения до 500000 помещаются, новый порог 400000 БД не задевает. Риск — только
  если кто-то решит добавить CHECK-ограничение в схему, но это вне задачи.
- **Логика «ограничитель, а не понижатель»**: при `LTV>85` и `mileage>400000`
  ожидаем `reject`, а не `review`. Это нужно явно зафиксировать тестом, иначе
  легко ошибочно реализовать правило как «всегда `review` при превышении пробега»
  и сломать ожидания риск-менеджмента.

Что не входит (out of scope):

- Любые правки `max_mileage_km`, фронтенда, схемы БД, README, клиентских
  материалов в `docs/sources/`.
- Лимит суммы по `ltv_by_age` (задача LOAN-12, `code_map.md:39-40`).
- Тексты ошибок валидатора и сообщения на фронте — пользовательский пробег > 400000
  не должен вызывать ошибку валидации, это именно решение `review`.
- Сравнение пробега с паспортными данными / одометром (см. `docs/sources/client_note.md:40`)
  — это разные числа и разные источники; данная задача — только про пробег из payload.

## Вопросы заказчику (без ответа продолжать нельзя)

1. **Граница: «≤ 400 000» vs «< 400 000».** В задаче сказано «не больше 400 000» — это
   включительно или строго меньше? План сейчас трактует «≤ 400 000» (граничное
   значение 400000 не понижается до `review`). Подтвердить.
2. **«Иначе review» — это потолок или приговор?** Трактовка плана: `mileage > 400000`
   понижает решение максимум до `review`, но не отменяет `reject` (при `LTV>85`
   остаётся `reject`). Если бизнес-смысл — «при превышении пробега всегда `review`,
   даже если LTV слишком высокий» — это другая реализация и другой набор тестов.
3. **Один порог или ступенчатая шкала?** Возможно, при `mileage>400000` заявка должна
   сразу идти в `reject`, а не в `review`, или наоборот — должны быть две зоны
   (например, 400001..450000 → `review`, > 450000 → `reject`). В текущем ТЗ этого нет.
4. **Источник числа 400 000.** В `docs/sources/client_note.md:24` фигурирует диапазон
   ~380 000…412 300 по одометру, и там же (строка 40) сказано, что одометр и
   паспортные данные могут расходиться. Правило применяется к какому пробегу — к
   тому, что клиент прислал в заявке, или к верифицированному? План сейчас —
   к полю payload. Подтвердить.
5. **Должно ли новое правило попадать в `approved_limit`.** Сейчас лимит = запрошенная
   сумма только при `approve`; при `review` он 0. Соответствует ли это бизнес-смыслу
   «не выдавать автоматический лимит при пробеге > 400 000», или при `review` по
   пробегу лимит должен остаться как при `approve` (расчёт человеком)?
6. **Влияет ли правило на уже сохранённые заявки в БД.** В `vehicles.mileage_km`
   могут лежать значения 400001..500000 с уже выданным `decision='approve'`. Делать
   ли переоценку исторических записей, или правило только для новых заявок?