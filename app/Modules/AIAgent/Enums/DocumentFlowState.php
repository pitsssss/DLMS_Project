<?php

namespace App\Modules\AIAgent\Enums;

enum DocumentFlowState: string
{
    case Idle = 'idle';
    case ApplicationSelection = 'application_selection';
    case DocumentUploadOffer = 'document_upload_offer';
    case ManualUploadSelected = 'manual_upload_selected';
    case DocumentSelection = 'document_selection';
    case AwaitingFile = 'awaiting_file';
    case UploadProcessing = 'upload_processing';
    case DocumentSelectionAfterUpload = 'document_selection_after_upload';
    case SubmittingForReview = 'submitting_for_review';
    case Completed = 'completed';
    case Failed = 'failed';

    public function allowsFileUpload(): bool
    {
        return $this === self::AwaitingFile;
    }

    public function allowsDocumentSelection(): bool
    {
        return in_array($this, [
            self::DocumentSelection,
            self::DocumentSelectionAfterUpload,
        ], true);
    }

    public function allowsUploadOfferDecision(): bool
    {
        return $this === self::DocumentUploadOffer;
    }
}
