<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../MemberHouseholdService.php';

class HouseholdController {
    private function service(PDO $db) {
        return new MemberHouseholdService($db);
    }

    public function show($id) {
        $db = Database::connect();
        $detail = $this->service($db)->getHouseholdDetail((int)$id);
        if (!$detail) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Household not found.']);
            return;
        }
        echo json_encode($detail);
    }

    public function ensureForMember($memberId) {
        $db = Database::connect();
        try {
            $detail = $this->service($db)->ensureLocalHouseholdForMember((int)$memberId);
            echo json_encode([
                'success' => true,
                'detail' => $detail
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update($id) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $insuranceStatus = trim((string)($input['insurance_status'] ?? 'Unknown'));
        $notes = trim((string)($input['notes'] ?? ''));

        if (!in_array($insuranceStatus, ['Unknown', 'Yes', 'No'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Insurance status must be Unknown, Yes or No.']);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE member_households
            SET insurance_status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$insuranceStatus, $notes, (int)$id]);

        echo json_encode(['success' => true]);
    }

    public function uploadAgreement($id) {
        $db = Database::connect();
        $detail = $this->service($db)->getHouseholdDetail((int)$id);
        if (!$detail) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Household not found.']);
            return;
        }

        try {
            $filePath = $this->storeUploadedAgreementFile((int)$id);
            if (!$filePath) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Please choose a PDF, JPG or PNG agreement file.']);
                return;
            }

            $signedAt = trim((string)($_POST['signed_at'] ?? ''));
            if ($signedAt === '') {
                $signedAt = date('Y-m-d H:i:s');
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $signedAt)) {
                $signedAt .= ' 00:00:00';
            }

            $originalName = trim((string)($_FILES['agreement_file']['name'] ?? basename($filePath)));
            $mimeType = trim((string)($_FILES['agreement_file']['type'] ?? 'application/octet-stream'));

            $stmt = $db->prepare("
                INSERT INTO household_agreement_documents (
                    household_id,
                    file_path,
                    original_name,
                    mime_type,
                    source_type,
                    signed_at,
                    is_active
                ) VALUES (?, ?, ?, ?, 'paper', ?, 1)
            ");
            $stmt->execute([(int)$id, $filePath, $originalName, $mimeType, $signedAt]);

            $householdUpdate = $db->prepare("
                UPDATE member_households
                SET agreement_signed_at = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $householdUpdate->execute([$signedAt, (int)$id]);

            $this->service($db)->recalculateAgreementStatus((int)$id);

            echo json_encode([
                'success' => true,
                'file_path' => $filePath
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteAgreement() {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $documentId = isset($input['id']) ? (int)$input['id'] : 0;
        if ($documentId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Agreement document id is required.']);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT id, household_id, file_path FROM household_agreement_documents WHERE id = ? LIMIT 1");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Agreement document not found.']);
            return;
        }

        $delete = $db->prepare("UPDATE household_agreement_documents SET is_active = 0 WHERE id = ?");
        $delete->execute([$documentId]);
        $this->deleteStoredFile($doc['file_path'] ?? null);
        $this->service($db)->recalculateAgreementStatus((int)$doc['household_id']);

        echo json_encode(['success' => true]);
    }

    private function storeUploadedAgreementFile($householdId) {
        if (!isset($_FILES['agreement_file']) || !is_array($_FILES['agreement_file'])) {
            return null;
        }

        $file = $_FILES['agreement_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Agreement upload failed.');
        }

        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) {
            throw new Exception('Uploaded agreement file was not received correctly.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];
        if (!$mime || !isset($allowed[$mime])) {
            throw new Exception('Please upload a PDF, JPG or PNG agreement file.');
        }

        $dir = __DIR__ . '/../../uploads/agreements';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('Could not create agreement uploads directory.');
        }

        $filename = sprintf(
            'household-%d-%s.%s',
            (int)$householdId,
            bin2hex(random_bytes(8)),
            $allowed[$mime]
        );
        $destination = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new Exception('Failed to save the agreement file.');
        }

        return '/uploads/agreements/' . $filename;
    }

    private function deleteStoredFile($path) {
        $path = trim((string)$path);
        if ($path === '' || strpos($path, '/uploads/agreements/') !== 0) {
            return;
        }

        $fullPath = realpath(__DIR__ . '/../../' . ltrim($path, '/'));
        $uploadsBase = realpath(__DIR__ . '/../../uploads/agreements');
        if ($fullPath && $uploadsBase && strpos($fullPath, $uploadsBase) === 0 && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
