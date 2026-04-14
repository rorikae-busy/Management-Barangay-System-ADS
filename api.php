<?php
declare(strict_types=1);

session_start();
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/db.php";

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    // Support both JSON body and multipart form (for file uploads, use $_POST)
    $raw = file_get_contents("php://input");
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST ?: [];
}

function requireAuth(): int
{
    if (empty($_SESSION["user_id"])) {
        jsonResponse(["success" => false, "error" => "Unauthorized. Please log in."], 401);
    }
    return (int) $_SESSION["user_id"];
}

function requireFields(array $data, array $fields): void
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $data) || trim((string) $data[$field]) === "") {
            jsonResponse(["success" => false, "error" => "Missing field: {$field}"], 422);
        }
    }
}

try {
    $conn     = db();
    $resource = $_GET["resource"] ?? "bootstrap";
    $data     = requestData();

    switch ($resource) {

        // ── BOOTSTRAP ─────────────────────────────────────────────────────────
        case "bootstrap":
            $userId = requireAuth();
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── RESIDENT SAVE (create / update) ───────────────────────────────────
        case "resident-save":
            requireAuth();
            requireFields($data, ["fullName", "address", "zone", "status"]);

            $householdId = !empty($data["householdId"]) ? (int) $data["householdId"] : null;

            // Resolve household display label
            $householdLabel = "";
            if ($householdId) {
                $hq = $conn->prepare("SELECT household_code FROM households WHERE id = ?");
                $hq->bind_param("i", $householdId);
                $hq->execute();
                $hrow = $hq->get_result()->fetch_assoc();
                $householdLabel = $hrow ? $hrow["household_code"] : "";
            }

            $birthday    = !empty($data["birthday"]) ? $data["birthday"] : null;
            $gender      = $data["gender"] ?? "";
            $civilStatus = $data["civilStatus"] ?? "";
            $contact     = $data["contact"] ?? "";

            if (!empty($data["dbId"])) {
                // Get old household_id before updating
                $oldHq = $conn->prepare("SELECT household_id FROM residents WHERE id = ?");
                $oldHq->bind_param("i", $data["dbId"]);
                $oldHq->execute();
                $oldRow = $oldHq->get_result()->fetch_assoc();
                $oldHouseholdId = $oldRow ? $oldRow["household_id"] : null;

                $stmt = $conn->prepare(
                    "UPDATE residents
                     SET full_name = ?, birthday = ?, gender = ?, civil_status = ?,
                         address = ?, contact = ?, household_id = ?, household = ?,
                         zone_name = ?, status = ?
                     WHERE id = ?"
                );
                $stmt->bind_param("ssssssisssi", $data["fullName"], $birthday, $gender, $civilStatus, $data["address"], $contact, $householdId, $householdLabel, $data["zone"], $data["status"], $data["dbId"]);
                $stmt->execute();

                // Update member counts if household changed
                if ($oldHouseholdId !== $householdId) {
                    if ($oldHouseholdId) {
                        $conn->query("UPDATE households SET members = (SELECT COUNT(*) FROM residents WHERE household_id = $oldHouseholdId AND archived = 0) WHERE id = $oldHouseholdId");
                    }
                    if ($householdId) {
                        $conn->query("UPDATE households SET members = (SELECT COUNT(*) FROM residents WHERE household_id = $householdId AND archived = 0) WHERE id = $householdId");
                    }
                }
            } else {
                $code = nextCode($conn, "residents", "resident_code", "R-2026-");
                $stmt = $conn->prepare(
                    "INSERT INTO residents
                        (resident_code, full_name, birthday, gender, civil_status, address, contact, household_id, household, zone_name, status, archived, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())"
                );
                $stmt->bind_param("sssssssisss", $code, $data["fullName"], $birthday, $gender, $civilStatus, $data["address"], $contact, $householdId, $householdLabel, $data["zone"], $data["status"]);
                $stmt->execute();

                // Increment household member count
                if ($householdId) {
                    $conn->query("UPDATE households SET members = (SELECT COUNT(*) FROM residents WHERE household_id = $householdId AND archived = 0) WHERE id = $householdId");
                }
            }

            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── RESIDENT ARCHIVE ───────────────────────────────────────────────────
        case "resident-archive":
            requireAuth();
            requireFields($data, ["dbId"]);
            $conn->begin_transaction();
            try {
                $sel = $conn->prepare(
                    "SELECT id, resident_code, full_name, birthday, gender, civil_status,
                            address, contact, household, zone_name, status, household_id
                     FROM residents WHERE id = ?"
                );
                $sel->bind_param("i", $data["dbId"]);
                $sel->execute();
                $resident = $sel->get_result()->fetch_assoc();
                if (!$resident) jsonResponse(["success" => false, "error" => "Resident not found."], 404);

                $ins = $conn->prepare(
                    "INSERT INTO archived_residents
                        (original_resident_id, resident_code, full_name, birthday, gender, civil_status,
                         address, contact, household, zone_name, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Archived')"
                );
                $ins->bind_param("issssssss",
                    $resident["id"],
                    $resident["resident_code"],
                    $resident["full_name"],
                    $resident["birthday"],
                    $resident["gender"],
                    $resident["civil_status"],
                    $resident["address"],
                    $resident["contact"],
                    $resident["household"],
                    $resident["zone_name"]
                );
                $ins->execute();

                $del = $conn->prepare("DELETE FROM residents WHERE id = ?");
                $del->bind_param("i", $data["dbId"]);
                $del->execute();

                // Update household member count
                if ($resident["household_id"]) {
                    $hid = (int) $resident["household_id"];
                    $conn->query("UPDATE households SET members = (SELECT COUNT(*) FROM residents WHERE household_id = $hid AND archived = 0) WHERE id = $hid");
                }

                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── RESIDENT RESTORE ───────────────────────────────────────────────────
        case "resident-restore":
            requireAuth();
            requireFields($data, ["dbId"]);
            $conn->begin_transaction();
            try {
                $sel = $conn->prepare(
                    "SELECT id, resident_code, full_name, birthday, gender, civil_status,
                            address, contact, household, zone_name
                     FROM archived_residents WHERE id = ?"
                );
                $sel->bind_param("i", $data["dbId"]);
                $sel->execute();
                $resident = $sel->get_result()->fetch_assoc();
                if (!$resident) jsonResponse(["success" => false, "error" => "Archived resident not found."], 404);

                $ins = $conn->prepare(
                    "INSERT INTO residents
                        (resident_code, full_name, birthday, gender, civil_status,
                         address, contact, household, zone_name, status, archived, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 0, NOW())"
                );
                $ins->bind_param("sssssssss",
                    $resident["resident_code"],
                    $resident["full_name"],
                    $resident["birthday"],
                    $resident["gender"],
                    $resident["civil_status"],
                    $resident["address"],
                    $resident["contact"],
                    $resident["household"],
                    $resident["zone_name"]
                );
                $ins->execute();

                $del = $conn->prepare("DELETE FROM archived_residents WHERE id = ?");
                $del->bind_param("i", $data["dbId"]);
                $del->execute();

                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── HOUSEHOLD SAVE ─────────────────────────────────────────────────────
        case "household-save":
            requireAuth();
            requireFields($data, ["head", "address"]);

            if (!empty($data["dbId"])) {
                $stmt = $conn->prepare(
                    "UPDATE households SET head_name = ?, address = ? WHERE id = ?"
                );
                $stmt->bind_param("ssi", $data["head"], $data["address"], $data["dbId"]);
                $stmt->execute();
            } else {
                $code = nextCode($conn, "households", "household_code", "H-2026-");
                $stmt = $conn->prepare(
                    "INSERT INTO households (household_code, head_name, address, members) VALUES (?, ?, ?, 0)"
                );
                $stmt->bind_param("sss", $code, $data["head"], $data["address"]);
                $stmt->execute();
            }
            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── HOUSEHOLD DELETE ───────────────────────────────────────────────────
        case "household-delete":
            requireAuth();
            requireFields($data, ["dbId"]);
            // Unlink any residents linked to this household before deleting
            $ul = $conn->prepare("UPDATE residents SET household_id = NULL, household = '' WHERE household_id = ?");
            $ul->bind_param("i", $data["dbId"]);
            $ul->execute();

            $del = $conn->prepare("DELETE FROM households WHERE id = ?");
            $del->bind_param("i", $data["dbId"]);
            $del->execute();
            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── DOCUMENT SAVE ──────────────────────────────────────────────────────
        case "document-save":
            $userId = requireAuth();
            requireFields($data, ["status"]);

            if (!empty($data["dbId"])) {
                // Edit mode: only status (and rejection_reason / processed_by) can change
                $chk = $conn->prepare("SELECT status, released_at FROM documents WHERE id = ?");
                $chk->bind_param("i", $data["dbId"]);
                $chk->execute();
                $chkRow = $chk->get_result()->fetch_assoc();

                // Allow editing if the request explicitly says to allow released edits
                // (i.e. restored document with override flag), otherwise block released docs
                $allowReleasedEdit = !empty($data["allowReleasedEdit"]) && $data["allowReleasedEdit"] === true;
                if ($chkRow && !empty($chkRow["released_at"]) && !$allowReleasedEdit) {
                    jsonResponse(["success" => false, "error" => "Released documents cannot be edited."], 422);
                }

                $newStatus = $data["status"];
                // Prevent manually setting status to Released via edit — use the Release button
                if ($newStatus === "Released") {
                    jsonResponse(["success" => false, "error" => "Use the Release button to release a document."], 422);
                }
                $rejectionReason = ($newStatus === "Rejected") ? ($data["rejectionReason"] ?? "") : null;

                // If overriding a released document edit, also clear the release fields
                if ($allowReleasedEdit && !empty($chkRow["released_at"])) {
                    $stmt = $conn->prepare(
                        "UPDATE documents SET status = ?, rejection_reason = ?, processed_by = ?,
                         released_at = NULL, released_by = NULL, recipient_name = NULL,
                         relationship_to_requester = NULL, proof_photo = NULL, proof_photo_mime = NULL
                         WHERE id = ?"
                    );
                    $stmt->bind_param("ssii", $newStatus, $rejectionReason, $userId, $data["dbId"]);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE documents SET status = ?, rejection_reason = ?, processed_by = ? WHERE id = ?"
                    );
                    $stmt->bind_param("ssii", $newStatus, $rejectionReason, $userId, $data["dbId"]);
                }
                $stmt->execute();
            } else {
                // Create mode — all fields allowed
                requireFields($data, ["name", "type", "dateRequested"]);
                $notes = $data["notes"] ?? "";
                $code = nextCode($conn, "documents", "request_code", "DR-2026-");
                $stmt = $conn->prepare(
                    "INSERT INTO documents (request_code, resident_name, document_type, date_requested, status, notes, archived)
                     VALUES (?, ?, ?, ?, ?, ?, 0)"
                );
                $stmt->bind_param("ssssss", $code, $data["name"], $data["type"], $data["dateRequested"], $data["status"], $notes);
                $stmt->execute();
            }
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── DOCUMENT PROOF UPLOAD ──────────────────────────────────────────────
        case "document-proof":
            $userId = requireAuth();
            $docId  = !empty($_POST["dbId"]) ? (int) $_POST["dbId"] : 0;
            if (!$docId) jsonResponse(["success" => false, "error" => "Missing document ID."], 422);

            if (empty($_FILES["proof"])) {
                jsonResponse(["success" => false, "error" => "No file uploaded."], 422);
            }

            $file = $_FILES["proof"];
            $allowedMimes = ["image/jpeg", "image/png", "image/gif", "image/webp"];
            $mime = mime_content_type($file["tmp_name"]);
            if (!in_array($mime, $allowedMimes, true)) {
                jsonResponse(["success" => false, "error" => "Only JPG, PNG, GIF, WEBP images allowed."], 422);
            }
            if ($file["size"] > 5 * 1024 * 1024) {
                jsonResponse(["success" => false, "error" => "Image must be under 5 MB."], 422);
            }

            $photoData = file_get_contents($file["tmp_name"]);
            $stmt = $conn->prepare("UPDATE documents SET proof_photo = ?, proof_photo_mime = ? WHERE id = ?");
            $null = null;
            $stmt->bind_param("bsi", $null, $mime, $docId);
            $stmt->send_long_data(0, $photoData);
            $stmt->execute();

            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── DOCUMENT PROOF GET ─────────────────────────────────────────────────
        case "document-proof-get":
            requireAuth();
            $docId = !empty($_GET["docId"]) ? (int) $_GET["docId"] : 0;
            if (!$docId) { http_response_code(404); exit; }
            $stmt = $conn->prepare("SELECT proof_photo, proof_photo_mime FROM documents WHERE id = ?");
            $stmt->bind_param("i", $docId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row || !$row["proof_photo"]) { http_response_code(404); exit; }
            header("Content-Type: " . $row["proof_photo_mime"]);
            header("Cache-Control: max-age=3600");
            echo $row["proof_photo"];
            exit;

        // ── ARCHIVED DOCUMENT PROOF GET ────────────────────────────────────────
        case "archived-proof-get":
            requireAuth();
            $docId = !empty($_GET["docId"]) ? (int) $_GET["docId"] : 0;
            if (!$docId) { http_response_code(404); exit; }
            $stmt = $conn->prepare("SELECT proof_photo, proof_photo_mime FROM archived_documents WHERE id = ?");
            $stmt->bind_param("i", $docId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row || !$row["proof_photo"]) { http_response_code(404); exit; }
            header("Content-Type: " . $row["proof_photo_mime"]);
            header("Cache-Control: max-age=3600");
            echo $row["proof_photo"];
            exit;

        // ── DOCUMENT RELEASE ──────────────────────────────────────────────────
        case "document-release":
            $userId = requireAuth();
            $docId  = !empty($_POST["dbId"]) ? (int) $_POST["dbId"] : 0;
            if (!$docId) jsonResponse(["success" => false, "error" => "Missing document ID."], 422);

            $recipientName           = trim((string)($_POST["recipientName"] ?? ""));
            $relationshipToRequester = trim((string)($_POST["relationshipToRequester"] ?? ""));
            if (!$recipientName)           jsonResponse(["success" => false, "error" => "Recipient full name is required."], 422);
            if (!$relationshipToRequester) jsonResponse(["success" => false, "error" => "Relationship to requester is required."], 422);

            // Check document exists and is not already released
            $chk = $conn->prepare("SELECT status FROM documents WHERE id = ?");
            $chk->bind_param("i", $docId);
            $chk->execute();
            $chkRow = $chk->get_result()->fetch_assoc();
            if (!$chkRow) jsonResponse(["success" => false, "error" => "Document not found."], 404);
            if (!empty($chkRow["released_at"])) jsonResponse(["success" => false, "error" => "Document is already released."], 422);

            // Save recipient info and release timestamp (status stays as-is: Approved or Rejected)
            $stmt = $conn->prepare(
                "UPDATE documents SET released_at = NOW(), released_by = ?, processed_by = ?,
                 recipient_name = ?, relationship_to_requester = ? WHERE id = ?"
            );
            $stmt->bind_param("iissi", $userId, $userId, $recipientName, $relationshipToRequester, $docId);
            $stmt->execute();

            // Upload proof photo if provided
            if (!empty($_FILES["proof"])) {
                $file = $_FILES["proof"];
                $allowedMimes = ["image/jpeg", "image/png", "image/gif", "image/webp"];
                $mime = mime_content_type($file["tmp_name"]);
                if (!in_array($mime, $allowedMimes, true)) {
                    jsonResponse(["success" => false, "error" => "Only JPG, PNG, GIF, WEBP images allowed."], 422);
                }
                if ($file["size"] > 5 * 1024 * 1024) {
                    jsonResponse(["success" => false, "error" => "Image must be under 5 MB."], 422);
                }
                $photoData = file_get_contents($file["tmp_name"]);
                $pstmt = $conn->prepare("UPDATE documents SET proof_photo = ?, proof_photo_mime = ? WHERE id = ?");
                $null = null;
                $pstmt->bind_param("bsi", $null, $mime, $docId);
                $pstmt->send_long_data(0, $photoData);
                $pstmt->execute();
            }

            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── DOCUMENT ARCHIVE ───────────────────────────────────────────────────
        case "document-archive":
            requireAuth();
            requireFields($data, ["dbId"]);
            $conn->begin_transaction();
            try {
                $sel = $conn->prepare(
                    "SELECT d.id, d.request_code, d.resident_name, d.document_type, d.date_requested,
                            d.status, d.notes, d.rejection_reason, d.released_at,
                            d.recipient_name, d.relationship_to_requester,
                            d.proof_photo, d.proof_photo_mime,
                            rb.full_name AS released_by_name,
                            pb.full_name AS processed_by_name
                     FROM documents d
                     LEFT JOIN users rb ON d.released_by = rb.id
                     LEFT JOIN users pb ON d.processed_by = pb.id
                     WHERE d.id = ?"
                );
                $sel->bind_param("i", $data["dbId"]);
                $sel->execute();
                $doc = $sel->get_result()->fetch_assoc();
                if (!$doc) jsonResponse(["success" => false, "error" => "Document not found."], 404);

                $ins = $conn->prepare(
                    "INSERT INTO archived_documents
                        (original_document_id, request_code, resident_name, document_type, date_requested,
                         status, notes, rejection_reason, released_at, released_by_name, processed_by_name,
                         recipient_name, relationship_to_requester, proof_photo, proof_photo_mime)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $nullPhoto = null;
                $ins->bind_param("issssssssssssbs",
                    $doc["id"],
                    $doc["request_code"],
                    $doc["resident_name"],
                    $doc["document_type"],
                    $doc["date_requested"],
                    $doc["status"],
                    $doc["notes"],
                    $doc["rejection_reason"],
                    $doc["released_at"],
                    $doc["released_by_name"],
                    $doc["processed_by_name"],
                    $doc["recipient_name"],
                    $doc["relationship_to_requester"],
                    $nullPhoto,
                    $doc["proof_photo_mime"]
                );
                // Send proof photo as long data if present
                if ($doc["proof_photo"]) {
                    $ins->send_long_data(13, $doc["proof_photo"]);
                }
                $ins->execute();

                $del = $conn->prepare("DELETE FROM documents WHERE id = ?");
                $del->bind_param("i", $data["dbId"]);
                $del->execute();

                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── DOCUMENT RESTORE ───────────────────────────────────────────────────
        case "document-restore":
            requireAuth();
            requireFields($data, ["dbId"]);
            $conn->begin_transaction();
            try {
                $sel = $conn->prepare(
                    "SELECT id, request_code, resident_name, document_type, date_requested, status,
                            notes, rejection_reason, released_at, released_by_name, processed_by_name,
                            recipient_name, relationship_to_requester, proof_photo, proof_photo_mime
                     FROM archived_documents WHERE id = ?"
                );
                $sel->bind_param("i", $data["dbId"]);
                $sel->execute();
                $doc = $sel->get_result()->fetch_assoc();
                if (!$doc) jsonResponse(["success" => false, "error" => "Archived document not found."], 404);

                // Resolve released_by and processed_by user IDs from names (best-effort)
                $releasedById   = null;
                $processedById  = null;
                if (!empty($doc["released_by_name"])) {
                    $uq = $conn->prepare("SELECT id FROM users WHERE full_name = ? LIMIT 1");
                    $uq->bind_param("s", $doc["released_by_name"]);
                    $uq->execute();
                    $ur = $uq->get_result()->fetch_assoc();
                    if ($ur) $releasedById = (int) $ur["id"];
                }
                if (!empty($doc["processed_by_name"])) {
                    $uq2 = $conn->prepare("SELECT id FROM users WHERE full_name = ? LIMIT 1");
                    $uq2->bind_param("s", $doc["processed_by_name"]);
                    $uq2->execute();
                    $ur2 = $uq2->get_result()->fetch_assoc();
                    if ($ur2) $processedById = (int) $ur2["id"];
                }

                $ins = $conn->prepare(
                    "INSERT INTO documents
                        (request_code, resident_name, document_type, date_requested, status, archived,
                         notes, rejection_reason, released_at, released_by, processed_by,
                         recipient_name, relationship_to_requester, proof_photo, proof_photo_mime)
                     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $nullPhoto = null;
                $ins->bind_param("ssssssssiissbs",
                    $doc["request_code"],
                    $doc["resident_name"],
                    $doc["document_type"],
                    $doc["date_requested"],
                    $doc["status"],
                    $doc["notes"],
                    $doc["rejection_reason"],
                    $doc["released_at"],
                    $releasedById,
                    $processedById,
                    $doc["recipient_name"],
                    $doc["relationship_to_requester"],
                    $nullPhoto,
                    $doc["proof_photo_mime"]
                );
                // Send proof photo as long data if present
                if ($doc["proof_photo"]) {
                    $ins->send_long_data(13, $doc["proof_photo"]);
                }
                $ins->execute();

                $del = $conn->prepare("DELETE FROM archived_documents WHERE id = ?");
                $del->bind_param("i", $data["dbId"]);
                $del->execute();

                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $userId = (int) $_SESSION["user_id"];
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── SETTINGS SAVE ──────────────────────────────────────────────────────
        case "settings-save":
            $userId = requireAuth();
            requireFields($data, ["section", "values"]);
            if (!is_array($data["values"])) jsonResponse(["success" => false, "error" => "Invalid payload."], 422);

            if ($data["section"] === "account") {
                // Save personal account info to users table
                $vals   = $data["values"];
                $fields = [];
                $types  = "";
                $args   = [];

                if (isset($vals["username"])) { $fields[] = "username = ?"; $types .= "s"; $args[] = $vals["username"]; }
                if (isset($vals["fullName"]))  { $fields[] = "full_name = ?"; $types .= "s"; $args[] = $vals["fullName"]; }
                if (isset($vals["email"]))     { $fields[] = "email = ?"; $types .= "s"; $args[] = $vals["email"]; }
                if (isset($vals["contact"]))   { $fields[] = "contact = ?"; $types .= "s"; $args[] = $vals["contact"]; }
                if (!empty($vals["password"])) {
                    $fields[] = "password_hash = ?";
                    $types   .= "s";
                    $args[]   = password_hash($vals["password"], PASSWORD_BCRYPT);
                }

                if ($fields) {
                    $types .= "i";
                    $args[] = $userId;
                    $stmt   = $conn->prepare("UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?");
                    $refs   = [$types];
                    foreach ($args as $i => $_) $refs[] = &$args[$i];
                    call_user_func_array([$stmt, "bind_param"], $refs);
                    $stmt->execute();
                }
            } else {
                $defaults = defaultSettings();
                if (!array_key_exists($data["section"], $defaults)) {
                    jsonResponse(["success" => false, "error" => "Unknown settings section."], 422);
                }
                $current = getSetting($conn, $data["section"], $defaults[$data["section"]]);
                if (!is_array($current)) $current = $defaults[$data["section"]];
                setSetting($conn, $data["section"], array_merge($current, $data["values"]));
            }

            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── PROFILE PHOTO UPLOAD ───────────────────────────────────────────────
        case "profile-photo":
            $userId = requireAuth();

            if (empty($_FILES["photo"])) {
                jsonResponse(["success" => false, "error" => "No file uploaded."], 422);
            }

            $file = $_FILES["photo"];
            $allowedMimes = ["image/jpeg", "image/png", "image/gif", "image/webp"];
            $mime = mime_content_type($file["tmp_name"]);

            if (!in_array($mime, $allowedMimes, true)) {
                jsonResponse(["success" => false, "error" => "Only JPG, PNG, GIF, WEBP images are allowed."], 422);
            }

            if ($file["size"] > 3 * 1024 * 1024) {
                jsonResponse(["success" => false, "error" => "Image must be under 3 MB."], 422);
            }

            $photoData = file_get_contents($file["tmp_name"]);
            $stmt = $conn->prepare("UPDATE users SET profile_photo = ?, profile_photo_mime = ? WHERE id = ?");
            $null = null;
            $stmt->bind_param("bsi", $null, $mime, $userId);
            $stmt->send_long_data(0, $photoData);
            $stmt->execute();

            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── PROFILE PHOTO GET ──────────────────────────────────────────────────
        case "profile-photo-get":
            requireAuth();
            $targetId = !empty($_GET["userId"]) ? (int) $_GET["userId"] : requireAuth();
            $stmt = $conn->prepare("SELECT profile_photo, profile_photo_mime FROM users WHERE id = ?");
            $stmt->bind_param("i", $targetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if (!$row || !$row["profile_photo"]) {
                http_response_code(404);
                exit;
            }

            header("Content-Type: " . $row["profile_photo_mime"]);
            header("Cache-Control: max-age=3600");
            echo $row["profile_photo"];
            exit;

        // ── BACKUP ────────────────────────────────────────────────────────────
        case "backup":
            $userId = requireAuth();
            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        // ── RESTORE ───────────────────────────────────────────────────────────
        case "restore":
            $userId = requireAuth();
            requireFields($data, ["backup"]);
            if (!is_array($data["backup"])) jsonResponse(["success" => false, "error" => "Invalid backup payload."], 422);

            $backup = $data["backup"];
            $conn->begin_transaction();
            try {
                replaceTableFromBackup($conn, "residents",
                    ["resident_code","full_name","address","household","zone_name","status","archived","created_at"],
                    array_map(fn($r) => [
                        "resident_code" => (string)($r["id"] ?? ""),
                        "full_name"     => (string)($r["fullName"] ?? ""),
                        "address"       => (string)($r["address"] ?? ""),
                        "household"     => (string)($r["household"] ?? ""),
                        "zone_name"     => (string)($r["zone"] ?? "Zone 1"),
                        "status"        => (string)($r["status"] ?? "Active"),
                        "archived"      => !empty($r["archived"]) ? 1 : 0,
                        "created_at"    => (string)(($r["createdAt"] ?? date("Y-m-d")) . " 00:00:00"),
                    ], $backup["residents"] ?? [])
                );

                replaceTableFromBackup($conn, "households",
                    ["household_code","head_name","address","members"],
                    array_map(fn($r) => [
                        "household_code" => (string)($r["id"] ?? ""),
                        "head_name"      => (string)($r["head"] ?? ""),
                        "address"        => (string)($r["address"] ?? ""),
                        "members"        => (int)($r["members"] ?? 1),
                    ], $backup["households"] ?? [])
                );

                replaceTableFromBackup($conn, "documents",
                    ["request_code","resident_name","document_type","date_requested","status","archived"],
                    array_map(fn($r) => [
                        "request_code"  => (string)($r["id"] ?? ""),
                        "resident_name" => (string)($r["name"] ?? ""),
                        "document_type" => (string)($r["type"] ?? ""),
                        "date_requested"=> (string)($r["dateRequested"] ?? date("Y-m-d")),
                        "status"        => (string)($r["status"] ?? "Pending"),
                        "archived"      => !empty($r["archived"]) ? 1 : 0,
                    ], $backup["documents"] ?? [])
                );

                if (isset($backup["settings"]) && is_array($backup["settings"])) {
                    foreach ($backup["settings"] as $key => $val) {
                        setSetting($conn, (string)$key, $val);
                    }
                }

                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }

            jsonResponse(["success" => true, "data" => bootstrapPayload($conn, $userId)]);

        default:
            jsonResponse(["success" => false, "error" => "Unknown resource."], 404);
    }

} catch (Throwable $exception) {
    jsonResponse(["success" => false, "error" => $exception->getMessage()], 500);
}
