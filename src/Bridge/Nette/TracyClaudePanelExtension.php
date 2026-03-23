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
 * Zero-config: just install the package and the extension auto-registers
 * via composer.json "extra.nette.extensions".
 *
 * The extension:
 * 1. Registers the BlueScreen panel in debug mode
 * 2. Hooks into Application::$onRequest for presenter:action detection
 */
final class TracyClaudePanelExtension extends CompilerExtension
{
	public function afterCompile(ClassType $class): void
	{
		if (!$this->isDebugMode()) {
			return;
		}

		$initialize = $class->getMethod('initialize');

		// Register the BlueScreen panel
		$initialize->addBody(
			ClaudeBlueScreenPanel::class . '::register(?);',
			[$this->getContainerBuilder()->parameters['appDir'] ?? '%appDir%'],
		);

		// Hook into Application::$onRequest for presenter:action detection
		$builder = $this->getContainerBuilder();
		$applicationName = $builder->getByType(\Nette\Application\Application::class);

		if ($applicationName !== null) {
			$application = $builder->getDefinition($applicationName);
			if ($application instanceof ServiceDefinition) {
				$application->addSetup('$onRequest[]', [[ClaudeBlueScreenPanel::class, 'onRequest']]);
			}
		}
	}

	private function isDebugMode(): bool
	{
		return $this->getContainerBuilder()->parameters['debugMode'] ?? false;
	}
}
