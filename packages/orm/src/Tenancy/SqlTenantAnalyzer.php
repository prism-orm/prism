<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * Analyse d'une requête SQL brute pour le TenantGuard (RG-08.7, ADR 0014) : chaque table
 * #[Tenanted] lue ou écrite doit porter une condition sur sa colonne du tenant, dans un terme du
 * WHERE ou de son ON relié aux autres par AND ; un groupe de OR n'en est une que si chacune de
 * ses branches l'est. Un INSERT doit fournir la colonne.
 *
 * En cas de doute, la table est refusée : sous-requête, WITH, UNION, INSERT … SELECT, plusieurs
 * instructions, ou découpage incertain, qui dépend de la plateforme (antislash dans une chaîne,
 * « # » et « /*! » de MySQL, « --x » de PostgreSQL, « $$ », guillemet non fermé). Seule la
 * présence d'une condition est vérifiée, pas la valeur liée.
 *
 * @internal
 */
final class SqlTenantAnalyzer
{
    public const string UNFILTERED = 'unfiltered';

    public const string UNPARSABLE = 'unparsable';

    public const string TRUNCATE = 'truncate';

    private const array ANALYZED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'WITH', 'MERGE'];

    // fin d'une liste de tables ou d'un WHERE
    private const array CLAUSES = ['WHERE', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'SET', 'RETURNING', 'FOR', 'OFFSET', 'WINDOW', 'FETCH', 'UNION', 'INTERSECT', 'EXCEPT', 'USING', 'INTO', 'LOCK', 'VALUES'];

    private const array JOINS = ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL', 'OUTER', 'STRAIGHT_JOIN'];

    // mots réservés : une table qui porte ce nom est forcément citée (« order », « group », « user »)
    private const array RESERVED = [
        'ALL', 'ALTER', 'AND', 'ANY', 'AS', 'ASC', 'BETWEEN', 'BY', 'CASE', 'CHECK', 'COLUMN', 'CONSTRAINT', 'CREATE', 'CROSS',
        'DEFAULT', 'DELETE', 'DESC', 'DISTINCT', 'DROP', 'ELSE', 'END', 'EXCEPT', 'EXISTS', 'FOR', 'FOREIGN', 'FROM', 'FULL',
        'GROUP', 'HAVING', 'IN', 'INDEX', 'INNER', 'INSERT', 'INTERSECT', 'INTO', 'IS', 'JOIN', 'KEY', 'LEFT', 'LIKE', 'LIMIT',
        'NOT', 'NULL', 'OFFSET', 'ON', 'OR', 'ORDER', 'OUTER', 'OVER', 'PARTITION', 'PRIMARY', 'RANGE', 'REFERENCES', 'RIGHT',
        'ROW', 'ROWS', 'SELECT', 'SET', 'TABLE', 'THEN', 'TO', 'UNION', 'UNIQUE', 'UPDATE', 'USER', 'USING', 'VALUES', 'WHEN',
        'WHERE', 'WINDOW', 'WITH',
    ];

    private const string PATTERN = <<<'REGEX'
        ~
          (?<ws>\s+)
        | (?<comment>--(?=\s|$)[^\n]*|/\*.*?\*/)
        | (?<string>'(?:[^']|'')*')
        | (?<ident>"(?:[^"]|"")*"|`(?:[^`]|``)*`)
        | (?<cast>::)
        | (?<param>:[A-Za-z_][A-Za-z0-9_]*|\?|\$\d+)
        | (?<word>[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*)
        | (?<number>\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)
        | (?<punct>[(),.;])
        | (?<op>[<>=!|&^\~+\-*/%@]+)
        | (?<other>.)
        ~xs
        REGEX;

    /**
     * @param array<string, string> $tables colonne du tenant, par nom de table en minuscules
     */
    public function __construct(
        private readonly array $tables,
    ) {
    }

    /**
     * Tables refusées et leur motif.
     *
     * @return list<array{string, string}> motif (UNFILTERED, UNPARSABLE, TRUNCATE), table
     */
    public function violations(string $sql): array
    {
        $tokens = self::tokenize($sql);
        if (null === $tokens) {
            // découpage incertain : toute table tenantée nommée dans le texte est refusée, dans l'ordre du texte
            $positions = [];
            foreach (array_keys($this->tables) as $table) {
                if (false !== $position = stripos($sql, $table)) {
                    $positions[$table] = $position;
                }
            }
            asort($positions);

            return self::all(self::UNPARSABLE, array_map(strval(...), array_keys($positions)));
        }
        $mentioned = $this->mentioned($tokens);
        $kind = self::kind($tokens);
        if ([] === $mentioned || (null !== $kind && !\in_array($kind, self::ANALYZED, true))) {
            return [];
        }
        if (null === $kind || 'WITH' === $kind || 'MERGE' === $kind || self::severalStatements($tokens) || self::hasSetOperation($tokens)) {
            return self::all(self::UNPARSABLE, $mentioned);
        }

        [$tokens, $nested] = $this->extractSubqueries($tokens);
        $violations = self::all(self::UNPARSABLE, $nested);

        return [...$violations, ...match ($kind) {
            'TRUNCATE' => self::all(self::TRUNCATE, $this->mentioned($tokens)),
            'INSERT', 'REPLACE' => $this->insert($tokens),
            default => $this->filtered($tokens, $kind),
        }];
    }

    /**
     * @param list<string> $tables
     *
     * @return list<array{string, string}>
     */
    private static function all(string $reason, array $tables): array
    {
        return array_map(static fn (string $table): array => [$reason, $table], $tables);
    }

    /**
     * Jetons significatifs : [type, valeur] ; w mot, i identifiant cité, p ponctuation, o opérateur,
     * l littéral ou paramètre. null : découpage incertain.
     *
     * @return list<array{string, string}>|null
     */
    private static function tokenize(string $sql): ?array
    {
        if (false === preg_match_all(self::PATTERN, $sql, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $tokens = [];
        foreach ($matches as $match) {
            $token = match (true) {
                null !== $match['ws'] => null,
                // /*! … */ est exécuté par MySQL
                null !== $match['comment'] => str_starts_with($match['comment'], '/*!') ? false : null,
                // un antislash échappe le guillemet sous MySQL, pas ailleurs
                null !== $match['string'] => str_contains($match['string'], '\\') ? false : ['l', '?'],
                null !== $match['ident'] => str_contains($match['ident'], '\\') ? false : ['i', str_replace(['""', '``'], ['"', '`'], substr($match['ident'], 1, -1))],
                null !== $match['cast'] => ['o', '::'],
                null !== $match['param'], null !== $match['number'] => ['l', '?'],
                null !== $match['word'] => ['w', $match['word']],
                null !== $match['punct'] => ['p', $match['punct']],
                // « --x » est un commentaire sous PostgreSQL et SQLite, pas sous MySQL
                null !== $match['op'] => str_contains($match['op'], '--') || str_contains($match['op'], '/*') || str_contains($match['op'], '*/') ? false : ['o', $match['op']],
                // « # » : commentaire sous MySQL, opérateur ailleurs ; « $$ », guillemet non fermé
                default => false,
            };
            if (false === $token) {
                return null;
            }
            if (null !== $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param list<array{string, string}> $tokens
     */
    private static function kind(array $tokens): ?string
    {
        $first = $tokens[0] ?? null;

        return null !== $first && 'w' === $first[0] ? strtoupper($first[1]) : null;
    }

    /**
     * Tables tenantées nommées, une fois chacune ; un mot réservé sans guillemets est un mot-clé.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return list<string>
     */
    private function mentioned(array $tokens): array
    {
        $tables = [];
        foreach ($tokens as [$type, $value]) {
            if (('i' === $type || ('w' === $type && !\in_array(strtoupper($value), self::RESERVED, true))) && isset($this->tables[strtolower($value)])) {
                $tables[strtolower($value)] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * @param list<array{string, string}> $tokens
     */
    private static function severalStatements(array $tokens): bool
    {
        foreach ($tokens as $i => [$type, $value]) {
            if ('p' === $type && ';' === $value && $i < \count($tokens) - 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{string, string}> $tokens
     */
    private static function hasSetOperation(array $tokens): bool
    {
        foreach ($tokens as [$type, $value]) {
            if (self::isWord([$type, $value], 'UNION', 'INTERSECT', 'EXCEPT')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remplace chaque sous-requête « (SELECT …) » par un littéral ; rend aussi les tables
     * tenantées qu'elles nomment.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return array{list<array{string, string}>, list<string>}
     */
    private function extractSubqueries(array $tokens): array
    {
        $result = [];
        $nested = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (self::isPunct($tokens[$i], '(') && isset($tokens[$i + 1]) && self::isWord($tokens[$i + 1], 'SELECT', 'WITH')) {
                $close = self::closing($tokens, $i);
                $nested = [...$nested, ...$this->mentioned(\array_slice($tokens, $i, $close - $i + 1))];
                $result[] = ['l', '?'];
                $i = $close;
                continue;
            }
            $result[] = $tokens[$i];
        }

        return [$result, array_values(array_unique($nested))];
    }

    /**
     * INSERT et REPLACE : la colonne du tenant figure dans la liste des colonnes, ou VALUES les
     * fournit toutes ; INSERT … SELECT depuis une table tenantée n'est pas analysé.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return list<array{string, string}>
     */
    private function insert(array $tokens): array
    {
        $i = 1;
        while (isset($tokens[$i]) && self::isWord($tokens[$i], 'IGNORE', 'LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'OR', 'REPLACE', 'ROLLBACK', 'ABORT', 'FAIL')) {
            ++$i;
        }
        if (isset($tokens[$i]) && self::isWord($tokens[$i], 'INTO')) {
            ++$i;
        }
        [$table, $i] = self::name($tokens, $i);
        if (null === $table) {
            return self::all(self::UNPARSABLE, $this->mentioned($tokens));
        }
        $source = $this->mentioned(\array_slice($tokens, $i));
        foreach (\array_slice($tokens, $i) as $token) {
            if (self::isWord($token, 'SELECT')) {
                $target = isset($this->tables[$table]) ? [$table] : [];

                return self::all(self::UNPARSABLE, array_values(array_unique([...$target, ...$source])));
            }
        }
        if (!isset($this->tables[$table])) {
            return [];
        }
        $column = strtolower($this->tables[$table]);
        if (isset($tokens[$i]) && self::isWord($tokens[$i], 'AS')) {
            $i += 2;
        }

        if (isset($tokens[$i]) && self::isPunct($tokens[$i], '(')) {
            $columns = \array_slice($tokens, $i + 1, self::closing($tokens, $i) - $i - 1);
            $given = [] !== array_filter($columns, static fn (array $t): bool => ('w' === $t[0] || 'i' === $t[0]) && strtolower($t[1]) === $column);
        } elseif (isset($tokens[$i]) && self::isWord($tokens[$i], 'SET')) {
            // INSERT … SET de MySQL : la colonne parmi les affectations
            $given = [] !== array_filter(\array_slice($tokens, $i + 1), static fn (array $t): bool => ('w' === $t[0] || 'i' === $t[0]) && strtolower($t[1]) === $column);
        } else {
            // VALUES sans liste : toutes les colonnes, dont celle du tenant ; DEFAULT VALUES : aucune
            $given = isset($tokens[$i]) && self::isWord($tokens[$i], 'VALUES', 'VALUE');
        }

        return $given ? [] : [[self::UNFILTERED, $table]];
    }

    /**
     * SELECT, UPDATE, DELETE : chaque table tenantée de la requête porte une condition.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return list<array{string, string}>
     */
    private function filtered(array $tokens, string $kind): array
    {
        $refs = [];
        $parsable = true;
        if ('SELECT' === $kind) {
            $from = self::find($tokens, 1, 'FROM');
            if (null !== $from) {
                [$refs, $parsable] = self::references($tokens, $from + 1);
            }
        } elseif ('UPDATE' === $kind) {
            $i = 1;
            while (isset($tokens[$i]) && self::isWord($tokens[$i], 'ONLY', 'LOW_PRIORITY', 'IGNORE', 'OR', 'ROLLBACK', 'ABORT', 'REPLACE', 'FAIL')) {
                ++$i;
            }
            [$refs, $parsable] = self::references($tokens, $i);
            // UPDATE … SET … FROM de PostgreSQL et SQLite
            $from = self::find($tokens, $i, 'FROM');
            if (null !== $from) {
                [$more, $ok] = self::references($tokens, $from + 1);
                [$refs, $parsable] = [[...$refs, ...$more], $parsable && $ok];
            }
        } else {
            // DELETE a FROM a JOIN … de MySQL : non analysé
            if (!isset($tokens[1]) || !self::isWord($tokens[1], 'FROM')) {
                return self::all(self::UNPARSABLE, $this->mentioned($tokens));
            }
            [$refs, $parsable] = self::references($tokens, 2);
            $using = self::find($tokens, 2, 'USING');
            if (null !== $using) {
                [$more, $ok] = self::references($tokens, $using + 1);
                [$refs, $parsable] = [[...$refs, ...$more], $parsable && $ok];
            }
        }
        if (!$parsable) {
            return self::all(self::UNPARSABLE, $this->mentioned($tokens));
        }

        $where = self::whereClause($tokens);
        $alone = 1 === \count($refs);
        $violations = [];
        // filet de sécurité : une table tenantée nommée hors des listes analysées est refusée
        $analyzed = array_map(static fn (array $ref): ?string => $ref[0], $refs);
        foreach ($this->mentioned($tokens) as $table) {
            if (!\in_array($table, $analyzed, true)) {
                $violations[$table] = [self::UNPARSABLE, $table];
            }
        }
        foreach ($refs as [$table, $qualifier, $on]) {
            if (null === $table || !isset($this->tables[$table])) {
                continue;
            }
            $column = strtolower($this->tables[$table]);
            if (!self::restricts($where, $qualifier, $column, $alone) && !self::restricts($on, $qualifier, $column, $alone)) {
                $violations[$table] = [self::UNFILTERED, $table];
            }
        }

        return array_values($violations);
    }

    /**
     * Tables d'une liste FROM, UPDATE ou USING : table, qualificatif (alias, sinon nom), ON.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return array{list<array{?string, string, list<array{string, string}>}>, bool}
     */
    private static function references(array $tokens, int $i): array
    {
        $refs = [];
        $count = \count($tokens);
        while ($i < $count) {
            if (self::isPunct($tokens[$i], '(')) {
                // jointure entre parenthèses : non analysée
                return [$refs, false];
            }
            if ('l' === $tokens[$i][0]) {
                // table dérivée (sous-requête déjà écartée) et son alias
                [$alias, $i] = self::alias($tokens, $i + 1);
                $table = null;
                $qualifier = $alias ?? '';
            } else {
                [$table, $i] = self::name($tokens, $i);
                if (null === $table) {
                    return [$refs, false];
                }
                [$alias, $i] = self::alias($tokens, $i);
                $qualifier = $alias ?? $table;
            }
            $on = [];
            if (isset($tokens[$i]) && self::isWord($tokens[$i], 'ON')) {
                $end = $i + 1;
                while ($end < $count && !self::endsCondition($tokens, $end)) {
                    if (self::isPunct($tokens[$end], '(')) {
                        $end = self::closing($tokens, $end);
                    }
                    ++$end;
                }
                $on = \array_slice($tokens, $i + 1, $end - $i - 1);
                $i = $end;
            } elseif (isset($tokens[$i]) && self::isWord($tokens[$i], 'USING') && isset($tokens[$i + 1]) && self::isPunct($tokens[$i + 1], '(')) {
                $i = self::closing($tokens, $i + 1) + 1;
            }
            $refs[] = [$table, strtolower($qualifier), $on];

            if (isset($tokens[$i]) && self::isPunct($tokens[$i], ',')) {
                ++$i;
                continue;
            }
            if (!isset($tokens[$i]) || !self::isJoin($tokens, $i)) {
                break;
            }
            while (isset($tokens[$i]) && self::isJoin($tokens, $i)) {
                ++$i;
            }
        }

        return [$refs, true];
    }

    /**
     * Nom éventuellement qualifié (schema.table) : sa dernière partie, en minuscules.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return array{?string, int}
     */
    private static function name(array $tokens, int $i): array
    {
        $name = null;
        while (isset($tokens[$i]) && ('i' === $tokens[$i][0] || ('w' === $tokens[$i][0] && (null !== $name || !self::isKeyword($tokens[$i]))))) {
            $name = strtolower($tokens[$i][1]);
            ++$i;
            if (!isset($tokens[$i], $tokens[$i + 1]) || !self::isPunct($tokens[$i], '.')) {
                break;
            }
            ++$i;
        }

        return [$name, $i];
    }

    /**
     * @param list<array{string, string}> $tokens
     *
     * @return array{?string, int}
     */
    private static function alias(array $tokens, int $i): array
    {
        if (isset($tokens[$i]) && self::isWord($tokens[$i], 'AS')) {
            ++$i;
        }
        if (isset($tokens[$i]) && ('i' === $tokens[$i][0] || ('w' === $tokens[$i][0] && !self::isKeyword($tokens[$i])))) {
            return [$tokens[$i][1], $i + 1];
        }

        return [null, $i];
    }

    /**
     * @param list<array{string, string}> $tokens
     *
     * @return list<array{string, string}>
     */
    private static function whereClause(array $tokens): array
    {
        $where = self::find($tokens, 0, 'WHERE');
        if (null === $where) {
            return [];
        }
        $count = \count($tokens);
        $end = $where + 1;
        while ($end < $count && !(self::isWord($tokens[$end], 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'RETURNING', 'FOR', 'OFFSET', 'WINDOW', 'FETCH', 'LOCK') || self::isPunct($tokens[$end], ';'))) {
            if (self::isPunct($tokens[$end], '(')) {
                $end = self::closing($tokens, $end);
            }
            ++$end;
        }

        return \array_slice($tokens, $where + 1, $end - $where - 1);
    }

    /**
     * La condition restreint-elle la colonne du tenant ? Un terme d'une conjonction suffit ;
     * chaque branche d'une disjonction est nécessaire.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function restricts(array $tokens, string $qualifier, string $column, bool $alone): bool
    {
        while (\count($tokens) >= 2 && self::isPunct($tokens[0], '(') && self::closing($tokens, 0) === \count($tokens) - 1) {
            $tokens = \array_slice($tokens, 1, -1);
        }
        if ([] === $tokens) {
            return false;
        }
        $branches = self::split($tokens, 'OR');
        if (\count($branches) > 1) {
            return [] === array_filter($branches, static fn (array $branch): bool => !self::restricts($branch, $qualifier, $column, $alone));
        }
        $terms = self::split($tokens, 'AND');
        if (\count($terms) > 1) {
            return [] !== array_filter($terms, static fn (array $term): bool => self::restricts($term, $qualifier, $column, $alone));
        }

        foreach ($tokens as $i => $token) {
            if (('w' !== $token[0] && 'i' !== $token[0]) || strtolower($token[1]) !== $column) {
                continue;
            }
            if (isset($tokens[$i + 1]) && (self::isPunct($tokens[$i + 1], '.') || self::isPunct($tokens[$i + 1], '('))) {
                continue;
            }
            $qualified = $i >= 2 && self::isPunct($tokens[$i - 1], '.');
            if ($qualified ? strtolower($tokens[$i - 2][1]) === $qualifier : $alone) {
                return true;
            }
        }

        return false;
    }

    /**
     * Découpe au niveau 0 sur AND ou OR ; le AND d'un BETWEEN n'en est pas un.
     *
     * @param list<array{string, string}> $tokens
     *
     * @return list<list<array{string, string}>>
     */
    private static function split(array $tokens, string $operator): array
    {
        $parts = [];
        $part = [];
        $depth = 0;
        $between = false;
        foreach ($tokens as $token) {
            if (self::isPunct($token, '(')) {
                ++$depth;
            } elseif (self::isPunct($token, ')')) {
                --$depth;
            } elseif (0 === $depth && self::isWord($token, 'BETWEEN')) {
                $between = true;
            } elseif (0 === $depth && self::isWord($token, $operator)) {
                if ('AND' === $operator && $between) {
                    $between = false;
                } else {
                    $parts[] = $part;
                    $part = [];
                    continue;
                }
            }
            $part[] = $token;
        }
        $parts[] = $part;

        return $parts;
    }

    /**
     * Premier mot $word au niveau 0 à partir de $from.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function find(array $tokens, int $from, string $word): ?int
    {
        $count = \count($tokens);
        for ($i = $from; $i < $count; ++$i) {
            if (self::isPunct($tokens[$i], '(')) {
                $i = self::closing($tokens, $i);
                continue;
            }
            if (self::isWord($tokens[$i], $word)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Indice de la parenthèse fermante ; la fin de la requête si elle manque.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $count = \count($tokens);
        for ($i = $open; $i < $count; ++$i) {
            if (self::isPunct($tokens[$i], '(')) {
                ++$depth;
            } elseif (self::isPunct($tokens[$i], ')') && 0 === --$depth) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * @param list<array{string, string}> $tokens
     */
    private static function endsCondition(array $tokens, int $i): bool
    {
        return self::isPunct($tokens[$i], ',') || self::isPunct($tokens[$i], ';') || self::isPunct($tokens[$i], ')') || self::isJoin($tokens, $i) || self::isWord($tokens[$i], ...self::CLAUSES);
    }

    /**
     * Mot d'une jointure ; LEFT( et RIGHT( sont des fonctions, JOIN ( une jointure entre parenthèses.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function isJoin(array $tokens, int $i): bool
    {
        return self::isWord($tokens[$i], ...self::JOINS) && !(self::isWord($tokens[$i], 'LEFT', 'RIGHT') && isset($tokens[$i + 1]) && self::isPunct($tokens[$i + 1], '('));
    }

    /**
     * @param array{string, string} $token
     */
    private static function isKeyword(array $token): bool
    {
        return self::isWord($token, 'AS', 'ON', ...self::CLAUSES, ...self::JOINS);
    }

    /**
     * @param array{string, string} $token
     */
    private static function isWord(array $token, string ...$words): bool
    {
        return 'w' === $token[0] && \in_array(strtoupper($token[1]), $words, true);
    }

    /**
     * @param array{string, string} $token
     */
    private static function isPunct(array $token, string $punct): bool
    {
        return 'p' === $token[0] && $punct === $token[1];
    }
}
