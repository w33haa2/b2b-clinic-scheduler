<?php

namespace App\Support\FormExtraction;

use Illuminate\Support\Str;

/**
 * Reads a printed form that has been turned into plain text.
 *
 * It looks for the shapes a paper form actually uses:
 *
 * ```
 * SECTION HEADING
 * Last name: ______________
 * Sex:  ☐ Male  ☐ Female
 * 3. Do you have any allergies?
 * ```
 *
 * The other drivers (PDF, DOCX, plain text files) all reduce their file to
 * text and finish here, so the heuristics live in one place.
 */
class PlainTextExtractor implements ExtractsFormFields
{
    public function __construct(private FieldGuesser $guesser) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['txt', 'md', 'text'];
    }

    /**
     * @return list<ExtractedField>
     */
    public function extract(string $path): array
    {
        return $this->fromText((string) file_get_contents($path));
    }

    /**
     * @return list<ExtractedField>
     */
    public function fromText(string $text): array
    {
        $fields = [];
        $section = null;

        foreach ($this->lines($text) as $line) {
            if ($this->isSectionHeading($line)) {
                $section = Str::of($line)->squish()->title()->value();

                continue;
            }

            $field = $this->fieldFromLine($line, $section);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);

        // A printed form often puts two fields on one line: "First name: ___   Last name: ___".
        $normalised = preg_replace('/\s{3,}(?=[A-Z][^:\n]{1,40}:)/u', "\n", $normalised) ?? $normalised;

        return array_values(array_filter(
            array_map(trim(...), explode("\n", $normalised)),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * An all-caps or title-case line with no colon and no fill-in rule.
     */
    private function isSectionHeading(string $line): bool
    {
        if (str_contains($line, ':') || mb_strlen($line) > 48 || mb_strlen($line) < 3) {
            return false;
        }

        return preg_match('/^[\p{Lu}\s&\/()-]+$/u', $line) === 1 && str_word_count($line) <= 6;
    }

    private function fieldFromLine(string $line, ?string $section): ?ExtractedField
    {
        $options = $this->optionsIn($line);
        $label = $this->labelIn($line);

        if ($label === null) {
            return null;
        }

        return $this->guesser->guess($label, $options, $line, $section);
    }

    /**
     * The label part of a line: before the colon, or the whole question.
     */
    private function labelIn(string $line): ?string
    {
        if (str_contains($line, ':')) {
            return Str::before($line, ':');
        }

        if (str_ends_with($line, '?')) {
            return rtrim($line, '?');
        }

        // "Last name ______________"
        if (preg_match('/^(.{2,60}?)\s*[_.\x{2026}]{3,}/u', $line, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Printed choices on a line: "☐ Male ☐ Female", "[ ] Yes [ ] No".
     *
     * @return list<string>
     */
    private function optionsIn(string $line): array
    {
        $after = str_contains($line, ':') ? Str::after($line, ':') : $line;

        if (preg_match_all('/(?:☐|☑|□|■|\[\s*\]|\(\s*\))\s*([^\x{2610}\x{2611}\x{25A1}\x{25A0}\[\]()\n]{1,40})/u', $after, $matches) < 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $option): string => Str::of($option)->squish()->rtrim('/,;')->value(),
            $matches[1],
        )));
    }
}
