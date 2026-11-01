<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Processing = 'processing';
    case Filed = 'filed';
    case NeedsReview = 'needs_review';
    case Uncategorised = 'uncategorised';
    case ProcessingFailed = 'processing_failed';
}
