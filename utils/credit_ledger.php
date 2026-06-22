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
        'card' => 'Tarjeta de credito',
        'credit' => 'Credito',
        'mixed' => 'Mixto',
        'voucher' => 'Vales de despensa',
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

function creditDueDateIsOverdue(string $rawDate): bool
{
    $value = trim($rawDate);
    if ($value === '') {
        return false;
    }
    $due = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$due instanceof DateTimeImmutable || $due->format('Y-m-d') !== $value) {
        return false;
    }
    $today = new DateTimeImmutable('today');
    return $due < $today;
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
        return 'Venta a credito';
    }

    if (count($items) === 1) {
        return 'Venta a credito: ' . (string)($items[0]['descripcion'] ?? 'Producto');
    }

    return 'Venta a credito (' . (string)count($items) . ' productos)';
}

/**
 * @return array<int, array<string, mixed>>
 */
function readCreditPayments(): array
{
    return readJsonFile(storagePath('credit_payments.json'));
}

function creditToCents(float $amount): int
{
    return (int)round($amount * 100);
}

function creditFromCents(int $cents): float
{
    return round($cents / 100, 2);
}

/**
 * @param array<string, mixed> $sale
 */
function creditSaleEventKey(array $sale): string
{
    $ticketId = trim((string)($sale['ticketId'] ?? ''));
    if ($ticketId !== '') {
        return 'sale:' . $ticketId;
    }

    $createdAt = trim((string)($sale['createdAt'] ?? ''));
    if ($createdAt !== '') {
        return 'sale:' . sha1($createdAt . '|' . json_encode($sale));
    }

    return 'sale:' . uniqid('', true);
}

/**
 * @param array<int, array<string, mixed>> $openSales
 * @return array<int, array{saleKey:string,ticketId:string,folio:string,amount:float}>
 */
function creditPlanTargetedPayment(array $openSales, string $selectedSaleKey, float $amount): array
{
    $amountCents = max(0, creditToCents($amount));
    $selectedIndex = null;

    foreach ($openSales as $index => $sale) {
        if ((string)($sale['saleKey'] ?? '') === $selectedSaleKey) {
            $selectedIndex = $index;
            break;
        }
    }

    if ($selectedIndex === null || $amountCents <= 0) {
        return [];
    }

    $selectedSale = $openSales[$selectedIndex];
    $selectedPending = max(0, (int)($selectedSale['pendingCents'] ?? 0));
    if ($selectedPending <= 0) {
        return [];
    }

    $allocations = [];
    $selectedApplied = min($selectedPending, $amountCents);
    if ($selectedApplied > 0) {
        $allocations[] = [
            'saleKey' => (string)$selectedSale['saleKey'],
            'ticketId' => (string)($selectedSale['ticketId'] ?? ''),
            'folio' => (string)($selectedSale['folio'] ?? ''),
            'amount' => creditFromCents($selectedApplied),
        ];
    }

    $leftover = $amountCents - $selectedApplied;
    if ($leftover <= 0) {
        return $allocations;
    }

    $remaining = [];
    foreach ($openSales as $sale) {
        $saleKey = (string)($sale['saleKey'] ?? '');
        $pendingCents = max(0, (int)($sale['pendingCents'] ?? 0));
        if ($saleKey === $selectedSaleKey || $pendingCents <= 0) {
            continue;
        }
        $remaining[] = [
            'saleKey' => $saleKey,
            'ticketId' => (string)($sale['ticketId'] ?? ''),
            'folio' => (string)($sale['folio'] ?? ''),
            'pendingCents' => $pendingCents,
            'allocatedCents' => 0,
        ];
    }

    while ($leftover > 0 && $remaining !== []) {
        $count = count($remaining);
        $base = intdiv($leftover, $count);
        $remainder = $leftover % $count;
        $capped = false;

        foreach ($remaining as $idx => &$sale) {
            $share = $base + ($idx < $remainder ? 1 : 0);
            if ($share <= 0) {
                continue;
            }
            if ((int)$sale['pendingCents'] <= $share) {
                $pay = (int)$sale['pendingCents'];
                $sale['allocatedCents'] += $pay;
                $leftover -= $pay;
                $sale['pendingCents'] = 0;
                $capped = true;
            }
        }
        unset($sale);

        if ($capped) {
            $remaining = array_values(array_filter($remaining, static function (array $sale): bool {
                return (int)($sale['pendingCents'] ?? 0) > 0;
            }));
            continue;
        }

        foreach ($remaining as $idx => &$sale) {
            $share = $base + ($idx < $remainder ? 1 : 0);
            if ($share <= 0) {
                continue;
            }
            $sale['allocatedCents'] += $share;
            $sale['pendingCents'] -= $share;
            $leftover -= $share;
        }
        unset($sale);
    }

    foreach ($remaining as $sale) {
        $allocatedCents = (int)($sale['allocatedCents'] ?? 0);
        if ($allocatedCents <= 0) {
            continue;
        }
        $allocations[] = [
            'saleKey' => (string)$sale['saleKey'],
            'ticketId' => (string)($sale['ticketId'] ?? ''),
            'folio' => (string)$sale['folio'],
            'amount' => creditFromCents($allocatedCents),
        ];
    }

    return $allocations;
}

/**
 * @param array<int, array<string, mixed>> $openSales
 * @return array{allocations: array<int, array{saleKey:string,ticketId:string,folio:string,amount:float}>, overflowAmount: float, selectedPending: float, selectedFolio: string}
 */
function creditPreviewTargetedPayment(array $openSales, string $selectedSaleKey, float $amount): array
{
    $selectedPending = 0.0;
    $selectedFolio = '';
    foreach ($openSales as $sale) {
        if ((string)($sale['saleKey'] ?? '') !== $selectedSaleKey) {
            continue;
        }
        $selectedPending = creditFromCents(max(0, (int)($sale['pendingCents'] ?? 0)));
        $selectedFolio = (string)($sale['folio'] ?? '');
        break;
    }

    return [
        'allocations' => creditPlanTargetedPayment($openSales, $selectedSaleKey, $amount),
        'overflowAmount' => round(max(0, $amount - $selectedPending), 2),
        'selectedPending' => $selectedPending,
        'selectedFolio' => $selectedFolio,
    ];
}

/**
 * @param array<int, array<string, mixed>> $openSales
 * @return array<int, array{saleKey:string,ticketId:string,folio:string,amount:float}>
 */
function creditPlanEqualPayment(array $openSales, float $amount): array
{
    $leftover = max(0, creditToCents($amount));
    if ($leftover <= 0) {
        return [];
    }

    $planned = [];
    foreach ($openSales as $sale) {
        $pendingCents = max(0, (int)($sale['pendingCents'] ?? 0));
        if ($pendingCents <= 0) {
            continue;
        }
        $planned[] = [
            'saleKey' => (string)($sale['saleKey'] ?? ''),
            'ticketId' => (string)($sale['ticketId'] ?? ''),
            'folio' => (string)($sale['folio'] ?? ''),
            'pendingCents' => $pendingCents,
            'allocatedCents' => 0,
        ];
    }

    while ($leftover > 0 && $planned !== []) {
        $remainingIndexes = [];
        foreach ($planned as $idx => $sale) {
            if ((int)($sale['pendingCents'] ?? 0) > 0) {
                $remainingIndexes[] = $idx;
            }
        }
        if ($remainingIndexes === []) {
            break;
        }

        $count = count($remainingIndexes);
        $base = intdiv($leftover, $count);
        $remainder = $leftover % $count;
        $capped = false;

        foreach ($remainingIndexes as $shareIndex => $idx) {
            $sale =& $planned[$idx];
            $share = $base + ($shareIndex < $remainder ? 1 : 0);
            if ($share <= 0) {
                continue;
            }
            if ((int)$sale['pendingCents'] <= $share) {
                $pay = (int)$sale['pendingCents'];
                $sale['allocatedCents'] += $pay;
                $leftover -= $pay;
                $sale['pendingCents'] = 0;
                $capped = true;
            }
            unset($sale);
        }

        if ($capped) {
            continue;
        }

        foreach ($remainingIndexes as $shareIndex => $idx) {
            $sale =& $planned[$idx];
            $share = $base + ($shareIndex < $remainder ? 1 : 0);
            if ($share <= 0) {
                continue;
            }
            $sale['allocatedCents'] += $share;
            $sale['pendingCents'] -= $share;
            $leftover -= $share;
            unset($sale);
        }
    }

    $allocations = [];
    foreach ($planned as $sale) {
        $allocatedCents = (int)($sale['allocatedCents'] ?? 0);
        if ($allocatedCents <= 0) {
            continue;
        }
        $allocations[] = [
            'saleKey' => (string)$sale['saleKey'],
            'ticketId' => (string)($sale['ticketId'] ?? ''),
            'folio' => (string)$sale['folio'],
            'amount' => creditFromCents($allocatedCents),
        ];
    }

    return $allocations;
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
        $saleStates = [];
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
                'cajero' => 'Migracion',
                'createdAt' => $openingIso,
                'canReceivePayment' => false,
                'ticket' => [
                    'folio' => 'SALDO-INI-' . strtoupper((string)($customerId)),
                    'cajero' => 'Migracion',
                    'cliente' => $customerName,
                    'fechaHora' => $openingDate->format('d/m/Y H:i'),
                    'items' => [[
                        'cantidad' => 1,
                        'descripcion' => 'Saldo inicial migrado',
                        'importe' => $openingBalance,
                    ]],
                    'total' => $openingBalance,
                    'pagoCon' => 'Credito',
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
                $saleKey = creditSaleEventKey($payload);
                $pendingCents = creditToCents($amount);
                $movementIndex = count($movements);

                $saleStates[$saleKey] = [
                    'saleKey' => $saleKey,
                    'folio' => $folio,
                    'ticketId' => (string)($payload['ticketId'] ?? ''),
                    'dueDate' => trim((string)($payload['creditDueDate'] ?? '')),
                    'pendingCents' => $pendingCents,
                    'totalCents' => $pendingCents,
                    'createdAt' => $createdAt,
                    'movementIndex' => $movementIndex,
                ];

                $movements[] = [
                    'fechaHora' => $formattedDate,
                    'folio' => $folio,
                    'movimiento' => 'VENTA',
                    'descripcion' => creditSaleDescription($payload),
                    'monto' => $amount,
                    'saldoActual' => $runningBalance,
                    'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                    'createdAt' => $createdAt,
                    'saleKey' => $saleKey,
                    'canReceivePayment' => true,
                    'ticket' => [
                        'folio' => $folio,
                        'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                        'cliente' => $customerName,
                        'fechaHora' => $formattedDate,
                        'items' => $ticketItems,
                        'total' => $amount,
                        'pagoCon' => $paymentMethod === 'mixed' ? 'Mixto (credito)' : 'Credito',
                        'dueDate' => trim((string)($payload['creditDueDate'] ?? '')),
                        'montoPendiente' => $amount,
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

                $remainingPaymentCents = creditToCents($amount);
                $storedAllocations = $payload['allocations'] ?? [];

                if (is_array($storedAllocations)) {
                    foreach ($storedAllocations as $allocation) {
                        if (!is_array($allocation)) {
                            continue;
                        }
                        $saleKey = trim((string)($allocation['saleKey'] ?? ''));
                        $allocAmount = max(0, creditToCents((float)($allocation['amount'] ?? 0)));
                        if ($saleKey === '' || $allocAmount <= 0 || !isset($saleStates[$saleKey])) {
                            continue;
                        }
                        $pay = min($allocAmount, max(0, (int)($saleStates[$saleKey]['pendingCents'] ?? 0)), $remainingPaymentCents);
                        if ($pay <= 0) {
                            continue;
                        }
                        $saleStates[$saleKey]['pendingCents'] -= $pay;
                        $remainingPaymentCents -= $pay;
                    }
                }

                if ($remainingPaymentCents > 0) {
                    foreach ($saleStates as &$saleState) {
                        $pendingCents = max(0, (int)($saleState['pendingCents'] ?? 0));
                        if ($pendingCents <= 0 || $remainingPaymentCents <= 0) {
                            continue;
                        }
                        $pay = min($pendingCents, $remainingPaymentCents);
                        $saleState['pendingCents'] -= $pay;
                        $remainingPaymentCents -= $pay;
                    }
                    unset($saleState);
                }

                $movements[] = [
                    'fechaHora' => $formattedDate,
                    'folio' => $folio,
                    'movimiento' => 'LIQUIDAR',
                    'descripcion' => trim((string)($payload['description'] ?? 'Abono a deuda')),
                    'monto' => 0 - $amount,
                    'saldoActual' => $runningBalance,
                    'cajero' => $cashier !== '' ? $cashier : 'Cajero',
                    'createdAt' => $createdAt,
                    'canReceivePayment' => false,
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

        foreach ($saleStates as $saleState) {
            $movementIndex = (int)($saleState['movementIndex'] ?? -1);
            if (!isset($movements[$movementIndex])) {
                continue;
            }
            $movements[$movementIndex]['ticket']['montoPendiente'] = creditFromCents(max(0, (int)($saleState['pendingCents'] ?? 0)));
            $dueDate = trim((string)($saleState['dueDate'] ?? ''));
            $isOverdue = $dueDate !== '' && max(0, (int)($saleState['pendingCents'] ?? 0)) > 0 && creditDueDateIsOverdue($dueDate);
            $movements[$movementIndex]['ticket']['dueDate'] = $dueDate;
            $movements[$movementIndex]['isOverdue'] = $isOverdue;
        }

        usort($movements, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        $openSales = [];
        foreach ($saleStates as $saleState) {
            $pendingCents = max(0, (int)($saleState['pendingCents'] ?? 0));
            if ($pendingCents <= 0) {
                continue;
            }
            $openSales[] = [
                'saleKey' => (string)($saleState['saleKey'] ?? ''),
                'folio' => (string)($saleState['folio'] ?? ''),
                'ticketId' => (string)($saleState['ticketId'] ?? ''),
                'dueDate' => (string)($saleState['dueDate'] ?? ''),
                'pending' => creditFromCents($pendingCents),
                'pendingCents' => $pendingCents,
                'total' => creditFromCents(max(0, (int)($saleState['totalCents'] ?? 0))),
                'createdAt' => (string)($saleState['createdAt'] ?? ''),
            ];
        }

        usort($openSales, static function (array $a, array $b): int {
            return strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? ''));
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
                    ? ('Ultimo pago: ' . $lastPayment['date'] . ' | ' . number_format((float)$lastPayment['amount'], 2, '.', ''))
                    : '',
                'movementsTotal' => array_reduce($movements, static function ($carry, $movement) {
                    return $carry + abs((float)($movement['monto'] ?? 0));
                }, 0.0),
            ],
            'openSales' => $openSales,
            'movements' => $movements,
        ];
    }

    return $ledgers;
}
