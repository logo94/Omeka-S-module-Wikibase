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
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.pre',
            function ($event) { $this->enrichUriValuesWithLabels($event); }
        );
    }

    public function enrichUriValuesWithLabels($event)
    {
        $request = $event->getParam('request');
        if (!in_array($request->getOperation(), ['create', 'update'])) {
            return;
        }

        $services  = $this->getServiceLocator();
        $settings  = $services->get('Omeka\Settings');
        $config    = $services->get('Config')['wikibase'];
        $apiUrl    = $settings->get('wikibase_api_url',   $config['api_url']);
        $languages = $settings->get('wikibase_languages', $config['languages']);
        $mapping   = $settings->get('wikibase_property_mapping', $config['property_mapping']);

        if (!$apiUrl || empty($languages)) {
            return;
        }

        $data    = $request->getContent();
        $changed = false;

        foreach (array_keys($mapping) as $term) {
            if (empty($data[$term]) || !is_array($data[$term])) {
                continue;
            }

            $newValues = [];
            foreach ($data[$term] as $value) {
                // Intervieni solo su valori uri senza label
                if (($value['type'] ?? '') !== 'uri' || !empty($value['o:label'])) {
                    $newValues[] = $value;
                    continue;
                }

                $uri = trim($value['@id'] ?? '');
                $qid = $uri ? $this->extractQid($uri) : null;

                if (!$qid) {
                    $newValues[] = $value;
                    continue;
                }

                $labels = $this->fetchLabelsFromWikibase($qid, $apiUrl, $languages);

                if (empty($labels)) {
                    $newValues[] = $value;
                    continue;
                }

                // Prima lingua: modifica il valore esistente
                $firstLang = $languages[0];
                $value['o:label'] = $labels[$firstLang] ?? null;
                $value['o:lang']  = $firstLang;
                $newValues[] = $value;

                // Lingue successive: aggiungi nuovi valori nella request
                foreach (array_slice($languages, 1) as $lang) {
                    $newValues[] = [
                        'type'          => 'uri',
                        '@id'           => $uri,
                        'o:label'       => $labels[$lang] ?? null,
                        'o:lang'        => $lang,
                        'property_id'   => $value['property_id'] ?? null,
                        'property_term' => $term,
                        'is_public'     => true,
                    ];
                }

                $changed = true;
            }

            if ($changed) {
                $data[$term] = $newValues;
            }
        }

        if ($changed) {
            $request->setContent($data);
        }
    }

    protected function extractQid(string $uri): ?string
    {
        // Supporta sia /entity/Q123 che /wiki/Item:Q123
        if (preg_match('/(Q\d+)$/', $uri, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function fetchLabelsFromWikibase(string $qid, string $apiUrl, array $languages): array
    {
        $url = $apiUrl . '?' . http_build_query([
            'action'    => 'wbgetentities',
            'ids'       => $qid,
            'props'     => 'labels',
            'languages' => implode('|', $languages),
            'format'    => 'json',
        ]);

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header'  => 'User-Agent: OmekaS-Wikibase-Module/1.0',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!$response) {
            return [];
        }

        $data   = json_decode($response, true);
        $entity = $data['entities'][$qid] ?? null;
        if (!$entity || isset($entity['missing'])) {
            return [];
        }

        $result = [];
        foreach ($entity['labels'] ?? [] as $lang => $labelData) {
            if (in_array($lang, $languages)) {
                $result[$lang] = $labelData['value'];
            }
        }
        return $result;
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