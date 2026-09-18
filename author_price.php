<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/admin_bootstrap.php';
requireAdmin();

const AUTHOR_PRICE_VAT_RATE = 0.07;
const DEFAULT_AUTHOR_DISCOUNT_PERCENT = 85.0;

function authorPriceMoney(float $value): string
{
    return number_format($value, 2, ',', '.') . ' €';
}

$books = $pdo->query(
    'SELECT b.id, b.title, b.selling_price, b.printing_costs, b.isbn_print,
            COALESCE(SUM(ba.margin_percent), 0) AS author_margin_percent
     FROM books b
     LEFT JOIN book_authors ba ON ba.book_id = b.id
     WHERE b.isbn_print IS NOT NULL AND b.isbn_print <> ""
     GROUP BY b.id
     ORDER BY b.title'
)->fetchAll();

$selectedId = filter_var($_GET['book_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$useAuthorMargin = ($_GET['use_author_margin'] ?? '') === '1';
$discountInput = $_GET['discount_percent'] ?? (string) DEFAULT_AUTHOR_DISCOUNT_PERCENT;
$discountPercent = filter_var($discountInput, FILTER_VALIDATE_FLOAT);
$discountError = $discountPercent === false || $discountPercent < 0 || $discountPercent > 100;
if ($discountError) {
    $discountPercent = DEFAULT_AUTHOR_DISCOUNT_PERCENT;
}
$discountRate = (float) $discountPercent / 100;
$retainedRate = 1 - $discountRate;
$selectedBook = null;
foreach ($books as $book) {
    if ((int) $book['id'] === $selectedId) {
        $selectedBook = $book;
        break;
    }
}

$calculation = null;
if ($selectedBook !== null) {
    if ($useAuthorMargin) {
        $discountPercent = (float) $selectedBook['author_margin_percent'];
        $discountError = false;
    }
    $discountRate = (float) $discountPercent / 100;
    $retainedRate = 1 - $discountRate;
    $grossPrice = (float) $selectedBook['selling_price'];
    $printingCosts = (float) $selectedBook['printing_costs'];
    $netPrice = $grossPrice / (1 + AUTHOR_PRICE_VAT_RATE);
    $revenueBeforeAuthorDiscount = $netPrice - $printingCosts;
    $authorDiscount = $revenueBeforeAuthorDiscount * $discountRate;
    $retainedRevenue = $revenueBeforeAuthorDiscount * $retainedRate;
    $authorPriceNet = $printingCosts + $retainedRevenue;
    $authorPriceVat = $authorPriceNet * AUTHOR_PRICE_VAT_RATE;
    $authorPriceGross = $authorPriceNet + $authorPriceVat;
    $saving = $grossPrice - $authorPriceGross;

    $calculation = [
        'gross_price' => $grossPrice,
        'net_price' => $netPrice,
        'printing_costs' => $printingCosts,
        'revenue_before_author_discount' => $revenueBeforeAuthorDiscount,
        'author_discount' => $authorDiscount,
        'discount_percent' => (float) $discountPercent,
        'retained_percent' => $retainedRate * 100,
        'use_author_margin' => $useAuthorMargin,
        'author_margin_percent' => (float) $selectedBook['author_margin_percent'],
        'retained_revenue' => $retainedRevenue,
        'author_price_net' => $authorPriceNet,
        'author_price_vat' => $authorPriceVat,
        'author_price_gross' => $authorPriceGross,
        'saving' => $saving,
    ];
}

$token = csrf();
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Autorenpreis-Rechner</title>
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
<header>
    <h1>Autorenpreis-Rechner</h1>
    <nav>
        <a class="button secondary" href="admin.php">Stammdaten</a>
        <a class="button secondary" href="billing.php">Abrechnung</a>
        <form method="post" action="admin.php" class="inline-form">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <button class="secondary" type="submit">Abmelden</button>
        </form>
    </nav>
</header>
<main>
    <section class="panel">
        <h2>Buch auswählen</h2>
        <form method="get">
            <label for="book-id">Printbuch</label>
            <select id="book-id" name="book_id" required>
                <option value="">Bitte auswählen</option>
                <?php foreach ($books as $book): ?>
                    <option value="<?= (int) $book['id'] ?>"<?= (int) $book['id'] === $selectedId ? ' selected' : '' ?>><?= e($book['title']) ?> (<?= e($book['isbn_print']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <div class="discount-input-row">
                <div>
                    <label for="discount-percent">Autorenrabatt (%)</label>
                    <input id="discount-percent" type="number" name="discount_percent" min="0" max="100" step="0.01" value="<?= e(number_format((float) $discountPercent, 2, '.', '')) ?>"<?= $useAuthorMargin ? ' disabled' : '' ?> required>
                </div>
                <label class="checkbox-label" for="use-author-margin"><input id="use-author-margin" type="checkbox" name="use_author_margin" value="1"<?= $useAuthorMargin ? ' checked' : '' ?>> Kumulierte Autorenmarge als Rabatt verwenden</label>
            </div>
            <?php if ($discountError): ?><p class="notice error">Der Rabatt muss zwischen 0 und 100 Prozent liegen. Es wurde 85 Prozent verwendet.</p><?php endif; ?>
            <p class="muted">Hinterlegte Autorenmarge für dieses Buch: <?= number_format((float) ($selectedBook['author_margin_percent'] ?? 0), 2, ',', '.') ?> %</p>
            <div class="actions"><button type="submit">Preis berechnen</button></div>
        </form>
    </section>

    <?php if ($calculation !== null): ?>
        <section class="panel">
            <h2>Autorenpreis: <?= e($selectedBook['title']) ?></h2>
            <p class="muted">Berechnung pro Exemplar. Die Zwischenwerte werden ungerundet berechnet und erst für die Anzeige auf Cent gerundet.</p>
            <div class="table-wrap">
                <table class="billing-table">
                    <tbody>
                        <tr><th>Regulärer Brutto-Preis</th><td><?= authorPriceMoney($calculation['gross_price']) ?></td></tr>
                        <tr><th>Regulärer Netto-Preis (Brutto ÷ 1,07)</th><td><?= authorPriceMoney($calculation['net_price']) ?></td></tr>
                        <tr><th>Abzüglich Druckkosten</th><td><?= authorPriceMoney($calculation['printing_costs']) ?></td></tr>
                        <tr><th>Erlös vor Autorenrabatt</th><td><?= authorPriceMoney($calculation['revenue_before_author_discount']) ?></td></tr>
                        <tr><th>Autorenrabatt <?= number_format($calculation['discount_percent'], 2, ',', '.') ?> %<?= $calculation['use_author_margin'] ? ' (kumulierte Autorenmarge)' : '' ?></th><td><?= authorPriceMoney($calculation['author_discount']) ?></td></tr>
                        <tr><th>Verbleibender Erlösanteil <?= number_format($calculation['retained_percent'], 2, ',', '.') ?> %</th><td><?= authorPriceMoney($calculation['retained_revenue']) ?></td></tr>
                        <tr><th>Autorenpreis netto (Druckkosten + verbleibender Anteil)</th><td><?= authorPriceMoney($calculation['author_price_net']) ?></td></tr>
                        <tr><th>Mehrwertsteuer 7 % auf Autorenpreis</th><td><?= authorPriceMoney($calculation['author_price_vat']) ?></td></tr>
                        <tr><th>Autorenpreis brutto</th><td><strong><?= authorPriceMoney($calculation['author_price_gross']) ?></strong></td></tr>
                        <tr><th>Ersparnis pro Exemplar</th><td><strong><?= authorPriceMoney($calculation['saving']) ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($selectedId !== null): ?>
        <section class="panel"><p class="notice error">Das ausgewählte Buch ist nicht als Printbuch vorhanden.</p></section>
    <?php endif; ?>
</main>
<script src="scripts/author_price.js"></script>
</body>
</html>
