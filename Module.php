<?php
namespace Wikibase;

use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getConfigForm(\Laminas\View\Renderer\PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $config   = $services->get('Config')['wikibase'];

        $mapping = $settings->get('wikibase_property_mapping', $config['property_mapping']);

        return $renderer->render('wikibase/admin/config-form', [
            'apiUrl'        => $settings->get('wikibase_api_url', $config['api_url']),
            'languages'     => implode(', ', $settings->get('wikibase_languages', $config['languages'])),
            'instanceOfPid' => $settings->get('wikibase_instance_of_pid', $config['instance_of_pid']),
            'mapping'       => $mapping,
        ]);
    }

    public function handleConfigForm(\Laminas\Mvc\Controller\AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $post     = $controller->getRequest()->getPost()->toArray();

        $settings->set('wikibase_api_url',
            trim($post['api_url'] ?? ''));
        $settings->set('wikibase_languages',
            array_filter(array_map('trim', explode(',', $post['languages'] ?? 'it,en'))));
        $settings->set('wikibase_instance_of_pid',
            trim($post['instance_of_pid'] ?? 'P5'));

        $mapping  = [];
        $terms    = $post['prop_term']    ?? [];
        $classes  = $post['prop_classes'] ?? [];
        $labels   = $post['prop_label']   ?? [];
        $preloads = $post['prop_preload'] ?? [];

        foreach ($terms as $i => $term) {
            $term = trim($term);
            if ($term && !empty($classes[$i])) {
                $classList = array_filter(array_map('trim', explode(',', $classes[$i])));
                $entry = ['classes' => $classList];
                if (!empty($labels[$i])) {
                    $entry['label'] = trim($labels[$i]);
                }
                $entry['preload'] = ($preloads[$i] ?? '0') === '1';
                $mapping[$term] = $entry;
            }
        }
        $settings->set('wikibase_property_mapping', $mapping);

        return true;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.add.before',
            function ($event) { $this->addAssets($event); }
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.before',
            function ($event) { $this->addAssets($event); }
        );
    }

    public function addAssets($event)
    {
        $services      = $this->getServiceLocator();
        $settings      = $services->get('Omeka\Settings');
        $config        = $services->get('Config')['wikibase'];

        $apiUrl        = $settings->get('wikibase_api_url', $config['api_url']);
        $languages     = $settings->get('wikibase_languages', $config['languages']);
        $instanceOfPid = $settings->get('wikibase_instance_of_pid', $config['instance_of_pid']);
        $mapping       = $settings->get('wikibase_property_mapping', $config['property_mapping']);

        $view = $event->getTarget();

        $view->headScript()->appendScript(
            'var wikibaseConfig = ' . json_encode([
                'proxyUrl'      => '/wikibase/proxy',
                'labelsUrl'     => '/wikibase/labels',
                'languages'     => $languages,
                'instanceOfPid' => $instanceOfPid,
                'mapping'       => $mapping,
            ]) . ';'
        );

        $view->headScript()->appendFile(
            $view->assetUrl('js/wikibase-suggest.js', 'Wikibase')
        );
    }
}