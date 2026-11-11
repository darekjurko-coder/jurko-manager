<?php
declare(strict_types=1);
require __DIR__.'/../server/auth-bootstrap.php';
require_once __DIR__.'/../server/database.php';
require_once __DIR__.'/../server/finance.php';
require_once __DIR__.'/../server/employee-portal.php';

function input(string $name, int $max): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('Sprawdź długość wpisanych danych.');
    }
    return trim($value);
}
function amount(string $name): string
{
    $value = str_replace(',', '.', input($name, 20));
    if (!preg_match('/^-?[0-9]{1,13}(?:\.[0-9]{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Wpisz prawidłową kwotę.');
    }
    return $value;
}
function rows(PDO $db, string $sql, array $params = []): array
{
    $query = $db->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
}
function e(mixed $value): string { return jurkoEscape((string)$value); }

try {
    $db = jurkoDatabase();
    $session = $_SESSION['user'] ?? [];
    $employee = jurkoStaffAccount($db, $session);
    $admin = ($_GET['admin'] ?? '') === '1';
    $actor = null;
    if ($admin) {
        $config = jurkoAuthConfig();
        $actor = jurkoFinanceAccount($db, jurkoCurrentUser($config) ?? [], $config);
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    $month = $_GET['month'] ?? substr($today, 0, 7);
    if (!is_string($month) || !preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $month)) {
        $month = substr($today, 0, 7);
    }
    $period = $month.'-01';
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, input('csrf', 64))) {
            throw new DomainException('Formularz wygasł.');
        }
        try {
            $action = input('action', 30);
            if (!$admin && !$employee && $action === 'login') {
                $password = $_POST['password'] ?? '';
                if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") || !jurkoStaffSignIn($db, strtolower(input('login', 40)), $password)) {
                    throw new InvalidArgumentException('Logowanie nie powiodło się. Sprawdź dane lub spróbuj ponownie później.');
                }
            } elseif ($admin) {
                $person = input('person', 20);
                $people = rows($db, 'SELECT id,display_name,department FROM jm_employees WHERE id=? AND active=TRUE', [$person]);
                if (!$people) {
                    throw new InvalidArgumentException('Wybierz aktywnego pracownika.');
                }
                $person = $people[0]['id'];
                switch ($action) {
                    case 'account':
                        $login = strtolower(input('login', 40));
                        $email = strtolower(input('email', 254));
                        $password = $_POST['password'] ?? '';
                        if (!preg_match('/^[a-z0-9._-]{2,40}$/D', $login) || !filter_var($email, FILTER_VALIDATE_EMAIL)
                            || !is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
                            throw new InvalidArgumentException('Podaj login, poprawny e-mail i osobne hasło Managera (12–72 bajty).');
                        }
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $db->beginTransaction();
                        try {
                            $matches = rows($db, 'SELECT id,employee_id,email,role FROM jm_accounts WHERE employee_id=? OR email=? FOR UPDATE', [$person,$email]);
                            if (!$matches) {
                                $db->prepare("INSERT INTO jm_accounts(employee_id,email,staff_login,password_hash,role,active) VALUES (?,?,?,?,'employee',TRUE)")->execute([$person,$email,$login,$hash]);
                            } else {
                                $match = $matches[0];
                                if (count($matches) !== 1 || $match['role'] !== 'employee' || ($match['employee_id'] !== null && (string)$match['employee_id'] !== (string)$person)) {
                                    throw new InvalidArgumentException('E-mail jest już przypisany do innego konta.');
                                }
                                $db->prepare('UPDATE jm_accounts SET employee_id=?,email=?,staff_login=?,password_hash=?,active=TRUE,session_version=session_version+1 WHERE id=?')->execute([$person,$email,$login,$hash,$match['id']]);
                            }
                            $db->commit();
                        } catch (Throwable $ex) {
                            $db->rollBack();
                            if ($ex instanceof PDOException && ($ex->errorInfo[1] ?? null) === 1062) {
                                throw new InvalidArgumentException('Login lub e-mail jest już zajęty.');
                            }
                            throw $ex;
                        }
                        break;
                    case 'disable':
                        $db->prepare("UPDATE jm_accounts SET active=FALSE,session_version=session_version+1 WHERE employee_id=? AND role='employee' AND staff_login IS NOT NULL")->execute([$person]);
                        break;
                    case 'sales':
                        if ($people[0]['department'] !== 'sales') {
                            throw new InvalidArgumentException('Wybierz handlowca.');
                        }
                        $projects = input('projects', 9);
                        if (!ctype_digit($projects)) {
                            throw new InvalidArgumentException('Wpisz liczbę projektów.');
                        }
                        $db->prepare('INSERT INTO jm_staff_sales VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE turnover=VALUES(turnover),projects=VALUES(projects)')->execute([$person,$period,amount('turnover'),$projects]);
                        break;
                    case 'receivable':
                        $document = input('document', 100);
                        $customer = input('customer', 150);
                        if ($people[0]['department'] !== 'sales' || $document === '' || $customer === '') {
                            throw new InvalidArgumentException('Wybierz handlowca i uzupełnij klienta oraz dokument.');
                        }
                        $db->prepare('INSERT INTO jm_staff_receivables VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE customer=VALUES(customer),balance=VALUES(balance)')->execute([$person,$document,$customer,amount('balance')]);
                        break;
                    case 'task':
                        $title = input('title', 200);
                        $date = input('due', 10);
                        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                        if ($title === '' || !$parsed || $parsed->format('Y-m-d') !== $date || $date < '2000-01-01') {
                            throw new InvalidArgumentException('Uzupełnij zadanie i termin.');
                        }
                        $db->prepare("INSERT INTO jm_tasks(company,title,assignee_id,due_date,created_by,owner_label) VALUES ('spk',?,?,?,?,?)")->execute([$title,$person,$date,$actor,mb_substr($people[0]['display_name'],0,80)]);
                        break;
                    case 'repeat':
                        $title = input('title', 200);
                        $day = input('day', 2);
                        if ($title === '' || !in_array($day, ['15','28'], true)) {
                            throw new InvalidArgumentException('Uzupełnij zadanie i wybierz dzień.');
                        }
                        $db->prepare('INSERT INTO jm_staff_task_rules(employee_id,title,month_day) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=id')->execute([$person,$title,$day]);
                        break;
                    default:
                        throw new InvalidArgumentException('Nieznana operacja.');
                }
            } elseif ($employee) {
                $person = $employee['employee_id'];
                switch ($action) {
                    case 'cash':
                        $db->prepare('INSERT INTO jm_staff_cash VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),note=VALUES(note)')->execute([$person,$today,amount('amount'),input('note',300)]);
                        break;
                    case 'task_done':
                        $db->prepare("UPDATE jm_tasks SET status='done',version=version+1 WHERE id=? AND company='spk' AND assignee_id=? AND deleted_at IS NULL AND status<>'done'")->execute([input('id',20),$person]);
                        break;
                    case 'repeat_done':
                        $db->prepare('INSERT INTO jm_staff_task_checks(rule_id,period) SELECT id,? FROM jm_staff_task_rules WHERE id=? AND employee_id=? ON DUPLICATE KEY UPDATE period=VALUES(period)')->execute([$period,input('id',20),$person]);
                        break;
                    default:
                        throw new InvalidArgumentException('Nieznana operacja.');
                }
            } else {
                throw new DomainException('Zaloguj się ponownie.');
            }
            $_SESSION['staff_saved'] = $action !== 'login';
            header('Location: /app/employee.php?'.http_build_query(($admin ? ['admin'=>1] : []) + ['month'=>$month]), true, 303);
            exit;
        } catch (InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }
    }
    $saved = $_SESSION['staff_saved'] ?? false;
    unset($_SESSION['staff_saved']);
    if ($admin) {
        $people = rows($db, "SELECT e.id,e.display_name,a.staff_login,a.active FROM jm_employees e
            LEFT JOIN jm_accounts a ON a.employee_id=e.id AND a.role='employee'
            WHERE e.active=TRUE ORDER BY e.display_name");
        $cash = rows($db, 'SELECT c.*,e.display_name FROM jm_staff_cash c JOIN jm_employees e ON e.id=c.employee_id WHERE cash_date>=? AND cash_date<DATE_ADD(?,INTERVAL 1 MONTH) ORDER BY cash_date DESC,e.display_name', [$period,$period]);
    } elseif ($employee) {
        $person = $employee['employee_id'];
        $tasks = rows($db, "SELECT id,title,due_date,status FROM jm_tasks WHERE company='spk' AND assignee_id=? AND deleted_at IS NULL ORDER BY due_date,id", [$person]);
        $rules = rows($db, 'SELECT r.*,c.rule_id AS done FROM jm_staff_task_rules r LEFT JOIN jm_staff_task_checks c ON c.rule_id=r.id AND c.period=? WHERE r.employee_id=? ORDER BY r.month_day,r.id', [$period,$person]);
        $sales = rows($db, 'SELECT turnover,projects FROM jm_staff_sales WHERE employee_id=? AND period=?', [$person,$period])[0] ?? [];
        $receivables = rows($db, 'SELECT * FROM jm_staff_receivables WHERE employee_id=? ORDER BY customer,document', [$person]);
        $cash = rows($db, 'SELECT * FROM jm_staff_cash WHERE employee_id=? AND cash_date>=? AND cash_date<DATE_ADD(?,INTERVAL 1 MONTH) ORDER BY cash_date DESC', [$person,$period,$period]);
    }
} catch (DomainException $ex) {
    http_response_code(403);
    exit('Dostęp wymaga odpowiednich uprawnień. Wróć do strony logowania.');
} catch (Throwable $ex) {
    http_response_code(503);
    exit('Panel pracownika jest chwilowo niedostępny.');
}
function beginForm(string $action): void
{
    global $csrf;
    echo '<form method="post"><input type="hidden" name="csrf" value="'.e($csrf).'"><input type="hidden" name="action" value="'.e($action).'">';
}
function personSelect(array $people): void
{
    echo '<label>Pracownik<select name="person" required><option value="">Wybierz</option>';
    foreach ($people as $person) {
        echo '<option value="'.e($person['id']).'">'.e($person['display_name']).'</option>';
    }
    echo '</select></label>';
}
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel pracownika · JURKO Manager</title><link rel="stylesheet" href="/app/employee.css"></head>
<body><main>
<header><p>JURKO MANAGER · JURKO sp. z o.o. sp.k.</p>
<h1><?= $admin ? 'Zarządzanie kontami pracowników' : ($employee ? e($employee['display_name']) : 'Logowanie pracownika') ?></h1>
<?php if ($admin): ?><a href="/index.html#pracownicy">Panel właściciela</a>
<?php elseif ($employee): ?><a href="/transport.php">Moje transporty</a>
<form method="post" action="/auth/index.php?action=logout"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><button>Wyloguj</button></form>
<?php else: ?><a href="/auth/">Logowanie właściciela</a><?php endif ?>
</header>
<?php if ($error): ?><p role="alert"><?=e($error)?></p><?php elseif ($saved): ?><p role="status">Zapisano zmiany.</p><?php endif ?>
<?php if (!$admin && !$employee): ?>
<section><?php beginForm('login'); ?>
<label>Login<input name="login" autocomplete="username" maxlength="40" required></label>
<label>Hasło Managera<input type="password" name="password" autocomplete="current-password" required></label>
<button>Zaloguj</button></form></section>
<?php else: ?>
<form method="get"><?php if ($admin): ?><input type="hidden" name="admin" value="1"><?php endif ?>
<label>Miesiąc<input type="month" name="month" value="<?=e($month)?>" required></label><button>Pokaż</button></form>
<?php if ($admin): ?>
<section><h2>Konta spółki komandytowej</h2><p>Wybierz z kartoteki wyłącznie osoby pracujące dla JURKO sp. z o.o. sp.k.</p>
<ul><?php foreach ($people as $person): if ($person['staff_login'] === null) continue; ?><li><?=e($person['display_name'])?> · <?=e($person['staff_login'])?> · <?=$person['active'] ? 'Aktywne' : 'Wyłączone'?></li><?php endforeach ?></ul>
<?php beginForm('account'); personSelect($people); ?>
<label>Login<input name="login" maxlength="40" placeholder="np. dj" required></label>
<label>Służbowy e-mail<input name="email" type="email" maxlength="254" required></label>
<label>Nowe hasło Managera<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>
<button>Zapisz konto i hasło</button></form>
<?php beginForm('disable'); personSelect($people); ?><button>Wyłącz konto</button></form></section>
<section><h2>Przydziel zadanie</h2>
<?php beginForm('task'); personSelect($people); ?><label>Zadanie<input name="title" maxlength="200" required></label><label>Termin<input type="date" name="due" required></label><button>Dodaj zadanie</button></form>
<?php beginForm('repeat'); personSelect($people); ?><label>Zadanie miesięczne<input name="title" maxlength="200" required></label><label>Dzień miesiąca<select name="day"><option>15</option><option>28</option></select></label><button>Dodaj cykl</button></form></section>
<section><h2>Dane sprzedaży</h2>
<?php beginForm('sales'); personSelect($people); ?><label>Obrót netto w PLN<input name="turnover" inputmode="decimal" required></label><label>Wykonane projekty<input name="projects" type="number" min="0" max="999999999" required></label><button>Zapisz wynik za <?=e($month)?></button></form>
<?php beginForm('receivable'); personSelect($people); ?><label>Klient<input name="customer" maxlength="150" required></label><label>Dokument<input name="document" maxlength="100" required></label><label>Saldo w PLN<input name="balance" inputmode="decimal" required></label><button>Zapisz rozrachunek</button></form></section>
<?php else: ?>
<?php if ($employee['department'] === 'sales'): ?>
<section><h2>Moja sprzedaż</h2><p>Obrót netto: <?=isset($sales['turnover']) ? e(number_format((float)$sales['turnover'],2,',',' ')).' zł' : 'Brak wpisu'?> · Wykonane projekty: <?=e($sales['projects'] ?? 'Brak wpisu')?></p>
<h3>Rozrachunki z klientami</h3>
<?php if (!$receivables): ?><p>Brak wpisów.</p><?php endif ?>
<?php foreach ($receivables as $row): ?><p><?=e($row['customer'])?> · <?=e($row['document'])?> · <?=e(number_format((float)$row['balance'],2,',',' '))?> zł</p><?php endforeach ?></section>
<?php endif ?>
<section><h2>Moje zadania</h2>
<?php if (!$tasks && !$rules): ?><p>Brak przydzielonych zadań.</p><?php endif ?>
<?php foreach ($tasks as $task): ?>
<?php beginForm('task_done'); ?><span><?=e($task['due_date'])?> · <?=e($task['title'])?></span>
<input type="hidden" name="id" value="<?=e($task['id'])?>">
<?php if ($task['status'] === 'done'): ?><strong>Zrobione</strong><?php else: ?><button>Oznacz jako zrobione</button><?php endif ?></form>
<?php endforeach ?>
<?php foreach ($rules as $rule): ?>
<?php beginForm('repeat_done'); ?><span><?=e($month.'-'.$rule['month_day'])?> · <?=e($rule['title'])?></span>
<input type="hidden" name="id" value="<?=e($rule['id'])?>">
<?php if ($rule['done']): ?><strong>Zrobione</strong><?php else: ?><button>Oznacz jako zrobione</button><?php endif ?></form>
<?php endforeach ?></section>
<?php endif ?>
<section><h2>Rozliczenie kasy</h2>
<?php if (!$admin): beginForm('cash'); ?><label>Kwota za <?=e($today)?> w PLN<input name="amount" inputmode="decimal" required></label><label>Uwagi<input name="note" maxlength="300"></label><button>Zapisz rozliczenie dnia</button></form><?php endif ?>
<?php if (!$cash): ?><p>Brak wpisów w tym miesiącu.</p><?php endif ?>
<?php foreach ($cash as $row): ?><p><?=e($row['cash_date'])?> · <?php if ($admin): ?><?=e($row['display_name'])?> · <?php endif ?><?=e(number_format((float)$row['amount'],2,',',' '))?> zł · <?=e($row['note'])?></p><?php endforeach ?></section>
<?php endif ?>
</main></body></html>
