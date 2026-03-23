<?php
namespace Wikibase\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class ProxyController extends AbstractActionController
{
    protected string $apiUrl;
    protected array  $languages;
    protected string $instanceOfPid;
    protected array  $propertyMapping;

    public function __construct(
        string $apiUrl,
        array  $languages,
        string $instanceOfPid,
        array  $propertyMapping
    ) {
        $this->apiUrl          = $apiUrl;
        $this->languages       = $languages;
        $this->instanceOfPid   = $instanceOfPid;
        $this->propertyMapping = $propertyMapping;
    }

    public function indexAction()
    {
        $query    = trim((string) $this->params()->fromQuery('query', ''));
        $lang     = trim((string) $this->params()->fromQuery('lang', 'it'));
        $property = trim((string) $this->params()->fromQuery('property', ''));

        if (!$query) {
            return new JsonModel(['results' => []]);
        }

        if (!in_array($lang, $this->languages)) {
            $lang = $this->languages[0];
        }

        $classFilter = null;
        if ($property && !empty($this->propertyMapping[$property]['classes'])) {
            $classFilter = $this->propertyMapping[$property]['classes'];
        }

        $searchResults = $this->searchEntities($query, $lang);
        if (empty($searchResults)) {
            return new JsonModel(['results' => []]);
        }

        if ($classFilter) {
            $ids           = array_column($searchResults, 'id');
            $searchResults = $this->filterByClass($searchResults, $ids, $classFilter);
        }

        $results = array_values(array_map(function ($item) {
            return [
                'value'       => $item['concepturi'],
                'label'       => $item['label'] ?? $item['id'],
                'description' => $item['description'] ?? '',
                'id'          => $item['id'],
            ];
        }, $searchResults));

        return new JsonModel(['results' => $results]);
    }

    protected function searchEntities(string $query, string $lang): array
    {
        $url = $this->apiUrl . '?' . http_build_query([
            'action'   => 'wbsearchentities',
            'search'   => $query,
            'language' => $lang,
            'type'     => 'item',
            'format'   => 'json',
            'limit'    => 20,
        ]);

        $response = $this->fetchUrl($url);
        if (!$response) {
            return [];
        }

        $data = json_decode($response, true);
        return $data['search'] ?? [];
    }

    public function labelsAction()
    {
        $id = trim((string) $this->params()->fromQuery('id', ''));
        if (!$id) {
            return new JsonModel(['labels' => []]);
        }

        $url = $this->apiUrl . '?' . http_build_query([
            'action'  => 'wbgetentities',
            'ids'     => $id,
            'props'   => 'labels',
            'format'  => 'json',
        ]);

        $response = $this->fetchUrl($url);
        if (!$response) {
            return new JsonModel(['labels' => []]);
        }

        $data   = json_decode($response, true);
        $entity = $data['entities'][$id] ?? null;
        if (!$entity) {
            return new JsonModel(['labels' => []]);
        }

        $labels = [];
        foreach ($entity['labels'] ?? [] as $lang => $labelData) {
            if (in_array($lang, $this->languages)) {
                $labels[$lang] = $labelData['value'];
            }
        }

        return new JsonModel(['labels' => $labels]);
    }

    protected function filterByClass(array $items, array $ids, array $classQids): array
    {
        $url = $this->apiUrl . '?' . http_build_query([
            'action' => 'wbgetentities',
            'ids'    => implode('|', $ids),
            'props'  => 'claims',
            'format' => 'json',
        ]);

        $response = $this->fetchUrl($url);
        if (!$response) {
            return $items;
        }

        $data     = json_decode($response, true);
        $entities = $data['entities'] ?? [];

        return array_filter($items, function ($item) use ($entities, $classQids) {
            $entity = $entities[$item['id']] ?? null;
            if (!$entity) {
                return false;
            }
            $claims = $entity['claims'][$this->instanceOfPid] ?? [];
            foreach ($claims as $claim) {
                $value = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
                if (in_array($value, $classQids)) {
                    return true;
                }
            }
            return false;
        });
    }

    protected function fetchUrl(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header'  => 'User-Agent: OmekaS-Wikibase-Module/1.0',
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
    }
}