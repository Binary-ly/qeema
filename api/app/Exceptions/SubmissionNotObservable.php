<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Submission;
use RuntimeException;

/**
 * A submission a reviewer approved that still cannot become an observation.
 *
 * Almost always an unrecognised unit: the reviewer is certain the phrase means
 * infant formula, but "صفيحة" is not a unit this deployment knows how to
 * convert, so there is no defensible price per base unit to publish.
 *
 * This exists because approving used to *look* like it worked. The observation
 * came back null, the submission was marked resolved anyway, and the price
 * silently never reached the index — the reviewer had every reason to believe
 * they had published it. Failing loudly and rolling the decision back is the
 * honest alternative: the reviewer can reject it with a reason, or an operator
 * can add the unit and the submission can be approved afterwards.
 */
final class SubmissionNotObservable extends RuntimeException
{
    public function __construct(public readonly Submission $submission)
    {
        parent::__construct(sprintf(
            'Submission %s cannot be normalised to a price per base unit; its unit "%s" is not configured for this country.',
            $submission->id,
            (string) $submission->raw_unit,
        ));
    }
}
