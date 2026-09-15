<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/admin_bootstrap.php';
requireAdmin();

const PRINT_VAT_RATE = 0.07;
const WHOLESALE_RETAINED_RATE = 0.50;
const BOOKSELLER_RETAINED_RATE = 0.70;
const DIRECT_RETAINED_RATE = 1.00;
const CSV_MAX_BYTES = 5242880;
const CSV_PREVIEW_SESSION_KEY = 'tat_billing_csv_preview';
const EBOOK_CSV_PREVIEW_SESSION_KEY = 'tat_ebook_csv_preview';

function money(float $value): string
{
    return number_format($value, 2, ',', '.') . ' €';
}

function normalizeCsvValue(string $value): string
{
    $value = preg_replace('/^\\xEF\\xBB\\xBF/', '', $value) ?? $value;
    $value = trim(mb_strtolower($value, 'UTF-8'));
    return preg_replace('/[^a-z0-9äöüß]+/u', '', $value) ?? $value;
}

function normalizeIsbn(string $value): string
{
    return preg_replace('/[^0-9xX]/', '', trim($value)) ?? '';
}

function csvChannel(string $customerGroup): ?string
{
    $group = normalizeCsvValue($customerGroup);
    return match ($group) {
        'großhandel', 'grosshandel', 'buchgrosshandel' => 'wholesale',
        'buchhandel' => 'bookseller',
        'endkunden', 'endkunde', 'direktkunden', 'direktkunde', 'direkt', 'privatkunden', 'privatkunde' => 'direct',
        default => null,
    };
}

function findCsvHeader(array $headers, array $accepted): ?int
{
    foreach ($headers as $index => $header) {
        if (in_array(normalizeCsvValue((string) $header), $accepted, true)) {
            return $index;
        }
    }
    return null;
}

function parseCsvUpload(array $upload, PDO $pdo): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Die CSV-Datei konnte nicht hochgeladen werden.');
    }
    if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('Ungültiger Datei-Upload.');
    }
    if ((int) ($upload['size'] ?? 0) > CSV_MAX_BYTES) {
        throw new InvalidArgumentException('Die CSV-Datei darf maximal 5 MB groß sein.');
    }

    $handle = fopen($upload['tmp_name'], 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('Die CSV-Datei konnte nicht gelesen werden.');
    }
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new InvalidArgumentException('Die CSV-Datei ist leer.');
    }
    $delimiterCounts = [
        ';' => substr_count($firstLine, ';'),
        ',' => substr_count($firstLine, ','),
        "\t" => substr_count($firstLine, "\t"),
    ];
    $delimiter = array_search(max($delimiterCounts), $delimiterCounts, true);
    rewind($handle);
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!is_array($headers)) {
        fclose($handle);
        throw new InvalidArgumentException('Die CSV-Kopfzeile konnte nicht gelesen werden.');
    }

    $groupIndex = findCsvHeader($headers, ['kundengruppe', 'kundengruppen', 'gruppe']);
    $isbnIndex = findCsvHeader($headers, ['isbn', 'isbnean', 'ean', 'isbnprint']);
    $quantityIndex = findCsvHeader($headers, ['menge', 'anzahl', 'verkaufsmenge', 'quantity']);
    if ($groupIndex === null || $isbnIndex === null || $quantityIndex === null) {
        fclose($handle);
        throw new InvalidArgumentException('Benötigte Spalten fehlen. Erwartet werden: Kundengruppe, ISBN und Menge.');
    }

    $books = $pdo->query('SELECT id, title, isbn_print FROM books WHERE isbn_print IS NOT NULL AND isbn_print <> ""')->fetchAll();
    $booksByIsbn = [];
    foreach ($books as $book) {
        $booksByIsbn[normalizeIsbn((string) $book['isbn_print'])] = $book;
    }

    $aggregated = [];
    $lineNumber = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;
        if ($row === [null] || count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }
        $isbn = normalizeIsbn((string) ($row[$isbnIndex] ?? ''));
        $group = trim((string) ($row[$groupIndex] ?? ''));
        $channel = csvChannel($group);
        $quantityText = trim((string) ($row[$quantityIndex] ?? ''));
        $quantity = filter_var($quantityText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000000]]);
        if ($isbn === '' || $channel === null || $quantity === false) {
            throw new InvalidArgumentException("Fehler in CSV-Zeile {$lineNumber}: ISBN, Kundengruppe oder Menge ist ungültig.");
        }
        if (!isset($booksByIsbn[$isbn])) {
            throw new InvalidArgumentException("Fehler in CSV-Zeile {$lineNumber}: ISBN {$isbn} ist in TAT nicht als Printbuch angelegt.");
        }
        $book = $booksByIsbn[$isbn];
        $bookId = (int) $book['id'];
        $aggregated[$bookId] ??= [
            'book_id' => $bookId,
            'title' => $book['title'],
            'isbn_print' => $book['isbn_print'],
            'wholesale' => 0,
            'bookseller' => 0,
            'direct' => 0,
            'source_rows' => 0,
        ];
        $aggregated[$bookId][$channel] += $quantity;
        $aggregated[$bookId]['source_rows']++;
    }
    fclose($handle);
    if (!$aggregated) {
        throw new InvalidArgumentException('Die CSV-Datei enthält keine verarbeitbaren Verkaufszeilen.');
    }
    return array_values($aggregated);
}

function parseEbookCsvUpload(array $upload, PDO $pdo): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('Die eBook-CSV konnte nicht hochgeladen werden.');
    }
    if ((int) ($upload['size'] ?? 0) > CSV_MAX_BYTES) {
        throw new InvalidArgumentException('Die eBook-CSV darf maximal 5 MB groß sein.');
    }
    $handle = fopen($upload['tmp_name'], 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('Die eBook-CSV konnte nicht gelesen werden.');
    }
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new InvalidArgumentException('Die eBook-CSV ist leer.');
    }
    $delimiterCounts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
    $delimiter = array_search(max($delimiterCounts), $delimiterCounts, true);
    rewind($handle);
    $headers = fgetcsv($handle, 0, $delimiter);
    $required = ['verkaufsdatum', 'verkaufsweg', 'isbn', 'titel', 'erlös'];
    if (!is_array($headers)) {
        fclose($handle);
        throw new InvalidArgumentException('Die eBook-CSV-Kopfzeile konnte nicht gelesen werden.');
    }
    $indexes = [];
    foreach ($required as $header) {
        $index = findCsvHeader($headers, [$header]);
        if ($index === null) {
            fclose($handle);
            throw new InvalidArgumentException('Benötigte eBook-Spalte fehlt: ' . $header . '.');
        }
        $indexes[$header] = $index;
    }
    $books = $pdo->query('SELECT id, title, isbn_ebook FROM books WHERE isbn_ebook IS NOT NULL AND isbn_ebook <> ""')->fetchAll();
    $booksByIsbn = [];
    foreach ($books as $book) {
        $booksByIsbn[normalizeIsbn((string) $book['isbn_ebook'])] = $book;
    }
    $aggregated = [];
    $lineNumber = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;
        if ($row === [null] || count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }
        $dateText = trim((string) ($row[$indexes['verkaufsdatum']] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $dateText) {
            throw new InvalidArgumentException("Fehler in eBook-CSV-Zeile {$lineNumber}: Verkaufsdatum muss JJJJ-MM-TT sein.");
        }
        $channel = trim((string) ($row[$indexes['verkaufsweg']] ?? ''));
        $isbn = normalizeIsbn((string) ($row[$indexes['isbn']] ?? ''));
        $revenueText = trim((string) ($row[$indexes['erlös']] ?? ''));
        $revenueText = str_replace(['€', ' '], '', $revenueText);
        if (str_contains($revenueText, ',') && str_contains($revenueText, '.')) {
            $revenueText = str_replace('.', '', $revenueText);
        }
        $revenue = filter_var(str_replace(',', '.', $revenueText), FILTER_VALIDATE_FLOAT);
        if ($channel === '' || $isbn === '' || $revenue === false || !is_finite((float) $revenue)) {
            throw new InvalidArgumentException("Fehler in eBook-CSV-Zeile {$lineNumber}: Verkaufsweg, ISBN oder Erlös ist ungültig.");
        }
        if (!isset($booksByIsbn[$isbn])) {
            throw new InvalidArgumentException("Fehler in eBook-CSV-Zeile {$lineNumber}: ISBN {$isbn} ist in TAT nicht als eBook angelegt.");
        }
        $book = $booksByIsbn[$isbn];
        $key = (int) $book['id'] . '|' . $date->format('Y-m-d') . '|' . $channel;
        $aggregated[$key] ??= [
            'book_id' => (int) $book['id'],
            'title' => $book['title'],
            'isbn_ebook' => $book['isbn_ebook'],
            'sale_date' => $date->format('Y-m-d'),
            'sales_channel' => $channel,
            'revenue' => 0.0,
        ];
        $aggregated[$key]['revenue'] += (float) $revenue;
    }
    fclose($handle);
    if (!$aggregated) {
        throw new InvalidArgumentException('Die eBook-CSV enthält keine verarbeitbaren Verkaufszeilen.');
    }
    foreach ($aggregated as &$row) {
        $row['revenue'] = round((float) $row['revenue'], 2);
    }
    unset($row);
    return array_values($aggregated);
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_sales') {
            $quantities = $_POST['quantity'] ?? [];
            if (!is_array($quantities) || count($quantities) > 10000) {
                throw new InvalidArgumentException('Ungültige Verkaufsdaten.');
            }
            $pdo->beginTransaction();
            $bookCheck = $pdo->prepare('SELECT id FROM books WHERE id = :id AND isbn_print IS NOT NULL AND isbn_print <> ""');
            $statement = $pdo->prepare(
                'INSERT INTO book_sales (book_id, wholesale_quantity, bookseller_quantity, direct_quantity)
                 VALUES (:book_id, :wholesale, :bookseller, :direct)
                 ON DUPLICATE KEY UPDATE wholesale_quantity = VALUES(wholesale_quantity), bookseller_quantity = VALUES(bookseller_quantity), direct_quantity = VALUES(direct_quantity)'
            );
            foreach ($quantities as $bookId => $values) {
                $bookId = filter_var($bookId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$bookId || !is_array($values)) {
                    throw new InvalidArgumentException('Ungültige Buchdaten.');
                }
                $bookCheck->execute([':id' => $bookId]);
                if (!$bookCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Es können nur Printbücher abgerechnet werden.');
                }
                $parsed = [];
                foreach (['wholesale', 'bookseller', 'direct'] as $channel) {
                    $value = filter_var($values[$channel] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000000]]);
                    if ($value === false) {
                        throw new InvalidArgumentException('Verkaufszahlen müssen ganze Zahlen ab 0 sein.');
                    }
                    $parsed[$channel] = $value;
                }
                $statement->execute([
                    ':book_id' => $bookId,
                    ':wholesale' => $parsed['wholesale'],
                    ':bookseller' => $parsed['bookseller'],
                    ':direct' => $parsed['direct'],
                ]);
            }
            $pdo->commit();
            $message = 'Verkaufszahlen gespeichert.';
        } elseif ($action === 'upload_csv') {
            $_SESSION[CSV_PREVIEW_SESSION_KEY] = parseCsvUpload($_FILES['sales_csv'] ?? [], $pdo);
            $message = 'CSV-Datei geprüft. Bitte die Vorschau kontrollieren und anschließend bestätigen.';
        } elseif ($action === 'upload_ebook_csv') {
            $_SESSION[EBOOK_CSV_PREVIEW_SESSION_KEY] = parseEbookCsvUpload($_FILES['ebook_sales_csv'] ?? [], $pdo);
            $message = 'eBook-CSV geprüft. Bitte die Vorschau kontrollieren und anschließend bestätigen.';
        } elseif ($action === 'confirm_ebook_csv') {
            $preview = $_SESSION[EBOOK_CSV_PREVIEW_SESSION_KEY] ?? null;
            if (!is_array($preview) || !$preview) {
                throw new InvalidArgumentException('Es ist keine eBook-CSV-Vorschau vorhanden.');
            }
            $selected = $_POST['ebook_selected'] ?? [];
            if (!is_array($selected) || !$selected) {
                throw new InvalidArgumentException('Bitte mindestens eine eBook-Zeile auswählen.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO ebook_sales (book_id, sale_date, sales_channel, revenue)
                 VALUES (:book_id, :sale_date, :sales_channel, :revenue)
                 ON DUPLICATE KEY UPDATE revenue = VALUES(revenue)'
            );
            $pdo->beginTransaction();
            $count = 0;
            foreach ($selected as $index) {
                $index = filter_var($index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($index === false || !isset($preview[$index])) {
                    throw new InvalidArgumentException('Ungültige Auswahl in der eBook-CSV-Vorschau.');
                }
                $row = $preview[$index];
                $statement->execute([
                    ':book_id' => (int) $row['book_id'],
                    ':sale_date' => $row['sale_date'],
                    ':sales_channel' => $row['sales_channel'],
                    ':revenue' => number_format((float) $row['revenue'], 2, '.', ''),
                ]);
                $count++;
            }
            $pdo->commit();
            unset($_SESSION[EBOOK_CSV_PREVIEW_SESSION_KEY]);
            $message = "eBook-CSV für {$count} ausgewählte Zeilen übernommen.";
        } elseif ($action === 'confirm_csv') {
            $preview = $_SESSION[CSV_PREVIEW_SESSION_KEY] ?? null;
            if (!is_array($preview) || !$preview) {
                throw new InvalidArgumentException('Es ist keine CSV-Vorschau vorhanden.');
            }
            $selected = $_POST['selected'] ?? [];
            if (!is_array($selected) || !$selected) {
                throw new InvalidArgumentException('Bitte mindestens ein Buch für den Import auswählen.');
            }
            $byId = [];
            foreach ($preview as $row) {
                $byId[(int) $row['book_id']] = $row;
            }
            $statement = $pdo->prepare(
                'INSERT INTO book_sales (book_id, wholesale_quantity, bookseller_quantity, direct_quantity)
                 VALUES (:book_id, :wholesale, :bookseller, :direct)
                 ON DUPLICATE KEY UPDATE wholesale_quantity = VALUES(wholesale_quantity), bookseller_quantity = VALUES(bookseller_quantity), direct_quantity = VALUES(direct_quantity)'
            );
            $pdo->beginTransaction();
            $count = 0;
            foreach ($selected as $bookId) {
                $bookId = filter_var($bookId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$bookId || !isset($byId[$bookId])) {
                    throw new InvalidArgumentException('Ungültige Auswahl in der CSV-Vorschau.');
                }
                $row = $byId[$bookId];
                $statement->execute([
                    ':book_id' => $bookId,
                    ':wholesale' => (int) $row['wholesale'],
                    ':bookseller' => (int) $row['bookseller'],
                    ':direct' => (int) $row['direct'],
                ]);
                $count++;
            }
            $pdo->commit();
            unset($_SESSION[CSV_PREVIEW_SESSION_KEY]);
            $message = "CSV-Abrechnung für {$count} ausgewählte Bücher übernommen.";
        } else {
            throw new InvalidArgumentException('Ungültige Aktion.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Die CSV-Abrechnung konnte nicht verarbeitet werden.';
    }
}

$csvPreview = $_SESSION[CSV_PREVIEW_SESSION_KEY] ?? [];
$books = $pdo->query(
    'SELECT b.id, b.title, b.isbn_print, b.isbn_ebook, b.selling_price, b.printing_costs,
            COALESCE(s.wholesale_quantity, 0) AS wholesale_quantity,
            COALESCE(s.bookseller_quantity, 0) AS bookseller_quantity,
            COALESCE(s.direct_quantity, 0) AS direct_quantity
     FROM books b
     LEFT JOIN book_sales s ON s.book_id = b.id
     WHERE b.isbn_print IS NOT NULL AND b.isbn_print <> ""
     ORDER BY b.title'
)->fetchAll();

$ebookCsvPreview = $_SESSION[EBOOK_CSV_PREVIEW_SESSION_KEY] ?? [];
$ebookSales = $pdo->query(
    'SELECT b.id AS book_id, b.title, b.isbn_ebook, es.sale_date, es.sales_channel, es.revenue
     FROM ebook_sales es
     INNER JOIN books b ON b.id = es.book_id
     ORDER BY es.sale_date, b.title, es.sales_channel'
)->fetchAll();

$authorsByBook = [];
$authorStatement = $pdo->query(
    'SELECT ba.book_id, a.name, ba.margin_percent
     FROM book_authors ba
     INNER JOIN authors a ON a.id = ba.author_id
     ORDER BY ba.book_id, a.name'
);
foreach ($authorStatement->fetchAll() as $author) {
    $authorsByBook[(int) $author['book_id']][] = $author;
}

$calculations = [];
$authorSummary = [];
$totalRevenue = 0.0;
$totalAuthorPayout = 0.0;
foreach ($books as $book) {
    $netPrice = (float) $book['selling_price'] / (1 + PRINT_VAT_RATE);
    $printingCosts = (float) $book['printing_costs'];
    $wholesaleRevenue = (($netPrice * WHOLESALE_RETAINED_RATE) - $printingCosts) * (int) $book['wholesale_quantity'];
    $booksellerRevenue = (($netPrice * BOOKSELLER_RETAINED_RATE) - $printingCosts) * (int) $book['bookseller_quantity'];
    $directRevenue = (($netPrice * DIRECT_RETAINED_RATE) - $printingCosts) * (int) $book['direct_quantity'];
    $revenue = $wholesaleRevenue + $booksellerRevenue + $directRevenue;
    $totalRevenue += $revenue;
    $authorCalculations = [];
    foreach ($authorsByBook[(int) $book['id']] ?? [] as $author) {
        $authorNet = $revenue * ((float) $author['margin_percent'] / 100);
        $authorPayout = $authorNet * (1 + PRINT_VAT_RATE);
        $totalAuthorPayout += $authorPayout;
        $authorName = (string) $author['name'];
        $authorSummary[$authorName] ??= ['books' => [], 'print_payout' => 0.0, 'ebook_payout' => 0.0];
        $authorSummary[$authorName]['books'][(int) $book['id']] ??= ['title' => (string) $book['title'], 'wholesale' => 0.0, 'bookseller' => 0.0, 'direct' => 0.0, 'ebook' => 0.0];
        $authorSummary[$authorName]['books'][(int) $book['id']]['wholesale'] += $wholesaleRevenue * ((float) $author['margin_percent'] / 100) * (1 + PRINT_VAT_RATE);
        $authorSummary[$authorName]['books'][(int) $book['id']]['bookseller'] += $booksellerRevenue * ((float) $author['margin_percent'] / 100) * (1 + PRINT_VAT_RATE);
        $authorSummary[$authorName]['books'][(int) $book['id']]['direct'] += $directRevenue * ((float) $author['margin_percent'] / 100) * (1 + PRINT_VAT_RATE);
        $authorSummary[$authorName]['print_payout'] += $authorPayout;
        $authorCalculations[] = ['name' => $authorName, 'margin_percent' => (float) $author['margin_percent'], 'payout' => $authorPayout];
    }
    $calculations[(int) $book['id']] = [
        'wholesale_revenue' => $wholesaleRevenue,
        'bookseller_revenue' => $booksellerRevenue,
        'direct_revenue' => $directRevenue,
        'revenue' => $revenue,
        'authors' => $authorCalculations,
    ];
}

$ebookCalculations = [];
$ebookTotalRevenue = 0.0;
$ebookTotalAuthorPayout = 0.0;
foreach ($ebookSales as $sale) {
    $revenue = (float) $sale['revenue'];
    $ebookTotalRevenue += $revenue;
    $authorCalculations = [];
    foreach ($authorsByBook[(int) $sale['book_id']] ?? [] as $author) {
        $payout = $revenue * ((float) $author['margin_percent'] / 100);
        $ebookTotalAuthorPayout += $payout;
        $authorName = (string) $author['name'];
        $authorSummary[$authorName] ??= ['books' => [], 'print_payout' => 0.0, 'ebook_payout' => 0.0];
        $authorSummary[$authorName]['books'][(int) $sale['book_id']] ??= ['title' => (string) $sale['title'], 'wholesale' => 0.0, 'bookseller' => 0.0, 'direct' => 0.0, 'ebook' => 0.0];
        $authorSummary[$authorName]['books'][(int) $sale['book_id']]['ebook'] += $payout;
        $authorSummary[$authorName]['ebook_payout'] += $payout;
        $authorCalculations[] = [
            'name' => $authorName,
            'margin_percent' => (float) $author['margin_percent'],
            'payout' => $payout,
        ];
    }
    $ebookCalculations[] = [
        'title' => $sale['title'],
        'isbn_ebook' => $sale['isbn_ebook'],
        'sale_date' => $sale['sale_date'],
        'sales_channel' => $sale['sales_channel'],
        'revenue' => $revenue,
        'authors' => $authorCalculations,
    ];
}

$token = csrf();
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Abrechnung erstellen – Autorenmargen</title><link rel="stylesheet" href="css/admin.css"></head>
<body>
<header><h1>Autorenmargen – Admin</h1><nav><a class="button secondary" href="admin.php">Stammdaten</a><form method="post" action="admin.php" class="inline-form"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><button class="secondary" type="submit">Abmelden</button></form></nav></header>
<main>
<section class="panel"><h2>CSV-Abrechnung importieren</h2><p class="muted">Erwartete Spalten: Kundengruppe, ISBN und Menge. Unterstützt werden Semikolon-, Komma- und Tabulator-getrennte CSV-Dateien. Kundengruppen werden so zugeordnet: Großhandel → Großhandel, Buchhandel → Buchhandel, Endkunden/Direktkunden → Direkt.</p>
<?php if ($message): ?><p class="notice"><?= e($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_csv"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><label for="sales-csv">JTL-Verkaufs-CSV</label><input id="sales-csv" type="file" name="sales_csv" accept=".csv,text/csv" required><div class="actions"><button type="submit">CSV prüfen und Vorschau anzeigen</button></div></form></section>
<section class="panel"><h2>eBook-CSV importieren</h2><p class="muted">Erwartete Spalten: Verkaufsdatum, Verkaufsweg, ISBN, Titel und Erlös. Der Erlös wird ohne Abzüge mit der Autorenmarge verrechnet; der Verkaufsweg bleibt erhalten. Mehrere Zeilen mit gleichem Datum, eBook und Verkaufsweg werden zusammengefasst.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_ebook_csv"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><label for="ebook-sales-csv">Distributor-eBook-CSV</label><input id="ebook-sales-csv" type="file" name="ebook_sales_csv" accept=".csv,text/csv" required><div class="actions"><button type="submit">eBook-CSV prüfen und Vorschau anzeigen</button></div></form></section>
<?php if ($ebookCsvPreview): ?><section class="panel"><h2>eBook-CSV-Vorschau</h2><p class="muted">Nur ausgewählte Zeilen werden übernommen. Bereits gespeicherte Daten mit identischem eBook, Verkaufsdatum und Verkaufsweg werden ersetzt.</p><form method="post"><input type="hidden" name="action" value="confirm_ebook_csv"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><div class="table-wrap"><table class="billing-table"><thead><tr><th>Übernehmen</th><th>Verkaufsdatum</th><th>Verkaufsweg</th><th>eBook</th><th>ISBN eBook</th><th>Erlös</th></tr></thead><tbody><?php foreach ($ebookCsvPreview as $index => $row): ?><tr><td><input type="checkbox" name="ebook_selected[]" value="<?= (int) $index ?>" checked></td><td><?= e($row['sale_date']) ?></td><td><?= e($row['sales_channel']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['isbn_ebook']) ?></td><td><?= money((float) $row['revenue']) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="actions"><button type="submit">Ausgewählte eBook-Daten übernehmen</button></div></form></section><?php endif; ?>
<?php if ($csvPreview): ?><section class="panel"><h2>CSV-Vorschau</h2><p class="muted">Die Mengen werden je ISBN und Kundengruppe zusammengefasst. Nur ausgewählte Zeilen werden beim Bestätigen übernommen; die dort gespeicherten Mengen werden ersetzt.</p><form method="post"><input type="hidden" name="action" value="confirm_csv"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><div class="table-wrap"><table class="billing-table"><thead><tr><th>Übernehmen</th><th>Buch</th><th>ISBN Print</th><th>Großhandel</th><th>Buchhandel</th><th>Direkt</th><th>CSV-Zeilen</th></tr></thead><tbody><?php foreach ($csvPreview as $row): ?><tr><td><input type="checkbox" name="selected[]" value="<?= (int) $row['book_id'] ?>" checked></td><td><?= e($row['title']) ?></td><td><?= e($row['isbn_print']) ?></td><td><?= (int) $row['wholesale'] ?></td><td><?= (int) $row['bookseller'] ?></td><td><?= (int) $row['direct'] ?></td><td><?= (int) $row['source_rows'] ?></td></tr><?php endforeach; ?></tbody></table></div><div class="actions"><button type="submit">Ausgewählte CSV-Daten übernehmen</button></div></form></section><?php endif; ?>
<section class="panel"><h2>Manuelle Verkaufszahlen</h2><p class="muted">Es werden nur Bücher mit Print-ISBN abgerechnet. eBook-only-Bücher bleiben bis zur separaten eBook-Logik ausgeschlossen.</p>
<form method="post"><input type="hidden" name="action" value="save_sales"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><div class="table-wrap"><table class="billing-table"><thead><tr><th>Buch</th><th>Verkaufspreis</th><th>Druckkosten</th><th>ISBN Print</th><th>Großhandel</th><th>Buchhandel</th><th>Direkt</th></tr></thead><tbody>
<?php foreach ($books as $book): ?><tr><td><strong><?= e($book['title']) ?></strong></td><td><?= money((float) $book['selling_price']) ?></td><td><?= money((float) $book['printing_costs']) ?></td><td><?= e($book['isbn_print']) ?></td><td><label class="visually-hidden" for="wholesale-<?= (int) $book['id'] ?>">Großhandel für <?= e($book['title']) ?></label><input id="wholesale-<?= (int) $book['id'] ?>" type="number" name="quantity[<?= (int) $book['id'] ?>][wholesale]" min="0" max="1000000000" step="1" value="<?= (int) $book['wholesale_quantity'] ?>" required></td><td><label class="visually-hidden" for="bookseller-<?= (int) $book['id'] ?>">Buchhandel für <?= e($book['title']) ?></label><input id="bookseller-<?= (int) $book['id'] ?>" type="number" name="quantity[<?= (int) $book['id'] ?>][bookseller]" min="0" max="1000000000" step="1" value="<?= (int) $book['bookseller_quantity'] ?>" required></td><td><label class="visually-hidden" for="direct-<?= (int) $book['id'] ?>">Direkt für <?= e($book['title']) ?></label><input id="direct-<?= (int) $book['id'] ?>" type="number" name="quantity[<?= (int) $book['id'] ?>][direct]" min="0" max="1000000000" step="1" value="<?= (int) $book['direct_quantity'] ?>" required></td></tr><?php endforeach; ?>
<?php if (!$books): ?><tr><td colspan="7">Es sind keine abrechenbaren Printbücher vorhanden.</td></tr><?php endif; ?></tbody></table></div><div class="actions"><button type="submit">Verkaufszahlen speichern und abrechnen</button></div></form></section>
<?php if ($authorSummary): ?><?php ksort($authorSummary, SORT_NATURAL | SORT_FLAG_CASE); ?><section class="panel"><h2>Auswertung nach Autoren</h2><p class="muted">Je Autor wird jedes Buch separat nach Großhandel, Buchhandel, Direkt und eBook ausgewiesen.</p><div class="table-wrap"><table class="billing-table"><thead><tr><th>Autor</th><th>Buch</th><th>Großhandel</th><th>Buchhandel</th><th>Direkt</th><th>eBook</th><th>Gesamt</th></tr></thead><tbody><?php foreach ($authorSummary as $authorName => $summary): ?><tr><th colspan="2"><?= e($authorName) ?></th><th><?= money(array_sum(array_column($summary['books'], 'wholesale'))) ?></th><th><?= money(array_sum(array_column($summary['books'], 'bookseller'))) ?></th><th><?= money(array_sum(array_column($summary['books'], 'direct'))) ?></th><th><?= money($summary['ebook_payout']) ?></th><th><?= money($summary['print_payout'] + $summary['ebook_payout']) ?></th></tr><?php foreach ($summary['books'] as $bookSummary): ?><tr><td></td><td><?= e($bookSummary['title']) ?></td><td><?= money($bookSummary['wholesale']) ?></td><td><?= money($bookSummary['bookseller']) ?></td><td><?= money($bookSummary['direct']) ?></td><td><?= money($bookSummary['ebook']) ?></td><td><?= money($bookSummary['wholesale'] + $bookSummary['bookseller'] + $bookSummary['direct'] + $bookSummary['ebook']) ?></td></tr><?php endforeach; ?><?php endforeach; ?></tbody><tfoot><tr><th colspan="2">Gesamt</th><th><?= money(array_sum(array_map(static fn (array $summary): float => array_sum(array_column($summary['books'], 'wholesale')), $authorSummary))) ?></th><th><?= money(array_sum(array_map(static fn (array $summary): float => array_sum(array_column($summary['books'], 'bookseller')), $authorSummary))) ?></th><th><?= money(array_sum(array_map(static fn (array $summary): float => array_sum(array_column($summary['books'], 'direct')), $authorSummary))) ?></th><th><?= money($ebookTotalAuthorPayout) ?></th><th><?= money($totalAuthorPayout + $ebookTotalAuthorPayout) ?></th></tr></tfoot></table></div></section><?php endif; ?>
<?php if ($books): ?><section class="panel"><h2>Berechnung nach Buch</h2><p class="muted">Die Zwischenwerte werden ungerundet berechnet und erst für die Anzeige auf Cent gerundet.</p><div class="table-wrap"><table class="billing-table"><thead><tr><th>Buch</th><th>Erlös Großhandel</th><th>Erlös Buchhandel</th><th>Erlös Direkt</th><th>Gesamterlös</th><th>Autorenauszahlung</th></tr></thead><tbody><?php foreach ($books as $book): $calculation = $calculations[(int) $book['id']]; ?><tr><td><strong><?= e($book['title']) ?></strong></td><td><?= money($calculation['wholesale_revenue']) ?></td><td><?= money($calculation['bookseller_revenue']) ?></td><td><?= money($calculation['direct_revenue']) ?></td><td><?= money($calculation['revenue']) ?></td><td><?php if ($calculation['authors']): ?><?php foreach ($calculation['authors'] as $author): ?><div><?= e($author['name']) ?> (<?= number_format($author['margin_percent'], 2, ',', '.') ?> %): <?= money($author['payout']) ?></div><?php endforeach; ?><?php else: ?><span class="muted">Kein Autor zugewiesen</span><?php endif; ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="4">Gesamt</th><th><?= money($totalRevenue) ?></th><th><?= money($totalAuthorPayout) ?></th></tr></tfoot></table></div></section><?php endif; ?>
<?php if ($ebookCalculations): ?><section class="panel"><h2>eBook-Berechnung</h2><p class="muted">Autorenanteil = Erlös × Autorenmarge / 100. Es werden keine Druck-, Vertriebs- oder sonstigen Kosten abgezogen.</p><div class="table-wrap"><table class="billing-table"><thead><tr><th>Verkaufsdatum</th><th>Verkaufsweg</th><th>eBook</th><th>ISBN eBook</th><th>Erlös</th><th>Autorenanteil</th></tr></thead><tbody><?php foreach ($ebookCalculations as $calculation): ?><tr><td><?= e($calculation['sale_date']) ?></td><td><?= e($calculation['sales_channel']) ?></td><td><strong><?= e($calculation['title']) ?></strong></td><td><?= e($calculation['isbn_ebook']) ?></td><td><?= money($calculation['revenue']) ?></td><td><?php if ($calculation['authors']): ?><?php foreach ($calculation['authors'] as $author): ?><div><?= e($author['name']) ?> (<?= number_format($author['margin_percent'], 2, ',', '.') ?> %): <?= money($author['payout']) ?></div><?php endforeach; ?><?php else: ?><span class="muted">Kein Autor zugewiesen</span><?php endif; ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="4">Gesamt</th><th><?= money($ebookTotalRevenue) ?></th><th><?= money($ebookTotalAuthorPayout) ?></th></tr></tfoot></table></div></section><?php endif; ?>
</main>
</body></html>
