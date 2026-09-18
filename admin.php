<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/admin_bootstrap.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = postText('username', 80);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $lockedUntil = (int) ($_SESSION['admin_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $error = 'Zu viele Fehlversuche. Bitte später erneut versuchen.';
    } else {
        $statement = $pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :username AND is_active = 1 LIMIT 1');
        $statement->execute([':username' => $username]);
        $admin = $statement->fetch();
        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            unset($_SESSION['admin_login_failures'], $_SESSION['admin_locked_until']);
            redirectAdmin();
        }
        $failures = (int) ($_SESSION['admin_login_failures'] ?? 0) + 1;
        $_SESSION['admin_login_failures'] = $failures;
        if ($failures >= 5) $_SESSION['admin_locked_until'] = time() + 300;
        usleep(500000);
        $error = 'Benutzername oder Passwort ist ungültig.';
    }
}

$loggedIn = isset($_SESSION['admin_id']);
if ($loggedIn) {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        try {
            $action = postText('action', 40);
            if ($action === 'logout') {
                $_SESSION = [];
                session_destroy();
                redirectAdmin();
            }
            if ($action === 'save_author') {
                $id = filter_var($_POST['author_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $name = postText('name', 255);
                $address = postText('address', 255);
                $postalCode = postText('postal_code', 20);
                $city = postText('city', 120);
                $bankAccount = postText('bank_account', 255);
                $bic = postText('bic', 20);
                $bankName = postText('bank_name', 255);
                $paypalAddress = postText('paypal_address', 255);
                if ($name === '') throw new InvalidArgumentException('Der Autorenname ist erforderlich.');
                if ($id) {
                    $statement = $pdo->prepare('UPDATE authors SET name = :name, address = :address, postal_code = :postal_code, city = :city, bank_account = :bank_account, bic = :bic, bank_name = :bank_name, paypal_address = :paypal_address WHERE id = :id');
                    $statement->execute([
                        ':name' => $name,
                        ':address' => $address,
                        ':postal_code' => $postalCode,
                        ':city' => $city,
                        ':bank_account' => $bankAccount,
                        ':bic' => $bic,
                        ':bank_name' => $bankName,
                        ':paypal_address' => $paypalAddress,
                        ':id' => $id,
                    ]);
                } else {
                    $statement = $pdo->prepare('INSERT INTO authors (name, address, postal_code, city, bank_account, bic, bank_name, paypal_address) VALUES (:name, :address, :postal_code, :city, :bank_account, :bic, :bank_name, :paypal_address)');
                    $statement->execute([':name' => $name, ':address' => $address, ':postal_code' => $postalCode, ':city' => $city, ':bank_account' => $bankAccount, ':bic' => $bic, ':bank_name' => $bankName, ':paypal_address' => $paypalAddress]);
                }
                $message = 'Autor gespeichert.';
            } elseif ($action === 'delete_author') {
                $id = filter_var($_POST['author_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) throw new InvalidArgumentException('Ungültiger Autor.');
                $statement = $pdo->prepare('DELETE FROM authors WHERE id = :id');
                $statement->execute([':id' => $id]);
                $message = $statement->rowCount() ? 'Autor gelöscht.' : 'Autor nicht gefunden.';
            } elseif ($action === 'save_book') {
                $id = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $title = postText('title', 255);
                $printingCosts = filter_var($_POST['printing_costs'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($printingCosts === false || $printingCosts < 0 || $printingCosts > 99999999.99) throw new InvalidArgumentException('Die Druckkosten müssen zwischen 0 und 99.999.999,99 liegen.');
                $printingCosts = round((float) $printingCosts, 2);
                $sellingPrice = filter_var($_POST['selling_price'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($sellingPrice === false || $sellingPrice < 0 || $sellingPrice > 99999999.99) throw new InvalidArgumentException('Der Verkaufspreis muss zwischen 0 und 99.999.999,99 liegen.');
                $sellingPrice = round((float) $sellingPrice, 2);
                $ebookPrice = filter_var($_POST['ebook_price'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($ebookPrice === false || $ebookPrice < 0 || $ebookPrice > 99999999.99) throw new InvalidArgumentException('Der eBook-Preis muss zwischen 0 und 99.999.999,99 liegen.');
                $ebookPrice = round((float) $ebookPrice, 2);
                $isbnPrint = postText('isbn_print', 20) ?: null;
                $isbnEbook = postText('isbn_ebook', 20) ?: null;
                $authorIds = $_POST['author_id'] ?? [];
                $margins = $_POST['margin_percent'] ?? [];
                if ($title === '') throw new InvalidArgumentException('Der Buchtitel ist erforderlich.');
                if (!is_array($authorIds) || !is_array($margins) || count($authorIds) !== count($margins) || count($authorIds) < 1 || count($authorIds) > 50) throw new InvalidArgumentException('Bitte mindestens einen und höchstens 50 Autoren zuweisen.');
                $authors = [];
                foreach ($authorIds as $index => $rawAuthorId) {
                    $authorId = filter_var($rawAuthorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $margin = filter_var($margins[$index], FILTER_VALIDATE_FLOAT);
                    if (!$authorId || $margin === false || $margin < 0 || $margin > 100 || isset($authors[$authorId])) throw new InvalidArgumentException('Autoren und Margen sind ungültig oder doppelt vergeben.');
                    $authors[$authorId] = round((float) $margin, 2);
                }
                $pdo->beginTransaction();
                if ($id) {
                    $statement = $pdo->prepare('UPDATE books SET title = :title, printing_costs = :printing_costs, selling_price = :selling_price, ebook_price = :ebook_price, isbn_print = :isbn_print, isbn_ebook = :isbn_ebook WHERE id = :id');
                    $statement->execute([':title' => $title, ':printing_costs' => $printingCosts, ':selling_price' => $sellingPrice, ':ebook_price' => $ebookPrice, ':isbn_print' => $isbnPrint, ':isbn_ebook' => $isbnEbook, ':id' => $id]);
                    $bookId = $id;
                    $pdo->prepare('DELETE FROM book_authors WHERE book_id = :book_id')->execute([':book_id' => $bookId]);
                } else {
                    $statement = $pdo->prepare('INSERT INTO books (title, printing_costs, selling_price, ebook_price, isbn_print, isbn_ebook) VALUES (:title, :printing_costs, :selling_price, :ebook_price, :isbn_print, :isbn_ebook)');
                    $statement->execute([':title' => $title, ':printing_costs' => $printingCosts, ':selling_price' => $sellingPrice, ':ebook_price' => $ebookPrice, ':isbn_print' => $isbnPrint, ':isbn_ebook' => $isbnEbook]);
                    $bookId = (int) $pdo->lastInsertId();
                }
                $statement = $pdo->prepare('INSERT INTO book_authors (book_id, author_id, margin_percent) VALUES (:book_id, :author_id, :margin_percent)');
                foreach ($authors as $authorId => $margin) $statement->execute([':book_id' => $bookId, ':author_id' => $authorId, ':margin_percent' => $margin]);
                $pdo->commit();
                $message = 'Buch gespeichert.';
            } elseif ($action === 'delete_book') {
                $id = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) throw new InvalidArgumentException('Ungültiges Buch.');
                $statement = $pdo->prepare('DELETE FROM books WHERE id = :id');
                $statement->execute([':id' => $id]);
                $message = $statement->rowCount() ? 'Buch gelöscht.' : 'Buch nicht gefunden.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Die Aktion konnte nicht ausgeführt werden.';
        }
    }
}

if (!$loggedIn) {
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin-Anmeldung</title><link rel="stylesheet" href="css/admin.css"></head>
<body><main><section class="panel login-panel"><h1>Autorenmargen – Admin</h1><?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="action" value="login"><label for="username">Benutzername</label><input id="username" name="username" maxlength="80" required autofocus><label for="password">Passwort</label><input id="password" type="password" name="password" required><div class="actions"><button type="submit">Anmelden</button></div></form></section></main></body></html>
<?php exit; }

$editAuthorId = filter_var($_GET['edit_author'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editBookId = filter_var($_GET['edit_book'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$author = ['id' => '', 'name' => '', 'address' => '', 'postal_code' => '', 'city' => '', 'bank_account' => '', 'bic' => '', 'bank_name' => '', 'paypal_address' => ''];
if ($editAuthorId) { $statement = $pdo->prepare('SELECT id, name, address, postal_code, city, bank_account, bic, bank_name, paypal_address FROM authors WHERE id = :id'); $statement->execute([':id' => $editAuthorId]); $author = $statement->fetch() ?: $author; }
$book = ['id' => '', 'title' => '', 'printing_costs' => '0.00', 'selling_price' => '0.00', 'ebook_price' => '0.00', 'isbn_print' => '', 'isbn_ebook' => ''];
$bookAuthors = [];
if ($editBookId) {
    $statement = $pdo->prepare('SELECT id, title, printing_costs, selling_price, ebook_price, isbn_print, isbn_ebook FROM books WHERE id = :id'); $statement->execute([':id' => $editBookId]); $book = $statement->fetch() ?: $book;
    $statement = $pdo->prepare('SELECT ba.author_id, a.name, ba.margin_percent FROM book_authors ba INNER JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = :id ORDER BY a.name'); $statement->execute([':id' => $editBookId]); $bookAuthors = $statement->fetchAll();
}
$authors = $pdo->query('SELECT id, name, address, postal_code, city, bank_account, bic, bank_name, paypal_address FROM authors ORDER BY name')->fetchAll();
$books = $pdo->query('SELECT b.id, b.title, b.printing_costs, b.selling_price, b.ebook_price, b.isbn_print, b.isbn_ebook, GROUP_CONCAT(CONCAT(a.name, " (", ba.margin_percent, " %)" ) ORDER BY a.name SEPARATOR ", ") AS author_summary FROM books b LEFT JOIN book_authors ba ON ba.book_id = b.id LEFT JOIN authors a ON a.id = ba.author_id GROUP BY b.id ORDER BY b.title')->fetchAll();
$token = csrf();
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autorenmargen – Admin</title><link rel="stylesheet" href="css/admin.css"></head>
<body data-admin-app data-csrf="<?= e($token) ?>" data-author-search="author_search.php">
<header><h1>Autorenmargen – Admin</h1><nav><a class="button secondary" href="author_price.php">Autorenpreis-Rechner</a><a class="button secondary" href="billing.php">Abrechnung erstellen</a><form method="post" class="inline-form"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><button class="secondary" type="submit">Abmelden</button></form></nav></header>
<main><?php if ($message): ?><p class="notice"><?= e($message) ?></p><?php endif; ?><?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>
<div class="grid">
<section class="panel"><h2><?= $author['id'] ? 'Autor bearbeiten' : 'Autor anlegen' ?></h2><form method="post"><input type="hidden" name="action" value="save_author"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="author_id" value="<?= e($author['id']) ?>"><label for="author-name">Name</label><input id="author-name" name="name" maxlength="255" value="<?= e($author['name']) ?>" required><label for="address">Adresse</label><input id="address" name="address" maxlength="255" value="<?= e($author['address']) ?>"><label for="postal-code">PLZ</label><input id="postal-code" name="postal_code" maxlength="20" value="<?= e($author['postal_code']) ?>"><label for="city">Ort</label><input id="city" name="city" maxlength="120" value="<?= e($author['city']) ?>"><label for="bank-account">IBAN</label><input id="bank-account" name="bank_account" maxlength="255" value="<?= e($author['bank_account']) ?>"><label for="bic">BIC</label><input id="bic" name="bic" maxlength="20" value="<?= e($author['bic']) ?>"><label for="bank-name">Bankname</label><input id="bank-name" name="bank_name" maxlength="255" value="<?= e($author['bank_name']) ?>"><label for="paypal-address">PayPal-Adresse</label><input id="paypal-address" name="paypal_address" maxlength="255" value="<?= e($author['paypal_address']) ?>"><div class="actions"><button type="submit">Speichern</button><?php if ($author['id']): ?><a class="button secondary" href="admin.php">Abbrechen</a><?php endif; ?></div></form></section>
<section class="panel"><h2><?= $book['id'] ? 'Buch bearbeiten' : 'Buch anlegen' ?></h2><form method="post"><input type="hidden" name="action" value="save_book"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="book_id" value="<?= e($book['id']) ?>"><label for="book-title">Titel</label><input id="book-title" name="title" maxlength="255" value="<?= e($book['title']) ?>" required><label for="printing-costs">Druckkosten (€)</label><input id="printing-costs" type="number" name="printing_costs" min="0" max="99999999.99" step="0.01" value="<?= e($book['printing_costs']) ?>" required><label for="selling-price">Print-Preis (€)</label><input id="selling-price" type="number" name="selling_price" min="0" max="99999999.99" step="0.01" value="<?= e($book['selling_price']) ?>" required><label for="ebook-price">eBook-Preis (€)</label><input id="ebook-price" type="number" name="ebook_price" min="0" max="99999999.99" step="0.01" value="<?= e($book['ebook_price']) ?>" required><label for="isbn-print">ISBN Print</label><input id="isbn-print" name="isbn_print" maxlength="20" value="<?= e($book['isbn_print']) ?>"><label for="isbn-ebook">ISBN eBook</label><input id="isbn-ebook" name="isbn_ebook" maxlength="20" value="<?= e($book['isbn_ebook']) ?>"><label>Autoren und individuelle Margen</label><div data-author-rows><?php foreach ($bookAuthors as $bookAuthor): ?><div class="author-row" data-author-row><div class="autocomplete"><input type="hidden" name="author_id[]" data-author-id value="<?= (int) $bookAuthor['author_id'] ?>"><input name="author_name[]" data-author-name value="<?= e($bookAuthor['name']) ?>" autocomplete="off" required><ul class="suggestions" data-suggestions></ul></div><div><label>Mar­ge %</label><input type="number" name="margin_percent[]" data-margin min="0" max="100" step="0.01" value="<?= e($bookAuthor['margin_percent']) ?>" required></div><button type="button" class="danger" data-remove-author>Entfernen</button></div><?php endforeach; ?></div><template data-author-template><div class="author-row" data-author-row><div class="autocomplete"><input type="hidden" name="author_id[]" data-author-id><input name="author_name[]" data-author-name autocomplete="off" required><ul class="suggestions" data-suggestions></ul></div><div><label>Mar­ge %</label><input type="number" name="margin_percent[]" data-margin min="0" max="100" step="0.01" required></div><button type="button" class="danger" data-remove-author>Entfernen</button></div></template><div class="actions"><button type="button" class="secondary" data-add-author>Autor hinzufügen</button><button type="submit">Buch speichern</button><?php if ($book['id']): ?><a class="button secondary" href="admin.php">Abbrechen</a><?php endif; ?></div></form></section>
</div>
<section class="panel"><h2>Autoren</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Adresse</th><th>IBAN</th><th>BIC</th><th>Bankname</th><th>PayPal-Adresse</th><th>Aktionen</th></tr></thead><tbody><?php foreach ($authors as $row): ?><tr><td><?= e($row['name']) ?></td><td><?= e(trim($row['address'] . ', ' . $row['postal_code'] . ' ' . $row['city'], ', ')) ?></td><td><?= e($row['bank_account']) ?></td><td><?= e($row['bic']) ?></td><td><?= e($row['bank_name']) ?></td><td><?= e($row['paypal_address']) ?></td><td><a class="button secondary" href="?edit_author=<?= (int) $row['id'] ?>">Bearbeiten</a> <form method="post" class="inline-form" data-confirm="Autor wirklich löschen?"><input type="hidden" name="action" value="delete_author"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="author_id" value="<?= (int) $row['id'] ?>"><button class="danger" type="submit">Löschen</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel"><h2>Bücher</h2><div class="table-wrap"><table><thead><tr><th>Titel</th><th>Druckkosten</th><th>Print-Preis</th><th>eBook-Preis</th><th>ISBN Print</th><th>ISBN eBook</th><th>Autoren / Margen</th><th>Aktionen</th></tr></thead><tbody><?php foreach ($books as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= number_format((float) $row['printing_costs'], 2, ',', '.') ?> €</td><td><?= number_format((float) $row['selling_price'], 2, ',', '.') ?> €</td><td><?= number_format((float) $row['ebook_price'], 2, ',', '.') ?> €</td><td><?= e($row['isbn_print']) ?></td><td><?= e($row['isbn_ebook']) ?></td><td><?= e($row['author_summary'] ?? '') ?></td><td><a class="button secondary" href="?edit_book=<?= (int) $row['id'] ?>">Bearbeiten</a> <form method="post" class="inline-form" data-confirm="Buch wirklich löschen?"><input type="hidden" name="action" value="delete_book"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="book_id" value="<?= (int) $row['id'] ?>"><button class="danger" type="submit">Löschen</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
</main><script src="scripts/admin.js"></script></body></html>
