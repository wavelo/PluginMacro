<?php


class PluginMacroExtension extends Nette\DI\CompilerExtension
{
	public $defaults = [
		'extended' => FALSE,
		'masks' => [],
	];


	public function loadConfiguration()
	{
		$config = $this->validateConfig($this->defaults);
	}


	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$registerToLatte = function (Nette\DI\ServiceDefinition $def) use ($config) {
			$def->addSetup('?->onCompile[] = function($engine) { PluginMacro::install($engine->getCompiler()); }', ['@self']);

			if ($config['extended']) {
				$def->addSetup('?->onCompile[] = function($engine) { PluginMacro::installExtended($engine->getCompiler()); }', ['@self']);
			}
		};

		$latteFactoryService = $builder->getByType('Nette\Bridges\ApplicationLatte\ILatteFactory');
		if (!$latteFactoryService || !self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), 'Latte\engine')) {
			$latteFactoryService = 'nette.latteFactory';
		}

		if ($builder->hasDefinition($latteFactoryService) && self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), 'Latte\Engine')) {
			$registerToLatte($builder->getDefinition($latteFactoryService));
		}

		if ($builder->hasDefinition('nette.latte')) {
			$registerToLatte($builder->getDefinition('nette.latte'));
		}
	}


	public function afterCompile(Nette\PhpGenerator\ClassType $class)
	{
		$initialize = $class->getMethod('initialize');
		$config = $this->getConfig();

		if ($config['masks']) {
			$initialize->addBody('PluginMacro::$masks = ?;', [$config['masks']]);
		}
	}


	/**
	 * @param string $class
	 * @param string $type
	 * @return bool
	 */
	private static function isOfType($class, $type)
	{
		return $class === $type || is_subclass_of($class, $type);
	}

}
