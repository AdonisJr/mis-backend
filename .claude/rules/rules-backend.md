# rules-backend.md — mis-backend (Laravel 12, backend)

<!-- Refreshed 2026-11-27 from wiki/raw/repo-profiles/be.rules-draft.md (repo-analyzer, branch mis-29) merged with the 2026-10-16 onboarding rules: the RFID scanner contract and the MySQL-dev migrate lesson are kept verbatim. Every rule cites a file, or says "framework default" or "decided <date>". Edit freely, and keep the citations. -->

Binding for every agent and human working in this repo. `rules-common.md` applies too. Where they conflict, this file wins for this repo.

---

## 1. Stack

- Language / runtime: PHP ^8.2 (`composer.json`)
- Framework: Laravel ^12.0 with Sanctum ^4.0 (`composer.json`, `config/sanctum.php`)
- Package manager: composer
- Database: PostgreSQL is the target (`.env.example` `DB_CONNECTION=pgsql`, `AGENTS.md` L15); the local dev database is MySQL (MIS-21 lesson, §3). Tests run on SQLite `:memory:` (`phpunit.xml`). Queue, cache and session are database-backed (`.env.example`).
- Money math: `Brick\Math\BigDecimal` (`app/Support/*`). It arrives through `laravel/framework`; declaring `brick/math` directly in `composer.json` is an open follow-up.
- App time zone: `Asia/Manila`; JSON timestamps are UTC ISO-8601 (`AGENTS.md` Work Guidance)
- Commands:
  - dev: `php artisan serve --host=0.0.0.0 --port=8000`
  - test: `php artisan test`
  - lint: `./vendor/bin/pint` (`--test` to check)
  - build: n/a
  - migrate: `php artisan migrate`
  - seed: `php artisan db:seed` (needs `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD`)
  - Sources: `CLAUDE.md`, `AGENTS.md` Work Guidance

## 2. Layer Chain (build order)

1. migration
2. model
3. factory
4. support? (optional — `app/Support`, used by every ticket since MIS-18)
5. request
6. resource? (optional)
7. controller
8. route
9. test

| Layer | Folder | Naming | Base / Wiring | Example |
|---|---|---|---|---|
| migration | `database/migrations` | `YYYY_MM_DD_HHMMSS_<verb>_<table>_table.php`; dated after the tables it references | anonymous `extends Migration`, `up()`/`down()`, explicit `restrictOnDelete`/cascade | `database/migrations/2026_11_26_110000_create_journal_entries_table.php` |
| model | `app/Models` | singular PascalCase (`SchoolClass` with `$table = 'classes'` for a reserved word) | `extends Model`, `HasFactory`, `$fillable`, `$attributes`, `casts()`, constants, `scopeX` | `app/Models/FeeItem.php` |
| factory | `database/factories` | `<Model>Factory` | `extends Factory<Model>`, patterned unique values, named states | `database/factories/FeeItemFactory.php` |
| support? | `app/Support` | noun + role (`*Builder`, `*Calculator`, `*Allocator`, `*Posting`, `*Finder`) | plain class, constructor inputs, `build()` returning arrays | `app/Support/TrialBalanceBuilder.php` |
| request | `app/Http/Requests` | `<Verb><Model>Request` (`List`, `Store`, `Update`, `Show`, action verbs); Update may extend Store | `extends FormRequest`, `authorize()` true, `rules()`, `after()` | `app/Http/Requests/StoreFeeItemRequest.php` |
| resource? | `app/Http/Resources` | `<Model>Resource`, `<Model>DetailResource`, `<Model>RowResource` | `extends JsonResource`, explicit key list | `app/Http/Resources/FeeItemResource.php` |
| controller | `app/Http/Controllers` | `<Model>Controller`, plus `<Model><Action>Controller` for a side flow (`FeeScheduleCopyController`) | `extends Controller`; verb-named methods; `SearchesPaginatedLists` for lists | `app/Http/Controllers/FeeItemController.php` |
| route | `routes/api.php` | `Route::<verb>('/kebab-path', [X::class, 'method'])->whereNumber('id')` | inside `Route::prefix('mis')` + `auth:sanctum` + `role:` group | `routes/api.php` |
| test | `tests/Feature`, `tests/Unit` | `<Area><Aspect>Test` (`Crud`, `Access`, `List`, `Rules`, `Status`), `test_<snake_case>` | `extends Tests\TestCase`, `RefreshDatabase`, `InteractsWith*` trait | `tests/Feature/FeeItemCrudTest.php` |

## 3. Layer Rules

- **migration:** every schema change goes in a new migration; never edit one that has run (framework default).
  - Name every multi-column index, unique and foreign key explicitly when the generated `<table>_<cols>_<type>` name could exceed 63 characters. MySQL (dev) allows 64 and Postgres (prod) 63, and the SQLite tests allow any length, so a long name passes the tests and then breaks `migrate` (MIS-21 `curriculum_entries`, fixed 2026-11-22).
  - Before handing off a ticket with migrations, run `php artisan migrate` on the dev MySQL database, not only the tests.
  - Uniqueness that ignores case is enforced in the request, not with an index (`AGENTS.md` fee_items, rooms, subjects).
- **model:**
  - Declare `$fillable` and `casts()`. Never use `$guarded = []` (`rules-backend.md` §3).
  - Keep state flags (`is_active`, `status`) out of `$fillable` and change them only through dedicated endpoints with `forceFill()` (`app/Models/FeeItem.php`, `FeeItemController::saveActiveState`).
  - Enumerations are model constants, used by requests through `Rule::in(Model::X)` (`app/Models/FeeItem.php`, `StoreFeeItemRequest.php`).
  - Relations carry generic docblocks (`@return HasMany<X, $this>`).
- **support?:** put domain computation shared across controllers, or too big for one, in `app/Support`. Examples: assessment calculation, grading, posting, reports (`AGENTS.md` Child DOX Index).
- **request:**
  - Every endpoint with input or query params has a FormRequest. Inline `$request->validate()` is not used in `app/` (grep).
  - `authorize()` returns true, because roles come from the route group.
  - A row-level refusal returns 403 `{message}` through `Concerns/RefusesWithMessage` (`app/Http/Requests/Concerns/RefusesWithMessage.php`).
  - Cross-row checks go in `after()` callables. Messages are class constants that tests reuse (`StoreFeeItemRequest.php`, `FeeItemCrudTest.php`).
- **resource?:** list the keys explicitly. Tests assert the exact key order (`FeeItemResource.php`, `FeeItemCrudTest::FEE_ITEM_KEYS`).
- **controller:**
  - Thin: take the validated request, call Eloquent or a Support class, and return `response()->json(['<snake_key>' => Resource])` (`FeeItemController.php`, `FinancialReportController.php`).
  - Look rows up with `findOrFail($id)`, with `int $id` route params.
  - Multi-row writes go inside `DB::transaction(fn, 3)` with a consistent lock order (`AGENTS.md` rollover/fee schedules; 26 files in `app/`).
- **route:**
  - Every route points to a controller method; no closures (`rules-backend.md` §3).
  - Every admin route lives under `Route::prefix('mis')` in the right `Role::*_ROLES` group. Add a new role set as a `Role` constant, not as an inline list (`routes/api.php`, `app/Models/Role.php`).
  - Numeric params use `->whereNumber()` (`routes/api.php`).
- **RFID flow (decided 2026-10-16)** — routes, payloads, response bodies and behaviour are identical to `enrollment/mis-be`, with exactly these deliberate deviations:
  - `/api/scan` resolves the **active school year** instead of hardcoding `school_year_id = 1`.
  - `students.rfid` is a nullable string with a **unique** index.
  - The api middleware group **forces `Accept: application/json`** (the firmware sends no Accept header).
- **Build order for RFID (decided 2026-10-16)** — attendance (`POST /api/scan`) and the relay (`POST /api/rfid/scan`) come first. Card→student assignment (open/close window, `PUT /api/mis/students/rfid/{id}`) is built later.
  - Not built yet (no scanner routes in `routes/api.php` as of mis-29).

## 4. Data & Validation

- Where input is validated: FormRequests (`app/Http/Requests`). A UID is uppercase hex bytes joined by `:` (4, 7 or 10 bytes) (`AGENTS.md` L24).
- Where persistence happens: Eloquent in controllers and `app/Support` classes.
- Response / error shape:
  - JSON always (`ForceJsonResponse`).
  - Success uses named keys: 200, 201 on create, 204 on delete.
  - Errors: 422 `{message, errors}`, 403/404 `{message}`.
  - Status precedence: 401 → 403 → 422 → 404 (`AGENTS.md` endpoint notes).
- Money: exact `BigDecimal` math, serialised as 2-decimal strings (`AGENTS.md` fee_schedules).
- Pagination: lists use `paginate()` (15 per page) with `meta {current_page, last_page, per_page, total}` from `SearchesPaginatedLists` (`app/Http/Controllers/Concerns/SearchesPaginatedLists.php`). Documented exceptions return all rows: school years, grade levels, curriculum entries, `*/active` pickers (`AGENTS.md` L77).
- Search: word-split, case-insensitive `LOWER(col) LIKE ?` across `SEARCH_COLUMNS` (`SearchesPaginatedLists.php`).
- Scanner responses match the reference exactly (decided 2026-10-16):
  - `POST /api/scan` → 200 `{student, attendance}` or 404 `{message}` (`enrollment/mis-be/app/Http/Controllers/AttendanceController.php` L26–30, L61–64).
  - `POST /api/rfid/scan` → 200 / 403 `{status, message}` (`enrollment/mis-be/app/Http/Controllers/RfidController.php` L16–21, L27–30).
  - The firmware only prints responses to Serial; it never parses them.
- Scanner contract (fixed by the firmware; decided 2026-10-16 identical to `enrollment/mis-be/routes/api.php` L19–20):
  - `POST /api/scan` — JSON `{"rfid"}` — **attendance** (yellow LED ON). Find the Student by `rfid` (404 if none). First scan of the day creates `student_attendances` with `time_in` and `status=present`; later scans that day set `time_out`.
  - `POST /api/rfid/scan` — form-encoded `rfid=` — **card-assignment relay** (yellow LED OFF). Returns 403 unless cache key `rfid_channel_opened` is set (set by `/api/mis/rfid/open`, 300 s TTL). Otherwise broadcasts `AssignRfid` (payload `message` = UID) on channel `rfid`.
  - Port 8000, plain HTTP, public (no auth) routes.
- RFID storage: `students.rfid`, string, nullable, unique (decided 2026-10-16; column name from `enrollment/mis-be/database/migrations/2025_07_29_141001_create_students_table.php` L24).

## 5. Cross-cutting

- Auth: Sanctum bearer tokens from `POST /api/mis/login`. Inactive users cannot log in (`AGENTS.md` L26, L39). Scanner routes will be public (`rules-backend.md` §5).
- Authorisation / permissions: `role:` → `RoleMiddleware` (any-match, 403 `{"message":"Unauthorized"}`), with groups from `Role::EMPLOYEE_ROLES`, `CLASS_LIST_ROLES`, `STAFF_ROLES`, `REGISTRY_ROLES`, `BILLING_READ_ROLES`, `PAYMENT_WRITE_ROLES`, `ACCOUNTING_ROLES` and `role:admin` (`app/Models/Role.php`, `routes/api.php`). Row-level access lives in requests and Support classes (`app/Support/ClassListAccess.php`). There are no Policies.
- Scoping / tenancy: none. Data is scoped by school year and term FKs and the active flags (`AGENTS.md`).
- Broadcasting: Pusher, public channel `rfid`, event `AssignRfid` (decided 2026-10-16: kept as in the reference; a private channel is reconsidered when assignment is built). Not installed yet (`.env.example` `BROADCAST_CONNECTION=log`).
- Push notifications: on attendance scans the backend sends a push to the student's/parents' devices through the **Expo push service** (decided 2026-10-16; needs an EAS project and FCM credentials later).
- Students and parents get login accounts with Sanctum tokens for mis-mobile; the scanner routes stay public for now, and a device token is reconsidered when assignment is built (decided 2026-10-16).
- Logging: `Log::` with a context array where an error is caught; never tokens or passwords (`rules-common.md` §15). No call sites exist yet.
- Config / secrets: never hardcode. Read with `config()` from `config/*.php` backed by `.env`; never call `env()` outside `config/` (`AGENTS.md` Work Guidance). Seed credentials come from `config('auth.seed_admin.*')`.

## 6. Tests

- Framework: PHPUnit 11, `php artisan test`, SQLite `:memory:` (`phpunit.xml`).
- Location & naming:
  - `tests/Feature/<Area><Aspect>Test.php` for HTTP; `tests/Unit` for pure logic (`GradeCalculatorTest`).
  - Shared fixtures go in `tests/Feature/Concerns/InteractsWith<Area>.php`.
  - Cross-repo parity fixtures go in `tests/Fixtures/`.
- Required per change:
  - Every new endpoint gets a success test and a validation-failure test (`AGENTS.md` Work Guidance).
  - Add an `*AccessTest` covering guest 401 and each excluded role's 403.
  - When a route is added, update the route counts in `TeacherAccessTest` / `AccountantAccessTest` (`AGENTS.md` L20).
  - Resources get an exact key-list assertion.
  - Scanner endpoints are tested with the firmware encoding and no Accept header.
- Fixtures / factories: one factory per model with patterned unique values and named states (`database/factories/*`), plus `RefreshDatabase`.

## 7. Prohibitions

- Do not change scanner paths, encodings, response bodies or behaviour without a firmware change in the same ticket (`rules-common.md` project additions).
- Do not use Postgres-only SQL without a note; tests run on SQLite (`phpunit.xml`). Use `LOWER(...)` on both sides for case-insensitive matching (`SearchesPaginatedLists.php`).
- No logic in route closures (`rules-backend.md` §3).
- No `$guarded = []` (`rules-backend.md` §3).
- No float math on money; use `BigDecimal` (`app/Support/*`).
- No `env()` outside `config/`, and no committed `.env` or credentials (`AGENTS.md`, `rules-common.md`).
- Do not remove the unused frontend scaffolding (`resources/`, `vite.config.js`, `package.json`, welcome view) (`AGENTS.md` Work Guidance).
- No `dd()`, `dump()`, `var_dump()`, `print_r()` or `ray()` (`rules-common.md` §14).

## 8. Self-Audit Checklist

Run on every touched file before finishing.

- [ ] Layers present in order: migration, model, factory, (support), request, (resource), controller, route, test
- [ ] Route sits in the correct `Role::*_ROLES` group under `/api/mis`, with `whereNumber` on ids
- [ ] Input is validated in a FormRequest; errors are JSON; status precedence is 401 → 403 → 422 → 404
- [ ] Status flags change only via `forceFill`; enumerations use model constants
- [ ] Multi-row writes use `DB::transaction(..., 3)`; money uses `BigDecimal` and 2-decimal strings
- [ ] Lists are paginated with `meta`, or the exception is documented in `AGENTS.md`
- [ ] No Postgres-only SQL; long index/FK names set explicitly
- [ ] Feature tests cover success, validation and access; the access-test route counts are updated
- [ ] `php artisan test` passes; `./vendor/bin/pint --test` is clean; `php artisan route:list` shows the routes
- [ ] `AGENTS.md` endpoint/entity notes and `.claude/diagrams/be-*` are updated (`be-developer.md` step 12)
- [ ] Scanner routes, payloads and response bodies unchanged vs `enrollment/mis-be`; attendance uses the active school year
- [ ] Migrations also run on the dev MySQL database (`php artisan migrate`)
- [ ] No secrets, hardcoded hosts or debug statements
