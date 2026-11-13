<?php
declare(strict_types=1);

function ensureCareerAdvisorRole(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if (!$column || !preg_match('/^enum\((.*)\)$/i', (string)$column['Type'], $match)) {
        return;
    }

    preg_match_all("/'((?:\\\\.|[^'])*)'/", $match[1], $values);
    $roles = array_map('stripcslashes', $values[1]);
    if (in_array('career_advisor', $roles, true)) {
        return;
    }
    $roles[] = 'career_advisor';
    $enum = implode(',', array_map(static fn(string $role): string => $pdo->quote($role), $roles));
    $nullability = $column['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
    $default = $column['Default'];
    $defaultSql = $default === null
        ? ($column['Null'] === 'YES' ? ' DEFAULT NULL' : '')
        : ' DEFAULT ' . $pdo->quote((string)$default);
    $pdo->exec('ALTER TABLE users MODIFY COLUMN role ENUM(' . $enum . ')' . $nullability . $defaultSql);
}
