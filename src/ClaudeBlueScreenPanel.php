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
 */
final class ClaudeBlueScreenPanel
{
	private string $appDir;
	private string $rootDir;

	private static ?string $lastPresenterAction = null;

	/** @var array<string, ?string> */
	private array $latteSourceCache = [];

	/** @var array<string, ?list<string>> */
	private array $fileContentCache = [];

	public function __construct(string $appDir)
	{
		$this->appDir = rtrim($appDir, '/\\');
		$this->rootDir = dirname($this->appDir);
	}

	/**
	 * Register the panel on Tracy BlueScreen.
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
	 * @return array{tab: string, panel: string, collapsed: bool}|null
	 */
	public function renderPanel(?\Throwable $e): ?array
	{
		if ($e === null) {
			return null;
		}

		$summary = $this->buildSummary($e);
		$escapedSummary = Helpers::escapeHtml($summary);

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

		$type = $e instanceof \ErrorException
			? Helpers::errorTypeToString($e->getSeverity())
			: get_debug_type($e);
		$lines[] = "Error: {$type} — {$e->getMessage()}";

		$url = $this->getCurrentUrl();
		if ($url !== null) {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
			$lines[] = "URL: {$method} {$url}";
		}

		$presenterAction = $this->detectPresenterAction($e);
		if ($presenterAction !== null) {
			$lines[] = "Presenter: {$presenterAction}";
		}

		$sourceFile = $e->getFile();
		$sourceLine = $e->getLine();

		// If error originates in vendor (e.g. trigger_error inside Nette),
		// find the first app frame in the stack trace — that's where the real problem is
		if (!str_starts_with($sourceFile, $this->appDir)) {
			$appFrame = $this->findFirstAppFrame($e);
			if ($appFrame !== null) {
				$sourceFile = $appFrame['file'];
				$sourceLine = $appFrame['line'];
			}
		}

		$resolved = $this->resolveLatteSource($sourceFile, $sourceLine);
		if ($resolved !== null) {
			$sourceFile = $resolved['file'];
			$sourceLine = $resolved['line'];
		}

		$lines[] = "File: {$this->relativePath($sourceFile)}:{$sourceLine}";

		$source = $this->getSourceSnippet($sourceFile, $sourceLine, 4);
		if ($source !== null) {
			$lines[] = '';
			$lines[] = 'Source:';
			$lines[] = $source;
		}

		$sql = $this->extractDoctrineQuery($e);
		if ($sql !== null) {
			$lines[] = '';
			$lines[] = "SQL: {$sql}";
		}

		$stack = $this->getFilteredStack($e);
		if ($stack !== []) {
			$lines[] = '';
			$lines[] = 'Stack (app only):';
			foreach ($stack as $frame) {
				$lines[] = "  {$frame}";
			}
		}

		// Chained exceptions (with circular chain protection via Tracy)
		foreach (array_slice(Helpers::getExceptionChain($e), 1) as $prev) {
			$prevType = get_debug_type($prev);
			$prevFile = $this->relativePath($prev->getFile());
			$lines[] = '';
			$lines[] = "Caused by: {$prevType} — {$prev->getMessage()}";
			$lines[] = "  at {$prevFile}:{$prev->getLine()}";

			$prevSql = $this->extractDoctrineQuery($prev);
			if ($prevSql !== null) {
				$lines[] = "  SQL: {$prevSql}";
			}
		}

		return implode("\n", $lines);
	}

	private function getCurrentUrl(): ?string
	{
		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return null;
		}

		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
		$uri = $_SERVER['REQUEST_URI'] ?? '/';

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
	 * Find the first stack frame that points to an app file (not vendor).
	 * @return array{file: string, line: int}|null
	 */
	private function findFirstAppFrame(\Throwable $e): ?array
	{
		foreach ($e->getTrace() as $frame) {
			if (!isset($frame['file'], $frame['line'])) {
				continue;
			}

			if (str_starts_with($frame['file'], $this->appDir) || $this->isLikelyCompiledLatte($frame['file'])) {
				return ['file' => $frame['file'], 'line' => $frame['line']];
			}
		}

		return null;
	}

	/**
	 * Uses value captured by Application::$onRequest event (universal, mapping-independent).
	 * Falls back to finding Presenter object in stack trace.
	 */
	private function detectPresenterAction(\Throwable $e): ?string
	{
		if (self::$lastPresenterAction !== null) {
			return self::$lastPresenterAction;
		}

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
	 * Resolve compiled Latte PHP file + line to original .latte source.
	 * @return array{file: string, line: int}|null
	 */
	private function resolveLatteSource(string $file, int $line): ?array
	{
		$latteSource = $this->detectLatteSource($file);
		if ($latteSource === null) {
			return null;
		}

		$latteLine = $this->mapCompiledLineToLatte($file, $line);
		return $latteLine !== null ? ['file' => $latteSource, 'line' => $latteLine] : null;
	}

	/**
	 * Detect original .latte file from compiled Latte PHP.
	 * Results are cached per file path.
	 */
	private function detectLatteSource(string $file): ?string
	{
		if (array_key_exists($file, $this->latteSourceCache)) {
			return $this->latteSourceCache[$file];
		}

		$result = $this->doDetectLatteSource($file);
		$this->latteSourceCache[$file] = $result;
		return $result;
	}

	private function doDetectLatteSource(string $file): ?string
	{
		if (!$this->isLikelyCompiledLatte($file)) {
			return null;
		}

		$lines = $this->readFileLines($file);
		if ($lines === null) {
			return null;
		}

		// Source comment is always near the top of compiled Latte files
		$limit = min(15, count($lines));
		for ($i = 0; $i < $limit; $i++) {
			if (preg_match('#/\*\*\s*source:\s*(.+\.latte)\s*\*/#', $lines[$i], $m)) {
				$latteSource = trim($m[1]);
				return is_file($latteSource) ? $latteSource : null;
			}
		}

		return null;
	}

	/**
	 * Map a line in compiled Latte PHP to original .latte line.
	 * Latte 3: /* pos 4:1 *​/ — Latte 2: /* line 42 *​/
	 */
	private function mapCompiledLineToLatte(string $file, int $phpLine): ?int
	{
		$content = $this->readFileLines($file);
		if ($content === null) {
			return null;
		}

		$from = min($phpLine - 1, count($content) - 1);
		$to = max(0, $phpLine - 10);

		for ($i = $from; $i >= $to; $i--) {
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
		$content = $this->readFileLines($file);
		if ($content === null) {
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
			$isLatteCompiled = $this->isLikelyCompiledLatte($file);

			if (!$isAppFile && !$isLatteCompiled) {
				continue;
			}

			$relativePath = $this->relativePath($file);
			$line = $frame['line'] ?? 0;

			if ($isLatteCompiled) {
				$resolved = $this->resolveLatteSource($file, $line);
				if ($resolved !== null) {
					$relativePath = $this->relativePath($resolved['file']);
					$line = $resolved['line'];
				}
			}

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

	private function isLikelyCompiledLatte(string $file): bool
	{
		if (str_ends_with($file, '.latte')) {
			return false;
		}

		// Match compiled Latte cache dirs, not vendor/latte/ source files
		return str_contains($file, '/cache/latte/') || str_contains($file, '\\cache\\latte\\');
	}

	/**
	 * Read file lines with caching. Returns null on failure.
	 * @return list<string>|null
	 */
	private function readFileLines(string $file): ?array
	{
		if (array_key_exists($file, $this->fileContentCache)) {
			return $this->fileContentCache[$file];
		}

		$content = @file($file);
		$result = $content !== false ? $content : null;
		$this->fileContentCache[$file] = $result;
		return $result;
	}

	private function shortClassName(string $class): string
	{
		if (preg_match('/^Template_[a-f0-9]+$/', $class)) {
			return 'LatteTemplate';
		}

		$pos = strrpos($class, '\\');
		return $pos !== false ? substr($class, $pos + 1) : $class;
	}
}
