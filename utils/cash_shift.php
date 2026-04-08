<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

function cashOpeningsPath(): string
{
    return storagePath('cash_openings.json');
}

function cashMovementsPath(): string
{
    return storagePath('cash_movements.json');
}

/**
 * @return array<int, array<string, mixed>>
 */
function readCashOpenings(): array
{
    return readJsonFile(cashOpeningsPath());
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function writeCashOpenings(array $rows): void
{
    writeJsonFile(cashOpeningsPath(), $rows);
}

/**
 * @return array<int, array<string, mixed>>
 */
function readCashMovements(): array
{
    return readJsonFile(cashMovementsPath());
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function writeCashMovements(array $rows): void
{
    writeJsonFile(cashMovementsPath(), $rows);
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function nextCashMovementId(array $rows): string
{
    $max = 0;
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if (preg_match('/^cmov-(\d+)$/i', $id, $matches) === 1) {
            $max = max($max, (int)$matches[1]);
        }
    }

    return 'cmov-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function nextCashOpeningId(array $rows): string
{
    $max = 0;
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if (preg_match('/^open-(\d+)$/i', $id, $matches) === 1) {
            $max = max($max, (int)$matches[1]);
        }
    }

    return 'open-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function findOpenShiftForUser(array $rows, int|string|null $userId): ?array
{
    if ($userId === null || $userId === '') {
        return null;
    }

    $candidate = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string)($row['userId'] ?? '') !== (string)$userId) {
            continue;
        }

        $status = (string)($row['status'] ?? 'open');
        if ($status !== 'open') {
            continue;
        }

        $candidate = $row;
    }

    return $candidate;
}

/**
 * @param array<string, mixed> $shift
 */
function computeExpectedCashForShift(array $shift): float
{
    $openingAmount = round((float)($shift['amount'] ?? 0), 2);
    $createdAt = (string)($shift['createdAt'] ?? '');
    $cashier = trim((string)($shift['name'] ?? ''));
    $sales = readJsonFile(storagePath('sales.json'));
    $creditPayments = readJsonFile(storagePath('credit_payments.json'));

    $cashSales = 0.0;
    foreach ($sales as $sale) {
        if (!is_array($sale)) {
            continue;
        }
        if ($createdAt !== '' && strcmp((string)($sale['createdAt'] ?? ''), $createdAt) < 0) {
            continue;
        }
        if ($cashier !== '' && trim((string)($sale['cashier'] ?? '')) !== $cashier) {
            continue;
        }

        $paymentMethod = strtolower(trim((string)($sale['paymentMethod'] ?? 'cash')));
        if ($paymentMethod === '' || $paymentMethod === 'cash') {
            $cashSales += round((float)($sale['total'] ?? 0), 2);
        }
    }

    $cashPayments = 0.0;
    foreach ($creditPayments as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        if ($createdAt !== '' && strcmp((string)($payment['createdAt'] ?? ''), $createdAt) < 0) {
            continue;
        }
        if ($cashier !== '' && trim((string)($payment['cashier'] ?? '')) !== $cashier) {
            continue;
        }

        $paymentMethod = strtolower(trim((string)($payment['paymentMethod'] ?? 'cash')));
        if ($paymentMethod === '' || $paymentMethod === 'cash') {
            $cashPayments += round((float)($payment['amount'] ?? 0), 2);
        }
    }

    $cashMovements = readCashMovements();
    $movementBalance = 0.0;
    $shiftId = (string)($shift['id'] ?? '');
    foreach ($cashMovements as $movement) {
        if (!is_array($movement)) {
            continue;
        }
        if ($shiftId !== '' && (string)($movement['shiftId'] ?? '') !== $shiftId) {
            continue;
        }

        $type = strtolower(trim((string)($movement['type'] ?? '')));
        $amount = round((float)($movement['amount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;
        }
        if ($type === 'entry') {
            $movementBalance += $amount;
        } elseif ($type === 'exit') {
            $movementBalance -= $amount;
        }
    }

    return round($openingAmount + $cashSales + $cashPayments + $movementBalance, 2);
}
