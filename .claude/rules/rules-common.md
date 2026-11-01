# rules-common.md — Universal Coding Standards

Language-agnostic rules that apply in every repo. Each repo's own `rules-<role>.md` adds stack-specific rules and wins on conflict for that repo.

Origin: adopted from the mes-workspace team's rules-common.md (rules 1–15). Onboarding asks whether to keep them. Edit them freely to match this team.


## 1. Strict Necessity (YAGNI/KISS)
Build only the code that the request needs. Do not add speculative classes or patterns. Do not add new files unless the task needs them. Move logic into a shared utility only when the same logic appears in two or more places.

- Bad:  A class or service that wraps one method call used in one place only
- Good: Write the logic inline. Move it to a shared utility only when it appears in 2+ places.

## 2. Function Length Limit
Keep each function to 40 lines or fewer. You may add up to 15 more lines, to a hard limit of 55 lines, only to finish one logic block. If the function still needs more than 55 lines, split it into named private methods.

- Bad:  An `init()` method that is 80 lines long
- Good: Split the logic into `_buildForm()`, `_bindEvents()`, and similar methods. Keep each method to 40 lines or fewer.

## 3. Argument Limits
Use 0, 1, or 2 arguments for each function. Never use 3 or more arguments. Do not wrap 3 or more arguments into one object or array only to lower the count — this hides the same complexity, it does not remove it. If a function needs 3 or more inputs, redesign it: split it into smaller functions, or resolve some inputs before the call.

**Exemption — class constructors and component setup calls:** a class constructor or component setup call (e.g. `Component.configure({...})`) may accept a single destructured options object, regardless of how many fields it holds or what those fields are (services, callbacks, config values, or plain data). This exemption is structural, not content-based — it applies because the call is a constructor/setup call, not because of what's inside the object. Standard functions and instance methods get no such exemption: wrapping 3+ real inputs into an object to pass the linter is a violation there, full stop.

**Note — this isn't a blank check on constructor size.** A constructor needing 6 or more fields is a signal the class may be doing too much (single-responsibility smell), not an argument-count violation. Treat it as a design conversation, not an auditable rule — audit agents should not flag constructors on field count alone.

**Scope note:** this exemption is tied strictly to the `constructor` language construct and explicit `.configure()`-style setup calls — not to naming conventions like `createX`/`makeX`. A function named `createX` taking a destructured multi-field object is a standard function under this rule, same as any other — if it needs 3+ real inputs, redesign it.

- Bad:  `function createEmployee(name, email, roleId, teamId)` — 4 arguments
- Bad:  `function createEmployee({ name, email, roleId, teamId })` — same 4 inputs, wrapped in one object; the function still depends on 4 pieces of data
- Bad:  `submitOrder({ orderId, customerId, items, notes })` on an instance method — plain data bundled to dodge the limit, not a constructor
- Good: `constructor({ service, onStatusChange, onError })` — DI-style constructor, exempt regardless of field content
- Good: `constructor({ record, files, schedules })` on a renderer — plain data, still exempt because it's a constructor
- Good: `DataGrid.configure({...})` — component setup call, same structural exemption

- Good (frontend):
  ```js
  class EmployeeForm {
    constructor(employee) {
      this._employee = employee
    }

    _resolveRole() {
      return this._roleService.getById(this._employee.roleId)
    }

    _resolveTeam() {
      return this._teamService.getById(this._employee.teamId)
    }

    submit() {
      this._employee.role = this._resolveRole()
      this._employee.team = this._resolveTeam()
      return this._employeeService.create(this._employee)
    }
  }
  ```
  Each method takes 0 or 1 argument. Role and team resolve as separate steps before `create()` is called with 1 argument.

- Good (backend):
  ```php
  class EmployeeService
  {
      public function create(Employee $employee): Employee
      {
          $employee->role_id = $this->resolveRole($employee)->id;
          $employee->team_id = $this->resolveTeam($employee)->id;
          return $employee;
      }

      private function resolveRole(Employee $employee): Role
      {
          return $this->roleRepository->find($employee->role_id);
      }

      private function resolveTeam(Employee $employee): Team
      {
          return $this->teamRepository->find($employee->team_id);
      }
  }
  ```
  Each method takes 1 argument. Role and team resolution live in their own methods instead of piling onto `create()`.

## 4. Action-Oriented Verbs
Start each function name with one precise action verb (e.g., `getActiveEmployees()`, `formatDate()`). Do not use vague verbs like `process()`, `handle()`, `manage()`, or `doStuff()`.

**Exception — Framework-mandated method names:** A framework contract or interface may require an exact method name. Do not rename these methods — the framework calls them by that exact name. Examples: Laravel `handle()` / `up()` / `down()`, React `render()`, Django `get_queryset()`, NestJS `canActivate()`.

- Bad:  `processEmployee()`, `handleSubmit()`, `manageQueue()`
- Good: `fetchEmployee()`, `submitForm()`, `clearQueue()`

## 5. Contextual Variables
Name each variable for its exact context (e.g., `$pendingEmployees`, `$loginResponse`). Do not use generic terms (`$data`, `$result`, `$tmp`, `$arr`). Do not use single letters, except `i` and `j` in simple loops.

- Bad:  `$data = $this->service->get(); $result = $data['items'];`
- Good: `$employeeList = $this->service->getAll(); $activeEmployees = $employeeList['items'];`

## 6. Interrogative Booleans
Start each boolean variable and boolean method name with `is`, `has`, or `can` (e.g., `$isActive`, `$hasPermission`, `$canEdit`).

- Bad:  `$active = true;` or `function superAdmin(): bool`
- Good: `$isActive = true;` or `function isSuperAdmin(): bool`

## 7. No Magic Values
Do not use inline magic numbers or strings for values with business meaning (status codes, permission slugs, header names) or values that repeat across the file. Declare these as named constants or Enums (e.g., `OrderStatus::CANCELLED`). A trivial, self-explanatory default used once (e.g. a pagination limit) does not need a constant.

- Bad:  `if ($status === 'admin.manage')` — inline permission slug
- Bad:  `split('/')[1]` — magic index
- Bad:  `headers: { 'X-Tenant-Code': tenantCode }` — magic header string
- Good: `TENANT_CODE_HEADER = 'X-Tenant-Code'` declared once, then `headers[TENANT_CODE_HEADER]`
- Good: `function paginate(limit = 15)` — trivial, self-explanatory, used once; no constant needed

## 8. Zero Nesting Policy
Do not nest a loop inside another loop. Do not nest an `if`/`else` statement inside another `if`/`else` statement. Keep loops and conditionals to 1 level of depth.

- Bad:
  ```js
  rows.forEach(row => {
    row.cells.forEach(cell => { // nested loop — violation
      if (cell.isActive) {
        if (cell.hasError) { } // nested if — violation
      }
    })
  })
  ```
- Good: Move the inner loop into a named method. Use guard clauses to flatten the conditions.

## 9. Guard Clauses & Early Returns
Flatten conditional logic with guard clauses. Do not wrap the primary logic in one large `if` block. Do not use an `else` block after a `return` statement.

- Bad:
  ```js
  function getLabel(item) {
    if (item) {
      if (item.isActive) {
        return item.label
      } else {
        return 'inactive'
      }
    }
  }
  ```
- Good:
  ```js
  function getLabel(item) {
    if (!item) return ''
    if (!item.isActive) return 'inactive'
    return item.label
  }
  ```

## 10. Fail Fast Validation
Validate each input at the entry point of the function. On invalid data, return early or throw an exception immediately. Do not nest callbacks; use `async`/`await` or sequential logic instead.

- Bad:
  ```js
  async function submitForm(data) {
    fetch('/api/submit', { body: JSON.stringify(data) }) // no validation, no await
      .then(res => res.json())
      .then(json => { if (json.ok) { ... } }) // nested callbacks
  }
  ```
- Good:
  ```js
  async function submitForm(data) {
    if (!data.name) return { success: false, message: 'Name required' }
    const res = await fetch('/api/submit', { body: JSON.stringify(data) })
    return await res.json()
  }
  ```

## 11. Contextual Comments Only
Do not write a comment that explains what the code does; clear names must do that job. Write a comment only to explain why (e.g., a non-obvious constraint, or a workaround for an external issue).

- Bad:  `// loop through employees and check permissions` — describes what
- Bad:  `// added for the edit flow` — references task context, not a constraint
- Good: `// bfcache restore — must register before DOMContentLoaded, bfcache pages skip that event`
- Good: `// dropdown has no blur event — document click is the only way to detect outside clicks`
- Good: `// ORM find() skips soft-deleted rows — include-deleted flag required here`

## 12. Clean Commits
Do not leave a `TODO` comment or commented-out code in a commit. Delete dead code immediately.

- Bad:  `// TODO: add validation`
- Bad:  `// const oldFetch = fetch(...)` — commented-out code
- Good: Delete dead code. Put task notes in the PR description, not in the source file.

## 13. File Boundaries
Keep each file to 500 lines or fewer, with one responsibility. You may add up to 50 more lines, to a hard limit of 550 lines, when you modify or create a class or file. If the file still needs more than 550 lines, split it by responsibility. Always put imports and dependencies at the top of the file.

**Exception — Test files:** A test file has no line limit. Tests carry setup and fixture boilerplate that each test case needs, and full coverage of one entity in one file reads better than the same coverage split across many small files. Split the file by concern (e.g. CRUD vs soft-delete vs authorization) when it grows hard to navigate — not on a fixed line count.

**Exception — Documentation files:** A documentation file (`AGENTS.md`, `README.md`, a rules file, any other `.md` file) has no line limit. This rule caps source code, not written documentation.

- Bad (frontend):  A single `app.js` with NavbarController + RouterController + SidebarController = 271 lines
- Good (frontend): Extract `SidebarController` to `controllers/SidebarController.js`; `app.js` drops to 177 lines
- Bad (backend):  A single `EmployeeController` with CRUD + report generation + CSV export logic = 480 lines
- Good (backend): Extract CSV export into `EmployeeExportService`; `EmployeeController` drops to 220 lines

## 14. Strict Debug Ban
Do not commit a debug statement. This includes `console.log()`, `debugger`, `print()` debugging, `dd()`, `dump()`, `var_dump()`, `fmt.Println` debugging, and the stack's equivalents — each repo's rules file lists its own.

- Bad:  `console.log('permissions:', perms)` — debug left in code
- Bad:  `dd($user)` — debug dump left in a controller
- Good: Remove all debug statements before committing. Use logging (rule 15) for runtime info.

## 15. Safe & Contextual Logging
Log an error only at the point where it is caught or detected — inside a `catch` block, or inside a failure branch that checks a result (e.g. `if (!result.success)`). Do not log inside a loop. Always pass a context object or array with the log. Never log sensitive data (passwords, tokens, PII).

- Bad:  `Log::info('Checking user ' . $user->email)` — inside a loop, logs PII
- Bad:  `Log::error($e->getMessage())` — no context, no structure
- Good (backend): `Log::error('Permission check failed', ['userId' => $user->id, 'slug' => $slug, 'reason' => $e->getMessage()])`
- Good (frontend): `Logger.error('Failed to save employee', { employeeId, reason: result.message })` — logged in the failure branch, not a raw `console.error`

---

## Self-Audit Checklist

- [ ] Builds only what the request needs. No speculative classes, patterns, or files.
- [ ] Each function ≤ 40 lines (hard limit 55, only to finish one logic block).
- [ ] Each function has 0–2 arguments. No 3+ inputs hidden in one object (constructors/setup calls exempt).
- [ ] Function names start with a precise action verb (framework-mandated names exempt).
- [ ] Variables named for their exact context — no `data`, `result`, `tmp`, `arr`.
- [ ] Booleans start with `is` / `has` / `can`.
- [ ] No inline magic values with business meaning or repetition.
- [ ] No nested loops; no nested if/else.
- [ ] Guard clauses; no `else` after `return`.
- [ ] Inputs validated at the entry point; fail fast.
- [ ] Comments explain why, not what.
- [ ] No TODOs, no commented-out code.
- [ ] Files ≤ 500 lines (hard 550), one responsibility, imports on top (tests and docs exempt).
- [ ] No debug statements.
- [ ] Errors logged with context, only where caught; never in loops; never sensitive data.

## Project-specific additions

Conventions that span more than one repo (decided 2026-10-16).

- **Scanner API contract is frozen.** The device-facing routes `POST /api/scan` (JSON `{"rfid"}`) and `POST /api/rfid/scan` (form `rfid=`), with their paths, encodings, UID format (uppercase hex bytes joined by `:`) and response bodies, never change unless the firmware (`rfid`) and the backend (`be`) change in the same ticket.
- **Realtime contract.** be and fe share public channel `rfid`, event `AssignRfid`, payload `message` = UID. Change both sides in the same ticket.
- **Branches.** Feature branches start from `dev` and are named by the lowercase ticket key (e.g. `mis-123`).
- **Secrets are never committed.** Each repo reads them from a gitignored file, `.env` (be, fe, mobile) or `mis/arduino_secrets.h` (rfid), and commits only the template (`.env.example`, `arduino_secrets.h.example`). Client-bundled vars (`VITE_*`, `EXPO_PUBLIC_*`) are public and never hold secrets.
