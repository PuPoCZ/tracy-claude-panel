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

		$initialize = $class->getMethod('initialize');
		$initialize->addBody(
			ClaudeBlueScreenPanel::class . '::register(?);',
			[$this->getContainerBuilder()->parameters['appDir'] ?? '%appDir%'],
		);
	}

	private function isDebugMode(): bool
	{
		return $this->getContainerBuilder()->parameters['debugMode'] ?? false;
	}
}
