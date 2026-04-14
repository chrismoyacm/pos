<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * @param array<string, mixed> $customer
 */
function creditCustomerName(array $customer): string
{
    $name = trim((string)($customer['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $first = trim((string)($customer['firstName'] ?? ''));
    $last = trim((string)($customer['lastName'] ?? ''));
    return trim($first . ' ' . $last);
}

/**
 * @param array<string, mixed> $customer
 */
function creditCustomerAddress(array $customer): string
{
    $parts = [];
    foreach (['address1', 'address2', 'parish', 'canton', 'province'] as $field) {
        $value = trim((string)($customer[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(', ', $parts);
}

function creditPaymentMethodLabel(string $method): string
{
    return match ($method) {
        'card' => 'Tarjeta de Crédito',
        'credit' => 'Crédito',
        'mixed' => 'Mixto',
        'voucher' => 'Vales de Despensa',
        'transfer' => 'Transferencia',
        'check' => 'Cheque',
        default => 'Efectivo',
    };
}

/**
 * @param array<string, mixed> $sale
 */
function normalizeSalePaymentMethod(array $sale): string
{
    $method = strtolower(trim((string)($sale['paymentMethod'] ?? 'cash')));
    return in_array($method, ['cash', 'card', 'credit', 'mixed', 'voucher', 'transfer', 'check'], true) ? $method : 'cash';
}

/**
 * @param array<string, mixed> $sale
 */
function creditSaleDateTime(array $sale): DateTimeImmutable
{
    try {
        return new DateTimeImmutable((string)($sale['createdAt'] ?? 'now'));
    } catch (Throwable) {
        return new DateTimeImmutable('now');
    }
}

function parseLegacyDateTime(string $raw): ?DateTimeImmutable
{
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', DateTimeInterface::ATOM, 'Y-m-d H:i:s'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param array<string, mixed> $sale
 * @return array<int, array<string, mixed>>
 */
function creditSaleTicketItems(array $sale): array
{
    $items = [];
    foreach (($sale['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qty = (int)($item['qty'] ?? 0);
        $price = (float)($item['price'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $items[] = [
            'cantidad' => $qty,
            'descripcion' => (string)($item['name'] ?? 'Producto'),
            'importe' => round($qty * $price, 2),
        ];
    }

    return $items;
}

/**
 * @param array<string, mixed> $sale
 */
function creditSaleDescription(array $sale): string
{
    $items = creditSaleTicketItems($sale);
    if ($items === []) {
        return 'Venta a crédito';
    }

    if (count($items) === 1) {
        return 'Venta a crédito: ' . (string)($items[0]['descripcion'] ?? 'Producto');
    }

    return 'Venta a crédito (' . (string)count($items) . ' productos)';
}

/**
 * @return array<int, array<string, mixed>>
 */
function readCreditPayments(): array
{
    return readJsonFile(storagePath('credit_payments.json'));
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildCreditLedgers(): array
{
    $customers = readJsonFile(storagePath('customers.json'));
    $sales = readJsonFile(storagePath('sales.json'));
    $payments = readCreditPayments();

    /** @var array<string, array<string, mixed>> $customersById */
    $customersById = [];
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $id = (string)($customer['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $customersById[$id] = $customer;
    }

    /** @var array<string, array<int, array<string, mixed>>> $eventsByCustomer */
    $eventsByCustomer = [];

    foreach ($customersById as $customerId => $customer) {
        $openingBalance = round((float)($customer['creditBalance'] ?? 0), 2);
        if ($openingBalance > 0) {
            $eventsByCustomer[$customerId] = $eventsByCustomer[$customerId] ?? [];
        }
    }

    foreach ($sales as $sale) {
        if (!is_array($sale)) {
            continue;
        }

        $customerId = trim((string)($sale['customerId'] ?? ''));
        if ($customerId === '' || $customerId === 'c-001') {
            continue;
        }

        $paymentMethod = normalizeSalePaymentMethod($sale);
        $pending = round((float)($sale['amountPending'] ?? 0), 2);
        if ($paymentMethod !== 'credit' && !($paymentMethod === 'mixed' && $pending > 0)) {
            continue;
        }

        $eventsByCustomer[$customerId][] = [
            'kind' => 'sale',
            'createdAt' => (string)($sale['createdAt'] ?? ''),
            'payload' => $sale,
        ];
    }

    foreach ($payments as $payment) {
        if (!is_array($payment)) {
            continue;
        }

        $customerId = trim((string)($payment['customerId'] ?? ''));
        if ($customerId === '') {
            continue;
        }

        $eventsByCustomer[$customerId][] = [
            'kind' => 'payment',
            'createdAt' => (string)($payment['createdAt'] ?? ''),
            'payload' => $payment,
        ];
    }

    /** @var array<string, array<string, mixed>> $ledgers */
    $ledgers = [];
    foreach ($eventsByCustomer as $customerId => $events) {
        $customer = $customersById[$customerId] ?? [
            'id' => $customerId,
            'name' => (string)($customerId !== '' ? $customerId : 'Cliente'),
            'creditAuthorized' => true,
        ];

        usort($events, static function ($a, $b) {
            $cmp = strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($a['kind'] ?? ''), (string)($b['kind'] ?? ''));
        });

        $customerName = creditCustomerName($customer);
        $openingBalance = round((float)($customer['creditBalance'] ?? 0), 2);
        $runningBalance = $openingBalance;
        $movements = [];
        $lastPayment = null;
        $creditLimit = (float)($customer['creditLimit'] ?? 0);
        if ($creditLimit <= 0) {
            $creditLimit = 1000.0;
        }

        if ($openingBalance > 0) {
            $legacyLast = parseLegacyDateTime((string)($customer['lastCreditPaymentAt'] ?? ''));
            $openingDate = $legacyLast instanceof DateTimeImmutable
                ? $legacyLast
                : new DateTimeImmutable('-1 day');
            $openingIso = $openingDate->format(DateTimeInterface::ATOM);

            $movements[] = [
                'fechaHora' => $openingDate->format('d/m/Y H:i'),
                'folio' => 'SALDO-INI-' . strtoupper((string)($customerId)),
                'movimiento' => 'SALDO_INICIAL',
                'descripcion' => 'Saldo inicial migrado desde sistema anterior',
                'monto' => $openingBalance,
                'saldoActual' => $runningBalance,
                'cajero' => 'Migración',
                'createdAt' => $openingIso,
                'ticket' => [
                    'folio' => 'SALDO-INI-' . strtoupper((string)($customerId)),
                    'cajero' => 'Migración',
                    'cliente' => $customerName,
                    'fechaHora' => $openingDate->format('d/m/Y H:i'),
                    'items' => [[
                        'cantidad' => 1,
                        'descripcion' => 'Saldo inicial migrado',
                        'importe' => $openingBalance,
                    ]],
                    'total' => $openingBalance,
                    'pagoCon' => 'Crédito',
                    'montoPendiente' => $runningBalance,
                ],
            ];
        }

        foreach ($events as $event) {
            $kind = (string)($event['kind'] ?? '');
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $createdAt = (string)($event['createdAt'] ?? '');
            $date = creditSaleDateTime(['createdAt' => $createdAt]);
            $formattedDate = $date->format('d/m/Y H:i');

            if ($kind === 'sale') {
                $paymentMethod = normalizeSalePaymentMethod($payload);
                $amount = $paymentMethod === 'mixed'
                    ? round((float)($payload['amountPending'] ?? 0), 2)
                    : round((float)($payload['total'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                $runningBalance = round($runningBalance + $amount, 2);
                $folio = 'V-' . str_pad((string)($payload['ticketId'] ?? ''), 6, '0', STR_PAD_LEFT);
                $cashier = trim((string)($payload['cashier'] ?? 'Cajero'));
                $ticketItems = creditSaleTicketItems($payload);

                $movements[] = [
                    'fechaHora' => $formattedDate,
                    'folio' => $folio,
                    'movimiento' => 'VENTA',
                    'descripcion' => creditSaleDescription($payload),
                    'monto' => $amount,
                    'saldoActual' => $runningBalance,
                    'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                    'createdAt' => $createdAt,
                    'ticket' => [
                        'folio' => $folio,
                        'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                        'cliente' => $customerName,
                        'fechaHora' => $formattedDate,
                        'items' => $ticketItems,
                        'total' => $amount,
                        'pagoCon' => $paymentMethod === 'mixed' ? 'Mixto (crédito)' : 'Crédito',
                        'montoPendiente' => $runningBalance,
                    ],
                ];
                continue;
            }

            if ($kind === 'payment') {
                $amount = round((float)($payload['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                $runningBalance = round(max(0, $runningBalance - $amount), 2);
                $folio = trim((string)($payload['folio'] ?? ''));
                if ($folio === '') {
                    $folio = 'P-' . str_pad((string)(count($movements) + 1), 6, '0', STR_PAD_LEFT);
                }
                $cashier = trim((string)($payload['cashier'] ?? 'Cajero'));
                $paymentMethod = normalizeSalePaymentMethod([
                    'paymentMethod' => (string)($payload['paymentMethod'] ?? 'cash'),
                ]);

                $movements[] = [
                    'fechaHora' => $formattedDate,
                    'folio' => $folio,
                    'movimiento' => 'LIQUIDAR',
                    'descripcion' => trim((string)($payload['description'] ?? 'Abono a deuda')),
                    'monto' => 0 - $amount,
                    'saldoActual' => $runningBalance,
                    'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                    'createdAt' => $createdAt,
                    'ticket' => [
                        'folio' => $folio,
                        'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                        'cliente' => $customerName,
                        'fechaHora' => $formattedDate,
                        'items' => [
                            [
                                'cantidad' => 1,
                                'descripcion' => trim((string)($payload['description'] ?? 'Abono a deuda')),
                                'importe' => $amount,
                            ],
                        ],
                        'total' => $amount,
                        'pagoCon' => creditPaymentMethodLabel($paymentMethod),
                        'montoPendiente' => $runningBalance,
                    ],
                ];
                $lastPayment = [
                    'date' => $formattedDate,
                    'amount' => $amount,
                ];
            }
        }

        usort($movements, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        $ledgers[$customerId] = [
            'client' => [
                'id' => $customerId,
                'name' => $customerName,
                'address' => creditCustomerAddress($customer),
                'limit' => $creditLimit,
                'phone' => (string)($customer['phone'] ?? ''),
            ],
            'summary' => [
                'balance' => $runningBalance,
                'lastPayment' => $lastPayment,
                'lastPaymentText' => $lastPayment !== null
                    ? ('Último pago: ' . $lastPayment['date'] . ' | ' . number_format((float)$lastPayment['amount'], 2, '.', ''))
                    : '',
                'movementsTotal' => array_reduce($movements, static function ($carry, $movement) {
                    return $carry + abs((float)($movement['monto'] ?? 0));
                }, 0.0),
            ],
            'movements' => $movements,
        ];
    }

    return $ledgers;
}
