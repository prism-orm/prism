<?php

declare(strict_types=1);

/*
 * Test d'endurance du worker FrankenPHP (phase 7, étape 9 ; RG-07.5) : N requêtes sur la démo,
 * lectures et écritures mêlées, puis les critères :
 *   - aucune requête en échec, un seul processus worker du début à la fin ;
 *   - chaque requête trouve l'identity map vide et aucune transaction ouverte ;
 *   - la mémoire ne croît pas de plus de 1 Mo après l'échauffement (les 1 000 premières requêtes).
 *
 *     php tools/worker/load.php [url] [requests] [concurrency]
 */

$base = rtrim($argv[1] ?? 'http://frankenphp', '/');
$total = (int) ($argv[2] ?? 10_000);
$concurrency = (int) ($argv[3] ?? 4);
const WARMUP = 1_000;
const MAX_GROWTH = 1_048_576;

// les commandes sans facture : une écriture (facture) par commande, tant qu'il en reste
$pdo = new PDO('mysql:host=mysql;port=3306;dbname=prism_demo', 'root', 'root');
$uninvoiced = $pdo->query('SELECT o.id FROM `order` o LEFT JOIN invoice i ON i.order_id = o.id WHERE i.id IS NULL ORDER BY o.id')->fetchAll(PDO::FETCH_COLUMN);
$customers = (int) $pdo->query('SELECT COUNT(*) FROM customer')->fetchColumn();
$orders = (int) $pdo->query('SELECT MAX(id) FROM `order`')->fetchColumn();
if (0 === $customers || 0 === $orders) {
    fwrite(STDERR, "The demo database is empty: run make demo-install first.\n");
    exit(1);
}

/**
 * La requête n : une page de liste, une fiche, le rapport, ou une facture (1 sur 10).
 *
 * @return array{string, string, array<string, string>|null} méthode, chemin, champs du POST
 */
function plan(int $n, array &$uninvoiced, int $customers, int $orders): array
{
    if (0 === $n % 10 && [] !== $uninvoiced) {
        return ['POST', '/orders/' . array_shift($uninvoiced), ['form[number]' => sprintf('W-%06d', $n)]];
    }

    return match ($n % 5) {
        0 => ['GET', '/orders?page=' . (1 + $n % 25), null],
        1 => ['GET', '/customers/' . (1 + $n % $customers), null],
        2 => ['GET', '/orders/' . (1 + $n % $orders), null],
        3 => ['GET', '/reports/revenue', null],
        default => ['GET', '/customers?page=' . (1 + $n % 3), null],
    };
}

/**
 * @param array<string, string>|null $form
 */
function handle(string $base, string $method, string $path, ?array $form): CurlHandle
{
    $curl = curl_init();
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_URL => $base . $path];
    if ('POST' === $method) {
        // le formulaire d'abord : son jeton CSRF s'il en a un (symfony/security-csrf installé)
        $page = curl_init($base . $path);
        curl_setopt($page, CURLOPT_RETURNTRANSFER, true);
        $html = (string) curl_exec($page);
        if (1 === preg_match('/name="form\[_token\]"[^>]*value="([^"]*)"/', $html, $token)) {
            $form['form[_token]'] = $token[1];
        }
        $options = [CURLOPT_URL => $base . $path . '/invoice', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form), CURLOPT_HTTPHEADER => ['Origin: ' . $base]] + $options;
    }
    curl_setopt_array($curl, $options);

    return $curl;
}

$multi = curl_multi_init();
$running = [];
$results = [];
$next = 1;
$start = microtime(true);
while ($next <= $total || [] !== $running) {
    while ($next <= $total && count($running) < $concurrency) {
        [$method, $path, $form] = plan($next, $uninvoiced, $customers, $orders);
        $curl = handle($base, $method, $path, $form);
        curl_multi_add_handle($multi, $curl);
        $running[spl_object_id($curl)] = [$curl, $next, $method . ' ' . $path];
        ++$next;
    }
    curl_multi_exec($multi, $active);
    curl_multi_select($multi, 1.0);
    while (false !== ($done = curl_multi_info_read($multi))) {
        $curl = $done['handle'];
        [, $n, $label] = $running[spl_object_id($curl)];
        $raw = (string) curl_multi_getcontent($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        preg_match_all('/^X-Prism-([A-Za-z-]+): (\S+)/mi', $raw, $h, PREG_SET_ORDER);
        $headers = [];
        foreach ($h as [, $name, $value]) {
            $headers[strtolower($name)] = $value;
        }
        $results[$n] = ['label' => $label, 'status' => $status, 'headers' => $headers];
        curl_multi_remove_handle($multi, $curl);
        unset($running[spl_object_id($curl)]);
    }
}
$seconds = microtime(true) - $start;
ksort($results);

// les critères
$failures = array_filter($results, static fn (array $r): bool => $r['status'] < 200 || $r['status'] >= 400 || !isset($r['headers']['memory']));
$pids = array_unique(array_column(array_column($results, 'headers'), 'pid'));
$dirty = array_filter($results, static fn (array $r): bool => '0' !== ($r['headers']['managed-at-start'] ?? '0') || '0' !== ($r['headers']['transaction-at-start'] ?? '0'));
$memory = static function (int $from, int $to) use ($results): int {
    $values = [];
    for ($n = $from; $n <= $to; ++$n) {
        if (isset($results[$n]['headers']['memory'])) {
            $values[] = (int) $results[$n]['headers']['memory'];
        }
    }
    sort($values);

    return $values[intdiv(count($values), 2)] ?? 0;
};
$baseline = $memory(WARMUP - 99, WARMUP);
$final = $memory($total - 99, $total);
$growth = $final - $baseline;

printf("%d requests in %.1f s (%.0f req/s), concurrency %d, %d writes\n", $total, $seconds, $total / $seconds, $concurrency, count(array_filter($results, static fn (array $r): bool => str_starts_with($r['label'], 'POST'))));
printf("worker memory (median of 100 requests): %s\n", implode(', ', array_map(static fn (int $at): string => sprintf('%d: %.2f MB', $at, $memory(max(1, $at - 99), $at) / 1_048_576), array_filter([100, WARMUP, 2_500, 5_000, 7_500, $total], static fn (int $at): bool => $at <= $total))));
printf("growth after warm-up: %+.1f KB (limit %d KB)\n", $growth / 1024, MAX_GROWTH / 1024);

$ok = true;
$check = static function (bool $pass, string $label) use (&$ok): void {
    printf("  %s %s\n", $pass ? '✔' : '✘', $label);
    $ok = $ok && $pass;
};
$check([] === $failures, sprintf('no failed request (%d)', count($failures)));
foreach (array_slice($failures, 0, 5, true) as $n => $r) {
    printf("      #%d %s → %d\n", $n, $r['label'], $r['status']);
}
$check(1 === count($pids), sprintf('one worker process for every request (%s)', implode(', ', $pids)));
$check([] === $dirty, sprintf('each request starts with an empty identity map and no transaction (%d otherwise)', count($dirty)));
$check($growth < MAX_GROWTH, 'no memory leak');

exit($ok ? 0 : 1);
