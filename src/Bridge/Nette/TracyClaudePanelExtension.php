<?php

declare(strict_types=1);

namespace PuPoC\TracyClaudePanel\Bridge\Nette;

use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\PhpGenerator\ClassType;
use PuPoC\TracyClaudePanel\ClaudeBlueScreenPanel;

/**
 * Nette DI extension for automatic registration of ClaudeBlueScreenPanel.
 *
 * The extension:
 * 1. Registers the BlueScreen panel in debug mode
 * 2. Hooks into Application::$onRequest for presenter:action detection
 */
final class TracyClaudePanelExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		if (!$this->isDebugMode()) {
			return;
		}

		// Register panel immediately so it catches errors during container compilation
		// (e.g. CompileError when autoloading a class with property conflicts).
		$appDir = $this->getContainerBuilder()->parameters['appDir'] ?? null;
		if (is_string($appDir)) {
			ClaudeBlueScreenPanel::register($appDir);
		}
	}

	public function beforeCompile(): void
	{
		if (!$this->isDebugMode()) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$applicationName = $builder->getByType(\Nette\Application\Application::class);

		if ($applicationName !== null) {
			$application = $builder->getDefinition($applicationName);
			if ($application instanceof ServiceDefinition) {
				$application->addSetup('$onRequest[]', [[ClaudeBlueScreenPanel::class, 'onRequest']]);
			}
		}
	}

	public function afterCompile(ClassType $class): void
	{
		if (!$this->isDebugMode()) {
			return;
		}

		$appDir = $this->getContainerBuilder()->parameters['appDir'] ?? '%appDir%';

		// Prepend panel registration to run before any other initialization code.
		// Using addBody() would append after other extensions, risking that their
		// init code triggers autoloading (and a CompileError) before our panel exists.
		$initialize = $class->getMethod('initialize');
		$existingBody = $initialize->getBody();
		$initialize->setBody('');
		$initialize->addBody(
			ClaudeBlueScreenPanel::class . '::register(?);',
			[$appDir],
		);
		$initialize->addBody($existingBody);
	}

	private function isDebugMode(): bool
	{
		return $this->getContainerBuilder()->parameters['debugMode'] ?? false;
	}
}
