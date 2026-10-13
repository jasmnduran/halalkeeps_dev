<?php

function ensureCommitteeEvidenceReviewsTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS committee_evidence_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            inspection_id INT DEFAULT NULL,
            item_type ENUM('inspection','ncr','corrective_action','document') NOT NULL,
            item_id VARCHAR(191) NOT NULL,
            item_label VARCHAR(255) NOT NULL,
            committee_role ENUM('impartial','decision') NOT NULL,
            committee_member_id INT NOT NULL,
            review_status ENUM('compliant','non_compliant') NOT NULL,
            reason TEXT DEFAULT NULL,
            ncr_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_committee_evidence_item (application_id, item_type, item_id, committee_role),
            KEY idx_committee_evidence_app (application_id),
            KEY idx_committee_evidence_ncr (ncr_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function loadCommitteeEvidenceReviews($conn, $applicationId, $roleKey) {
    $reviews = [];
    $stmt = $conn->prepare("
        SELECT *
        FROM committee_evidence_reviews
        WHERE application_id = ? AND committee_role = ?
    ");
    $stmt->bind_param("is", $applicationId, $roleKey);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $reviews[$row['item_type'] . ':' . $row['item_id']] = $row;
    }
    return $reviews;
}

function ensureImpartialCarShell($conn, $applicationId, $submittedBy) {
    $shellStmt = $conn->prepare("SELECT id FROM corrective_action_reports WHERE application_id = ? AND ncr_id IS NULL AND report_content = '' LIMIT 1");
    $shellStmt->bind_param("i", $applicationId);
    $shellStmt->execute();
    $existingShell = $shellStmt->get_result()->fetch_assoc();

    if ($existingShell) {
        $updShell = $conn->prepare("UPDATE corrective_action_reports SET status='submitted', submitted_by = ?, submitted_at = NOW(), updated_at = NOW() WHERE id = ?");
        $updShell->bind_param("ii", $submittedBy, $existingShell['id']);
        $updShell->execute();
    } else {
        $insShell = $conn->prepare("INSERT INTO corrective_action_reports (application_id, ncr_id, action_text, attachment_path, status, submitted_by, submitted_at, report_content, corrective_actions) VALUES (?, NULL, '', '', 'submitted', ?, NOW(), '', '')");
        $insShell->bind_param("ii", $applicationId, $submittedBy);
        $insShell->execute();
    }
}

function generateCommitteeNcrNumber($conn) {
    do {
        $number = 'NCR-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $stmt = $conn->prepare("SELECT id FROM ncr_reports WHERE ncr_number = ? LIMIT 1");
        $stmt->bind_param("s", $number);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
    } while ($exists);
    return $number;
}

function saveCommitteeEvidenceReview($conn, $roleKey, $userId) {
    $appId = intval($_POST['application_id'] ?? 0);
    $inspectionId = intval($_POST['inspection_id'] ?? 0);
    $itemType = sanitize($_POST['item_type'] ?? '');
    $itemId = sanitize($_POST['item_id'] ?? '');
    $itemLabel = sanitize($_POST['item_label'] ?? '');
    $status = sanitize($_POST['review_status'] ?? '');
    $reason = sanitize($_POST['reason'] ?? '');

    $allowedTypes = ['inspection', 'ncr', 'corrective_action', 'document'];
    if (!$appId || !$inspectionId || !in_array($itemType, $allowedTypes, true) || $itemId === '' || $itemLabel === '') {
        return ['success' => false, 'message' => 'Invalid evidence item. Please try again.'];
    }
    if (!in_array($status, ['compliant', 'non_compliant'], true)) {
        return ['success' => false, 'message' => 'Please choose compliant or non-compliant.'];
    }
    if ($status === 'non_compliant' && trim($reason) === '') {
        return ['success' => false, 'message' => 'Please provide a reason before sending this item back.'];
    }

    $ownerStmt = $conn->prepare("
        SELECT ha.business_owner_id, loi.company_name
        FROM hdp_applications ha
        JOIN letter_of_intent loi ON ha.loi_id = loi.id
        WHERE ha.id = ?
        LIMIT 1
    ");
    $ownerStmt->bind_param("i", $appId);
    $ownerStmt->execute();
    $owner = $ownerStmt->get_result()->fetch_assoc();
    if (!$owner) {
        return ['success' => false, 'message' => 'Application not found.'];
    }

    $existingStmt = $conn->prepare("
        SELECT id, ncr_id
        FROM committee_evidence_reviews
        WHERE application_id = ? AND item_type = ? AND item_id = ? AND committee_role = ?
        LIMIT 1
    ");
    $existingStmt->bind_param("isss", $appId, $itemType, $itemId, $roleKey);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();

    $ncrId = $existing['ncr_id'] ?? null;
    if ($status === 'non_compliant') {
        $roleLabel = $roleKey === 'decision' ? 'Decision Committee' : 'Impartial Committee';
        $finding = $roleLabel . " evidence review marked this item as non-compliant:\n" . $itemLabel . "\n\nReason: " . $reason;
        $required = 'Please submit a corrective action report addressing the committee finding for "' . $itemLabel . '": ' . $reason;

        if ($ncrId) {
            $updNcr = $conn->prepare("
                UPDATE ncr_reports
                SET finding_details = ?, corrective_action_required = ?, status = 'open', updated_at = NOW()
                WHERE id = ?
            ");
            $updNcr->bind_param("ssi", $finding, $required, $ncrId);
            $updNcr->execute();
        } else {
            $number = generateCommitteeNcrNumber($conn);
            $deadline = date('Y-m-d', strtotime('+7 days'));
            $followup = date('Y-m-d', strtotime('+14 days'));
            $brief = $roleLabel . ' item-level non-compliance';
            $documentReference = ucfirst(str_replace('_', ' ', $itemType));
            $insNcr = $conn->prepare("
                INSERT INTO ncr_reports
                    (inspection_id, application_id, ncr_number, finding_details, category,
                     corrective_action_required, deadline, document_reference, brief_summary,
                     recommendation, followup_date, prepared_by, status)
                VALUES (?, ?, ?, ?, 'minor', ?, ?, ?, ?, '', ?, ?, 'open')
            ");
            $insNcr->bind_param(
                "iisssssssi",
                $inspectionId,
                $appId,
                $number,
                $finding,
                $required,
                $deadline,
                $documentReference,
                $brief,
                $followup,
                $userId
            );
            $insNcr->execute();
            $ncrId = $conn->insert_id;
        }

        $feedbackType = $roleKey === 'decision' ? 'dc_defer' : 'ic_return';
        $newStatus = $roleKey === 'decision' ? 'deferred' : 'returned';
        $feedback = $conn->real_escape_string($finding);
        $conn->query("
            UPDATE hdp_applications
            SET status = '$newStatus',
                committee_feedback = '$feedback',
                committee_feedback_type = '$feedbackType',
                committee_feedback_at = NOW()
            WHERE id = $appId
        ");
        if ($itemType === 'document') {
            ensureImpartialCarShell($conn, $appId, $userId);
        }
        createNotification(
            $conn,
            intval($owner['business_owner_id']),
            $roleLabel . ' NCR Issued',
            'An NCR has been issued from committee evidence review for ' . ($owner['company_name'] ?? 'your application') . '. Please submit a corrective action report.',
            'warning',
            BASE_URL . 'dashboard/business_owner/audit_findings.php?tab=car&ncr=' . intval($ncrId)
        );
    }

    if ($existing) {
        $upd = $conn->prepare("
            UPDATE committee_evidence_reviews
            SET inspection_id = ?, item_label = ?, committee_member_id = ?, review_status = ?,
                reason = ?, ncr_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->bind_param("isissii", $inspectionId, $itemLabel, $userId, $status, $reason, $ncrId, $existing['id']);
        $upd->execute();
    } else {
        $ins = $conn->prepare("
            INSERT INTO committee_evidence_reviews
                (application_id, inspection_id, item_type, item_id, item_label, committee_role,
                 committee_member_id, review_status, reason, ncr_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param("iissssissi", $appId, $inspectionId, $itemType, $itemId, $itemLabel, $roleKey, $userId, $status, $reason, $ncrId);
        $ins->execute();
    }

    $activity = $status === 'non_compliant' ? 'Committee NCR Created' : 'Committee Evidence Accepted';
    logActivity($conn, $userId, $activity, ucfirst($roleKey) . ' committee marked ' . $itemLabel . ' as ' . str_replace('_', '-', $status) . ' for App #' . $appId, 'committee');

    return [
        'success' => true,
        'message' => $status === 'non_compliant'
            ? 'Non-compliance saved and an NCR was issued to the business owner.'
            : 'Evidence item marked compliant.'
    ];
}

function renderCommitteeEvidenceReviewControls($appId, $inspectionId, $itemType, $itemId, $itemLabel, $existing = null) {
    $status = $existing['review_status'] ?? '';
    $reason = $existing['reason'] ?? '';
    $formId = 'cer-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $itemType . '-' . $itemId);
    ob_start();
    ?>
    <form method="POST" class="committee-review-form" id="<?= htmlspecialchars($formId) ?>">
        <input type="hidden" name="submit_committee_evidence_review" value="1">
        <input type="hidden" name="application_id" value="<?= intval($appId) ?>">
        <input type="hidden" name="inspection_id" value="<?= intval($inspectionId) ?>">
        <input type="hidden" name="item_type" value="<?= htmlspecialchars($itemType) ?>">
        <input type="hidden" name="item_id" value="<?= htmlspecialchars((string)$itemId) ?>">
        <input type="hidden" name="item_label" value="<?= htmlspecialchars($itemLabel) ?>">
        <div class="cer-actions">
            <label class="cer-choice <?= $status === 'compliant' ? 'active-ok' : '' ?>">
                <input type="radio" name="review_status" value="compliant" <?= $status === 'compliant' ? 'checked' : '' ?> onchange="toggleCommitteeReason('<?= htmlspecialchars($formId) ?>')">
                <i class="fas fa-check"></i> Compliant
            </label>
            <label class="cer-choice <?= $status === 'non_compliant' ? 'active-bad' : '' ?>">
                <input type="radio" name="review_status" value="non_compliant" <?= $status === 'non_compliant' ? 'checked' : '' ?> onchange="toggleCommitteeReason('<?= htmlspecialchars($formId) ?>')">
                <i class="fas fa-times"></i> Non-compliant
            </label>
        </div>
        <div class="cer-reason" style="<?= $status === 'non_compliant' ? 'display:block;' : 'display:none;' ?>">
            <textarea name="reason" rows="2" class="form-control" placeholder="Reason for sending this item back..."><?= htmlspecialchars($reason) ?></textarea>
        </div>
        <?php if (!empty($existing['ncr_id'])): ?>
        <div class="cer-ncr-note"><i class="fas fa-exclamation-triangle"></i> NCR #<?= intval($existing['ncr_id']) ?> issued</div>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-outline cer-save"><i class="fas fa-save"></i> Save Item Review</button>
    </form>
    <?php
    return ob_get_clean();
}
