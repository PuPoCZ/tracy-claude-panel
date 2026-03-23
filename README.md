# Tracy Claude Panel

Tracy BlueScreen panel that formats errors for AI-assisted debugging. One-click error export to [Claude Code](https://claude.ai/code) and other AI coding assistants.

![Panel screenshot](https://img.shields.io/badge/Tracy-BlueScreen_Panel-blue)

## What it does

When a PHP error occurs, this panel appears on Tracy's BlueScreen with a **"Copy for Claude"** button. One click copies a structured error summary to your clipboard, ready to paste into Claude Code or any AI assistant.

**Example output:**

```
Error: Warning — Undefined variable $person
URL: GET http://localhost:8080/admin/employee/5
Presenter: User:Admin:EmployeeDetail:default
File: app/Modules/User/Presentation/Admin/EmployeeDetail/default.latte:42

Source:
   38:     <div class="grid grid-cols-2 gap-4">
   39:         <div>
   40:             <dt>Employee ID</dt>
   41:             <dd>{$employee->getNumber()}</dd>
 → 42:         {if $person->getOffice()}
   43:             <dt>Office</dt>
   44:             <dd>{$person->getOffice()}</dd>
   45:         </div>
   46:     </div>

Stack (app only):
  app/Shared/Components/admin-card.latte:50 LatteTemplate::main()
  app/Modules/User/Presentation/Admin/EmployeeDetail/default.latte:42 LatteTemplate::renderBlock()
```

## Features

- **Error info** — type, message, file, line number
- **URL + HTTP method** — `GET http://localhost:8080/admin/users`
- **Nette presenter:action** — `User:Admin:EmployeeDetail:default` (auto-detected via Application event)
- **Latte template resolution** — maps compiled PHP back to original `.latte` file and line
- **Source code snippet** — shows code around the error with `→` marker
- **Doctrine SQL** — extracts query + parameters from DBAL exceptions
- **Filtered stack trace** — only your app files, no vendor noise
- **Chained exceptions** — shows the full cause chain

## Installation

```bash
composer require --dev pupocz/tracy-claude-panel
```

### Nette Framework (zero-config)

That's it. The package auto-registers via Nette DI extension. It only activates in debug mode.

### Standalone Tracy (without Nette)

Register manually in your bootstrap:

```php
use PuPoC\TracyClaudePanel\ClaudeBlueScreenPanel;

// After Tracy::enable()
ClaudeBlueScreenPanel::register(__DIR__ . '/app');
```

The argument is the absolute path to your application source directory. Files outside this directory are filtered from the stack trace.

### Nette — presenter:action detection

Presenter detection works automatically with the DI extension. For standalone usage, hook into the Application manually:

```php
$application->onRequest[] = [ClaudeBlueScreenPanel::class, 'onRequest'];
```

## Requirements

- PHP 8.1+
- Tracy 2.10+

### Optional (auto-detected)

- **nette/application** — for presenter:action context
- **latte/latte** — for template source resolution
- **doctrine/dbal** — for SQL query extraction

## How it works

1. Registers a panel on Tracy's BlueScreen via `addPanel()`
2. When an error occurs, builds a structured text summary server-side
3. Renders a "Copy for Claude" button that copies the summary to clipboard
4. For Nette apps, hooks into `Application::$onRequest` to capture the current presenter before the error happens

## License

MIT
