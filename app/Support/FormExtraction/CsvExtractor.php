<?php

namespace App\Support\FormExtraction;

/**
 * Reads a spreadsheet export: the header row is the list of fields, and the
 * first few data rows hint at the type (a short, repeating set of values
 * becomes a choice list).
 */
class CsvExtractor implements ExtractsFormFields
{
    /**
     * How many data rows to look at when guessing choices.
     */
    private const SAMPLE_ROWS = 25;

    public function __construct(private FieldGuesser $guesser) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['csv', 'tsv'];
    }

    /**
     * @return list<ExtractedField>
     */
    public function extract(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $separator = str_ends_with(mb_strtolower($path), '.tsv') ? "\t" : ',';
        $header = fgetcsv($handle, escape: '\\', separator: $separator);

        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $samples = $this->sample($handle, count($header), $separator);
        fclose($handle);

        $fields = [];

        foreach ($header as $index => $label) {
            $field = $this->guesser->guess(
                label: (string) $label,
                options: $this->optionsFrom($samples[$index] ?? []),
                sourceLine: 'Column '.($index + 1).': '.$label,
            );

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Values seen under each column.
     *
     * @param  resource  $handle
     * @return array<int, list<string>>
     */
    private function sample($handle, int $columns, string $separator): array
    {
        $samples = array_fill(0, $columns, []);

        for ($row = 0; $row < self::SAMPLE_ROWS; $row++) {
            $values = fgetcsv($handle, escape: '\\', separator: $separator);

            if (! is_array($values)) {
                break;
            }

            foreach (array_slice($values, 0, $columns) as $index => $value) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $samples[$index][] = $value;
                }
            }
        }

        return $samples;
    }

    /**
     * A column with few distinct short values is a choice list.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function optionsFrom(array $values): array
    {
        $distinct = array_values(array_unique($values));

        if (count($values) < 3 || count($distinct) > 8 || count($distinct) === count($values)) {
            return [];
        }

        foreach ($distinct as $value) {
            if (mb_strlen($value) > 40) {
                return [];
            }
        }

        return $distinct;
    }
}
