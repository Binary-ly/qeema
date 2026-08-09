# SPDX-License-Identifier: Apache-2.0
"""Text normalisation for product matching.

This is the Python half of a transform that also exists in PHP
(``App\\Support\\Text\\TextNormalizer``). The duplication is deliberate —
seeding must not require this service to be running, and Postgres trigram
queries need the normalised form available in SQL — but it is dangerous:
two implementations of the same transform drift, and a drift here is nearly
invisible. Text normalised one way at index time and another way at query time
simply does not match, and nothing errors.

Both sides are therefore tested against the same fixtures in
``contracts/text-normalisation.json``. If you change one, the other's test
fails. Keep it that way.
"""

from __future__ import annotations

import re
import unicodedata

# --- characters folded to a single canonical form ---------------------------
# Informal Arabic writing treats each group as interchangeable, so a matcher
# that distinguishes them misses obvious matches.
_CHARACTER_FOLDS = {
    # Alef variants -> bare alef
    "أ": "ا",  # أ hamza above
    "إ": "ا",  # إ hamza below
    "آ": "ا",  # آ madda
    "ٱ": "ا",  # ٱ wasla
    # Taa marbuta -> haa
    "ة": "ه",  # ة
    # Alef maksura -> yaa
    "ى": "ي",  # ى
    # Hamza carriers -> base letters
    "ؤ": "و",  # ؤ
    "ئ": "ي",  # ئ
}

# Harakat, shadda, sukun, superscript alef, tatweel and the standalone hamza.
# None of them change which product is meant.
_REMOVED_MARKS = re.compile(r"[ً-ٰٟـء]")

# Arabic-Indic (U+0660..U+0669) and extended Arabic-Indic (U+06F0..U+06F9).
_DIGIT_BLOCKS = (0x0660, 0x06F0)

# Punctuation becomes a space rather than vanishing, so "ارز،ابيض" does not
# collapse into one token.
_PUNCTUATION = re.compile(r"[،؛؟٪٫٬۔,;:!?()\[\]{}\"'/\\|_+*=<>@#$%^&~`]")

_WHITESPACE = re.compile(r"\s+")

_DIGIT_TRANSLATION = {ord(chr(block + i)): str(i) for block in _DIGIT_BLOCKS for i in range(10)}

_FOLD_TRANSLATION = {ord(k): v for k, v in _CHARACTER_FOLDS.items()}


def normalise(text: str | None) -> str:
    """Normalise a product name for lexical matching.

    Idempotent: normalising an already-normalised string returns it unchanged.

    Measured motivation: ``حليب اطفال`` and ``حليب أطفال`` differ by a single
    hamza and score only 0.571 trigram similarity unnormalised. After this they
    are identical.
    """
    if not text or not text.strip():
        return ""

    # NFC first so a decomposed alef-plus-hamza folds the same way a composed
    # one does. Without it, two visually identical strings normalise
    # differently depending on which keyboard produced them.
    result = unicodedata.normalize("NFC", text)

    result = result.translate(_DIGIT_TRANSLATION)
    result = _REMOVED_MARKS.sub("", result)
    result = result.translate(_FOLD_TRANSLATION)
    result = _PUNCTUATION.sub(" ", result)

    # Hyphens and dots survive: they carry meaning inside prices ("12.50") and
    # sizes ("400-gram").
    result = result.lower()
    result = _WHITESPACE.sub(" ", result)

    return result.strip()


def tokenise(text: str | None) -> list[str]:
    """Normalise and split into tokens."""
    normalised = normalise(text)

    return [t for t in normalised.split(" ") if t] if normalised else []
