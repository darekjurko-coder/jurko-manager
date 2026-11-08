<?php
declare(strict_types=1);
require __DIR__ . '/../server/auth-bootstrap.php';
require_once __DIR__ . '/../server/database.php';
require_once __DIR__ . '/../server/finance.php';
try {
    $config = jurkoAuthConfig(); $user = jurkoCurrentUser($config);
    if (!$user) { header('Location: /auth/', true, 303); exit; }
    $pdo = jurkoDatabase(); $actor = jurkoFinanceAccount($pdo, $user, $config);
} catch (DomainException $e) { http_response_code(403); exit('To konto nie ma dostępu do finansów.'); }
catch (Throwable $e) { http_response_code(503); exit('Panel jest chwilowo niedostępny. Dane nie zostały zmienione.'); }
$error = '';
$values = ['company'=>'zoo','entry_type'=>'cost','entry_date'=>date('Y-m-d'),'description'=>'','cost_code'=>'','amount_net'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])
        || !is_string($_POST['request_key'] ?? null) || !isset($_SESSION['entry_requests'][$_POST['request_key']])) {
        http_response_code(403); exit('Formularz wygasł lub został już wysłany. Odśwież stronę.');
    }
    try {
        $entry = jurkoValidateEntry($_POST); jurkoSaveEntry($pdo, $actor, $entry);
        unset($_SESSION['entry_requests'][$_POST['request_key']]); $_SESSION['entry_saved'] = true;
        header('Location: /app/', true, 303); exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        foreach ($values as $key=>$default) { $values[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : $default; }
    } catch (Throwable $e) { $error = 'Nie zapisano pozycji. Spróbuj ponownie za chwilę.'; }
}
$saved = $_SESSION['entry_saved'] ?? false; unset($_SESSION['entry_saved']);
$key = bin2hex(random_bytes(24)); $_SESSION['entry_requests'][$key] = time();
$_SESSION['entry_requests'] = array_slice($_SESSION['entry_requests'], -20, null, true);
$company = is_string($_GET['company'] ?? null) && in_array($_GET['company'], ['zoo','spk'],true) ? $_GET['company'] : '';
$month = is_string($_GET['month'] ?? null) && preg_match('/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D',$_GET['month']) ? $_GET['month'] : date('Y-m');
$start = $month . '-01'; $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
$where = 'entry_date >= ? AND entry_date < ?'; $args = [$start,$end];
if ($company !== '') { $where .= ' AND company = ?'; $args[] = $company; }
try {
    $q = $pdo->prepare('SELECT company,entry_type,entry_date,description,cost_code,amount_net FROM jm_financial_entries WHERE ' . $where . ' ORDER BY entry_date DESC,id DESC LIMIT 500');
    $q->execute($args); $rows = $q->fetchAll();
    $q = $pdo->prepare('SELECT entry_type, SUM(amount_net) AS total FROM jm_financial_entries WHERE ' . $where . ' GROUP BY entry_type');
    $q->execute($args); $totals = $q->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { http_response_code(503); exit('Nie można odczytać zapisów. Spróbuj ponownie później.'); }
$companies = ['zoo'=>'JURKO sp. z o.o.','spk'=>'JURKO sp. z o.o. sp.k.'];
function amount(string $v): string { [$whole,$fraction] = explode('.', $v . '.00'); return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) . ',' . str_pad($fraction,2,'0') . ' zł'; }
?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finanse — JURKO MANAGER</title><link rel="stylesheet" href="/app/style.css"></head><body><main>
<header><div><p class="eyebrow">JURKO MANAGER</p><h1>Finanse firmy</h1><p>Zapis w bazie MySQL • dostęp właściciela</p></div><form method="post" action="/auth/index.php?action=logout"><input type="hidden" name="csrf" value="<?= jurkoEscape($_SESSION['csrf']) ?>"><button class="secondary">Wyloguj</button></form></header>
<?php if ($saved): ?><p class="success" role="status">Pozycja została zapisana w bazie.</p><?php endif ?>
<?php if ($error): ?><p class="error" role="alert"><?= jurkoEscape($error) ?></p><?php endif ?>
<section><h2>Dodaj koszt lub przychód</h2><form method="post"><input type="hidden" name="csrf" value="<?= jurkoEscape($_SESSION['csrf']) ?>"><input type="hidden" name="request_key" value="<?= $key ?>"><div class="grid">
<label>Spółka<select name="company"><?php foreach ($companies as $c=>$label): ?><option value="<?= $c ?>" <?= $values['company']===$c?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label>
<label>Rodzaj<select name="entry_type"><option value="cost" <?= $values['entry_type']==='cost'?'selected':'' ?>>Koszt</option><option value="revenue" <?= $values['entry_type']==='revenue'?'selected':'' ?>>Przychód</option></select></label>
<label>Data<input type="date" name="entry_date" min="2000-01-01" max="2100-12-31" required value="<?= jurkoEscape($values['entry_date']) ?>"></label>
<label>Kwota netto (zł)<input name="amount_net" inputmode="decimal" required placeholder="np. 1250,50" value="<?= jurkoEscape($values['amount_net']) ?>"></label>
<label class="wide">Opis<input name="description" maxlength="200" required placeholder="np. Faktura za transport" value="<?= jurkoEscape($values['description']) ?>"></label>
<label>Kod kosztu (opcjonalnie)<input name="cost_code" maxlength="8" placeholder="np. 100" value="<?= jurkoEscape($values['cost_code']) ?>"></label></div><button>Zapisz w bazie</button></form></section>
<section><h2>Zapisane pozycje</h2><form method="get" class="filters"><label>Spółka<select name="company"><option value="">Obie spółki</option><?php foreach ($companies as $c=>$label): ?><option value="<?= $c ?>" <?= $company===$c?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label><label>Miesiąc<input type="month" name="month" value="<?= $month ?>" required></label><button>Pokaż</button></form>
<div class="totals"><p>Przychody netto<strong><?= amount((string)($totals['revenue'] ?? '0.00')) ?></strong></p><p>Koszty netto<strong><?= amount((string)($totals['cost'] ?? '0.00')) ?></strong></p></div>
<div class="table"><table><thead><tr><th>Data</th><th>Spółka</th><th>Rodzaj</th><th>Opis</th><th>Kod</th><th>Netto</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= jurkoEscape($row['entry_date']) ?></td><td><?= $companies[$row['company']] ?></td><td><?= $row['entry_type']==='cost'?'Koszt':'Przychód' ?></td><td><?= jurkoEscape($row['description']) ?></td><td><?= jurkoEscape($row['cost_code'] ?? '') ?></td><td class="money"><?= amount($row['amount_net']) ?></td></tr><?php endforeach ?><?php if (!$rows): ?><tr><td colspan="6">Brak pozycji w wybranym miesiącu.</td></tr><?php endif ?></tbody></table></div><p class="hint">Lista pokazuje do 500 ostatnich pozycji z miesiąca. Podsumowanie obejmuje wszystkie pozycje. Panel ewidencyjny nie zastępuje programu księgowego.</p></section></main></body></html>
