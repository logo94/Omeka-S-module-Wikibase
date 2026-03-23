<?php
namespace Wikibase\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Wikibase\Controller\ProxyController;

class ProxyControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $settings      = $container->get('Omeka\Settings');
        $config        = $container->get('Config');
        $defaultConfig = $config['wikibase'];

        return new ProxyController(
            $settings->get('wikibase_api_url',         $defaultConfig['api_url']),
            $settings->get('wikibase_languages',        $defaultConfig['languages']),
            $settings->get('wikibase_instance_of_pid',  $defaultConfig['instance_of_pid']),
            $settings->get('wikibase_property_mapping', $defaultConfig['property_mapping'])
        );
    }
}