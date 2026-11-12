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

function transportDate(string $key): string
{
    $value = field($key, 10, true);
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
                    $kind = choice('kind', ['factory','carrier']);
                    $name = field('name', 150, true);
                    $country = $kind === 'factory' ? choice('country', array_keys(FACTORIES)) : '';
                    $address = $kind === 'factory' ? field('address', 300, true) : '';
                    $email = $kind === 'carrier' ? field('email', 254) : '';
                    $phone = $kind === 'carrier' ? preg_replace('/[ +()-]/', '', field('phone', 30)) : '';
                    if (($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) ||
                        ($phone !== '' && !preg_match('/^[1-9][0-9]{7,14}$/D', $phone)) ||
                        ($kind === 'carrier' && $email === '' && $phone === '')) {
                        throw new InvalidArgumentException('Podaj poprawny e-mail lub numer WhatsApp z kodem kraju.');
                    }
                    $query = $db->prepare('INSERT INTO jm_transport_directory (kind,name,country,address,email,phone) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email=?,phone=?');
                    $query->execute([$kind,$name,$country,$address,$email,$phone,$email,$phone]);
                    break;
                case 'create':
                    $factory = numberField('factory', 10, true, true);
                    $carrier = numberField('carrier', 10, true, true);
                    $query = $db->prepare("SELECT COUNT(*) FROM jm_transport_directory WHERE (id=? AND kind='factory') OR (id=? AND kind='carrier')");
                    $query->execute([$factory,$carrier]);
                    if ((int)$query->fetchColumn() !== 2) {
                        throw new InvalidArgumentException('Wybierz fabrykę i przewoźnika z kartoteki.');
                    }
                    $pickup = transportDate('pickup');
                    $delivery = transportDate('delivery');
                    $request = field('request', 32, true);
                    if ($delivery < $pickup || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
                        throw new InvalidArgumentException('Sprawdź datę dostawy i odśwież formularz.');
                    }
                    $query = $db->prepare('INSERT INTO jm_transport_orders (account_id,factory_id,carrier_id,company,reference,delivery_address,pickup_date,delivery_date,pallets,weight_kg,agreed_net,currency,notes,request_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
                    $query->execute([$actor,$factory,$carrier,choice('company',array_keys(COMPANIES)),field('reference',150,true),
                        field('delivery_address',300,true),$pickup,$delivery,numberField('pallets',6,true,true),
                        numberField('weight',8,true),numberField('agreed',10),choice('currency',['PLN','EUR']),field('notes',6000),$request]);
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
                    break;
                default:
                    throw new InvalidArgumentException('Nieznana operacja.');
            }
            $_SESSION['transport_saved'] = true;
            header('Location: /transport.php'.($embedded ? '?panel=1' : ''), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
    $saved = !empty($_SESSION['transport_saved']);
    unset($_SESSION['transport_saved']);
    $employees = $owner ? $db->query("SELECT email,active FROM jm_accounts WHERE role='employee' ORDER BY email")->fetchAll() : [];
    $directory = $db->query('SELECT * FROM jm_transport_directory ORDER BY country,name,address')->fetchAll();
    $factories = array_filter($directory, static fn(array $row): bool => $row['kind'] === 'factory');
    $carriers = array_filter($directory, static fn(array $row): bool => $row['kind'] === 'carrier');
    $sql = 'SELECT t.id,t.company,t.reference,t.delivery_address,t.pickup_date,t.delivery_date,
        t.pallets,t.weight_kg,t.agreed_net,t.actual_net,t.currency,t.notes,t.status,t.created_at,
        t.goods_invoice,t.carrier_invoice,t.invoice_pdf IS NOT NULL AS has_pdf,
        f.name AS factory, f.address AS pickup_address, c.name AS carrier,
        c.email AS carrier_email, c.phone AS carrier_phone, a.email AS author
        FROM jm_transport_orders t JOIN jm_transport_directory f ON f.id=t.factory_id
        JOIN jm_transport_directory c ON c.id=t.carrier_id JOIN jm_accounts a ON a.id=t.account_id';
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
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; form-action 'self'; frame-ancestors $frameAncestors; base-uri 'none'");
$csrf = e($_SESSION['csrf']);
$createValues = ($_POST['action'] ?? '') === 'create' ? $_POST : [];
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
*{box-sizing:border-box}body{margin:0;background:#f2f5f3;color:#182720;font:15px/1.5 system-ui,sans-serif}
header{background:#142a22;color:white}nav,main{max-width:1220px;margin:auto;padding:18px 24px}
nav{display:flex;align-items:center;justify-content:space-between;gap:16px}nav a{color:#d7eee0}
h1{font-size:clamp(30px,5vw,46px);letter-spacing:-.04em;margin:16px 0}h2{margin:0 0 12px}
section,main>details{background:white;border:1px solid #d8e1dc;border-radius:14px;padding:20px;margin:0 0 16px}
summary{cursor:pointer;font-weight:700}details[open]>summary{margin-bottom:15px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:14px}
label{display:grid;gap:4px;font-weight:600}input,select,textarea,button{font:inherit;padding:8px;border:1px solid #a7b7ad;border-radius:7px;min-width:0}
textarea{width:100%;resize:vertical}button,.button{display:inline-block;border:0;background:#19533e;color:white;padding:9px 14px;border-radius:7px;font-weight:700;cursor:pointer;text-decoration:none}
.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}.table{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e9e5;text-align:left;vertical-align:top}td details{min-width:200px}td form label{margin:6px 0}
.notice{padding:12px;background:#e1f1e8;border-radius:8px}.error{background:#fce4e1}.muted,small{color:#4b6155}
.check{display:flex;align-items:center;gap:8px}nav form{margin:0}
.transport-layout{display:grid;grid-template-columns:190px minmax(0,1fr);gap:16px;align-items:start}
.transport-content{min-width:0}.transport-content>details{background:white;border:1px solid #d8e1dc;border-radius:14px;padding:20px;margin:0 0 16px}
.factory-menu{position:sticky;top:12px;background:white;border:1px solid #d8e1dc;border-radius:14px;padding:14px}
.factory-menu h2{font-size:18px}.factory-group{display:grid;gap:5px;margin-bottom:16px}
.factory-menu button{width:100%;text-align:left;background:#edf4ef;color:#19533e;overflow-wrap:anywhere}
.factory-menu button:hover,.factory-menu button[aria-pressed=true]{background:#19533e;color:white}
#factory-results{display:grid;gap:5px;margin-top:8px}#factory-feedback{overflow-wrap:anywhere}
@media(max-width:740px){.transport-layout{grid-template-columns:minmax(0,1fr)}.factory-menu{position:static}}
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
<aside class="factory-menu" aria-label="Fabryki">
<h2>Fabryki</h2>
<div id="factory-shortcuts"></div>
<label>Szukaj fabryki<input id="factory-search" type="search" placeholder="Nazwa lub adres"></label>
<div id="factory-results"></div>
<p id="factory-feedback" class="muted" role="status"></p>
</aside>
<div class="transport-content">
<h1>Transport zewnętrzny</h1>
<?php if ($saved): ?><p class="notice" role="status">Zapisano zmiany.</p><?php endif ?>
<?php if ($error !== ''): ?><p class="notice error" role="alert"><?=e($error)?></p><?php endif ?>
<?php if ($owner): ?>
<details>
<summary>Dostęp pracowników</summary>
<p>Dodaj adres Gmail lub Google Workspace pracownika. Po zalogowaniu pracownik zobaczy swoje zlecenia.</p>
<form method="post" class="actions">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="access">
<label>E-mail konta Google<input name="email" type="email" maxlength="254" required></label>
<button name="access" value="enable">Włącz dostęp</button><button name="access" value="disable">Wyłącz dostęp</button>
</form>
<?php if ($employees): ?><ul><?php foreach ($employees as $employee): ?><li><?=e($employee['email'])?> · <?=$employee['active']?'Aktywny':'Wyłączony'?></li><?php endforeach ?></ul><?php endif ?>
</details>
<details <?=(!$factories || !$carriers) ? 'open' : ''?>>
<summary>Kartoteka fabryk i przewoźników</summary>
<div class="grid">
<form method="post">
<h2>Punkt odbioru</h2>
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="directory"><input type="hidden" name="kind" value="factory">
<label>Kraj<select name="country" required id="factory-country"><?php foreach (FACTORIES as $country => $names): ?><option><?=e($country)?></option><?php endforeach ?></select></label>
<label>Fabryka<input id="directory-factory" name="name" list="factory-names" maxlength="150" required></label><datalist id="factory-names"></datalist>
<label>Adres odbioru<input id="directory-address" name="address" maxlength="300" required></label>
<p><button>Dodaj punkt odbioru</button></p>
</form>
<form method="post">
<h2>Przewoźnik</h2>
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="directory"><input type="hidden" name="kind" value="carrier">
<label>Nazwa firmy<input name="name" maxlength="150" required></label>
<label>E-mail<input name="email" type="email" maxlength="254"></label>
<label>WhatsApp z kodem kraju<input name="phone" type="tel" maxlength="30" placeholder="+48"></label>
<p><button>Zapisz przewoźnika</button></p>
</form>
</div>
<div class="table"><table><thead><tr><th>Rodzaj</th><th>Nazwa</th><th>Adres lub kontakt</th></tr></thead><tbody>
<?php foreach ($directory as $item): ?>
<tr><td><?=$item['kind']==='factory'?'Fabryka':'Przewoźnik'?></td><td><?=e($item['name'])?></td><td><?=e(implode(' · ',array_filter([$item['country'],$item['address'],$item['email'],$item['phone']])))?></td></tr>
<?php endforeach ?>
</tbody></table></div>
</details>
<?php endif ?>
<section id="new-order">
<h2>Nowe zlecenie</h2>
<?php if (!$factories || !$carriers): ?><p>Do wystawienia zlecenia potrzebny jest punkt odbioru i przewoźnik w kartotece.</p><?php else: ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create">
<input type="hidden" name="request" value="<?=e(bin2hex(random_bytes(16)))?>">
<div class="grid">
<label>Spółka<select name="company"><?php foreach (COMPANIES as $id => $name): ?><option value="<?=e($id)?>" <?=previous('company')===$id?'selected':''?>><?=e($name)?></option><?php endforeach ?></select></label>
<label>Fabryka i punkt odbioru<select name="factory" required id="order-factory"><option value="">Wybierz punkt odbioru</option><?php foreach (array_keys(FACTORIES) as $country): ?><optgroup label="<?=e($country)?>"><?php foreach ($factories as $item): if ($item['country'] !== $country) continue; ?><option value="<?=e($item['id'])?>" data-address="<?=e($item['address'])?>" <?=previous('factory')===(string)$item['id']?'selected':''?>><?=e($item['name'].' · '.$item['address'])?></option><?php endforeach ?></optgroup><?php endforeach ?></select></label>
<label>Adres odbioru<input id="order-address" readonly></label>
<label>Przewoźnik<select name="carrier" required id="order-carrier"><option value="">Wybierz przewoźnika</option><?php foreach ($carriers as $item): ?><option value="<?=e($item['id'])?>" <?=previous('carrier')===(string)$item['id']?'selected':''?>><?=e($item['name'])?></option><?php endforeach ?></select></label>
<label>Numer zamówienia lub referencja odbioru<input name="reference" maxlength="150" value="<?=previous('reference')?>" required></label>
<label>Liczba palet<input type="number" name="pallets" min="1" max="999999" value="<?=previous('pallets')?>" required></label>
<label>Waga brutto (kg)<input name="weight" inputmode="decimal" value="<?=previous('weight')?>" required></label>
<label>Data odbioru<input type="date" name="pickup" value="<?=previous('pickup')?>" required></label>
<label>Data dostawy<input type="date" name="delivery" value="<?=previous('delivery')?>" required></label>
<label>Adres dostawy<input name="delivery_address" maxlength="300" value="<?=previous('delivery_address')?>" required></label>
<label>Uzgodniona cena netto<input name="agreed" inputmode="decimal" value="<?=previous('agreed')?>"></label>
<label>Waluta<select name="currency"><option>PLN</option><option <?=previous('currency')==='EUR'?'selected':''?>>EUR</option></select></label>
</div>
<p><label>Uwagi lub wklejone dane z fabryki<textarea name="notes" rows="3" maxlength="6000"><?=previous('notes')?></textarea></label></p>
<button>Zapisz zlecenie</button>
</form>
<?php endif ?>
</section>
<section>
<h2>Zlecenia transportowe</h2>
<form method="get" class="actions">
<?php if ($embedded): ?><input type="hidden" name="panel" value="1"><?php endif ?>
<label>Szukaj zlecenia, zamówienia lub faktury<input name="q" value="<?=e($search)?>" maxlength="150"></label>
<label class="check"><input type="checkbox" name="unbilled" value="1" <?=($_GET['unbilled']??'')==='1'?'checked':''?>> Dostarczone bez faktury</label>
<button>Filtruj</button>
</form>
<?php if (!$orders): ?><p class="muted">Brak zleceń do wyświetlenia.</p><?php else: ?>
<div class="table"><table><thead><tr><th>Zlecenie</th><th>Fabryka i przewoźnik</th><th>Odbiór / dostawa</th><th>Koszt netto</th><th>Status i faktury</th><th>Wiadomość</th></tr></thead><tbody>
<?php foreach ($orders as $order):
    $number = transportNumber($order);
    $message = "Dzień dobry,\nproszę o odbiór towaru.\n\nZlecenie: $number\nSpółka: ".COMPANIES[$order['company']].
        "\nFabryka: ".$order['factory']."\nAdres odbioru: ".$order['pickup_address']."\nData odbioru: ".$order['pickup_date'].
        "\nAdres dostawy: ".$order['delivery_address']."\nData dostawy: ".$order['delivery_date'].
        "\nReferencja: ".$order['reference']."\nPalety: ".$order['pallets']."\nWaga brutto: ".$order['weight_kg']." kg".
        ($order['agreed_net'] !== null ? "\nUzgodniona stawka netto: ".$order['agreed_net'].' '.$order['currency'] : '').
        "\n\nProszę o potwierdzenie zlecenia i podanie numeru $number na fakturze.";
?>
<tr>
<td><strong><?=e($number)?></strong><br><?=e($order['reference'])?><br><small><?=e(COMPANIES[$order['company']])?><br><?=e($order['author'])?></small></td>
<td><?=e($order['factory'])?><br><small><?=e($order['pickup_address'])?></small><br><?=e($order['carrier'])?></td>
<td><?=e($order['pickup_date'])?><br><?=e($order['delivery_date'])?><br><small><?=e($order['delivery_address'])?><br><?=e($order['pallets'])?> pal. · <?=e($order['weight_kg'])?> kg</small><?php if ($order['notes'] !== ''): ?><details><summary>Uwagi</summary><?=nl2br(e($order['notes']))?></details><?php endif ?></td>
<td>Uzgodniony <?=e($order['agreed_net'] === null ? '—' : $order['agreed_net'].' '.$order['currency'])?><br>Rozliczony <?=e($order['actual_net'] === null ? '—' : $order['actual_net'].' '.$order['currency'])?></td>
<td><details><summary><?=e(STATUSES[$order['status']])?><?php if ($order['carrier_invoice'] !== ''): ?><br><small><?=e($order['carrier_invoice'])?></small><?php endif ?></summary>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=e($order['id'])?>">
<label>Status<select name="status"><?php foreach (STATUSES as $id => $name): ?><option value="<?=e($id)?>" <?=$order['status']===$id?'selected':''?>><?=e($name)?></option><?php endforeach ?></select></label>
<label>Faktura zakupu towaru<input name="goods_invoice" maxlength="150" value="<?=e($order['goods_invoice'])?>"></label>
<?php if ($owner): ?><label>Faktura przewoźnika<input name="carrier_invoice" maxlength="150" value="<?=e($order['carrier_invoice'])?>"></label><label>Koszt kursu netto (<?=e($order['currency'])?>)<input name="actual" inputmode="decimal" value="<?=e($order['actual_net'])?>"></label>
<label>PDF faktury (do 1,5 MB)<input type="file" name="invoice_pdf" accept="application/pdf"></label>
<?php if ($order['has_pdf']): ?><p><a href="/transport.php?invoice=<?=e($order['id'])?>">Pobierz fakturę PDF</a></p><?php endif ?>
<?php endif ?>
<button>Zapisz</button></form></details></td>
<td>
<?php if ($order['carrier_email'] !== ''): ?><a href="<?=e('mailto:'.str_replace('%40','@',rawurlencode($order['carrier_email'])).'?subject='.rawurlencode('Zlecenie transportu '.$number).'&body='.rawurlencode($message))?>">E-mail</a><br><?php endif ?>
<?php if ($order['carrier_phone'] !== ''): ?><a href="<?=e('https://wa.me/'.$order['carrier_phone'].'?text='.rawurlencode($message))?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif ?>
</td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="muted">Po wysłaniu wiadomości ustaw status „Wysłane”.</p>
<?php endif ?>
</section>
</div>
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
if (factory && carrier) {
    const sortCarriers = () => {
        const selected = carrier.value;
        const counts = new Map(usage.filter(row => String(row.factory_id) === factory.value)
            .map(row => [String(row.carrier_id), Number(row.uses)]));
        const options = Array.from(carrier.options).slice(1);
        options.sort((a,b) => (counts.get(b.value) || 0) - (counts.get(a.value) || 0)
            || a.text.localeCompare(b.text,'pl'));
        carrier.replaceChildren(carrier.options[0], ...options);
        carrier.value = selected;
    };
    factory.addEventListener('change', sortCarriers);
    sortCarriers();
}
const shortcuts = document.getElementById('factory-shortcuts');
const search = document.getElementById('factory-search');
const results = document.getElementById('factory-results');
const feedback = document.getElementById('factory-feedback');
const orderAddress = document.getElementById('order-address');
const directoryName = document.getElementById('directory-factory');
const directoryAddress = document.getElementById('directory-address');
const points = <?=json_encode(array_values(array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id','name','country','address'])), $factories)), JSON_HEX_TAG | JSON_THROW_ON_ERROR)?>;
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
function updateAddress() {
    if (orderAddress) orderAddress.value = factory.selectedOptions[0]?.dataset.address || '';
}
factory?.addEventListener('change', updateAddress);
updateAddress();
function selectPoint(point) {
    if (factory) {
        factory.value = String(point.id);
        factory.dispatchEvent(new Event('change'));
        document.getElementById('new-order').scrollIntoView({block:'start'});
    }
    if (directoryName) {
        country.value = point.country;
        country.dispatchEvent(new Event('change'));
        directoryName.value = point.name;
        directoryAddress.value = point.address;
    }
    feedback.textContent = point.address;
}
function addButton(container, label, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', action);
    container.append(button);
}
function selectBrand(brand) {
    results.replaceChildren();
    if (brand.points.length === 1) {
        selectPoint(brand.points[0]);
    } else if (brand.points.length > 1) {
        feedback.textContent = 'Wybierz punkt odbioru dla ' + brand.name + '.';
        for (const point of brand.points) addButton(results, point.address, () => selectPoint(point));
    } else {
        if (factory) {
            factory.value = '';
            factory.dispatchEvent(new Event('change'));
        }
        if (directoryName) {
            country.value = brand.country;
            country.dispatchEvent(new Event('change'));
            directoryName.value = brand.name;
            directoryAddress.value = '';
            directoryName.closest('details').open = true;
            directoryAddress.focus();
        }
        feedback.textContent = 'Brak zapisanego adresu odbioru dla ' + brand.name + '.';
    }
}
for (const [, brands] of groups) {
    const group = document.createElement('div');
    group.className = 'factory-group';
    shortcuts.append(group);
    for (const brand of brands) addButton(group, brand, () => selectBrand(directory.get(normalize(brand))));
}
search.addEventListener('input', () => {
    results.replaceChildren();
    feedback.textContent = '';
    const query = normalize(search.value);
    if (!query) return;
    let found = false;
    for (const [key, brand] of directory) {
        if (key.includes(query) || brand.points.some(point => normalize(point.address).includes(query))) {
            addButton(results, brand.name, () => selectBrand(brand));
            found = true;
        }
    }
    if (!found) feedback.textContent = 'Brak pasujących fabryk.';
});
</script>
</body>
</html>
