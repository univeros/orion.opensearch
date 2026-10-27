<?php

declare(strict_types=1);

namespace Orion\OpenSearch;

use InvalidArgumentException;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;
use Orion\Support\Assert;

use function array_flip;
use function array_key_exists;
use function array_replace_recursive;
use function is_array;
use function in_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Neutral schema to OpenSearch mapping and back. The native mapping is the truth `inspect()` reports; the neutral
 * attributes are kept in the mapping's `_meta` only to tell apart what OpenSearch cannot (sortable versus facetable
 * both mean `doc_values`), and only where the native mapping agrees. Anything with no neutral equivalent (a `float`
 * rather than a `double`, an analyzer Orion did not create) comes back as {@see FieldType::Unknown}, so a plan
 * touching it fails closed. A vector is a `knn_vector` on the Lucene engine (HNSW, the metric as its space type),
 * and an index holding one has `index.knn` on, which the bundled k-NN plugin needs for its `knn` query.
 */
final class Mapping
{
    /** ISO 639 hints to the built-in language analyzers (the Lucene ones), one to one so `inspect()` can read them back. */
    private const array LANGUAGES = [
        'ar' => 'arabic', 'bg' => 'bulgarian', 'ca' => 'catalan', 'cs' => 'czech', 'da' => 'danish', 'de' => 'german',
        'el' => 'greek', 'en' => 'english', 'es' => 'spanish', 'eu' => 'basque', 'fa' => 'persian', 'fi' => 'finnish',
        'fr' => 'french', 'ga' => 'irish', 'gl' => 'galician', 'hi' => 'hindi', 'hu' => 'hungarian', 'hy' => 'armenian',
        'id' => 'indonesian', 'it' => 'italian', 'lt' => 'lithuanian', 'lv' => 'latvian', 'nl' => 'dutch',
        'no' => 'norwegian', 'pt' => 'portuguese', 'pt-br' => 'brazilian', 'ro' => 'romanian', 'ru' => 'russian',
        'sv' => 'swedish', 'th' => 'thai', 'tr' => 'turkish',
    ];
    private const array METRICS = ['cosine' => 'cosinesimil', 'dot_product' => 'innerproduct', 'euclidean' => 'l2'];
    private const array KNN_METHOD = ['name' => 'hnsw', 'engine' => 'lucene'];
    private const array PIPELINES = ['default_pipeline', 'final_pipeline', 'search.default_pipeline'];
    private const string FOLDED = '/^orion_(?:([a-z]{2,3}(?:-[a-z0-9]{2,8})?)_)?folded\z/';

    /**
     * The create-index body: mappings, the analyzers folding needs, `index.knn` when a vector is declared, then the
     * schema's `native('opensearch')` `settings` and `mappings` merged over them. The merge cannot loosen the
     * contract: the mapping stays `dynamic: strict`, its `_meta` stays Orion's and k-NN stays on.
     *
     * @return array<string, mixed>
     */
    public static function create(IndexSchema $schema, string $engine): array
    {
        $mappings = self::mappings($schema->fields);
        [$analyzers, $filters] = self::analysis($schema->fields);
        $body = ['mappings' => $mappings];
        if ($analyzers !== []) {
            $body = ['settings' => ['analysis' => ['analyzer' => $analyzers, ...($filters === [] ? [] : ['filter' => $filters])]], ...$body];
        }
        $native = $schema->native[$engine] ?? [];
        foreach ($native as $key => $_) {
            if ($key !== 'settings' && $key !== 'mappings') {
                throw new InvalidArgumentException(sprintf('Native OpenSearch settings take "settings" and "mappings" only, not %s.', Assert::show((string) $key)));
            }
        }
        if ($schema->orgKey !== null) {
            self::refuseOrgKeyChanges(is_array($native['mappings'] ?? null) ? $native['mappings'] : [], $schema->orgKey);
        }
        self::refusePipelines(is_array($native['settings'] ?? null) ? $native['settings'] : []);
        $body = array_replace_recursive($body, $native);
        $body['mappings']['dynamic'] = $mappings['dynamic'];
        $body['mappings']['_meta'] = $mappings['_meta'];
        // No engine returns vectors in a hit; the k-NN index holds them apart from the source.
        $vectors = self::vectorPaths($schema->fields);
        if ($vectors !== []) {
            $body['mappings']['_source'] = ['excludes' => $vectors];
            // The setting may also be written flat; one form only, so the server sees no contradiction.
            unset($body['settings']['index.knn'], $body['settings']['knn']);
            $body['settings']['index']['knn'] = true;
        }

        return $body;
    }

    /**
     * @param list<Field> $fields
     * @return list<string> dotted paths of the vector fields
     */
    private static function vectorPaths(array $fields, string $prefix = ''): array
    {
        $paths = [];
        foreach ($fields as $field) {
            if ($field->type === FieldType::Vector) {
                $paths[] = $prefix . $field->name;
            }
            $paths = [...$paths, ...self::vectorPaths($field->fields, $prefix . $field->name . '.')];
        }

        return $paths;
    }

    /**
     * An ingest pipeline would rewrite a document after Orion stamped it, a default search pipeline a query after the
     * scope restricted it: neither may be set on an index Orion owns. Settings may be written nested or dotted, with
     * or without the `index.` prefix, so they are read as the paths they name.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function refusePipelines(array $settings, string $prefix = ''): void
    {
        foreach ($settings as $key => $value) {
            $path = $prefix . $key;
            if (in_array(str_starts_with($path, 'index.') ? substr($path, 6) : $path, self::PIPELINES, true)) {
                throw new InvalidArgumentException(sprintf('Native OpenSearch settings cannot name a pipeline (%s): Orion\'s documents and scoped queries reach the index as sent.', Assert::show($path)));
            }
            if (is_array($value)) {
                self::refusePipelines($value, $path . '.');
            }
        }
    }

    /**
     * The org key is the tenant boundary on a shared index: native mappings may not retype, normalize or shadow it, nor
     * copy another field's values into it, any of which would let one tenant's filter match another's documents.
     *
     * @param array<array-key, mixed> $mappings
     */
    private static function refuseOrgKeyChanges(array $mappings, string $orgKey): void
    {
        foreach (['properties', 'runtime', 'derived'] as $section) {
            if (is_array($mappings[$section] ?? null) && array_key_exists($orgKey, $mappings[$section])) {
                throw new InvalidArgumentException(sprintf('Native OpenSearch mappings cannot redefine the org key %s.', Assert::show($orgKey)));
            }
        }
        self::refuseCopiesInto($mappings, $orgKey);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function refuseCopiesInto(array $node, string $orgKey): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'copy_to' && in_array($orgKey, (array) $value, true)) {
                throw new InvalidArgumentException(sprintf('Native OpenSearch mappings cannot copy values into the org key %s.', Assert::show($orgKey)));
            }
            if (is_array($value)) {
                self::refuseCopiesInto($value, $orgKey);
            }
        }
    }

    /**
     * @param list<Field> $fields
     * @return array{dynamic: string, _meta: array<string, mixed>, properties: array<string, mixed>}
     */
    public static function mappings(array $fields): array
    {
        return ['dynamic' => 'strict', '_meta' => ['orion' => ['fields' => Field::mapToArray($fields)]], 'properties' => self::properties($fields)];
    }

    /**
     * @param list<Field> $fields
     * @return array<string, mixed>
     */
    public static function properties(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field->name] = self::property($field);
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $mapping the index's `mappings`
     * @return list<Field>
     */
    public static function fields(array $mapping): array
    {
        $meta = $mapping['_meta']['orion']['fields'] ?? [];

        return self::read(is_array($mapping['properties'] ?? null) ? $mapping['properties'] : [], is_array($meta) ? $meta : []);
    }

    /**
     * The custom analyzers folding needs, and the named stemmer filters they reference (the engine accepts only
     * filter names inside an analyzer definition).
     *
     * @param list<Field> $fields
     * @return array{array<string, array<string, mixed>>, array<string, array<string, string>>}
     */
    public static function analysis(array $fields): array
    {
        $analyzers = [];
        $filters = [];
        foreach ($fields as $field) {
            $name = self::analyzer($field);
            if ($field->type === FieldType::Text && $field->folding && $name !== null) {
                $chain = ['lowercase', 'asciifolding'];
                if ($field->language !== null) {
                    $filter = 'orion_' . $field->language . '_stemmer';
                    $filters[$filter] = ['type' => 'stemmer', 'language' => self::language($field->language)];
                    $chain[] = $filter;
                }
                $analyzers[$name] = ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => $chain];
            }
            [$childAnalyzers, $childFilters] = self::analysis($field->fields);
            $analyzers = [...$analyzers, ...$childAnalyzers];
            $filters = [...$filters, ...$childFilters];
        }

        return [$analyzers, $filters];
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(Field $field): array
    {
        $flags = ['index' => $field->filterable, 'doc_values' => $field->sortable || $field->facetable];

        return match ($field->type) {
            FieldType::Text => ['type' => 'text', ...(self::analyzer($field) === null ? [] : ['analyzer' => self::analyzer($field)])],
            FieldType::Keyword => ['type' => 'keyword', ...$flags],
            FieldType::Integer => ['type' => 'long', ...$flags],
            FieldType::Float => ['type' => 'double', ...$flags],
            FieldType::Date => ['type' => 'date', ...$flags],
            FieldType::Boolean => ['type' => 'boolean', 'index' => $field->filterable, 'doc_values' => $field->facetable],
            FieldType::Geo => ['type' => 'geo_point', 'index' => $field->filterable, 'doc_values' => $field->sortable],
            FieldType::Vector => ['type' => 'knn_vector', 'dimension' => $field->dims, 'method' => ['name' => self::KNN_METHOD['name'], 'space_type' => self::METRICS[($field->metric ?? VectorMetric::Cosine)->value], 'engine' => self::KNN_METHOD['engine']]],
            FieldType::Object => ['type' => 'object', 'properties' => self::properties($field->fields)],
            FieldType::Nested => ['type' => 'nested', 'properties' => self::properties($field->fields)],
            FieldType::Unknown => throw new InvalidArgumentException(sprintf('The field %s has no neutral type.', Assert::show($field->name))),
        };
    }

    private static function analyzer(Field $field): ?string
    {
        if ($field->folding) {
            return $field->language === null ? 'orion_folded' : 'orion_' . $field->language . '_folded';
        }

        return $field->language === null ? null : self::language($field->language);
    }

    private static function language(string $iso): string
    {
        return self::LANGUAGES[$iso] ?? throw new InvalidArgumentException(sprintf('OpenSearch has no built-in analyzer for the language %s.', Assert::show($iso)));
    }

    /**
     * @param array<array-key, mixed> $properties
     * @param array<array-key, mixed> $meta
     * @return list<Field>
     */
    private static function read(array $properties, array $meta): array
    {
        $fields = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            $attributes = is_array($meta[$name] ?? null) ? $meta[$name] : [];
            $fields[] = is_array($property) ? self::field($name, $property, $attributes) : Field::unknown($name);
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $property
     * @param array<array-key, mixed> $meta
     */
    private static function field(string $name, array $property, array $meta): Field
    {
        $type = $property['type'] ?? (isset($property['properties']) ? 'object' : null);
        $index = ($property['index'] ?? true) !== false;
        $docValues = ($property['doc_values'] ?? true) !== false;
        // One doc_values flag means both sortable and facetable; _meta splits it, unless it contradicts the mapping.
        $sortable = $facetable = $docValues;
        $metaSortable = ($meta['sortable'] ?? false) === true;
        $metaFacetable = ($meta['facetable'] ?? false) === true;
        if ($docValues && ($metaSortable || $metaFacetable)) {
            [$sortable, $facetable] = [$metaSortable, $metaFacetable];
        }
        $children = is_array($property['properties'] ?? null) ? $property['properties'] : [];
        $childMeta = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];

        return match ($type) {
            'text' => self::text($name, $property, $meta),
            'keyword' => Field::keyword($name, $index, $sortable, $facetable),
            'long' => Field::integer($name, $index, $sortable, $facetable),
            'double' => Field::float($name, $index, $sortable, $facetable),
            'date' => Field::date($name, $index, $sortable, $facetable),
            'boolean' => Field::boolean($name, $index, $docValues),
            'geo_point' => Field::geo($name, $index, $docValues),
            'knn_vector' => self::vector($name, $property),
            'object' => $children === [] ? Field::unknown($name) : Field::object($name, ...self::read($children, $childMeta)),
            'nested' => $children === [] ? Field::unknown($name) : Field::nested($name, ...self::read($children, $childMeta)),
            default => Field::unknown($name),
        };
    }

    /**
     * @param array<array-key, mixed> $property
     * @param array<array-key, mixed> $meta
     */
    private static function text(string $name, array $property, array $meta): Field
    {
        $analyzer = $property['analyzer'] ?? 'standard';
        $boost = (float) ($meta['boost'] ?? 1.0);
        if ($analyzer === 'standard') {
            return Field::text($name, boost: $boost);
        }
        $languages = array_flip(self::LANGUAGES);
        if (is_string($analyzer) && isset($languages[$analyzer])) {
            return Field::text($name, language: (string) $languages[$analyzer], boost: $boost);
        }
        if (is_string($analyzer) && preg_match(self::FOLDED, $analyzer, $match) === 1) {
            return Field::text($name, language: ($match[1] ?? '') === '' ? null : $match[1], boost: $boost, folding: true);
        }

        return Field::unknown($name);
    }

    /**
     * Only a float vector on the Lucene engine, what Orion creates, reads back as a vector: another engine or data
     * type, or a model-backed field, has no neutral equivalent and fails a plan closed. The space type sits in the
     * method when the field was created through one, on the field itself otherwise (OpenSearch 2.17 and later).
     *
     * @param array<array-key, mixed> $property
     */
    private static function vector(string $name, array $property): Field
    {
        $method = is_array($property['method'] ?? null) ? $property['method'] : [];
        if (($method['engine'] ?? null) !== self::KNN_METHOD['engine'] || ($property['data_type'] ?? 'float') !== 'float' || isset($property['model_id'])) {
            return Field::unknown($name);
        }
        $spaceType = $method['space_type'] ?? $property['space_type'] ?? '';
        $metric = array_flip(self::METRICS)[is_string($spaceType) ? $spaceType : ''] ?? null;
        $dims = $property['dimension'] ?? null;
        if ($metric === null || !is_int($dims)) {
            return Field::unknown($name);
        }

        return Field::vector($name, $dims, VectorMetric::from($metric));
    }
}
