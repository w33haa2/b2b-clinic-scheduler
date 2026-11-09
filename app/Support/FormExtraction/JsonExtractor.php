<?php

namespace App\Support\FormExtraction;

/**
 * Reads a JSON export. Either a list of records (whose keys are the fields)
 * or an explicit `fields` array of `{label, type?, options?}`.
 */
class JsonExtractor implements ExtractsFormFields
{
    public function __construct(private FieldGuesser $guesser) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['json'];
    }

    /**
     * @return list<ExtractedField>
     */
    public function extract(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = match (true) {
            isset($decoded['fields']) && is_array($decoded['fields']) => $decoded['fields'],
            isset($decoded['data']) && is_array($decoded['data']) => $decoded['data'],
            array_is_list($decoded) => $decoded,
            default => [$decoded],
        };

        $first = $rows[0] ?? null;

        if (! is_array($first)) {
            return [];
        }

        return isset($first['label']) || isset($first['name'])
            ? $this->fromDefinitions($rows)
            : $this->fromRecords($rows);
    }

    /**
     * A file that already describes a form.
     *
     * @param  array<int, mixed>  $rows
     * @return list<ExtractedField>
     */
    private function fromDefinitions(array $rows): array
    {
        $fields = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = (string) ($row['label'] ?? $row['name'] ?? '');
            $options = array_values(array_filter(
                array_map(strval(...), (array) ($row['options'] ?? [])),
            ));

            $field = $this->guesser->guess($label, $options, "fields[].label: {$label}");

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * A file of records: the keys of the first one are the fields.
     *
     * @param  array<int, mixed>  $rows
     * @return list<ExtractedField>
     */
    private function fromRecords(array $rows): array
    {
        $first = $rows[0];
        $fields = [];

        foreach (array_keys($first) as $key) {
            $values = [];

            foreach (array_slice($rows, 0, 25) as $row) {
                if (is_array($row) && isset($row[$key]) && is_scalar($row[$key])) {
                    $values[] = (string) $row[$key];
                }
            }

            $distinct = array_values(array_unique($values));
            $field = $this->guesser->guess(
                label: str_replace(['_', '-'], ' ', (string) $key),
                options: count($values) >= 3 && count($distinct) <= 6 && count($distinct) < count($values) ? $distinct : [],
                sourceLine: "key: {$key}",
            );

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
