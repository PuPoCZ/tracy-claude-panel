<?php

declare(strict_types=1);

namespace PuPoC\TracyClaudePanel;

use Tracy\Debugger;
use Tracy\Helpers;

/**
 * Tracy BlueScreen panel that formats errors for AI-assisted debugging.
 *
 * Generates a structured, copy-paste-ready error summary optimized for
 * Claude Code and other AI coding assistants.
 *
 * Features:
 * - Error type, message, file, line
 * - HTTP method + URL
 * - Nette presenter:action (via Application event, mapping-independent)
 * - Latte template source resolution (compiled PHP → original .latte)
 * - Doctrine SQL query extraction from DBAL exceptions
 * - Source code snippet with error line marker
 * - Filtered call stack (app files only, no vendor noise)
 * - Chained exception support
 */
final class ClaudeBlueScreenPanel
{
	private string $appDir;
	private string $rootDir;

	/** Captured by Application::$onRequest event */
	private static ?string $lastPresenterAction = null;

	public function __construct(string $appDir)
	{
		$this->appDir = rtrim($appDir, '/\\');
		$this->rootDir = dirname($this->appDir);
	}

	/**
	 * Register the panel on Tracy BlueScreen.
	 * This is the only method you need to call for standalone (non-Nette) usage.
	 *
	 * @param string $appDir Absolute path to your application source directory (e.g. __DIR__ . '/app')
	 */
	public static function register(string $appDir): void
	{
		$panel = new self($appDir);
		Debugger::getBlueScreen()->addPanel([$panel, 'renderPanel']);
	}

	/**
	 * Hook into Nette Application to capture presenter:action before errors occur.
	 * Registered automatically by the Nette DI extension, or manually:
	 *
	 *     $application->onRequest[] = [ClaudeBlueScreenPanel::class, 'onRequest'];
	 */
	public static function onRequest(\Nette\Application\Application $app, \Nette\Application\Request $request): void
	{
		$presenter = $request->getPresenterName();
		$action = $request->getParameter('action') ?? 'default';
		self::$lastPresenterAction = "{$presenter}:{$action}";
	}

	/**
	 * Tracy BlueScreen panel callback.
	 *
	 * @return array{tab: string, panel: string, collapsed: bool}|null
	 */
	public function renderPanel(?\Throwable $e): ?array
	{
		if ($e === null) {
			return null;
		}

		$summary = $this->buildSummary($e);
		$escapedSummary = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');

		$panel = <<<HTML
		<div id="tracy-claude-panel">
			<pre id="tracy-claude-text" style="background:#1a1a2e;color:#e0e0e0;padding:16px;border-radius:8px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;margin:0 0 12px 0;max-height:500px;overflow:auto">{$escapedSummary}</pre>
			<button type="button" id="tracy-claude-copy" onclick="
				var text = document.getElementById('tracy-claude-text').textContent;
				navigator.clipboard.writeText(text).then(function() {
					var btn = document.getElementById('tracy-claude-copy');
					var orig = btn.textContent;
					btn.textContent = '\u2713 Copied';
					btn.style.background = '#059669';
					setTimeout(function() { btn.textContent = orig; btn.style.background = '#7c3aed'; }, 2000);
				});
			" style="background:#7c3aed;color:white;border:none;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.2s">Copy for Claude</button>
		</div>
		HTML;

		return [
			'tab' => "\u{1F916} Claude",
			'panel' => $panel,
			'collapsed' => false,
		];
	}

	private function buildSummary(\Throwable $e): string
	{
		$lines = [];

		// Error type + message
		$type = $e instanceof \ErrorException
			? Helpers::errorTypeToString($e->getSeverity())
			: get_debug_type($e);

		$lines[] = "Error: {$type} — {$e->getMessage()}";

		// URL + HTTP method
		$url = $this->getCurrentUrl();
		if ($url !== null) {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
			$lines[] = "URL: {$method} {$url}";
		}

		// Presenter:action (Nette)
		$presenterAction = $this->detectPresenterAction($e);
		if ($presenterAction !== null) {
			$lines[] = "Presenter: {$presenterAction}";
		}

		// File + line — for Latte errors, resolve to .latte source
		$sourceFile = $e->getFile();
		$sourceLine = $e->getLine();
		$latteSource = $this->detectLatteSource($e->getFile());

		if ($latteSource !== null) {
			$latteLine = $this->mapCompiledLineToLatte($e->getFile(), $e->getLine());
			if ($latteLine !== null) {
				$sourceFile = $latteSource;
				$sourceLine = $latteLine;
			}
		}

		$lines[] = "File: {$this->relativePath($sourceFile)}:{$sourceLine}";

		// Source code snippet
		$source = $this->getSourceSnippet($sourceFile, $sourceLine, 4);
		if ($source !== null) {
			$lines[] = '';
			$lines[] = 'Source:';
			$lines[] = $source;
		}

		// Doctrine SQL query
		$sql = $this->extractDoctrineQuery($e);
		if ($sql !== null) {
			$lines[] = '';
			$lines[] = "SQL: {$sql}";
		}

		// Filtered call stack (app files only)
		$stack = $this->getFilteredStack($e);
		if ($stack !== []) {
			$lines[] = '';
			$lines[] = 'Stack (app only):';
			foreach ($stack as $frame) {
				$lines[] = "  {$frame}";
			}
		}

		// Caused by (recursive)
		$prev = $e->getPrevious();
		while ($prev !== null) {
			$prevType = get_debug_type($prev);
			$prevFile = $this->relativePath($prev->getFile());
			$lines[] = '';
			$lines[] = "Caused by: {$prevType} — {$prev->getMessage()}";
			$lines[] = "  at {$prevFile}:{$prev->getLine()}";

			$prevSql = $this->extractDoctrineQuery($prev);
			if ($prevSql !== null) {
				$lines[] = "  SQL: {$prevSql}";
			}

			$prev = $prev->getPrevious();
		}

		return implode("\n", $lines);
	}

	private function getCurrentUrl(): ?string
	{
		if (PHP_SAPI === 'cli') {
			return null;
		}

		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
		$uri = $_SERVER['REQUEST_URI'] ?? '/';

		// Strip Tracy debug params for cleaner URL
		$uri = preg_replace('/[?&]_fid=[^&]*/', '', $uri) ?? $uri;
		$uri = preg_replace('/\?$/', '', $uri) ?? $uri;

		return "{$scheme}://{$host}{$uri}";
	}

	private function relativePath(string $path): string
	{
		if (str_starts_with($path, $this->rootDir)) {
			return ltrim(substr($path, strlen($this->rootDir)), '/\\');
		}

		return $path;
	}

	/**
	 * Detect Nette presenter:action.
	 * Primary: value captured by Application::$onRequest event (universal, mapping-independent).
	 * Fallback: find Presenter object in stack trace.
	 */
	private function detectPresenterAction(\Throwable $e): ?string
	{
		if (self::$lastPresenterAction !== null) {
			return self::$lastPresenterAction;
		}

		// Fallback: find Presenter object in stack trace
		if (!class_exists(\Nette\Application\UI\Presenter::class, false)) {
			return null;
		}

		foreach ($e->getTrace() as $frame) {
			$object = $frame['object'] ?? null;
			if ($object instanceof \Nette\Application\UI\Presenter) {
				$name = $object->getName();
				$action = $object->getAction();
				if ($name !== null) {
					return "{$name}:{$action}";
				}
			}
		}

		return null;
	}

	/**
	 * Detect original .latte file from compiled Latte PHP.
	 * Compiled files contain: /** source: /path/to/file.latte *​/
	 */
	private function detectLatteSource(string $file): ?string
	{
		if (!is_file($file) || !is_readable($file)) {
			return null;
		}

		// Quick check — only process files that could be compiled Latte
		if (!str_contains($file, 'latte') && !str_contains($file, 'temp')) {
			return null;
		}

		// Already a .latte file
		if (str_ends_with($file, '.latte')) {
			return null;
		}

		$handle = fopen($file, 'r');
		if ($handle === false) {
			return null;
		}

		$latteSource = null;
		$lineCount = 0;
		while (($line = fgets($handle)) !== false && $lineCount < 15) {
			if (preg_match('#/\*\*\s*source:\s*(.+\.latte)\s*\*/#', $line, $m)) {
				$latteSource = trim($m[1]);
				break;
			}
			$lineCount++;
		}
		fclose($handle);

		if ($latteSource !== null && is_file($latteSource)) {
			return $latteSource;
		}

		return null;
	}

	/**
	 * Map a line in compiled Latte PHP to original .latte line.
	 * Latte 3: /* pos 4:1 *​/ (line:column)
	 * Latte 2: /* line 42 *​/
	 */
	private function mapCompiledLineToLatte(string $file, int $phpLine): ?int
	{
		if (!is_file($file) || !is_readable($file)) {
			return null;
		}

		$content = file($file);
		if ($content === false) {
			return null;
		}

		$searchRange = range(
			min($phpLine - 1, count($content) - 1),
			max(0, $phpLine - 10),
		);

		foreach ($searchRange as $i) {
			if (!isset($content[$i])) {
				continue;
			}
			if (preg_match('#/\*\s*pos\s+(\d+):\d+\s*\*/#', $content[$i], $m)) {
				return (int) $m[1];
			}
			if (preg_match('#/\*\s*line\s+(\d+)\s*\*/#', $content[$i], $m)) {
				return (int) $m[1];
			}
		}

		return null;
	}

	/**
	 * Extract SQL query from Doctrine DBAL exceptions.
	 */
	private function extractDoctrineQuery(\Throwable $e): ?string
	{
		if (!class_exists(\Doctrine\DBAL\Exception\DriverException::class, false)) {
			return null;
		}

		if (!$e instanceof \Doctrine\DBAL\Exception\DriverException) {
			return null;
		}

		$query = $e->getQuery();
		if ($query === null) {
			return null;
		}

		$sql = $query->getSQL();
		$params = $query->getParams();

		if ($params !== []) {
			$sql .= ' -- params: ' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		return $sql;
	}

	private function getSourceSnippet(string $file, int $line, int $context): ?string
	{
		if (!is_file($file) || !is_readable($file)) {
			return null;
		}

		$content = file($file);
		if ($content === false) {
			return null;
		}

		$start = max(0, $line - $context - 1);
		$end = min(count($content), $line + $context);

		$snippet = [];
		$gutterWidth = strlen((string) $end);

		for ($i = $start; $i < $end; $i++) {
			$lineNum = $i + 1;
			$text = rtrim($content[$i]);
			$marker = ($lineNum === $line) ? " \u{2192} " : '   ';
			$snippet[] = sprintf("%s%{$gutterWidth}d: %s", $marker, $lineNum, $text);
		}

		return implode("\n", $snippet);
	}

	/**
	 * @return string[]
	 */
	private function getFilteredStack(\Throwable $e): array
	{
		$result = [];

		foreach ($e->getTrace() as $frame) {
			if (!isset($frame['file'])) {
				continue;
			}

			$file = $frame['file'];

			$isAppFile = str_starts_with($file, $this->appDir);
			$isLatteCompiled = str_contains($file, '/temp/cache/latte/')
				|| str_contains($file, '\\temp\\cache\\latte\\');

			if (!$isAppFile && !$isLatteCompiled) {
				continue;
			}

			$relativePath = $this->relativePath($file);
			$line = $frame['line'] ?? '?';

			if ($isLatteCompiled) {
				$latteSource = $this->detectLatteSource($file);
				if ($latteSource !== null) {
					$relativePath = $this->relativePath($latteSource);
					$latteLine = $this->mapCompiledLineToLatte($file, is_int($line) ? $line : 0);
					if ($latteLine !== null) {
						$line = $latteLine;
					}
				}
			}

			$call = '';
			if (isset($frame['class'])) {
				$shortClass = $this->shortClassName($frame['class']);
				$call = " {$shortClass}::{$frame['function']}()";
			} else {
				$call = " {$frame['function']}()";
			}

			$result[] = "{$relativePath}:{$line}{$call}";
		}

		return $result;
	}

	private function shortClassName(string $class): string
	{
		// For compiled Latte templates (Template_xxxx), simplify
		if (preg_match('/^Template_[a-f0-9]+$/', $class)) {
			return 'LatteTemplate';
		}

		// Use short class name
		$pos = strrpos($class, '\\');
		return $pos !== false ? substr($class, $pos + 1) : $class;
	}
}
