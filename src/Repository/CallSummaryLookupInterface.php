<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallSummary;
use App\Entity\CallTranscript;

interface CallSummaryLookupInterface
{
    public function findOneByTranscript(CallTranscript $transcript): ?CallSummary;
}
