<?php declare(strict_types=1);

// Requires PHP compiled with the changes at
// https://github.com/cameron1729/php-src/tree/with-syntax

$products = [
    (object)['name' => 'widget', 'cost' => 3.50],
    (object)['name' => 'trinket', 'cost' => 6.50],
    (object)['name' => 'gadget', 'cost' => 9.60]
];

$inrange = fn(stdClass $product): bool with ($price = $product->cost * 1.2) => $price <= 10 && $price > 5;
print_r(array_filter($products, $inrange));


$squareandcube = fn(int $n): array with ($square = $n ** 2, $cube = $square * $n) => [$square, $cube];
print_r(array_map($squareandcube, [2, 4, 6]));

$sumpairs = fn(array $pair): int with ([$a, $b] = $pair, $sum = $a + $b) => $sum;
print_r(array_map($sumpairs, [[10, 5], [3, 7], [8, 8]]));


$users = [
    ['first' => 'John', 'last' => 'Smith'],
    ['first' => 'Manager', 'last' => 'Dogmas'],
];

$fullnameandslug = fn(array $user): array with ($fullname = $user['first'] . " " . $user['last']) => [
    $fullname,
    strtolower(str_replace(' ', '-', $fullname))
];
print_r(array_map($fullnameandslug, $users));
