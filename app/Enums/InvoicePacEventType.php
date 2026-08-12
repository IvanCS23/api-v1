<?php

namespace App\Enums;

enum InvoicePacEventType: string
{
    case DraftCreated = 'draft_created';
    case DraftSynced = 'draft_synced';
    case DraftUpdated = 'draft_updated';

    case IssueAttempted = 'issue_attempted';
    case IssueSucceeded = 'issue_succeeded';
    case IssueFailed = 'issue_failed';

    case StampAttempted = 'stamp_attempted';
    case StampSucceeded = 'stamp_succeeded';
    case StampFailed = 'stamp_failed';

    case Reconciled = 'reconciled';
    case ReconciliationRequired = 'reconciliation_required';

    case ArtifactsStored = 'artifacts_stored';
    case ArtifactsFailed = 'artifacts_failed';

    case CancellationRequested = 'cancellation_requested';
    case CancellationPending = 'cancellation_pending';
    case CancellationAccepted = 'cancellation_accepted';
    case CancellationRejected = 'cancellation_rejected';
    case CancellationExpired = 'cancellation_expired';

    case CancellationReceiptAttempted = 'cancellation_receipt_attempted';
    case CancellationReceiptStored = 'cancellation_receipt_stored';
    case CancellationReceiptUnavailable = 'cancellation_receipt_unavailable';
    case CancellationReceiptIdentityMismatch = 'cancellation_receipt_identity_mismatch';
}
