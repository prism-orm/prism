<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

/**
 * Anonymise le dump d'un schéma ERP réel (phpMyAdmin, MariaDB) pour en faire une fixture locale
 * de db:import (RG-06.10) : la structure reste identique (types, longueurs, nullabilité,
 * clés, index, graphe des clés étrangères), les noms et les valeurs disparaissent.
 *
 * - tables : erp_t001, erp_t002… dans l'ordre du dump ; colonnes : c01, c02… (id est gardé) ;
 * - index : erp_t001_i1, contraintes : erp_t001_fk1 ; commentaires retirés ;
 * - défauts texte non vides remplacés par 'v' (le JSON '[]' est gardé).
 *
 * Ni le dump brut (tmp/) ni la fixture (Fixtures/Erp/) ne sont versionnés : le schéma, même
 * anonymisé, n'est pas public ; ImportTest saute le test ERP sans lui. Régénérer la fixture :
 *
 *     docker compose run --rm php php packages/orm/tests/Support/ErpSchemaAnonymizer.php tmp/dump.sql
 *
 * @internal
 */
final class ErpSchemaAnonymizer
{
    /** @var array<string, string> table d'origine → table anonyme */
    private array $tables = [];

    /** @var array<string, array<string, string>> table d'origine → colonne d'origine → colonne anonyme */
    private array $columns = [];

    public static function fixture(): string
    {
        return \dirname(__DIR__) . '/Fixtures/Erp/schema.sql';
    }

    public function anonymize(string $dump): string
    {
        preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)` \((.*?)\n\)([^;]*);/s', $dump, $creates, \PREG_SET_ORDER);
        foreach ($creates as $i => [, $table, $body]) {
            $this->tables[$table] = \sprintf('erp_t%03d', $i + 1);
            $n = 0;
            preg_match_all('/^  `([^`]+)` /m', $body, $names);
            foreach ($names[1] as $column) {
                $this->columns[$table][$column] = 'id' === $column ? 'id' : \sprintf('c%02d', ++$n);
            }
        }

        $out = ["-- Schéma ERP réel anonymisé (fixture de db:import, RG-06.10) : généré par ErpSchemaAnonymizer, ne pas modifier.\n"];
        foreach ($creates as [, $table, $body, $options]) {
            $out[] = \sprintf("CREATE TABLE `%s` (\n%s\n)%s;\n", $this->tables[$table], $this->body($table, $body), preg_replace('/\s*COMMENT=\'[^\']*\'/', '', $options));
        }

        preg_match_all('/ALTER TABLE `([^`]+)`\n(.*?);/s', $dump, $alters, \PREG_SET_ORDER);
        foreach ($alters as [, $table, $clauses]) {
            $n = 0;
            $constraints = [];
            preg_match_all('/ADD CONSTRAINT `[^`]+` FOREIGN KEY \(([^)]*)\) REFERENCES `([^`]+)` \(([^)]*)\)([^,\n]*)/', $clauses, $fks, \PREG_SET_ORDER);
            foreach ($fks as [, $local, $target, $foreign, $actions]) {
                $constraints[] = \sprintf(
                    '  ADD CONSTRAINT `%s_fk%d` FOREIGN KEY (%s) REFERENCES `%s` (%s)%s',
                    $this->tables[$table],
                    ++$n,
                    $this->columnList($table, $local),
                    $this->tables[$target],
                    $this->columnList($target, $foreign),
                    rtrim($actions),
                );
            }
            if ([] !== $constraints) {
                $out[] = \sprintf("ALTER TABLE `%s`\n%s;\n", $this->tables[$table], implode(",\n", $constraints));
            }
        }

        return implode("\n", $out);
    }

    private function body(string $table, string $body): string
    {
        $lines = [];
        $index = 0;
        foreach (explode("\n", trim($body, "\n")) as $line) {
            $line = rtrim($line, ',');
            if (1 === preg_match('/^  `([^`]+)` (.*)$/', $line, $m)) {
                $definition = (string) preg_replace('/\s+COMMENT \'(?:[^\']|\'\')*\'/', '', $m[2]);
                // les défauts texte révèlent des valeurs métier ; le JSON '[]' et le vide restent
                $definition = (string) preg_replace("/DEFAULT '(?!\\[\\]')(?!')(?:[^']|'')+'/", "DEFAULT 'v'", $definition);
                $definition = (string) preg_replace_callback('/json_valid\(`([^`]+)`\)/', fn (array $j): string => 'json_valid(`' . $this->columns[$table][$j[1]] . '`)', $definition);
                $lines[] = \sprintf('  `%s` %s', $this->columns[$table][$m[1]], $definition);
            } elseif (1 === preg_match('/^  PRIMARY KEY \(([^)]*)\)/', $line, $m)) {
                $lines[] = '  PRIMARY KEY (' . $this->columnList($table, $m[1]) . ')';
            } elseif (1 === preg_match('/^  (UNIQUE )?KEY `[^`]+` \(([^)]*)\)/', $line, $m)) {
                $lines[] = \sprintf('  %sKEY `%s_%s%d` (%s)', $m[1], $this->tables[$table], '' === $m[1] ? 'i' : 'u', ++$index, $this->columnList($table, $m[2]));
            } else {
                throw new \UnexpectedValueException(\sprintf('Unexpected line in the table %s: %s', $table, $line));
            }
        }

        return implode(",\n", $lines);
    }

    private function columnList(string $table, string $list): string
    {
        return (string) preg_replace_callback('/`([^`]+)`/', fn (array $c): string => '`' . ($this->columns[$table][$c[1]] ?? throw new \UnexpectedValueException(\sprintf('Unknown column %s.%s', $table, $c[1]))) . '`', $list);
    }
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (\PHP_SAPI === 'cli' && \is_string($script) && __FILE__ === realpath($script)) {
    require \dirname(__DIR__, 4) . '/vendor/autoload.php';
    $source = $argv[1] ?? 'tmp/dump.sql';
    $dump = is_file($source) ? (string) file_get_contents($source) : throw new \RuntimeException(\sprintf('No dump at %s.', $source));
    file_put_contents(ErpSchemaAnonymizer::fixture(), new ErpSchemaAnonymizer()->anonymize($dump));
    echo 'Written ' . ErpSchemaAnonymizer::fixture() . "\n";
}
