<?php
declare(strict_types=1);

function jurkoOriginalReport(PDO $db): void
{
    $dir = __DIR__;
    $asset = $_GET['report_asset'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (isset($_GET['report_api'])) {
        header('Content-Type: application/json; charset=utf-8');
        $reply = static function (array $data, int $status = 200): void {
            http_response_code($status);
            echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        };
        $read = static function () use ($db): array {
            $row = $db->query('SELECT revision, report_json FROM jm_original_report_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['report' => ['version' => 1, 'month' => date('Y-m'), 'documents' => []], 'etag' => '0'];
            }
            return ['report' => json_decode($row['report_json'], true, 512, JSON_THROW_ON_ERROR), 'etag' => (string)$row['revision']];
        };
        if ($method === 'GET') {
            $reply($read());
            return;
        }
        if ($method !== 'PUT') {
            $reply(['error' => 'Niedozwolona metoda.'], 405);
            return;
        }
        $csrf = $_SESSION['csrf'] ?? '';
        if ($csrf === '' || !hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            $reply(['error' => 'Sesja wygasła. Odśwież raport.'], 403);
            return;
        }
        $body = file_get_contents('php://input', false, null, 0, 2000001);
        if ($body === false || strlen($body) > 2000000) {
            $reply(['error' => 'Raport jest za duży.'], 413);
            return;
        }
        try {
            $input = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $reply(['error' => 'Nieprawidłowy format raportu.'], 422);
            return;
        }
        $report = $input['report'] ?? null;
        if (!is_array($report) || ($report['version'] ?? null) !== 1 ||
            !is_string($report['month'] ?? null) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $report['month']) ||
            !is_array($report['documents'] ?? null) || !array_is_list($report['documents']) || count($report['documents']) > 1000) {
            $reply(['error' => 'Nieprawidłowe dane raportu.'], 422);
            return;
        }

        if ((int)$db->query("SELECT GET_LOCK('jm_original_report_save', 5)")->fetchColumn() !== 1) {
            $reply(['error' => 'Trwa inny zapis. Spróbuj ponownie.'], 503);
            return;
        }
        try {
            $previous = $read();
            if (($input['etag'] ?? null) !== $previous['etag']) {
                $reply(['error' => 'Raport zmienił się w innym oknie. Pobierz kopię JSON i odśwież.'], 409);
                return;
            }
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $id = (string)((int)$previous['etag'] + 1);
            $db->prepare('INSERT INTO jm_original_report_state (id, revision, report_json) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE revision = ?, report_json = ?')
                ->execute([$id, $json, $id, $json]);
            $reply(['ok' => true, 'etag' => $id]);
        } finally {
            $db->query("SELECT RELEASE_LOCK('jm_original_report_save')");
        }
        return;
    }

    $assets = ['xlsx'=>'xlsx.js', 'pdf'=>'pdf.mjs', 'pdf-worker'=>'pdf-worker.mjs'];
    if ($method !== 'GET' || ($asset !== null && (!is_string($asset) || !isset($assets[$asset])))) {
        http_response_code(404);
        return;
    }
    if ($asset !== null) {
        header('Content-Type: text/javascript; charset=utf-8');
        readfile($dir.'/'.$assets[$asset]);
        return;
    }
    $nonce = base64_encode(random_bytes(24));
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce'; worker-src 'self'; style-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'self'; form-action 'self'");
    $html = file_get_contents($dir.'/app.html');
    $csrf = json_encode($_SESSION['csrf'], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
    $html = str_replace('__REPORT_CSRF__', $csrf, $html);
    echo str_replace(['<script>', '__COST_DRIVE_CONFIG__'], ['<script nonce="'.$nonce.'">', 'null'], $html);
}
