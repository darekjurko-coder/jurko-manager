<?php
declare(strict_types=1);

require __DIR__.'/server/auth-bootstrap.php';
require_once __DIR__.'/server/database.php';
require_once __DIR__.'/server/finance.php';

const FACTORIES = [
    'Polska' => ['Tubądzin', 'Paradyż', 'Cerrad'],
    'Włochy' => ['Novabell', 'Italgraniti'],
    'Hiszpania' => ['DNA', 'Peronda'],
];
const COMPANIES = ['spk' => 'JURKO sp. z o.o. sp.k.', 'zoo' => 'JURKO sp. z o.o.'];
const STATUSES = ['draft' => 'Robocze', 'sent' => 'Wysłane', 'delivered' => 'Dostarczone'];

function field(string $key, int $length, bool $required = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || mb_strlen(trim($value)) > $length || ($required && trim($value) === '')) {
        throw new InvalidArgumentException('Uzupełnij poprawnie wymagane pola.');
    }
    return trim($value);
}

function choice(string $key, array $options): string
{
    $value = field($key, 20, true);
    if (!in_array($value, $options, true)) {
        throw new InvalidArgumentException('Wybierz wartość z listy.');
    }
    return $value;
}

function numberField(string $key, int $digits, bool $required = false, bool $integer = false): ?string
{
    $value = str_replace(',', '.', field($key, $digits + 3, $required));
    if ($value === '') {
        return null;
    }
    $fraction = $integer ? '' : '(?:\.[0-9]{1,2})?';
    if (!preg_match('/^[0-9]{1,'.$digits.'}'.$fraction.'$/D', $value) || ($required && (float)$value <= 0)) {
        throw new InvalidArgumentException('Sprawdź liczbę palet, wagę lub kwotę.');
    }
    return $value;
}

function transportDate(string $key): ?string
{
    $value = field($key, 10);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '2000-01-01') {
        throw new InvalidArgumentException('Sprawdź daty odbioru i dostawy.');
    }
    return $value;
}

function e(string|int|null $value): string
{
    return jurkoEscape((string)$value);
}

function transportNumber(array $order): string
{
    $date = new DateTimeImmutable($order['created_at'], new DateTimeZone('UTC'));
    return 'TR/'.$date->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y/m').'/'.str_pad((string)$order['id'], 4, '0', STR_PAD_LEFT);
}

$embedded = ($_GET['panel'] ?? '') === '1';

try {
    $config = jurkoAuthConfig();
    $user = jurkoCurrentUser($config);
    if (!$user) {
        if ($embedded) {
            http_response_code(401);
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'");
            header('Content-Type: text/html; charset=utf-8');
            exit('<p>Sesja wygasła. <a href="/auth/" target="_top">Zaloguj się ponownie</a>.</p>');
        }
        header('Location: /auth/', true, 303);
        exit;
    }
    $db = jurkoDatabase();
    $owner = $user['role'] === 'owner';
    if ($owner) {
        $actor = jurkoFinanceAccount($db, $user, $config);
    } else {
        $query = $db->prepare("SELECT id FROM jm_accounts WHERE email = ? AND role = ? AND active = TRUE");
        $query->execute([$user['transport_email'] ?? $config['accounts'][$user['sub']]['email'] ?? '', $user['role']]);
        $actor = (int)$query->fetchColumn();
        if (!$actor) {
            throw new DomainException('Konto nie ma dostępu do transportu.');
        }
    }

    if (isset($_GET['invoice'])) {
        $invoiceId = $_GET['invoice'];
        if (!$owner || !is_string($invoiceId) || !preg_match('/^[0-9]{1,12}$/D', $invoiceId)) {
            http_response_code(404);
            exit;
        }
        $query = $db->prepare('SELECT invoice_pdf FROM jm_transport_orders WHERE id=?');
        $query->execute([$invoiceId]);
        $pdf = $query->fetchColumn();
        if (!is_string($pdf) || $pdf === '') {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="faktura-transport-'.$invoiceId.'.pdf"');
        echo base64_decode($pdf, true);
        exit;
    }
    $error = '';
    $redirect = $embedded ? '?panel=1' : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], field('csrf', 64))) {
            http_response_code(403);
            exit('Formularz wygasł. Odśwież stronę.');
        }
        try {
            switch (field('action', 20)) {
                case 'access':
                    if (!$owner) {
                        throw new DomainException('Dostęp zmienia właściciel.');
                    }
                    $email = strtolower(field('email',254,true));
                    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException('Podaj poprawny adres konta Google.');
                    }
                    $active = choice('access', ['enable','disable']) === 'enable';
                    if ($active) {
                        $query = $db->prepare("INSERT INTO jm_accounts (email,role,active) VALUES (?,'employee',TRUE) ON DUPLICATE KEY UPDATE active=IF(role='employee',TRUE,active)");
                    } else {
                        $query = $db->prepare("UPDATE jm_accounts SET active=FALSE WHERE email=? AND role='employee'");
                    }
                    $query->execute([$email]);
                    break;
                case 'directory':
                    if (!$owner) {
                        throw new DomainException('Kartotekę edytuje właściciel.');
                    }
                    $kind = choice('kind', ['factory','carrier','unload']);
                    $name = field('name', 150, true);
                    $country = $kind === 'factory' ? choice('country', array_keys(FACTORIES)) : '';
                    $address = $kind !== 'carrier' ? field('address', 300, true) : '';
                    $email = field('email', 254);
                    $phone = $kind === 'carrier' ? preg_replace('/[ +()-]/', '', field('phone', 30)) : '';
                    $addresses = $kind === 'factory' ? array_map('trim', explode(',', $email)) : [$email];
                    if (($email !== '' && count(array_filter($addresses, static fn(string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false)) !== count($addresses)) ||
                        ($phone !== '' && !preg_match('/^[1-9][0-9]{7,14}$/D', $phone)) ||
                        ($kind === 'carrier' && $email === '' && $phone === '')) {
                        throw new InvalidArgumentException('Podaj poprawny e-mail lub numer WhatsApp z kodem kraju.');
                    }
                    $pointId = $kind === 'factory' ? numberField('directory_id', 12, false, true) : null;
                    if ($pointId !== null) {
                        try {
                            $query = $db->prepare("UPDATE jm_transport_directory SET name=?,country=?,address=?,email=? WHERE id=? AND kind='factory'");
                            $query->execute([$name,$country,$address,$email,$pointId]);
                        } catch (PDOException $exception) {
                            if (($exception->errorInfo[1] ?? null) === 1062) {
                                throw new InvalidArgumentException('Taki punkt odbioru już istnieje w kartotece.');
                            }
                            throw $exception;
                        }
                        break;
                    }
                    $query = $db->prepare('INSERT INTO jm_transport_directory (kind,name,country,address,email,phone) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email=?,phone=?');
                    $query->execute([$kind,$name,$country,$address,$email,$phone,$email,$phone]);
                    break;
                case 'create':
                    $editId = numberField('edit_id', 12, false, true);
                    $selectedPoint = numberField('factory', 10, false, true);
                    $carrier = numberField('carrier', 10, false, true);
                    $address = field('pickup_address', 300);
                    if ($carrier !== null) {
                        $query = $db->prepare("SELECT id FROM jm_transport_directory WHERE id=? AND kind='carrier'");
                        $query->execute([$carrier]);
                        if (!$query->fetchColumn()) {
                            throw new InvalidArgumentException('Wybierz przewoźnika z kartoteki.');
                        }
                    }
                    if ($selectedPoint !== null) {
                        $query = $db->prepare("SELECT name,country FROM jm_transport_directory WHERE id=? AND kind='factory'");
                        $query->execute([$selectedPoint]);
                        $brand = $query->fetch();
                        if (!$brand) {
                            throw new InvalidArgumentException('Wybierz punkt odbioru z kartoteki.');
                        }
                        $name = $brand['name'];
                        $country = $brand['country'];
                    } else {
                        $country = field('pickup_country',20);
                        $name = field('pickup_brand',150);
                        if ($name !== '' && !in_array($name, FACTORIES[$country] ?? [], true)) {
                            throw new InvalidArgumentException('Wybierz fabrykę z kartoteki.');
                        }
                    }
                    $pickup = transportDate('pickup');
                    $delivery = transportDate('delivery');
                    $request = field('request', 32, true);
                    if (($delivery !== null && $pickup !== null && $delivery < $pickup) || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
                        throw new InvalidArgumentException('Sprawdź datę dostawy i odśwież formularz.');
                    }
                    $values = [choice('company',array_keys(COMPANIES)),field('reference',150),
                        field('delivery_address',300),$pickup,$delivery,numberField('pallets',6,false,true),
                        numberField('weight',8),numberField('agreed',10),choice('currency',['PLN','EUR']),field('notes',6000),$request];
                    $db->beginTransaction();
                    try {
                        if ($editId !== null) {
                            $query = $db->prepare('SELECT id FROM jm_transport_orders WHERE id=?'.($owner ? '' : ' AND account_id=?').' FOR UPDATE');
                            $query->execute($owner ? [$editId] : [$editId,$actor]);
                            if (!$query->fetchColumn()) {
                                throw new InvalidArgumentException('Zlecenie jest niedostępne.');
                            }
                        }
                        $factory = null;
                        if ($name !== '' || $address !== '') {
                            $query = $db->prepare("INSERT INTO jm_transport_directory (kind,name,country,address) VALUES ('factory',?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                            $query->execute([$name,$country,$address]);
                            $factory = $db->lastInsertId();
                        }
                        if ($editId !== null) {
                            $query = $db->prepare('UPDATE jm_transport_orders SET factory_id=?,carrier_id=?,company=?,reference=?,delivery_address=?,pickup_date=?,delivery_date=?,pallets=?,weight_kg=?,agreed_net=?,currency=?,notes=? WHERE id=?');
                            $query->execute([$factory,$carrier,...array_slice($values,0,-1),$editId]);
                            $savedOrder = $editId;
                        } else {
                            $query = $db->prepare('INSERT INTO jm_transport_orders (account_id,factory_id,carrier_id,company,reference,delivery_address,pickup_date,delivery_date,pallets,weight_kg,agreed_net,currency,notes,request_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
                            $query->execute([$actor,$factory,$carrier,...$values]);
                            $query = $db->prepare('SELECT id FROM jm_transport_orders WHERE account_id=? AND request_id=?');
                            $query->execute([$actor,$request]);
                            $savedOrder = $query->fetchColumn();
                        }
                        $db->commit();
                        $redirect .= ($embedded ? '&' : '?').($editId !== null ? 'edit='.$savedOrder.'#new-order' : 'history=1&order='.$savedOrder.'#order-'.$savedOrder);
                    } catch (Throwable $exception) {
                        $db->rollBack();
                        throw $exception;
                    }
                    break;
                case 'status':
                    $id = numberField('id', 12, true, true);
                    $status = choice('status',array_keys(STATUSES));
                    if ($owner) {
                        $invoice = field('carrier_invoice',150);
                        $actual = numberField('actual',10);
                        if (($invoice === '') !== ($actual === null)) {
                            throw new InvalidArgumentException('Podaj numer faktury przewoźnika i koszt netto albo zostaw oba pola puste.');
                        }
                        $file = $_FILES['invoice_pdf'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
                        $pdf = null;
                        if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                            if ($invoice === '' || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 1500000
                                || !is_uploaded_file($file['tmp_name'])) {
                                throw new InvalidArgumentException('Dołącz PDF do faktury. Maksymalny rozmiar to 1,5 MB.');
                            }
                            $pdf = file_get_contents($file['tmp_name']);
                            if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
                                throw new InvalidArgumentException('Wybierz fakturę w formacie PDF.');
                            }
                            $pdf = base64_encode($pdf);
                        }
                        $query = $db->prepare("UPDATE jm_transport_orders SET status=?, goods_invoice=?,
                            invoice_pdf=CASE WHEN ? IS NOT NULL THEN ? WHEN carrier_invoice=? THEN invoice_pdf ELSE NULL END,
                            carrier_invoice=?, actual_net=? WHERE id=?");
                        $query->execute([$status,field('goods_invoice',150),$pdf,$pdf,$invoice,$invoice,$actual,$id]);
                    } else {
                        $query = $db->prepare('UPDATE jm_transport_orders SET status=?, goods_invoice=? WHERE id=? AND account_id=?');
                        $query->execute([$status,field('goods_invoice',150),$id,$actor]);
                    }
                    $redirect .= ($embedded ? '&' : '?').'history=1&order='.$id.'#order-'.$id;
                    break;
                default:
                    throw new InvalidArgumentException('Nieznana operacja.');
            }
            $_SESSION['transport_saved'] = true;
            header('Location: /transport.php'.$redirect, true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
    $saved = !empty($_SESSION['transport_saved']);
    unset($_SESSION['transport_saved']);
    $employees = $owner ? $db->query("SELECT email,active FROM jm_accounts WHERE role='employee' ORDER BY email")->fetchAll() : [];
    $directory = $db->query('SELECT * FROM jm_transport_directory ORDER BY country,name,address')->fetchAll();
    $factories = array_filter($directory, static fn(array $row): bool => $row['kind'] === 'factory' && $row['name'] !== '' && $row['address'] !== '');
    $unloads = array_filter($directory, static fn(array $row): bool => $row['kind'] === 'unload');
    $openOrder = is_string($_GET['order'] ?? null) && ctype_digit($_GET['order']) ? $_GET['order'] : '';
    $carriers = array_filter($directory, static fn(array $row): bool => $row['kind'] === 'carrier');
    $sql = 'SELECT t.id,t.factory_id,t.carrier_id,f.country AS pickup_country,t.company,t.reference,t.delivery_address,t.pickup_date,t.delivery_date,
        t.pallets,t.weight_kg,t.agreed_net,t.actual_net,t.currency,t.notes,t.status,t.created_at,
        t.goods_invoice,t.carrier_invoice,t.invoice_pdf IS NOT NULL AS has_pdf,
        f.name AS factory, f.address AS pickup_address, c.name AS carrier,
        c.email AS carrier_email, c.phone AS carrier_phone, a.email AS author
        FROM jm_transport_orders t LEFT JOIN jm_transport_directory f ON f.id=t.factory_id
        LEFT JOIN jm_transport_directory c ON c.id=t.carrier_id JOIN jm_accounts a ON a.id=t.account_id';
    $filters = $owner ? [] : ['t.account_id = ?'];
    $params = $owner ? [] : [$actor];
    if (($_GET['unbilled'] ?? '') === '1') {
        $filters[] = "t.status='delivered' AND t.carrier_invoice=''";
    }
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,150) : '';
    if ($search !== '') {
        $filters[] = "(CONCAT('TR/',DATE_FORMAT(t.created_at,'%Y/%m/'),IF(t.id<1000,LPAD(t.id,4,'0'),t.id)) LIKE ? OR t.reference LIKE ? OR t.carrier_invoice LIKE ? OR t.goods_invoice LIKE ?)";
        array_push($params,...array_fill(0,4,'%'.$search.'%'));
        if (preg_match('~^TR/[0-9]{4}/[0-9]{2}/([0-9]{4,12})$~iD', $search, $matches)) {
            $filters[count($filters)-1] = '('.$filters[count($filters)-1].' OR t.id=?)';
            $params[] = $matches[1];
        }
    }
    $query = $db->prepare($sql.($filters ? ' WHERE '.implode(' AND ',$filters) : '').' ORDER BY t.id DESC');
    $query->execute($params);
    $orders = $query->fetchAll();
    $editing = null;
    $edit = $_GET['edit'] ?? '';
    if (is_string($edit) && preg_match('/^[1-9][0-9]{0,11}$/D', $edit)) {
        $query = $db->prepare($sql.' WHERE t.id=?'.($owner ? '' : ' AND t.account_id=?'));
        $query->execute($owner ? [$edit] : [$edit,$actor]);
        $editing = $query->fetch() ?: null;
    }
    $usage = $db->query("SELECT factory_id, carrier_id, COUNT(*) AS uses FROM jm_transport_orders WHERE status<>'draft' GROUP BY factory_id, carrier_id")->fetchAll();
} catch (DomainException $exception) {
    http_response_code(403);
    exit('To konto nie ma dostępu do transportu.');
} catch (Throwable $exception) {
    http_response_code(503);
    exit('Nie można potwierdzić operacji. Odśwież kartotekę i sprawdź ostatni wpis.');
}
$nonce = base64_encode(random_bytes(18));
$frameAncestors = $embedded ? "'self'" : "'none'";
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce' 'self'; worker-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors $frameAncestors; base-uri 'none'");
$csrf = e($_SESSION['csrf']);
$createValues = ($_POST['action'] ?? '') === 'create' ? $_POST : [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editing) {
    foreach (['factory'=>'factory_id','carrier'=>'carrier_id','pickup_brand'=>'factory','pickup_country'=>'pickup_country',
        'pickup_address'=>'pickup_address','delivery_address'=>'delivery_address','reference'=>'reference',
        'pickup'=>'pickup_date','delivery'=>'delivery_date','weight'=>'weight_kg','pallets'=>'pallets',
        'company'=>'company','agreed'=>'agreed_net','currency'=>'currency','notes'=>'notes'] as $key=>$column) {
        $createValues[$key] = (string)$editing[$column];
    }
}
$editFormId = $editing['id'] ?? ($createValues['edit_id'] ?? '');
$transportUrl = '/transport.php'.($embedded ? '?panel=1' : '');
$transportQuery = $transportUrl.($embedded ? '&' : '?');
function previous(string $key): string
{
    global $createValues;
    return is_string($createValues[$key] ?? null) ? e($createValues[$key]) : '';
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Transport zewnętrzny · JURKO Manager</title>
<style nonce="<?=e($nonce)?>">
*{box-sizing:border-box}body{margin:0;background:#f2f5f3;color:#182720;font:12px/1.35 system-ui,sans-serif}
header{background:#142a22;color:white}nav,main{max-width:1220px;margin:auto;padding:10px 14px}
nav{display:flex;align-items:center;justify-content:space-between;gap:16px}nav a{color:#d7eee0}
h1{font-size:clamp(18px,2vw,24px);letter-spacing:-.02em;margin:0 0 8px}h2{font-size:14px;margin:0 0 8px}h3{font-size:13px}
section,main>details{background:white;border:1px solid #d8e1dc;border-radius:8px;padding:10px;margin:0 0 9px}
summary{cursor:pointer;font-weight:700}details[open]>summary{margin-bottom:8px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,145px),1fr));gap:7px}
label{display:grid;gap:3px;font-weight:600}input,select,textarea,button{font:inherit;padding:4px 6px;border:1px solid #b9c7bd;border-radius:4px;min-width:0}
textarea{width:100%;resize:vertical}button,.button{display:inline-block;border:0;background:#19533e;color:white;padding:5px 8px;border-radius:4px;font-weight:700;cursor:pointer;text-decoration:none}
.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:7px}.table{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:4px 6px;border:1px solid #d7e1da;text-align:left;vertical-align:top}thead th{background:#e3ede5;font-size:11px}tbody tr:nth-child(even){background:#f5f8f6}td details{min-width:125px}td form label{margin:4px 0}
.notice{padding:12px;background:#e1f1e8;border-radius:8px}.error{background:#fce4e1}.muted,small{color:#4b6155}
.check{display:flex;align-items:center;gap:8px}nav form{margin:0}
.transport-layout{display:grid;grid-template-columns:minmax(170px,.65fr) minmax(300px,1.4fr) minmax(240px,1fr);gap:9px;align-items:start}
.transport-content,.transport-messages,.transport-sidebar{min-width:0}.transport-content{grid-column:2;grid-row:1}.transport-sidebar{grid-column:1;grid-row:1}.transport-messages{grid-column:3;grid-row:1}.transport-history{grid-column:1/-1;min-width:0}.transport-content>details{background:white;border:1px solid #d8e1dc;border-radius:8px;padding:10px;margin:0 0 9px}
.factory-menu{position:sticky;top:12px;background:white;border:1px solid #d8e1dc;border-radius:8px;padding:8px}
.factory-menu h2{font-size:14px}.factory-group{display:grid;gap:4px;margin-bottom:8px}
.factory-menu button{width:100%;text-align:left;background:#f1f2f3;color:#293238;overflow-wrap:anywhere}
.factory-menu button:hover,.factory-menu button[aria-pressed=true]{background:#e0e3e6;color:#20282d}
#factory-results{display:grid;gap:5px;margin-top:8px}#factory-feedback{overflow-wrap:anywhere}
main{max-width:1600px}
.transport-history[hidden]{display:none}.transport-sidebar>a{display:block;margin-bottom:7px;text-align:center}
.transport-messages textarea{font-size:12px}.transport-messages h2{font-size:14px}
@media(max-width:1050px){.transport-layout{grid-template-columns:minmax(125px,.65fr) minmax(0,1.4fr) minmax(185px,1fr)}.transport-messages .grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.transport-layout{display:block}.transport-sidebar,.transport-content,.transport-messages{margin-bottom:16px}}

.pickup-block{display:grid;gap:6px;margin:0 0 8px}.pickup-block h3{margin:0}.pickup-block p{margin:0}
#carrier-recommendations{margin:3px 0 7px}#carrier-recommendations td{padding:5px 0}#carrier-recommendations button{padding:6px 8px}
.factory-addresses{display:grid;gap:5px;margin:6px 0 9px 10px}.factory-addresses[hidden]{display:none}
.factory-addresses button{font-size:11px}.factory-recipients{display:grid;gap:6px;margin-bottom:8px}
.factory-recipients label{display:flex;align-items:center;gap:6px;padding:5px 8px;overflow-wrap:anywhere}
.factory-recipients label:has(:checked){background:#e1f1e8;border-radius:6px}
.factory-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px}
.factory-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}.factory-heading h2{margin:0}.factory-heading button{width:30px;text-align:center;font-size:17px;padding:3px}
.shipment-step{border:1px solid #dce0e3;border-radius:6px;padding:7px}
.transport-messages .actions [aria-disabled=true]{opacity:.55;cursor:default}
.factory-menu{position:static}
#factory-entry{box-sizing:border-box;width:min(460px,calc(100% - 24px));max-height:calc(100% - 24px);padding:16px;border:1px solid #dce0e3;border-radius:8px;color:inherit}#factory-entry::backdrop{background:#18292066}
#factory-entry form{display:grid;gap:5px}
#factory-entry input,#factory-entry select{width:100%}
#factory-entry form button{white-space:normal;text-align:center}
#factory-photo-status:empty{display:none}
p{margin:6px 0}.transport-messages textarea{min-height:185px;resize:vertical}.transport-history .table{margin-top:7px}
@media(max-width:600px){input,select,textarea{font-size:16px}button,.button{min-height:32px}}
.carrier-directory{margin-top:8px}.carrier-directory form,.shipment-step details{display:grid;gap:5px}
.carrier-directory input{width:100%}.carrier-names{margin:6px 0 0;padding-left:18px}
[id$="-photo-status"]:empty,#pickup-message:empty{display:none}
.shipment-header{display:flex;justify-content:space-between;align-items:start;gap:8px;margin-bottom:7px}
.shipment-header>label{flex:0 1 52%}.shipment-fields{grid-template-columns:repeat(3,minmax(0,1fr))}
@media(max-width:1050px){.shipment-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
.directory-buttons{display:grid;gap:4px;margin:6px 0}
.directory-buttons button{text-align:left;background:#f1f2f3;color:#293238;white-space:normal;overflow-wrap:anywhere}
.directory-buttons button:hover{background:#e0e3e6}
.selected-carrier{background:#f6f6f6;padding:8px;line-height:1.6;overflow-wrap:anywhere}
.shipment-photo{margin-top:8px;min-height:70px}#unload-directory form{display:grid;gap:5px;margin-top:6px}
@media(max-width:600px){.shipment-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
.employee-access{margin-top:8px}.employee-access .actions{gap:5px}
#factory-entry-handle{cursor:move;touch-action:none;user-select:none}#factory-entry-handle button{cursor:pointer}
.transport-heading{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;margin-bottom:8px}
.transport-heading h1{margin:0}.transport-heading .button{min-width:min(220px,100%);text-align:center;box-sizing:border-box}
.cutting-panel{grid-column:1/-1;background:#fff;border:1px solid #d8e1dc;border-radius:8px;padding:12px}.cutting-tab{display:block;text-align:center}.cutting-scroll{overflow:auto;margin:10px 0}.cutting-scroll table{min-width:1150px}.cutting-scroll input{width:72px;margin:2px}.cutting-scroll td:first-child input{width:135px}.cutting-scroll td:nth-child(4),.cutting-scroll td:nth-child(5){white-space:nowrap}.cutting-scroll td:nth-child(4) input,.cutting-scroll td:nth-child(5) input{width:46px}.cutting-scroll output{display:block;white-space:pre-line;min-width:170px}
</style>
</head>
<body>
<?php if (!$embedded): ?>
<header><nav>
<?php if ($owner): ?><a href="/index.html">← JURKO Manager</a><?php else: ?><strong>JURKO Manager</strong><?php endif ?>
<form action="/auth/index.php?action=logout" method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><button>Wyloguj</button></form>
</nav></header>
<?php endif ?>
<main>
<div class="transport-layout">
<div class="transport-sidebar">
<aside class="factory-menu" aria-label="Fabryki">
<div class="factory-heading"><h2>Fabryki</h2><?php if ($owner): ?><button type="button" id="add-factory" aria-label="Dodaj fabrykę" title="Dodaj fabrykę">+</button><?php endif ?></div>
<div id="factory-shortcuts"></div>
<label>Szukaj fabryki<input id="factory-search" type="search" placeholder="Nazwa lub adres"></label>
<div id="factory-results"></div>
<div id="factory-top-carriers"></div>
<p id="factory-feedback" class="muted" role="status"></p>
<?php if ($owner): ?>
<dialog id="factory-entry" aria-labelledby="factory-entry-title">
<div class="factory-heading" id="factory-entry-handle"><h2 id="factory-entry-title">Dodaj fabrykę</h2><button type="button" id="close-factory" aria-label="Zamknij">×</button></div>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="directory"><input type="hidden" name="kind" value="factory">
<input type="hidden" name="directory_id" id="directory-point-id">
<label>Kraj<select name="country" required id="factory-country"><?php foreach (FACTORIES as $country => $names): ?><option><?=e($country)?></option><?php endforeach ?></select></label>
<label>Fabryka<input id="directory-factory" name="name" list="factory-names" maxlength="150" required></label><datalist id="factory-names"></datalist>
<label>Adres odbioru<input id="directory-address" name="address" maxlength="300" required></label>
<label>E-maile do fabryki<input id="directory-emails" name="email" type="email" multiple maxlength="254" placeholder="Adresy oddziel przecinkami"></label>
<label>Zdjęcie z danymi<input id="factory-photo" type="file" accept="image/png,image/jpeg,image/webp"></label>
<label>Tekst ze zdjęcia lub schowka<textarea id="factory-photo-text" rows="3" maxlength="12000"></textarea></label>
<button type="button" id="fill-factory-fields">Uzupełnij pola z tekstu</button>
<p id="factory-photo-status" class="muted" role="status"></p>
<button>Zapisz punkt odbioru</button>
</form>
</dialog>
<?php endif ?>
</aside>
<section class="carrier-directory">
<h2>Przewoźnik</h2>
<?php if ($owner): ?>
<details><summary>Dodaj firmę transportową</summary>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="directory"><input type="hidden" name="kind" value="carrier">
<label>Nazwa firmy<input id="carrier-name" name="name" maxlength="150" required></label>
<label>E-mail<input id="carrier-email" name="email" type="email" maxlength="254"></label>
<label>Telefon do SMS / WhatsApp<input id="carrier-phone" name="phone" type="tel" maxlength="30" placeholder="+48"></label>
<label>Zdjęcie z danymi<input id="carrier-photo" type="file" accept="image/png,image/jpeg,image/webp"></label>
<label>Wklej zdjęcie lub tekst<textarea id="carrier-photo-text" rows="3" maxlength="12000"></textarea></label>
<button type="button" id="fill-carrier-fields">Uzupełnij pola z tekstu</button>
<p id="carrier-photo-status" class="muted" role="status"></p>
<button>Zapisz przewoźnika</button>
</form>
</details>
<?php endif ?>
<details><summary>Wybierz przewoźnika</summary>
<div class="directory-buttons">
<?php foreach ($carriers as $item): ?><button type="button" data-carrier="<?=e($item['id'])?>"><?=e($item['name'])?></button><?php endforeach ?>
<?php if (!$carriers): ?><p>Brak zapisanych przewoźników.</p><?php endif ?>
</div>
</details>
</section>
<section id="unload-directory">
<h2>Punkt rozładunku</h2>
<div class="directory-buttons">
<button type="button" data-unload="JURKO, Al. Jerozolimskie 229, 02-495 Warszawa">JURKO · Al. Jerozolimskie 229</button>
<?php foreach ($unloads as $item): ?><button type="button" data-unload="<?=e($item['address'])?>"><?=e($item['name'])?><br><small><?=e($item['address'])?></small></button><?php endforeach ?>
</div>
<?php if ($owner): ?>
<details><summary>Dodaj punkt rozładunku</summary>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="directory"><input type="hidden" name="kind" value="unload">
<label>Nazwa budowy lub punktu<input name="name" maxlength="150" required></label>
<label>Adres rozładunku<input name="address" maxlength="300" required></label>
<button>Zapisz punkt rozładunku</button>
</form>
</details>
<?php endif ?>
</section>
<?php if ($owner): ?>
<details class="factory-menu employee-access">
<summary>Dostęp pracowników</summary>
<p>Dodaj adres Gmail lub Google Workspace pracownika. Po zalogowaniu pracownik zobaczy swoje zlecenia.</p>
<form method="post" class="actions">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="access">
<label>E-mail konta Google<input name="email" type="email" maxlength="254" required></label>
<button name="access" value="enable">Włącz dostęp</button><button name="access" value="disable">Wyłącz dostęp</button>
</form>
<?php if ($employees): ?><ul><?php foreach ($employees as $employee): ?><li><?=e($employee['email'])?> · <?=$employee['active']?'Aktywny':'Wyłączony'?></li><?php endforeach ?></ul><?php endif ?>
</details>
<?php endif ?>
</div>
<div class="transport-content">
<div class="transport-heading"><h1>Transport zewnętrzny</h1><a class="button" href="#transport-history" id="open-history" aria-expanded="false">Wcześniejsze zlecenia</a></div>
<?php if ($saved): ?><p class="notice" role="status">Zapisano zmiany.</p><?php endif ?>
<?php if ($error !== ''): ?><p class="notice error" role="alert"><?=e($error)?></p><?php endif ?>
<section id="new-order">
<div class="shipment-header"><h2><?=$editFormId !== '' ? 'Edycja zlecenia'.($editing ? ' · '.e(transportNumber($editing)) : '') : 'Nowa przesyłka'?></h2>
<?php if ($editFormId !== ''): ?><a href="<?=e($transportUrl)?>">Nowa przesyłka</a><?php endif ?><button type="submit" form="shipment-form">Zapisz zlecenie</button></div>

<form method="post" id="shipment-form">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create">
<input type="hidden" name="edit_id" value="<?=e(is_scalar($editFormId) ? (string)$editFormId : '')?>">
<input type="hidden" name="request" value="<?=e(bin2hex(random_bytes(16)))?>">
<div class="pickup-block shipment-step">
<div class="shipment-header"><h3 id="pickup-title">Punkt odbioru</h3>
<label>Adres dostawy<input id="delivery-address" name="delivery_address" maxlength="300" value="<?=($editFormId !== '' || array_key_exists('delivery_address',$createValues)) ? previous('delivery_address') : 'JURKO, Al. Jerozolimskie 229, 02-495 Warszawa'?>"></label>
</div>
<input type="hidden" id="pickup-brand" name="pickup_brand" value="<?=previous('pickup_brand')?>">
<input type="hidden" id="pickup-country" name="pickup_country" value="<?=previous('pickup_country')?>">
<label hidden>Zapisany punkt odbioru<select name="factory" id="order-factory"><option value="">Wybierz punkt odbioru</option><?php foreach (array_keys(FACTORIES) as $country): ?><optgroup label="<?=e($country)?>"><?php foreach ($factories as $item): if ($item['country'] !== $country) continue; ?><option value="<?=e($item['id'])?>" data-address="<?=e($item['address'])?>" <?=previous('factory')===(string)$item['id']?'selected':''?>><?=e($item['name'].' · '.$item['address'])?></option><?php endforeach ?></optgroup><?php endforeach ?></select></label>
<label>Adres odbioru<input id="order-address" name="pickup_address" maxlength="300" value="<?=previous('pickup_address')?>"></label>
</div>
<div class="pickup-block shipment-step shipment-fields">
<label>Dyspozycja<input id="pickup-reference" name="reference" maxlength="150" value="<?=previous('reference')?>"></label>
<label>Waga brutto (kg)<input name="weight" inputmode="decimal" value="<?=previous('weight')?>"></label>
<label>Data odbioru<input type="date" name="pickup" value="<?=previous('pickup')?>"></label>
<label>Liczba palet<input type="number" name="pallets" min="1" max="999999" value="<?=previous('pallets')?>"></label>
<label>Data dostawy<input type="date" name="delivery" value="<?=previous('delivery')?>"></label>
</div>
<div class="pickup-block shipment-step">
<h3>Przewoźnik</h3>
<div id="selected-carrier" class="selected-carrier" hidden></div>
<label hidden>Przewoźnik<select name="carrier" id="order-carrier"><option value="">Wybierz przewoźnika</option><?php foreach ($carriers as $item): ?><option value="<?=e($item['id'])?>" <?=previous('carrier')===(string)$item['id']?'selected':''?>><?=e($item['name'])?></option><?php endforeach ?></select></label>
<?php if (!$carriers): ?><p class="muted">Brak przewoźników w kartotece.</p><?php endif ?>
</div>
<details class="shipment-step"><summary>Spółka, cena i uwagi</summary><div class="grid">
<label>Spółka<select name="company"><?php foreach (COMPANIES as $id => $name): ?><option value="<?=e($id)?>" <?=previous('company')===$id?'selected':''?>><?=e($name)?></option><?php endforeach ?></select></label>
<label>Uzgodniona cena netto<input name="agreed" inputmode="decimal" value="<?=previous('agreed')?>"></label>
<label>Waluta<select name="currency"><option>PLN</option><option <?=previous('currency')==='EUR'?'selected':''?>>EUR</option></select></label>
</div>
<p><label>Uwagi lub wklejone dane z fabryki<textarea name="notes" rows="3" maxlength="6000"><?=previous('notes')?></textarea></label></p>
</details>
<div class="pickup-block shipment-step shipment-photo"><details><summary>Wklej zdjęcie lub tekst dyspozycji</summary>
<label>Zdjęcie z danymi<input id="pickup-photo" type="file" accept="image/png,image/jpeg,image/webp"></label>
<label>Wklej zdjęcie lub tekst<textarea id="pickup-paste" rows="3" maxlength="12000"></textarea></label>
<button type="button" id="split-pickup">Uzupełnij pola z tekstu</button>
<p id="pickup-message" class="muted" role="status"></p>
</details></div>
</form>
</section>

</div>
<aside class="transport-messages" aria-label="Wiadomości transportowe">
<section>
<a class="button cutting-tab" href="#cutting">Cięcie</a>
</section>
<section>
<h2>Zlecenie do przewoźnika</h2>
<label>Treść wiadomości<textarea id="carrier-preview" rows="6" readonly></textarea></label>
<div class="actions"><a class="button" id="carrier-mail" aria-disabled="true">E-mail</a><a class="button" id="carrier-whatsapp" aria-disabled="true" target="_blank" rel="noopener noreferrer">WhatsApp</a><a class="button" id="carrier-sms" aria-disabled="true">SMS</a></div>
<p id="carrier-channel-status" class="muted" role="status"></p>
</section>
<section>
<h2>Dane kierowcy do fabryki</h2>
<div class="grid">
<label>Imię i nazwisko<input id="driver-name" maxlength="100"></label>
<label>Telefon<input id="driver-phone" type="tel" maxlength="32"></label>
<label>Rejestracja pojazdu<input id="driver-plate" maxlength="24"></label>

</div>
<p><label>Treść wiadomości<textarea id="factory-preview" rows="5" readonly></textarea></label></p>
<div id="factory-emails" class="factory-recipients"></div>
<label>Dodatkowy e-mail do fabryki<input id="factory-email" type="email" multiple maxlength="254"></label>
<a id="factory-mail" hidden>Otwórz e-mail do fabryki</a>
</section>

</aside>
<details id="cutting" class="cutting-panel">
<summary>Cięcie · wycena netto</summary>
<div class="cutting-scroll"><table>
<thead><tr><th>Pozycja</th><th>Ilość</th><th>Jednostka</th><th>Płytka (cm)</th><th>Element (cm)</th><th>Elementy z płytki</th><th>Materiał PLN/m²</th><th>Cięcie netto</th><th>Rozkrój</th><th>Narzut %</th><th>Wynik</th><th></th></tr></thead>
<tbody id="cutting-rows"></tbody>
</table></div>
<div class="actions"><button type="button" id="cutting-add">Dodaj pozycję</button><button type="button" id="cutting-example">Dodaj przykłady</button></div>
<p id="cutting-total" role="status"></p>
<template id="cutting-row">
<tr>
<td><input aria-label="Nazwa pozycji" data-field="name" maxlength="150"></td>
<td><input aria-label="Ilość" data-field="qty" inputmode="decimal"></td>
<td><select aria-label="Jednostka ilości" data-field="unit"><option>szt.</option><option>m²</option><option>mb</option></select></td>
<td><input aria-label="Długość płytki" data-field="tileL" inputmode="decimal"> × <input aria-label="Szerokość płytki" data-field="tileW" inputmode="decimal"></td>
<td><input aria-label="Długość elementu" data-field="partL" inputmode="decimal"> × <input aria-label="Szerokość elementu" data-field="partW" inputmode="decimal"></td>
<td><input aria-label="Elementy z płytki, opcjonalna korekta" placeholder="Auto" data-field="yield" inputmode="numeric"></td>
<td><input aria-label="Cena materiału za metr kwadratowy" data-field="material" inputmode="decimal"></td>
<td><input aria-label="Koszt obróbki za sztukę" placeholder="PLN/szt." data-field="finish" inputmode="decimal"><input aria-label="Cena obróbki dla klienta za sztukę" placeholder="Klient PLN/szt." data-field="client" inputmode="decimal"></td>
<td><input aria-label="Metry rozkroju na płytkę" placeholder="mb/płytkę" data-field="cutLength" inputmode="decimal"><input aria-label="Koszt rozkroju za metr" placeholder="PLN/mb" data-field="cutRate" inputmode="decimal"></td>
<td><input aria-label="Narzut na usługi" data-field="markup" inputmode="decimal"></td>
<td><output></output></td><td><button type="button" data-remove aria-label="Usuń pozycję">×</button></td>
</tr>
</template>
</details>
<section class="transport-history" id="transport-history" <?=($search === '' && ($_GET['unbilled'] ?? '') !== '1' && !isset($_GET['history'])) ? 'hidden' : ''?>>
<h2>Wcześniejsze zlecenia</h2>
<form method="get" class="actions">
<?php if ($embedded): ?><input type="hidden" name="panel" value="1"><?php endif ?>
<input type="hidden" name="history" value="1">
<label>Szukaj zlecenia, zamówienia lub faktury<input name="q" value="<?=e($search)?>" maxlength="150"></label>
<label class="check"><input type="checkbox" name="unbilled" value="1" <?=($_GET['unbilled']??'')==='1'?'checked':''?>> Dostarczone bez faktury</label>
<button>Filtruj</button>
</form>
<?php if (!$orders): ?><p class="muted">Brak zleceń do wyświetlenia.</p><?php else: ?>
<div class="table"><table><thead><tr><th>Zlecenie</th><th>Fabryka i przewoźnik</th><th>Odbiór / dostawa</th><th>Koszt netto</th><th>Status i faktury</th><th>Wiadomość</th></tr></thead><tbody>
<?php foreach ($orders as $order):
    $number = transportNumber($order);
    $message = "Dzień dobry,\nproszę o odbiór towaru.\n\nZlecenie: $number\nSpółka: ".COMPANIES[$order['company']].
         "\nFabryka: ".$order['factory']."\nOdbiór z: ".$order['pickup_address']."\nData odbioru: ".$order['pickup_date'].
        "\nDostawa do: ".$order['delivery_address']."\nData dostawy: ".$order['delivery_date'].
        "\nReferencja: ".$order['reference']."\nPalety: ".$order['pallets']."\nWaga brutto: ".$order['weight_kg']." kg".
        ($order['agreed_net'] !== null ? "\nUzgodniona stawka netto: ".$order['agreed_net'].' '.$order['currency'] : '').
        "\n\nProszę o potwierdzenie zlecenia i podanie numeru $number na fakturze.";
?>
<tr id="order-<?=e($order['id'])?>">
<td><a class="button" href="<?=e($transportQuery.'edit='.$order['id'].'#new-order')?>">Otwórz i edytuj</a><br><a href="<?=e('/transport.php?'.($embedded ? 'panel=1&' : '').'history=1&order='.$order['id'].'#order-'.$order['id'])?>"><?=e($number)?></a><br><?=e($order['reference'])?><br><small><?=e(COMPANIES[$order['company']])?><br><?=e($order['author'])?></small></td>
<td><?=e($order['factory'])?><br><small><?=e($order['pickup_address'])?></small><br><?=e($order['carrier'])?></td>
<td><?=e($order['pickup_date'])?><br><?=e($order['delivery_date'])?><br><small><?=e($order['delivery_address'])?><br><?=e($order['pallets'] === null ? '—' : $order['pallets'].' pal.')?> · <?=e($order['weight_kg'] === null ? '—' : $order['weight_kg'].' kg')?></small><?php if ($order['notes'] !== ''): ?><details><summary>Uwagi</summary><?=nl2br(e($order['notes']))?></details><?php endif ?></td>
<td>Uzgodniony <?=e($order['agreed_net'] === null ? '—' : $order['agreed_net'].' '.$order['currency'])?><br>Rozliczony <?=e($order['actual_net'] === null ? '—' : $order['actual_net'].' '.$order['currency'])?></td>
<td><details <?=$openOrder===(string)$order['id'] ? 'open' : ''?>><summary><?=e(STATUSES[$order['status']])?><?php if ($order['carrier_invoice'] !== ''): ?><br><small><?=e($order['carrier_invoice'])?></small><?php endif ?></summary>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=e($order['id'])?>">
<label>Status<select name="status"><?php foreach (STATUSES as $id => $name): ?><option value="<?=e($id)?>" <?=$order['status']===$id?'selected':''?>><?=e($name)?></option><?php endforeach ?></select></label>
<label>Faktura zakupu towaru<input name="goods_invoice" maxlength="150" value="<?=e($order['goods_invoice'])?>"></label>
<?php if ($owner): ?><label>Faktura przewoźnika<input name="carrier_invoice" maxlength="150" value="<?=e($order['carrier_invoice'])?>"></label><label>Koszt kursu netto (<?=e($order['currency'])?>)<input name="actual" inputmode="decimal" value="<?=e($order['actual_net'])?>"></label>
<label>PDF faktury (do 1,5 MB)<input type="file" name="invoice_pdf" accept="application/pdf"></label>
<?php if ($order['has_pdf']): ?><p><a href="/transport.php?invoice=<?=e($order['id'])?>">Pobierz fakturę PDF</a></p><?php endif ?>
<?php endif ?>
<button>Zapisz</button></form></details></td>
<td>
<?php if (!empty($order['carrier_email'])): ?><a href="<?=e('mailto:'.str_replace('%40','@',rawurlencode($order['carrier_email'])).'?subject='.rawurlencode('Zlecenie transportu '.$number).'&body='.rawurlencode($message))?>">E-mail</a><br><?php endif ?>
<?php if (!empty($order['carrier_phone'])): ?><a href="<?=e('https://wa.me/'.$order['carrier_phone'].'?text='.rawurlencode($message))?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif ?>
</td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="muted">Po wysłaniu wiadomości ustaw status „Wysłane”.</p>
<?php endif ?>
</section>
</div>
</main>
<script nonce="<?=e($nonce)?>">
const country = document.getElementById('factory-country');
const names = <?=json_encode(FACTORIES, JSON_HEX_TAG | JSON_THROW_ON_ERROR)?>;
if (country) {
    const updateNames = () => {
        document.getElementById('factory-names').replaceChildren(
            ...names[country.value].map(name => new Option(name))
        );
    };
    country.addEventListener('change', updateNames);
    updateNames();
}
const factory = document.getElementById('order-factory');
const carrier = document.getElementById('order-carrier');
const usage = <?=json_encode($usage, JSON_HEX_TAG | JSON_THROW_ON_ERROR)?>;
const shortcuts = document.getElementById('factory-shortcuts');
const search = document.getElementById('factory-search');
const results = document.getElementById('factory-results');
const feedback = document.getElementById('factory-feedback');
const orderAddress = document.getElementById('order-address');
const points = <?=json_encode(array_values(array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id','name','country','address','email'])), $factories)), JSON_HEX_TAG | JSON_THROW_ON_ERROR)?>;
const normalize = value => value.toLocaleLowerCase('pl').replace(/ł/g,'l').normalize('NFD').replace(/\p{M}/gu,'').replace(/[^a-z0-9]+/g,'');
const groups = [['Polska',['Tubądzin','Paradyż','Cerrad']],['Włochy',['Novabell','Italgraniti']]];
const directory = new Map();
for (const [place, brands] of Object.entries(names)) {
    for (const brand of brands) directory.set(normalize(brand), {name:brand, country:place, points:[]});
}
for (const point of points) {
    const key = normalize(point.name);
    if (!directory.has(key)) directory.set(key, {name:point.name, country:point.country, points:[]});
    directory.get(key).points.push(point);
}
const brandInput = document.getElementById('pickup-brand');
const countryInput = document.getElementById('pickup-country');
const pickupTitle = document.getElementById('pickup-title');
const sidebarCarriers = document.getElementById('factory-top-carriers');
function showBrand(brand) {
    brandInput.value = brand.name;
    countryInput.value = brand.country;
    pickupTitle.textContent = 'Punkt odbioru · ' + brand.name;
    const ids = new Set(brand.points.map(point => String(point.id)));
    const totals = new Map();
    for (const row of usage) {
        if (ids.has(String(row.factory_id))) {
            const id = String(row.carrier_id);
            totals.set(id, (totals.get(id) || 0) + Number(row.uses));
        }
    }
    const top = [...carrier.options].slice(1).filter(option => totals.has(option.value))
        .sort((a,b) => totals.get(b.value)-totals.get(a.value) || a.text.localeCompare(b.text,'pl')).slice(0,3);
    for (const container of [sidebarCarriers]) {
        container.replaceChildren();
        if (!top.length) {
            container.textContent = 'Ranking pojawi się po wysłaniu zleceń z tej fabryki.';
            continue;
        }
        const table = document.createElement('table');
        const caption = table.createCaption();
        caption.textContent = 'Najczęstsi przewoźnicy · ' + brand.name;
        for (const option of top) {
            const row = table.insertRow();
            addButton(row.insertCell(), option.text, () => { carrier.value = option.value; carrier.dispatchEvent(new Event('change',{bubbles:true})); });
            row.insertCell().textContent = totals.get(option.value);
        }
        container.append(table);
    }
}
function selectPoint(point) {
    factory.value = String(point.id);
    factory.dispatchEvent(new Event('change'));
    feedback.textContent = point.address;
    orderAddress.dispatchEvent(new Event('input',{bubbles:true}));
    orderAddress.focus({preventScroll:true});
    document.getElementById('new-order').scrollIntoView({block:'start'});
}
factory.addEventListener('change', () => {
    const point = points.find(item => String(item.id) === factory.value);
    if (!point) return;
    orderAddress.value = point.address;
    showBrand(directory.get(normalize(point.name)));
});
const initialPoint = points.find(item => String(item.id) === factory.value);
const initialBrand = directory.get(normalize(initialPoint?.name || brandInput.value));
if (initialBrand) showBrand(initialBrand);
function addButton(container, label, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', action);
    container.append(button);
    return button;
}
const defaultTubadzin = 'Tubądzin Management Group, Cedrowice Parcela 11, 95-035 Ozorków';
let expandedFactory;
function openFactoryEntry(point = null) {
    const entry = document.getElementById('factory-entry');
    entry.querySelector('form').reset();
    document.getElementById('factory-photo-status').textContent = '';
    document.getElementById('directory-point-id').value = point?.id ?? '';
    country.value = point?.country ?? country.options[0].value;
    country.dispatchEvent(new Event('change'));
    document.getElementById('directory-factory').value = point?.name ?? '';
    document.getElementById('directory-address').value = point?.address ?? '';
    document.getElementById('directory-emails').value = point?.email ?? '';
    document.getElementById('factory-entry-title').textContent = point ? 'Modyfikuj punkt odbioru' : 'Dodaj fabrykę';
    entry.showModal();
    document.getElementById('directory-factory').focus();
}
if (country) {
    const entry = document.getElementById('factory-entry');
    const handle = document.getElementById('factory-entry-handle');
    let drag;
    handle.addEventListener('pointerdown', event => {
        if (event.button !== 0 || drag || event.target.closest('button')) return;
        const rect = entry.getBoundingClientRect();
        drag = {id:event.pointerId,x:event.clientX-rect.left,y:event.clientY-rect.top};
        handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener('pointermove', event => {
        if (drag?.id !== event.pointerId) return;
        const rect = entry.getBoundingClientRect();
        const x = Math.max(0,Math.min(innerWidth-rect.width,event.clientX-drag.x));
        const y = Math.max(0,Math.min(innerHeight-rect.height,event.clientY-drag.y));
        Object.assign(entry.style,{margin:'0',inset:'auto',left:x+'px',top:y+'px'});
    });
    handle.addEventListener('lostpointercapture', () => { drag = null; });
    entry.addEventListener('close', () => { entry.removeAttribute('style'); });
    document.getElementById('add-factory').addEventListener('click', () => openFactoryEntry());
    document.getElementById('close-factory').addEventListener('click', () => document.getElementById('factory-entry').close());
}
function addFactory(container, brand) {
    const group = document.createElement('div');
    const addresses = document.createElement('div');
    addresses.className = 'factory-addresses';
    addresses.hidden = true;
    const row = document.createElement('div');
    row.className = 'factory-row';
    group.append(row);
    const toggle = addButton(row, brand.name, () => {
        const wasOpen = !addresses.hidden;
        if (expandedFactory) {
            expandedFactory.addresses.hidden = true;
            expandedFactory.toggle.setAttribute('aria-expanded','false');
        }
        if (wasOpen) { expandedFactory = null; return; }
        addresses.hidden = false;
        toggle.setAttribute('aria-expanded','true');
        expandedFactory = {addresses,toggle};
    });
    toggle.setAttribute('aria-expanded','false');
    for (const point of brand.points) {
        const pointRow = document.createElement('div');
        pointRow.className = 'factory-row';
        addButton(pointRow, point.address, () => selectPoint(point));
        if (country) {
            const edit = addButton(pointRow, 'Modyfikuj', () => openFactoryEntry(point));
            edit.setAttribute('aria-label','Modyfikuj punkt odbioru: '+point.address);
        }
        addresses.append(pointRow);
    }
    if (!brand.points.length && brand.name === 'Tubądzin') {
        addButton(addresses, defaultTubadzin, () => selectBrand(brand));
    } else if (!brand.points.length) {
        addButton(addresses, 'Wpisz adres ręcznie', () => {
            selectBrand(brand);
            orderAddress.focus();
        });
    }
    group.append(addresses);
    container.append(group);
}
function selectBrand(brand) {
    showBrand(brand);
    if (brand.points.length === 1) {
        selectPoint(brand.points[0]);
        return;
    }
    factory.value = '';
    orderAddress.value = !brand.points.length && brand.name === 'Tubądzin' ? defaultTubadzin : '';
    feedback.textContent = brand.points.length > 1 ? 'Wybierz punkt odbioru pod nazwą fabryki.'
        : orderAddress.value || 'Wklej dyspozycję lub wpisz adres odbioru.';
    orderAddress.dispatchEvent(new Event('input',{bubbles:true}));
}
const paste = document.getElementById('pickup-paste');
const reference = document.getElementById('pickup-reference');
const message = document.getElementById('pickup-message');
function splitPickup() {
    const raw = paste.value.trim();
    if (!raw) { message.textContent = 'Wklej dyspozycję z fabryki.'; return; }
    const marker = /\b(?:(?:nr|numer)\.?\s+(?:dyspozycji|odbioru|awizacji|zamówienia)|dyspozycja(?:\s+do\s+odbioru)?|referencja(?:\s+odbioru)?|pickup\s+(?:reference|number))\s*(?:nr\.?\s*)?[:#–-]?\s*([\p{L}\d][\p{L}\d._/-]*)/iu;
    if (!brandInput.value) {
        const normalizedText = normalize(raw);
        const brand = [...directory.entries()].find(([name]) => normalizedText.includes(name))?.[1];
        if (brand) selectBrand(brand);
    }
    const found = raw.match(marker);
    const date = raw.match(/(?:data\s+odbioru|odbiór|załadunek)\s*[:–-]?\s*(?:(\d{4})-(\d{2})-(\d{2})|(\d{1,2})[./-](\d{1,2})[./-](\d{4}))/iu);
    const kg = raw.match(/(?:waga(?:\s+brutto)?\s*[:=]?\s*)?(\d{1,3}(?:[ \u00a0]\d{3})+|\d+)(?:([,.]\d{1,2}))?\s*(kg|t)\b/iu);
    const pallets = raw.match(/(?:liczba palet|ilość palet|palety|palet)\s*[:=]?\s*(\d+)|\b(\d+)\s+palet(?:y|a)?\b/iu);
    const fields = paste.closest('form').elements;
    if (date) {
        const iso = date[1] ? date[1]+'-'+date[2]+'-'+date[3] : date[6]+'-'+date[5].padStart(2,'0')+'-'+date[4].padStart(2,'0');
        const parsed = new Date(iso+'T00:00:00Z');
        if (!Number.isNaN(parsed.valueOf()) && parsed.toISOString().slice(0,10) === iso) fields.namedItem('pickup').value = iso;
    }
    if (kg) {
        const weight = Number((kg[1]+(kg[2] || '')).replace(/\s/g,'').replace(',','.')) * (kg[3].toLowerCase() === 't' ? 1000 : 1);
        if (weight > 0 && weight < 1e8) fields.namedItem('weight').value = String(Number(weight.toFixed(2)));
    }
    if (pallets && Number(pallets[1] || pallets[2]) <= 999999) fields.namedItem('pallets').value = pallets[1] || pallets[2];
    const lines = raw.replace(marker, '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
    const label = /(?:adres(?:\s+punktu)?\s+(?:odbioru|załadunku)|punkt odbioru|miejsce załadunku)\s*:/iu;
    const addressStart = lines.findIndex(line => label.test(line));
    const addressLines = (addressStart >= 0 ? lines.slice(addressStart, addressStart + 3) : lines)
        .filter(line => !/^(?:data|waga|liczba palet|ilość palet|palety|odbiór\s*:|załadunek\s*:)/iu.test(line));
    const address = addressLines.map(line => line.replace(label,'').trim()).filter(Boolean).join(', ');
    const looksLikeAddress = /\d{2}-\d{3}|\b\d{5}\b|\b(?:ul\.|via\s|al\.|strada\s)/iu.test(address);
    if (found) reference.value = found[1];
    if (looksLikeAddress && address.length <= 300) orderAddress.value = address;
    orderAddress.dispatchEvent(new Event('input',{bubbles:true}));
    message.textContent = found && looksLikeAddress && address.length <= 300
        ? 'Uzupełniono adres i numer. Sprawdź oba pola przed zapisaniem.'
        : 'Sprawdź pola i uzupełnij dane, których nie udało się odczytać.';
}
document.getElementById('split-pickup').addEventListener('click', splitPickup);

const shortcutBrands = new Set(groups.flatMap(([, brands]) => brands.map(normalize)));
for (const [, brands] of groups) {
    const group = document.createElement('div');
    group.className = 'factory-group';
    shortcuts.append(group);
    for (const brand of brands) addFactory(group, directory.get(normalize(brand)));
}
for (const [key, brand] of directory) {
    if (brand.points.length && !shortcutBrands.has(key)) addFactory(shortcuts, brand);
}
search.addEventListener('input', () => {
    results.replaceChildren();
    feedback.textContent = '';
    const query = normalize(search.value);
    if (!query) return;
    let found = false;
    for (const [key, brand] of directory) {
        if (key.includes(query) || brand.points.some(point => normalize(point.address).includes(query))) {
            addFactory(results, brand);
            found = true;
        }
    }
    if (!found) feedback.textContent = 'Brak pasujących fabryk.';
});
const orderForm = document.querySelector('#new-order form');
const orderHistory = document.getElementById('transport-history');
const historyLink = document.getElementById('open-history');
historyLink.setAttribute('aria-expanded', String(!orderHistory.hidden));
historyLink.addEventListener('click', event => {
    event.preventDefault();
    orderHistory.hidden = !orderHistory.hidden;
    historyLink.setAttribute('aria-expanded', String(!orderHistory.hidden));
    if (!orderHistory.hidden) orderHistory.scrollIntoView({block:'nearest'});
});
const carrierContacts = <?=json_encode(array_column(array_map(static fn(array $item): array => array_intersect_key($item,array_flip(['id','name','address','email','phone'])), $carriers), null, 'id'), JSON_HEX_TAG | JSON_THROW_ON_ERROR)?>;
const selectedCarrier = document.getElementById('selected-carrier');
function showCarrier() {
    const contact = carrierContacts[carrier.value];
    selectedCarrier.hidden = !contact;
    selectedCarrier.replaceChildren();
    if (!contact) return;
    const title = document.createElement('strong');
    title.textContent = contact.name;
    selectedCarrier.append(title);
    for (const line of [contact.address, contact.email, contact.phone && '+'+contact.phone]) {
        if (!line) continue;
        const detail = document.createElement('div');
        detail.textContent = line;
        selectedCarrier.append(detail);
    }
}
carrier.addEventListener('change', showCarrier);
showCarrier();
for (const button of document.querySelectorAll('[data-carrier]')) {
    button.addEventListener('click', () => {
        carrier.value = button.dataset.carrier;
        carrier.dispatchEvent(new Event('change', {bubbles:true}));
    });
}
const deliveryAddress = document.getElementById('delivery-address');
for (const button of document.querySelectorAll('[data-unload]')) {
    button.addEventListener('click', () => {
        deliveryAddress.value = button.dataset.unload;
        deliveryAddress.dispatchEvent(new Event('input', {bubbles:true}));
    });
}
const carrierPreview = document.getElementById('carrier-preview');
const factoryPreview = document.getElementById('factory-preview');
const email = document.getElementById('factory-email');
let previousDraftBrand = null;
const driverFields = ['driver-name','driver-phone','driver-plate'].map(id => document.getElementById(id));
function draftLink(id, url) {
    const link = document.getElementById(id);
    if (id.startsWith('carrier-')) link.setAttribute('aria-disabled', String(!url));
    else link.hidden = !url;
    if (url) link.href = url;
    else link.removeAttribute('href');
}
function updatePreviews() {
    const value = name => orderForm.elements.namedItem(name).value.trim();
    if (previousDraftBrand !== brandInput.value) {
        previousDraftBrand = brandInput.value;
        const addresses = [...new Set((directory.get(normalize(brandInput.value))?.points || [])
            .flatMap(point => (point.email || '').split(',').map(address => address.trim())).filter(Boolean))];
        const list = document.getElementById('factory-emails');
        list.replaceChildren();
        for (const address of addresses) {
            const label = document.createElement('label');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = address;
            checkbox.checked = true;
            checkbox.addEventListener('change', updatePreviews);
            label.append(checkbox, address);
            list.append(label);
        }
        if (brandInput.value && !addresses.length) list.textContent = 'Brak zapisanych adresów e-mail tej fabryki.';
        email.value = '';
    }
    const referenceNumber = value('reference');
    const subject = 'Odbiór z fabryki ' + (brandInput.value || '—') + (referenceNumber ? ' · ' + referenceNumber : '');
    const common = [['Fabryka',brandInput.value],['Adres odbioru',value('pickup_address')],
        ['Numer dyspozycji',referenceNumber],['Data odbioru',value('pickup')]];
    const shipment = [['Odbiór z',value('pickup_address') || '—'],['Dostawa do',value('delivery_address') || '—'],
        common[0],common[2],common[3],['Data dostawy',value('delivery')],
        ['Waga brutto',value('weight') && value('weight')+' kg'],['Liczba palet',value('pallets')],
        ['Uwagi',value('notes')]];
    const driver = [...common,...driverFields.map((field,index) => [['Kierowca','Telefon','Rejestracja'][index],field.value.trim()])];
    const compose = (intro, fields) => 'Dzień dobry,\n'+intro+'\n\n'+fields.filter(([,text])=>text).map(([label,text])=>label+': '+text).join('\n');
    carrierPreview.value = compose('Proszę o odbiór i dostarczenie przesyłki.',shipment);
    factoryPreview.value = compose('Przekazuję dane kierowcy do odbioru przesyłki.',driver);
    const contact = carrierContacts[carrier.value];
    const mailto = (address,body) => 'mailto:'+address.split(',').map(item => encodeURIComponent(item).replace(/%40/g,'@')).join(',')+'?subject='+encodeURIComponent(subject)+'&body='+encodeURIComponent(body);
    const hasRoute = Boolean(value('pickup_address') && value('delivery_address'));
    const phone = /^\d{8,15}$/.test(contact?.phone || '') ? contact.phone : '';
    const body = encodeURIComponent(carrierPreview.value);
    draftLink('carrier-mail', hasRoute && contact?.email ? mailto(contact.email,carrierPreview.value) : '');
    draftLink('carrier-whatsapp', hasRoute && phone ? 'https://wa.me/'+phone+'?text='+body : '');
    const smsSeparator = /iPhone|iPad|iPod/.test(navigator.userAgent) ? '&' : '?';
    draftLink('carrier-sms', hasRoute && phone ? 'sms:+'+phone+smsSeparator+'body='+body : '');
    const channelStatus = document.getElementById('carrier-channel-status');
    channelStatus.textContent = !contact ? 'Wybierz przewoźnika w formularzu.'
        : !hasRoute ? 'Uzupełnij adres odbioru i dostawy.'
        : !phone && !contact.email ? 'Uzupełnij kontakt przewoźnika w kartotece.'
        : !phone ? 'WhatsApp i SMS wymagają numeru telefonu przewoźnika.'
        : !contact.email ? 'E-mail wymaga adresu przewoźnika w kartotece.' : '';
    const recipients = Array.from(document.querySelectorAll('#factory-emails input:checked'), input => input.value);
    if (email.value.trim() && email.validity.valid) recipients.push(...email.value.split(',').map(address => address.trim()));
    draftLink('factory-mail', recipients.length && email.validity.valid ? mailto([...new Set(recipients)].join(','),factoryPreview.value) : '');
}
orderForm.addEventListener('input', updatePreviews);
orderForm.addEventListener('change', updatePreviews);
for (const field of [...driverFields,email]) field.addEventListener('input', updatePreviews);
updatePreviews();
let ocrLibrary;
function bindTransportPhoto(prefix, rawText, photoStatus, fill) {
    const photo = document.getElementById(prefix+'-photo');
    async function readPhoto(file) {
        if (!file || !['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 12000000) {
            photoStatus.textContent = 'Wybierz zdjęcie PNG, JPG lub WebP do 12 MB.';
            return;
        }
        photo.disabled = true;
        photoStatus.textContent = 'Odczytuję zdjęcie…';
        let worker;
        try {
            ocrLibrary ??= new Promise((resolve,reject) => {
                const script = document.createElement('script');
                script.src = '/app/transport-ocr/tesseract.min.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.append(script);
            });
            await ocrLibrary;
            const base = new URL('/app/transport-ocr/',location.origin).href;
            worker = await Tesseract.createWorker(['pol','eng'],1,{
                workerPath:base+'worker.min.js',corePath:base,langPath:base,workerBlobURL:false
            });
            const {data} = await worker.recognize(file);
            rawText.value = data.text.trim().slice(0,12000);
            fill(rawText.value.trim(),photoStatus);
        } catch {
            ocrLibrary = null;
            photoStatus.textContent = 'Odczyt zdjęcia się nie powiódł. Spróbuj ponownie lub wklej tekst.';
        } finally {
            photo.disabled = false;
            photo.value = '';
            if (worker) await worker.terminate();
        }
    }

    photo.addEventListener('change', () => readPhoto(photo.files[0]));
    rawText.addEventListener('paste', event => {
        const image = Array.from(event.clipboardData.files).find(file => file.type.startsWith('image/'));
        if (!image) { requestAnimationFrame(() => fill(rawText.value.trim(),photoStatus)); return; }
        event.preventDefault();
        if (!photo.disabled) readPhoto(image);
    });
}
    function fillFactoryFields(raw, photoStatus) {
        if (!raw) {
            photoStatus.textContent = 'Dodaj zdjęcie lub wklej tekst.';
            return;
        }
        const emails = [...new Set((raw.match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/gi) || [])
            .map(email => email.toLowerCase()))].join(',');
        const lines = raw.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
        const addressLabel = /^(?:adres(?: odbioru| załadunku)?|punkt odbioru|pickup address|indirizzo)\s*:\s*/i;
        const street = /(?:^|\s)(?:ul\.|al\.|via\s|viale\s|strada\s|calle\s)/i;
        const postal = /\b(?:\d{2}-\d{3}|\d{5})\b/;
        const labelIndex = lines.findIndex(line => addressLabel.test(line));
        const postalIndex = lines.findIndex(line => postal.test(line));
        const streetIndex = lines.findIndex(line => street.test(line));
        const start = labelIndex >= 0 ? labelIndex : streetIndex >= 0 ? streetIndex : postalIndex > 0 && /\d/.test(lines[postalIndex-1]) ? postalIndex-1 : postalIndex;
        const end = start >= 0 && postalIndex >= start && postalIndex <= start+2 ? postalIndex : start;
        const address = start < 0 ? '' : lines.slice(start,end+1).map(line => line.replace(addressLabel,'').trim())
            .filter(line => line && !line.includes('@')).join(', ');
        const nameField = document.getElementById('directory-factory');
        const brand = !nameField.value.trim() && [...directory.values()].find(item => normalize(raw).includes(normalize(item.name)));
        if (brand) {
            country.value = brand.country;
            country.dispatchEvent(new Event('change'));
            nameField.value = brand.name;
        }
        if (address && address.length <= 300) document.getElementById('directory-address').value = address;
        if (emails && emails.length <= 254) document.getElementById('directory-emails').value = emails;
        photoStatus.textContent = 'Sprawdź uzupełnione pola i dopisz brakujące dane przed zapisaniem.';
    }


function fillCarrierFields(raw, status) {
    if (!raw) { status.textContent = 'Dodaj zdjęcie lub wklej tekst.'; return; }
    const lines = raw.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
    const email = raw.match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i)?.[0];
    const phoneLine = lines.find(line => /^(?:telefon|tel\.?|kom\.?|whatsapp)\s*[:+0-9]/i.test(line));
    const phone = (phoneLine || lines.find(line => /^\+\d[\d ().-]{7,24}$/.test(line)) || '').match(/\+?\d[\d ()-]{7,22}\d/)?.[0]?.replace(/[^0-9+]/g,'');
    const name = lines.find(line => /^(?:firma|nazwa firmy|przewoźnik)\s*:/i.test(line))?.replace(/^[^:]+:\s*/,'')
        || lines.find(line => /\p{L}/u.test(line) && !/[@:]|^(?:ul\.|adres|tel|fax|nip|regon|www)/i.test(line));
    if (name && name.length <= 150) document.getElementById('carrier-name').value = name;
    if (email && email.length <= 254) document.getElementById('carrier-email').value = email;
    if (phone && /^\+\d{8,15}$/.test(phone)) document.getElementById('carrier-phone').value = phone;
    else if (phone && /^\d{9}$/.test(phone)) document.getElementById('carrier-phone').value = '+48'+phone;
    status.textContent = 'Sprawdź nazwę, e-mail i telefon przed zapisaniem.';
}
if (country) {
    for (const [prefix,fill] of [['factory',fillFactoryFields],['carrier',fillCarrierFields]]) {
        const input = document.getElementById(prefix+'-photo-text');
        const status = document.getElementById(prefix+'-photo-status');
        bindTransportPhoto(prefix,input,status,fill);
        document.getElementById('fill-'+prefix+'-fields').addEventListener('click',() => fill(input.value.trim(),status));
    }
}
bindTransportPhoto('pickup',paste,message,splitPickup);

const cutting = document.getElementById('cutting');
const cuttingRows = document.getElementById('cutting-rows');
const cuttingKey = 'jurko-cutting-v1';
const cuttingMoney = new Intl.NumberFormat('pl-PL',{style:'currency',currency:'PLN'});
const cuttingNumber = new Intl.NumberFormat('pl-PL',{maximumFractionDigits:3});
const cuttingData = row => Object.fromEntries([...row.querySelectorAll('[data-field]')].map(input => [input.dataset.field,input.value]));
function calculateCutting(data) {
    const number = key => {
        const raw = (data[key] ?? '').trim().replace(',', '.');
        return raw === '' ? 0 : /^\d+(?:\.\d+)?$/.test(raw) && Number(raw) <= 1e8 ? Number(raw) : NaN;
    };
    const qty = number('qty'), tl = number('tileL'), tw = number('tileW');
    const pl = number('partL'), pw = number('partW'), override = number('yield');
    const prices = ['material','finish','client','cutLength','cutRate','markup'].map(number);
    if ([qty,tl,tw,pl,pw,override,...prices].some(Number.isNaN) || !Number.isInteger(override)) return null;
    if (!qty || !tl || !tw || !pl || !pw) return null;
    const fit = Math.max(Math.floor(tl/pl)*Math.floor(tw/pw),Math.floor(tl/pw)*Math.floor(tw/pl));
    if (!fit) return null;
    const perTile = override || fit;
    const ceil = value => Math.ceil(value-1e-9);
    const pieces = ceil(qty / (data.unit === 'm²' ? pl*pw/10000 : data.unit === 'mb' ? pl/100 : 1));
    const tiles = ceil(pieces/perTile);
    const area = tiles*tl*tw/10000;
    const [materialRate,finishRate,clientRate,cutLength,cutRate,markup] = prices;
    const material = area*materialRate;
    const cut = tiles*cutLength*cutRate;
    const cost = material + pieces*finishRate + cut;
    const sale = material + pieces*(data.client.trim() ? clientRate : finishRate*(1+markup/100)) + cut*(1+markup/100);
    return {pieces,tiles,area,perTile,cost,sale,cutMeters:tiles*cutLength};
}
function updateCutting() {
    const data = [...cuttingRows.rows].map(cuttingData);
    let cost=0, sale=0, incomplete=0;
    data.forEach((item,index) => {
        const result = calculateCutting(item);
        const output = cuttingRows.rows[index].querySelector('output');
        if (!result) {
            incomplete++;
            output.textContent = 'Sprawdź ilość i wymiary';
            return;
        }
        cost += result.cost;
        sale += result.sale;
        output.textContent = [
            result.pieces+' szt. · '+result.tiles+' płytek · '+result.perTile+' szt./płytkę',
            cuttingNumber.format(result.area)+' m² materiału · '+cuttingNumber.format(result.cutMeters)+' mb rozkroju',
            'Koszt: '+cuttingMoney.format(result.cost),
            'Klient: '+cuttingMoney.format(result.sale)
        ].join('\n');
    });
    document.getElementById('cutting-total').textContent = 'Koszt netto '+cuttingMoney.format(cost)+' · Dla klienta netto '+cuttingMoney.format(sale)+(incomplete ? ' · Pominięte pozycje: '+incomplete : '');
    try { localStorage.setItem(cuttingKey,JSON.stringify(data)); } catch {}
}
function addCuttingRow(values = {}) {
    const row = document.getElementById('cutting-row').content.firstElementChild.cloneNode(true);
    for (const input of row.querySelectorAll('[data-field]')) {
        if (values[input.dataset.field] !== undefined) input.value = values[input.dataset.field];
    }
    cuttingRows.append(row);
}
const cuttingExamples = [
    {name:'Stopnica 30 × 60',tileL:'60',tileW:'60',partL:'60',partW:'30',finish:'6,50',cutLength:'0,60',cutRate:'1,50'},
    {name:'Stopnica 30 × 120',tileL:'120',tileW:'60',partL:'120',partW:'30',finish:'12',cutLength:'1,20',cutRate:'2'},
    {name:'Cokół 8 × 60',tileL:'60',tileW:'60',partL:'60',partW:'8',finish:'5,50'},
    {name:'Cokół 8 × 120',tileL:'120',tileW:'60',partL:'120',partW:'8',finish:'5,50'}
];
document.querySelector('.cutting-tab').addEventListener('click',() => { cutting.open = true; });
if (location.hash === '#cutting') cutting.open = true;
document.getElementById('cutting-add').addEventListener('click',() => { addCuttingRow(); updateCutting(); });
document.getElementById('cutting-example').addEventListener('click',() => {
    for (const example of cuttingExamples) addCuttingRow({qty:'50',unit:'m²',material:'150',markup:'13',...example});
    updateCutting();
});
cuttingRows.addEventListener('input',updateCutting);
cuttingRows.addEventListener('click',event => {
    if (!event.target.closest('[data-remove]')) return;
    event.target.closest('tr').remove();
    updateCutting();
});
try {
    const saved = JSON.parse(localStorage.getItem(cuttingKey) || '[]');
    if (Array.isArray(saved)) for (const item of saved) addCuttingRow(item);
} catch {}
updateCutting();

</script>
</body>
</html>
