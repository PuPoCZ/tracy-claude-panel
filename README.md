# Tracy Claude Panel

Tracy BlueScreen panel that formats errors for AI-assisted debugging. One-click error export to [Claude Code](https://claude.ai/code) and other AI coding assistants.

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

**Example with vendor-originated error (e.g. invalid Nette link):**

```
Error: User Warning — Invalid link: Missing parameter $id required by ProfilePresenter::actionDefault()
URL: GET http://localhost:8080/admin/external-lecturers/2/detail
Presenter: User:Admin:ExternalLecturerDetail:default
File: app/Modules/User/Presentation/Admin/ExternalLecturerDetail/ExternalLecturerDetailPresenter.php:63

Source:
   59:         $this['pageHeader']->setBackLink($this->link('ExternalLecturers:default'));
   60:
   61:         if ($this->getUser()->isAllowed('ExternalLecturers', 'edit')) {
   62:             if ($person->getSlug()) {
 → 63:                 $this['pageHeader']->add('Profile', $this->link(':User:Front:Profile:default', ['slug' => $person->getSlug()]))
   64:                     ->setIcon('visibility');
   65:             }
   66:         }
   67:     }

Args: Component::link(':User:Front:Profile:default', ['slug': 'ivan-omavka'])

Stack (app only):
  app/Modules/User/Presentation/Admin/ExternalLecturerDetail/ExternalLecturerDetailPresenter.php:63 Component::link()
```

## Features

- **Error info** — type, message, file, line number
- **URL + HTTP method** — `GET http://localhost:8080/admin/users`
- **Nette presenter:action** — `User:Admin:EmployeeDetail:default` (auto-detected via Application event, works with any mapping)
- **Smart file resolution** — when error originates in vendor code (e.g. `trigger_error` inside Nette), resolves to the first app-level frame where the problem actually is
- **Function arguments** — shows the arguments of the call that triggered the error (scalars, short arrays, class names for objects)
- **Latte template resolution** — maps compiled PHP back to original `.latte` file and line number (supports Latte 2 and 3)
- **Source code snippet** — shows code around the error with `→` marker
- **Doctrine SQL** — extracts query + parameters from DBAL exceptions
- **Filtered stack trace** — only your app files, no vendor noise; Latte frames resolved to `.latte` paths
- **Chained exceptions** — shows the full cause chain with circular reference protection

## Installation

```bash
composer require --dev pupocz/tracy-claude-panel
```

### Nette Framework

Register the extension in your config (e.g. `config/common.neon`):

```neon
extensions:
    tracyClaudePanel: PuPoC\TracyClaudePanel\Bridge\Nette\TracyClaudePanelExtension
```

That's it. The extension registers the panel and hooks into `Application::$onRequest` for presenter detection. It only activates in debug mode.

### Standalone Tracy (without Nette)

Register manually in your bootstrap after enabling Tracy:

```php
use PuPoC\TracyClaudePanel\ClaudeBlueScreenPanel;

Tracy\Debugger::enable();
ClaudeBlueScreenPanel::register(__DIR__ . '/app');
```

The argument is the absolute path to your application source directory. Files outside this directory are filtered from the stack trace.

#### Optional: Presenter detection for standalone usage

```php
$application->onRequest[] = [ClaudeBlueScreenPanel::class, 'onRequest'];
```

## Requirements

- PHP 8.1+
- Tracy 2.10+

### Optional (auto-detected when available)

- **nette/application** — for presenter:action context
- **latte/latte** — for template source resolution (Latte 2 and 3)
- **doctrine/dbal** — for SQL query extraction

## How it works

1. Registers a panel on Tracy's BlueScreen via `addPanel()`
2. When an error occurs, builds a structured text summary
3. If the error originates in vendor code, walks the stack trace to find the first app-level frame
4. Resolves compiled Latte templates to original `.latte` files using source comments and position markers
5. For Nette apps, uses the `Application::$onRequest` event to capture the current presenter name before the error happens
6. Renders a "Copy for Claude" button that copies the summary to clipboard

## License

MIT
