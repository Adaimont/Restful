<?php
namespace Drahak\Restful\DI;

use Drahak\Restful\Application\Routes\ResourceRoute;
use Drahak\Restful\IResource;
use Nette\Caching\Storages\FileStorage;
use Nette\DI\CompilerExtension;
use Nette\Configurator;
use Nette\DI\ContainerBuilder;
use Nette\DI\ServiceDefinition;
use Nette\DI\Statement;
use Nette\Diagnostics\Debugger;
use Nette\Loaders\RobotLoader;
use Nette\Utils\Validators;

/**
 * Drahak RestfulExtension
 * @package Drahak\Restful\DI
 * @author Drahomír Hanák
 */
class RestfulExtension extends CompilerExtension
{

	/** Converter tag name */
	const CONVERTER_TAG = 'restful.converter';

	/** Snake case convention config name */
	const CONVENTION_SNAKE_CASE = 'snake_case';

	/** Camel case convention config name */
	const CONVENTION_CAMEL_CASE = 'camelCase';

	/** Pascal case convention config name */
	const CONVENTION_PASCAL_CASE = 'PascalCase';

	/**
	 * Default DI settings
	 * @var array
	 */
	protected $defaults = array(
		'convention' => NULL,
		'timeFormat' => 'c',
		'cacheDir' => '%tempDir%/cache',
		'jsonpKey' => 'jsonp',
		'prettyPrintKey' => 'pretty',
		'routes' => array(
			'presentersRoot' => '%appDir%',
			'autoGenerated' => TRUE,
			'module' => '',
			'prefix' => '',
			'panel' => TRUE
		),
		'security' => array(
			'privateKey' => NULL,
			'requestTimeKey' => 'timestamp',
			'requestTimeout' => 300,
		)
	);

	/**
	 * Load DI configuration
	 */
	public function loadConfiguration()
	{
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		// Additional module
		$this->loadRestful($container, $config);
		$this->loadResourceConverters($container, $config);
		$this->loadSecuritySection($container, $config);
		if ($config['routes']['autoGenerated']) $this->loadAutoGeneratedRoutes($container, $config);
		if ($config['routes']['panel']) $this->loadResourceRoutePanel($container, $config);
	}

	/**
	 * Before compile
	 */
	public function beforeCompile()
	{
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		$resourceConverter = $container->getDefinition($this->prefix('resourceConverter'));
		$services = $container->findByTag(self::CONVERTER_TAG);

		foreach ($services as $service => $args) {
			$resourceConverter->addSetup('$service->addConverter(?)', array('@' . $service));
		}
	}

	/**
	 * @param ContainerBuilder $container
	 * @param $config
	 */
	private function loadRestful(ContainerBuilder $container, $config)
	{
		Validators::assert($config['jsonpKey'], 'string');
		Validators::assert($config['prettyPrintKey'], 'string');

		$container->addDefinition($this->prefix('responseFactory'))
			->setClass('Drahak\Restful\ResponseFactory');

		$container->addDefinition($this->prefix('resourceFactory'))
			->setClass('Drahak\Restful\ResourceFactory');
		$container->addDefinition($this->prefix('resource'))
			->setFactory($this->prefix('@resourceFactory') . '::create');

		// Mappers
		$container->addDefinition($this->prefix('xmlMapper'))
			->setClass('Drahak\Restful\Mapping\XmlMapper');
		$container->addDefinition($this->prefix('jsonMapper'))
			->setClass('Drahak\Restful\Mapping\JsonMapper');
		$container->addDefinition($this->prefix('queryMapper'))
			->setClass('Drahak\Restful\Mapping\QueryMapper');
		$container->addDefinition($this->prefix('dataUrlMapper'))
			->setClass('Drahak\Restful\Mapping\DataUrlMapper');

		$container->addDefinition($this->prefix('mapperContext'))
			->setClass('Drahak\Restful\Mapping\MapperContext')
			->addSetup('$service->addMapper(?, ?)', array(IResource::XML, $this->prefix('@xmlMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::JSON, $this->prefix('@jsonMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::QUERY, $this->prefix('@queryMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::DATA_URL, $this->prefix('@dataUrlMapper')));

		// Input & validation
		$container->addDefinition($this->prefix('inputFactory'))
			->setClass('Drahak\Restful\Http\InputFactory');
		$container->addDefinition($this->prefix('input'))
			->setClass('Drahak\Restful\Http\Input')
			->setFactory($this->prefix('@inputFactory') . '::create');

		$container->addDefinition($this->prefix('validator'))
			->setClass('Drahak\Restful\Validation\Validator');

		$container->addDefinition($this->prefix('validationScope'))
			->setClass('Drahak\Restful\Validation\ValidationScope')
			->setImplement('Drahak\Restful\Validation\ValidationScopeFactory');

		// Http
		$container->getDefinition('httpRequest')
			->setClass('Drahak\Restful\Http\IRequest');
		$container->getDefinition('httpResponse')
			->setClass('Drahak\Restful\Http\ResponseProxy');

		$container->addDefinition($this->prefix('requestFilter'))
			->setClass('Drahak\Restful\Utils\RequestFilter')
			->setArguments(array('@httpRequest', array($config['jsonpKey'], $config['prettyPrintKey'])));

		$container->getDefinition('nette.httpRequestFactory')
			->setClass('Drahak\Restful\Http\RequestFactory')
			->setArguments(array($config['jsonpKey'], $config['prettyPrintKey']));

		$container->addDefinition($this->prefix('methodHandler'))
			->setClass('Drahak\Restful\Application\Events\MethodHandler');

		$container->getDefinition('application')
			->addSetup('$service->onStartup[] = ?', array(array($this->prefix('@methodHandler'), 'run')));
	}

	/**
	 * @param ContainerBuilder $container
	 * @param $config
	 */
	private function loadResourceConverters(ContainerBuilder $container, $config)
	{
		Validators::assert($config['timeFormat'], 'string');

		// Default used converters
		$container->addDefinition($this->prefix('objectConverter'))
			->setClass('Drahak\Restful\Resource\ObjectConverter')
			->addTag(self::CONVERTER_TAG);
		$container->addDefinition($this->prefix('dateTimeConverter'))
			->setClass('Drahak\Restful\Resource\DateTimeConverter')
			->setArguments(array($config['timeFormat']))
			->addTag(self::CONVERTER_TAG);

		// Other available converters
		$container->addDefinition($this->prefix('camelCaseConverter'))
			->setClass('Drahak\Restful\Resource\CamelCaseConverter');
		$container->addDefinition($this->prefix('pascalCaseConverter'))
			->setClass('Drahak\Restful\Resource\PascalCaseConverter');
		$container->addDefinition($this->prefix('snakeCaseConverter'))
			->setClass('Drahak\Restful\Resource\SnakeCaseConverter');

		// Determine which converter to use if any
		if ($config['convention'] === self::CONVENTION_SNAKE_CASE) {
			$container->getDefinition($this->prefix('snakeCaseConverter'))
				->addTag(self::CONVERTER_TAG);
		} else if ($config['convention'] === self::CONVENTION_CAMEL_CASE) {
			$container->getDefinition($this->prefix('camelCaseConverter'))
				->addTag(self::CONVERTER_TAG);
		} else if ($config['convention'] === self::CONVENTION_PASCAL_CASE) {
			$container->getDefinition($this->prefix('pascalCaseConverter'))
				->addTag(self::CONVERTER_TAG);
		}

		// Load converters by tag
		$container->addDefinition($this->prefix('resourceConverter'))
			->setClass('Drahak\Restful\Resource\ResourceConverter');
	}

	/**
	 * @param ContainerBuilder $container
	 * @param array $config
	 */
	private function loadAutoGeneratedRoutes(ContainerBuilder $container, $config)
	{
		$container->addDefinition($this->prefix('routeAnnotation'))
			->setClass('Drahak\Restful\Application\RouteAnnotation');

		$container->addDefinition($this->prefix('routeListFactory'))
			->setClass('Drahak\Restful\Application\Routes\RouteListFactory')
			->setArguments(array($config['routes']['presentersRoot']))
			->addSetup('$service->setModule(?)', array($config['routes']['module']))
			->addSetup('$service->setPrefix(?)', array($config['routes']['prefix']));

		$container->addDefinition($this->prefix('cachedRouteListFactory'))
			->setClass('Drahak\Restful\Application\Routes\CachedRouteListFactory')
			->setArguments(array($config['routes']['presentersRoot'], $this->prefix('@routeListFactory')));

		$container->getDefinition('router')
			->addSetup('offsetSet', array(
				NULL,
				new Statement($this->prefix('@cachedRouteListFactory') . '::create')
			));
	}

	/**
	 * @param ContainerBuilder $container
	 * @param array $config
	 */
	private function loadResourceRoutePanel(ContainerBuilder $container, $config)
	{
		$container->addDefinition($this->prefix('panel'))
			->setClass('Drahak\Restful\Diagnostics\ResourceRouterPanel')
			->setArguments(array(
				$config['security']['privateKey'],
				isset($config['security']['requestTimeKey']) ? $config['security']['requestTimeKey'] : 'timestamp'
			))
			->addSetup('Nette\Diagnostics\Debugger::$bar->addPanel(?)', array('@self'));

		$container->getDefinition('application')
			->addSetup('$service->onStartup[] = ?', array(array($this->prefix('@panel'), 'getTab')));
	}

	/**
	 * @param ContainerBuilder $container
	 * @param array $config
	 */
	private function loadSecuritySection(ContainerBuilder $container, $config)
	{
		$container->addDefinition($this->prefix('security.hashCalculator'))
			->setClass('Drahak\Restful\Security\HashCalculator')
			->setArguments(array($this->prefix('@queryMapper')))
			->addSetup('$service->setPrivateKey(?)', array($config['security']['privateKey']));

		$container->addDefinition($this->prefix('security.hashAuthenticator'))
			->setClass('Drahak\Restful\Security\Authentication\HashAuthenticator')
			->setArguments(array($config['security']['privateKey']));
		$container->addDefinition($this->prefix('security.timeoutAuthenticator'))
			->setClass('Drahak\Restful\Security\Authentication\TimeoutAuthenticator')
			->setArguments(array($config['security']['requestTimeKey'], $config['security']['requestTimeout']));

		$container->addDefinition($this->prefix('security.nullAuthentication'))
			->setClass('Drahak\Restful\Security\Process\NullAuthentication');
		$container->addDefinition($this->prefix('security.securedAuthentication'))
			->setClass('Drahak\Restful\Security\Process\SecuredAuthentication');
		$container->addDefinition($this->prefix('security.basicAuthentication'))
			->setClass('Drahak\Restful\Security\Process\BasicAuthentication');

		$container->addDefinition($this->prefix('security.authentication'))
			->setClass('Drahak\Restful\Security\AuthenticationContext')
			->addSetup('$service->setAuthProcess(?)', array($this->prefix('@security.nullAuthentication')));

		// enable OAuth2 in Restful
		if ($this->getByType($container, 'Drahak\OAuth2\KeyGenerator')) {
			$container->addDefinition($this->prefix('security.oauth2Authentication'))
				->setClass('Drahak\Restful\Security\Process\OAuth2Authentication');
		}
	}

	/**
	 * @param ContainerBuilder $container
	 * @param string $type
	 * @return ServiceDefinition|null
	 */
	private function getByType(ContainerBuilder $container, $type)
	{
		$definitionas = $container->getDefinitions();
		foreach ($definitionas as $definition) {
			if ($definition->class === $type) {
				return $definition;
			}
		}
		return NULL;
	}

	/**
	 * Register REST API extension
	 * @param Configurator $configurator
	 */
	public static function install(Configurator $configurator)
	{
		$configurator->onCompile[] = function($configurator, $compiler) {
			$compiler->addExtension('restful', new RestfulExtension);
		};
	}

}
