<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Normalises free-text product names for lexical matching.
 *
 * There are two implementations of this transform — this one and
 * `qeema_ml.matching.normalise` on the Python side. That duplication is
 * deliberate: seeding must not require the ML service to be running, and
 * Postgres trigram queries need the normalised form available in SQL. Both are
 * tested against the shared fixtures in `contracts/text-normalisation.json`,
 * because a drift between them would be almost invisible and would quietly
 * destroy matching — text normalised one way at seed time and another way at
 * query time simply will not match.
 *
 * How much this matters, measured: `حليب اطفال` and `حليب أطفال` differ by a
 * single hamza and score only 0.571 trigram similarity unnormalised. After
 * normalisation they are identical.
 */
final class TextNormalizer
{
    /**
     * Characters folded to a single canonical form.
     *
     * Informal Arabic writing treats each of these groups as interchangeable,
     * so a matcher that distinguishes them will miss obvious matches.
     *
     * @var array<string, string>
     */
    private const CHARACTER_FOLDS = [
        // Alef variants -> bare alef
        "\u{0623}" => "\u{0627}",  // أ hamza above
        "\u{0625}" => "\u{0627}",  // إ hamza below
        "\u{0622}" => "\u{0627}",  // آ madda
        "\u{0671}" => "\u{0627}",  // ٱ wasla
        // Taa marbuta -> haa
        "\u{0629}" => "\u{0647}",  // ة
        // Alef maksura -> yaa
        "\u{0649}" => "\u{064A}",  // ى
        // Hamza carriers -> their base letters
        "\u{0624}" => "\u{0648}",  // ؤ
        "\u{0626}" => "\u{064A}",  // ئ
    ];

    /**
     * Marks removed outright: harakat, shadda, sukun, superscript alef, tatweel
     * and the standalone hamza. None of them change which product is meant.
     */
    private const REMOVED_MARKS = '/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{0621}]/u';

    /** Arabic-Indic (U+0660..U+0669) and extended Arabic-Indic (U+06F0..U+06F9). */
    private const DIGIT_BLOCKS = ["\u{0660}", "\u{06F0}"];

    /** Punctuation, Arabic or Latin, becomes a space rather than vanishing. */
    private const PUNCTUATION = '/[\x{060C}\x{061B}\x{061F}\x{066A}\x{066B}\x{066C}\x{06D4},;:!?()\[\]{}"\'\/\\\\|_+*=<>@#$%^&~`]/u';

    /**
     * Normalise a string for storage in `normalized_text` and for querying.
     *
     * The transform is idempotent: normalising an already-normalised string
     * returns it unchanged.
     */
    public function normalize(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $result = $this->foldDigits($text);
        $result = preg_replace(self::REMOVED_MARKS, '', $result) ?? $result;
        $result = strtr($result, self::CHARACTER_FOLDS);
        $result = preg_replace(self::PUNCTUATION, ' ', $result) ?? $result;

        // Hyphens and dots are kept: they carry meaning inside prices ("12.50")
        // and product sizes ("400-gram"), so only surrounding space is squeezed.
        $result = mb_strtolower($result, 'UTF-8');
        $result = preg_replace('/\s+/u', ' ', $result) ?? $result;

        return trim($result);
    }

    /**
     * Fold Arabic-Indic digit blocks to ASCII.
     *
     * Done before mark removal so that a digit carrying a combining mark is not
     * left orphaned.
     */
    private function foldDigits(string $text): string
    {
        foreach (self::DIGIT_BLOCKS as $blockStart) {
            // mb_ord/mb_chr cannot fail here: the block starts are literal,
            // valid codepoints defined above.
            $base = mb_ord($blockStart, 'UTF-8');

            $map = [];
            for ($i = 0; $i <= 9; $i++) {
                $map[mb_chr($base + $i, 'UTF-8')] = (string) $i;
            }

            $text = strtr($text, $map);
        }

        return $text;
    }

    /**
     * Normalise and split into tokens, for callers that need word-level work.
     *
     * @return list<string>
     */
    public function tokenize(?string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), fn (string $t): bool => $t !== ''));
    }
}
