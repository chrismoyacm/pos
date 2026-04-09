(function () {
    const root = document.getElementById('reportes-module');
    if (!root) {
        return;
    }

    const rangeButtons = Array.from(root.querySelectorAll('.btn-range'));
    const cajaSelect = document.getElementById('reportes-caja');
    const kpisNode = document.getElementById('reportes-kpis');
    const metodoBody = document.querySelector('#reportes-metodo-table tbody');
    const deptoBody = document.querySelector('#reportes-departamento-table tbody');
    const clienteBody = document.querySelector('#reportes-cliente-table tbody');
    const ivaBody = document.querySelector('#reportes-impuestos-table tbody');
    const chartStackedNode = document.getElementById('reportes-chart-stacked');
    const chartStackedLegendNode = document.getElementById('reportes-chart-stacked-legend');
    const metodoResumenBody = document.querySelector('#reportes-metodo-resumen-table tbody');
    const deptVentasMiniBody = document.querySelector('#reportes-departamento-ventas-mini-table tbody');
    const deptGananciaMiniBody = document.querySelector('#reportes-departamento-ganancia-mini-table tbody');
    const chartDeptSalesDonutNode = document.getElementById('reportes-chart-dept-sales-donut');
    const chartDeptSalesLegendNode = document.getElementById('reportes-chart-dept-sales-legend');
    const chartDeptProfitDonutNode = document.getElementById('reportes-chart-dept-profit-donut');
    const chartDeptProfitLegendNode = document.getElementById('reportes-chart-dept-profit-legend');

    const state = {
        range: 'week',
        caja: 'all',
        sales: [],
        products: [],
        customers: []
    };

    const nf = new Intl.NumberFormat('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function money(value) {
        return '$ ' + nf.format(Number(value || 0));
    }

    function number(value) {
        return nf.format(Number(value || 0));
    }

    function int(value) {
        return Math.round(Number(value || 0));
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[ch] || ch;
        });
    }

    function parseDate(raw) {
        if (!raw) {
            return null;
        }
        const dt = new Date(raw);
        if (!isNaN(dt.getTime())) {
            return dt;
        }

        const asString = String(raw).trim();
        const parts = asString.split(/[\sT]/)[0].split(/[\/-]/);
        if (parts.length === 3) {
            const y = Number(parts[0]);
            const m = Number(parts[1]);
            const d = Number(parts[2]);
            if (y > 1900 && m >= 1 && m <= 12 && d >= 1 && d <= 31) {
                return new Date(y, m - 1, d);
            }
        }
        return null;
    }

    function getSaleDate(sale) {
        return parseDate(sale.createdAt || sale.date || sale.saleDate || sale.datetime);
    }

    function toArray(payload) {
        if (Array.isArray(payload)) {
            return payload;
        }
        if (payload && Array.isArray(payload.data)) {
            return payload.data;
        }
        return [];
    }

    async function fetchJson(url) {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) {
            throw new Error('No fue posible cargar ' + url);
        }
        return res.json();
    }

    function normalizeMethod(methodRaw) {
        const method = String(methodRaw || '').trim().toLowerCase();
        if (!method) {
            return 'efectivo';
        }
        if (method === 'cash' || method === 'efectivo') {
            return 'efectivo';
        }
        if (method === 'credit' || method === 'credito' || method === 'crédito' || method === 'cr') {
            return 'credito';
        }
        if (method === 'transfer' || method === 'transferencia') {
            return 'transferencia';
        }
        if (method === 'mixed' || method === 'mixto') {
            return 'mixto';
        }
        if (method === 'card' || method === 'tarjeta') {
            return 'tarjeta';
        }
        return method;
    }

    function getMethodLabel(method) {
        if (method === 'efectivo') return 'Efectivo';
        if (method === 'credito') return 'Crédito';
        if (method === 'transferencia') return 'Transferencia';
        if (method === 'mixto') return 'Mixto';
        if (method === 'tarjeta') return 'Tarjeta';
        return method.charAt(0).toUpperCase() + method.slice(1);
    }

    function formatIsoDate(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function shortDayLabel(date) {
        return date.toLocaleDateString('es-EC', { weekday: 'short', day: '2-digit' });
    }

    function weekBounds(reference) {
        const current = new Date(reference.getFullYear(), reference.getMonth(), reference.getDate());
        const day = current.getDay();
        const diff = day === 0 ? 6 : day - 1;
        const start = new Date(current);
        start.setDate(current.getDate() - diff);
        const end = new Date(start);
        end.setDate(start.getDate() + 7);
        return { start, end };
    }

    function applyRangeFilter(sales) {
        if (state.range === 'all') {
            return sales;
        }

        const now = new Date();
        const thisMonthStart = new Date(now.getFullYear(), now.getMonth(), 1);
        const nextMonthStart = new Date(now.getFullYear(), now.getMonth() + 1, 1);
        const thisYearStart = new Date(now.getFullYear(), 0, 1);
        const nextYearStart = new Date(now.getFullYear() + 1, 0, 1);
        const week = weekBounds(now);

        return sales.filter(function (sale) {
            const dt = getSaleDate(sale);
            if (!dt) {
                return false;
            }

            if (state.range === 'week') {
                return dt >= week.start && dt < week.end;
            }
            if (state.range === 'month') {
                return dt >= thisMonthStart && dt < nextMonthStart;
            }
            if (state.range === 'year') {
                return dt >= thisYearStart && dt < nextYearStart;
            }
            return true;
        });
    }

    function applyCajaFilter(sales) {
        if (state.caja === 'all') {
            return sales;
        }
        return sales.filter(function (sale) {
            const cajaName = String(sale.cashier || sale.user || sale.openedBy || '').trim();
            return cajaName === state.caja;
        });
    }

    function buildProductMap(products) {
        const byId = new Map();
        const byBarcode = new Map();

        products.forEach(function (p) {
            const product = p || {};
            const id = String(product.id || product.productId || '').trim();
            const barcode = String(product.barcode || product.code || '').trim();
            if (id) byId.set(id, product);
            if (barcode) byBarcode.set(barcode, product);
        });

        return { byId, byBarcode };
    }

    function resolveProduct(item, productMap) {
        const id = String(item.productId || item.id || '').trim();
        const barcode = String(item.barcode || item.code || '').trim();
        if (id && productMap.byId.has(id)) {
            return productMap.byId.get(id);
        }
        if (barcode && productMap.byBarcode.has(barcode)) {
            return productMap.byBarcode.get(barcode);
        }
        return null;
    }

    function getSaleItems(sale) {
        if (Array.isArray(sale.items)) {
            return sale.items;
        }
        if (Array.isArray(sale.products)) {
            return sale.products;
        }
        return [];
    }

    function getCustomerLabel(sale, customersMap) {
        const id = String(sale.customerId || sale.customer || '').trim();
        if (id && customersMap.has(id)) {
            return customersMap.get(id);
        }

        const explicit = String(sale.customerName || sale.clientName || '').trim();
        if (explicit) {
            return explicit;
        }

        if (id) {
            return 'Cliente #' + id;
        }

        return 'Consumidor final';
    }

    function getSaleTotal(sale) {
        const total = Number(sale.total);
        if (isFinite(total)) {
            return total;
        }

        const items = getSaleItems(sale);
        return items.reduce(function (acc, item) {
            const qty = Number(item.qty || item.quantity || 0) || 0;
            const price = Number(item.price || item.unitPrice || 0) || 0;
            return acc + qty * price;
        }, 0);
    }

    function aggregate(sales, products, customers) {
        const productMap = buildProductMap(products);
        const customersMap = new Map();
        customers.forEach(function (c) {
            const id = String(c.id || '').trim();
            const name = String(c.name || c.fullName || c.customerName || '').trim();
            if (id && name) {
                customersMap.set(id, name);
            }
        });

        const methodMap = new Map();
        const deptMap = new Map();
        const customerMap = new Map();
        const ivaMap = new Map();
        const dayMethodMap = new Map();

        let totalSales = 0;
        let totalCost = 0;
        let totalProfit = 0;
        let units = 0;

        sales.forEach(function (sale) {
            const saleTotal = getSaleTotal(sale);
            totalSales += saleTotal;

            const method = normalizeMethod(sale.paymentMethod || sale.method);
            const methodAgg = methodMap.get(method) || { tickets: 0, sales: 0 };
            methodAgg.tickets += 1;
            methodAgg.sales += saleTotal;
            methodMap.set(method, methodAgg);

            const saleDate = getSaleDate(sale);
            if (saleDate) {
                const dayKey = formatIsoDate(saleDate);
                const dayLabel = shortDayLabel(saleDate);
                const dayAgg = dayMethodMap.get(dayKey) || { dayKey: dayKey, dayLabel: dayLabel, methods: {} };
                dayAgg.methods[method] = Number(dayAgg.methods[method] || 0) + saleTotal;
                dayMethodMap.set(dayKey, dayAgg);
            }

            const customer = getCustomerLabel(sale, customersMap);
            const customerAgg = customerMap.get(customer) || { tickets: 0, sales: 0, profit: 0 };
            customerAgg.tickets += 1;
            customerAgg.sales += saleTotal;

            getSaleItems(sale).forEach(function (item) {
                const qty = Number(item.qty || item.quantity || 0) || 0;
                const price = Number(item.price || item.unitPrice || 0) || 0;
                const lineTotal = qty * price;
                units += qty;

                const product = resolveProduct(item, productMap) || {};
                const cost = Number(product.cost || product.purchasePrice || item.cost || 0) || 0;
                const lineCost = qty * cost;
                const lineProfit = lineTotal - lineCost;

                totalCost += lineCost;
                totalProfit += lineProfit;
                customerAgg.profit += lineProfit;

                const department = String(product.department || item.department || 'Sin departamento').trim() || 'Sin departamento';
                const deptAgg = deptMap.get(department) || { units: 0, sales: 0, cost: 0, profit: 0 };
                deptAgg.units += qty;
                deptAgg.sales += lineTotal;
                deptAgg.cost += lineCost;
                deptAgg.profit += lineProfit;
                deptMap.set(department, deptAgg);

                const ivaRate = Number(item.iva || product.tax || 0) || 0;
                const ivaKey = ivaRate > 0 ? ivaRate.toFixed(2) : '0.00';
                const ivaAgg = ivaMap.get(ivaKey) || { base: 0, iva: 0, total: 0 };
                let base = lineTotal;
                let iva = 0;
                if (ivaRate > 0) {
                    base = lineTotal / (1 + (ivaRate / 100));
                    iva = lineTotal - base;
                }
                ivaAgg.base += base;
                ivaAgg.iva += iva;
                ivaAgg.total += lineTotal;
                ivaMap.set(ivaKey, ivaAgg);
            });

            customerMap.set(customer, customerAgg);
        });

        return {
            totalSales,
            totalCost,
            totalProfit,
            units,
            tickets: sales.length,
            methodRows: Array.from(methodMap.entries()).map(function (entry) {
                return {
                    method: entry[0],
                    tickets: entry[1].tickets,
                    sales: entry[1].sales,
                    avg: entry[1].tickets ? entry[1].sales / entry[1].tickets : 0
                };
            }).sort(function (a, b) {
                return b.sales - a.sales;
            }),
            deptRows: Array.from(deptMap.entries()).map(function (entry) {
                const d = entry[1];
                const margin = d.sales > 0 ? (d.profit / d.sales) * 100 : 0;
                return {
                    department: entry[0],
                    units: d.units,
                    sales: d.sales,
                    cost: d.cost,
                    profit: d.profit,
                    margin: margin
                };
            }).sort(function (a, b) {
                return b.sales - a.sales;
            }),
            customerRows: Array.from(customerMap.entries()).map(function (entry) {
                const c = entry[1];
                return {
                    customer: entry[0],
                    tickets: c.tickets,
                    sales: c.sales,
                    profit: c.profit,
                    avg: c.tickets ? c.sales / c.tickets : 0
                };
            }).sort(function (a, b) {
                return b.sales - a.sales;
            }),
            ivaRows: Array.from(ivaMap.entries()).map(function (entry) {
                return {
                    rate: Number(entry[0]),
                    base: entry[1].base,
                    iva: entry[1].iva,
                    total: entry[1].total
                };
            }).sort(function (a, b) {
                return b.rate - a.rate;
            }),
            dayMethodRows: Array.from(dayMethodMap.values()).sort(function (a, b) {
                return a.dayKey.localeCompare(b.dayKey);
            })
        };
    }

    function renderKpis(summary) {
        const avgTicket = summary.tickets > 0 ? summary.totalSales / summary.tickets : 0;
        const margin = summary.totalSales > 0 ? (summary.totalProfit / summary.totalSales) * 100 : 0;

        kpisNode.innerHTML = [
            { label: 'Ventas', value: money(summary.totalSales) },
            { label: 'Tickets', value: number(summary.tickets) },
            { label: 'Promedio Ticket', value: money(avgTicket) },
            { label: 'Unidades', value: number(summary.units) },
            { label: 'Costo', value: money(summary.totalCost) },
            { label: 'Ganancia', value: money(summary.totalProfit) },
            { label: 'Margen', value: number(margin) + '%' }
        ].map(function (card) {
            return '<article class="reportes-kpi"><span>' + esc(card.label) + '</span><strong>' + esc(card.value) + '</strong></article>';
        }).join('');
    }

    function renderMetodo(rows) {
        if (!metodoBody) {
            return;
        }
        if (!rows.length) {
            metodoBody.innerHTML = '<tr><td colspan="4" class="muted">Sin datos para el filtro seleccionado.</td></tr>';
            return;
        }

        metodoBody.innerHTML = rows.map(function (row) {
            return '<tr>' +
                '<td>' + esc(getMethodLabel(row.method)) + '</td>' +
                '<td>' + esc(number(row.tickets)) + '</td>' +
                '<td>' + esc(money(row.sales)) + '</td>' +
                '<td>' + esc(money(row.avg)) + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderDepartamentos(rows) {
        if (!deptoBody) {
            return;
        }
        if (!rows.length) {
            deptoBody.innerHTML = '<tr><td colspan="6" class="muted">Sin datos para el filtro seleccionado.</td></tr>';
            return;
        }

        deptoBody.innerHTML = rows.map(function (row) {
            return '<tr>' +
                '<td>' + esc(row.department) + '</td>' +
                '<td>' + esc(number(row.units)) + '</td>' +
                '<td>' + esc(money(row.sales)) + '</td>' +
                '<td>' + esc(money(row.cost)) + '</td>' +
                '<td>' + esc(money(row.profit)) + '</td>' +
                '<td>' + esc(number(row.margin)) + '%</td>' +
                '</tr>';
        }).join('');
    }

    function renderClientes(rows) {
        if (!clienteBody) {
            return;
        }
        if (!rows.length) {
            clienteBody.innerHTML = '<tr><td colspan="5" class="muted">Sin datos para el filtro seleccionado.</td></tr>';
            return;
        }

        clienteBody.innerHTML = rows.map(function (row) {
            return '<tr>' +
                '<td>' + esc(row.customer) + '</td>' +
                '<td>' + esc(number(row.tickets)) + '</td>' +
                '<td>' + esc(money(row.sales)) + '</td>' +
                '<td>' + esc(money(row.profit)) + '</td>' +
                '<td>' + esc(money(row.avg)) + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderIva(rows) {
        if (!ivaBody) {
            return;
        }
        if (!rows.length) {
            ivaBody.innerHTML = '<tr><td colspan="4" class="muted">Sin datos para el filtro seleccionado.</td></tr>';
            return;
        }

        ivaBody.innerHTML = rows.map(function (row) {
            return '<tr>' +
                '<td>' + esc(number(row.rate)) + '%</td>' +
                '<td>' + esc(money(row.base)) + '</td>' +
                '<td>' + esc(money(row.iva)) + '</td>' +
                '<td>' + esc(money(row.total)) + '</td>' +
                '</tr>';
        }).join('');
    }

    function methodColor(method, index) {
        const palette = {
            efectivo: '#41a5c9',
            credito: '#e88755',
            transferencia: '#1f7a8c',
            mixto: '#9c6ade',
            tarjeta: '#16a34a'
        };
        if (palette[method]) {
            return palette[method];
        }
        const fallback = ['#2f6c8f', '#4b9cd3', '#d97706', '#7c3aed', '#0f766e', '#334155'];
        return fallback[index % fallback.length];
    }

    function escAttr(value) {
        return esc(value).replace(/"/g, '&quot;');
    }

    function renderStackedChart(summary) {
        if (!chartStackedNode || !chartStackedLegendNode || !metodoResumenBody) {
            metodoBody.innerHTML = '<tr><td colspan="4" class="muted">Sin datos para el filtro seleccionado.</td></tr>';
            return;
        }

        const methods = summary.methodRows.map(function (row) { return row.method; });
        const days = summary.dayMethodRows.slice(-7);

        if (!methods.length || !days.length) {
            chartStackedNode.innerHTML = '<p class="reportes-chart-empty">Sin datos para el filtro seleccionado.</p>';
            chartStackedLegendNode.innerHTML = '';
            metodoResumenBody.innerHTML = '<tr><td colspan="2" class="muted">Sin datos</td></tr>';
            return;
        }

        const width = 620;
        const height = 320;
        const padding = { top: 18, right: 16, bottom: 50, left: 56 };
        const chartW = width - padding.left - padding.right;
        const chartH = height - padding.top - padding.bottom;
        const dayGap = 18;
        const barWidth = Math.max(26, (chartW - ((days.length - 1) * dayGap)) / days.length);

        const dayTotals = days.map(function (day) {
            return methods.reduce(function (acc, method) {
                return acc + Number(day.methods[method] || 0);
            }, 0);
        });

        const maxTotal = dayTotals.reduce(function (acc, n) { return n > acc ? n : acc; }, 0) || 1;
        const steps = 5;
        const yTicks = [];
        for (let i = 0; i <= steps; i += 1) {
            const value = (maxTotal / steps) * i;
            const y = padding.top + chartH - ((chartH / steps) * i);
            yTicks.push({ value: value, y: y });
        }

        let barsSvg = '';
        days.forEach(function (day, dayIndex) {
            const x = padding.left + (dayIndex * (barWidth + dayGap));
            let accHeight = 0;

            methods.forEach(function (method, methodIndex) {
                const value = Number(day.methods[method] || 0);
                if (value <= 0) {
                    return;
                }
                const segH = (value / maxTotal) * chartH;
                const y = padding.top + chartH - accHeight - segH;
                const color = methodColor(method, methodIndex);
                barsSvg += '<rect x="' + x.toFixed(2) + '" y="' + y.toFixed(2) + '" width="' + barWidth.toFixed(2) + '" height="' + segH.toFixed(2) + '" fill="' + color + '"></rect>';
                accHeight += segH;
            });

            barsSvg += '<text x="' + (x + barWidth / 2).toFixed(2) + '" y="' + (height - 20) + '" text-anchor="middle" font-size="11" fill="#334155">' + esc(day.dayLabel) + '</text>';
        });

        let gridSvg = '';
        yTicks.forEach(function (tick) {
            gridSvg += '<line x1="' + padding.left + '" y1="' + tick.y.toFixed(2) + '" x2="' + (width - padding.right) + '" y2="' + tick.y.toFixed(2) + '" stroke="#e5e7eb" stroke-width="1"></line>';
            gridSvg += '<text x="' + (padding.left - 8) + '" y="' + (tick.y + 4).toFixed(2) + '" text-anchor="end" font-size="11" fill="#64748b">' + esc('$ ' + int(tick.value)) + '</text>';
        });

        chartStackedNode.innerHTML = '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="Ventas por forma de pago por día">' +
            gridSvg + barsSvg +
            '</svg>';

        chartStackedLegendNode.innerHTML = summary.methodRows.map(function (row, index) {
            const color = methodColor(row.method, index);
            return '<div class="reportes-legend-item">' +
                '<div class="reportes-legend-key"><span class="reportes-legend-swatch" style="background:' + color + '"></span><span class="reportes-legend-label">' + esc(getMethodLabel(row.method)) + '</span></div>' +
                '<span class="reportes-legend-value">' + esc(money(row.sales)) + '</span>' +
                '</div>';
        }).join('');

        metodoResumenBody.innerHTML = summary.methodRows.map(function (row) {
            return '<tr><td>' + esc(getMethodLabel(row.method)) + '</td><td>' + esc(money(row.sales)) + '</td></tr>';
        }).join('');
    }

    function arcPath(cx, cy, rOuter, rInner, startAngle, endAngle) {
        const cos = Math.cos;
        const sin = Math.sin;
        const sx = cx + rOuter * cos(startAngle);
        const sy = cy + rOuter * sin(startAngle);
        const ex = cx + rOuter * cos(endAngle);
        const ey = cy + rOuter * sin(endAngle);
        const six = cx + rInner * cos(endAngle);
        const siy = cy + rInner * sin(endAngle);
        const eix = cx + rInner * cos(startAngle);
        const eiy = cy + rInner * sin(startAngle);
        const largeArc = (endAngle - startAngle) > Math.PI ? 1 : 0;
        return [
            'M', sx, sy,
            'A', rOuter, rOuter, 0, largeArc, 1, ex, ey,
            'L', six, siy,
            'A', rInner, rInner, 0, largeArc, 0, eix, eiy,
            'Z'
        ].join(' ');
    }

    function renderDonutChart(node, legendNode, rows, options) {
        if (!node || !legendNode) {
            return;
        }

        const chartRows = Array.isArray(rows) ? rows.slice(0, options.limit || 7) : [];
        const total = chartRows.reduce(function (acc, row) {
            return acc + Number(options.value(row) || 0);
        }, 0);

        if (!chartRows.length || total <= 0) {
            node.innerHTML = '<p class="reportes-chart-empty">Sin datos para el filtro seleccionado.</p>';
            legendNode.innerHTML = '';
            return;
        }

        const w = 320;
        const h = 240;
        const cx = 120;
        const cy = 120;
        const rOuter = 82;
        const rInner = 44;

        let start = -Math.PI / 2;
        const segments = chartRows.map(function (row, idx) {
            const val = Number(options.value(row) || 0);
            const sweep = (val / total) * (Math.PI * 2);
            const end = start + sweep;
            const color = options.color(row, idx);
            const path = arcPath(cx, cy, rOuter, rInner, start, end);
            start = end;
            return '<path d="' + path + '" fill="' + color + '"></path>';
        }).join('');

        node.innerHTML = '<svg viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + escAttr(options.ariaLabel) + '">' +
            segments +
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + (rInner - 1) + '" fill="#fff"></circle>' +
            '<text x="' + cx + '" y="' + (cy - 4) + '" text-anchor="middle" font-size="11" fill="#64748b">Total</text>' +
            '<text x="' + cx + '" y="' + (cy + 14) + '" text-anchor="middle" font-size="14" font-weight="700" fill="#0f172a">' + esc(money(total)) + '</text>' +
            '</svg>';

        legendNode.innerHTML = chartRows.map(function (row, idx) {
            const color = options.color(row, idx);
            const val = Number(options.value(row) || 0);
            return '<div class="reportes-legend-item">' +
                '<div class="reportes-legend-key">' +
                '<span class="reportes-legend-swatch" style="background:' + color + '"></span>' +
                '<span class="reportes-legend-label" title="' + escAttr(options.label(row)) + '">' + esc(options.label(row)) + '</span>' +
                '</div>' +
                '<span class="reportes-legend-value">' + esc(options.format(val)) + '</span>' +
                '</div>';
        }).join('');
    }

    function renderDeptMiniTable(tbodyNode, rows, valueField) {
        if (!tbodyNode) {
            return;
        }

        const tableRows = Array.isArray(rows) ? rows.slice(0, 7) : [];
        if (!tableRows.length) {
            tbodyNode.innerHTML = '<tr><td colspan="2" class="muted">Sin datos</td></tr>';
            return;
        }

        tbodyNode.innerHTML = tableRows.map(function (row) {
            return '<tr><td>' + esc(row.department) + '</td><td>' + esc(money(row[valueField])) + '</td></tr>';
        }).join('');
    }

    function renderCharts(summary) {
        renderStackedChart(summary);

        const deptSalesRows = summary.deptRows.slice().sort(function (a, b) { return b.sales - a.sales; });
        const deptProfitRows = summary.deptRows.slice().sort(function (a, b) { return b.profit - a.profit; });

        renderDeptMiniTable(deptVentasMiniBody, deptSalesRows, 'sales');
        renderDonutChart(chartDeptSalesDonutNode, chartDeptSalesLegendNode, deptSalesRows, {
            ariaLabel: 'Ventas por departamento',
            limit: 7,
            label: function (row) { return row.department; },
            value: function (row) { return row.sales; },
            color: function (_row, idx) {
                const tones = ['#62c4ba', '#1d6f9e', '#2f90b7', '#3bb6ab', '#76a9d2', '#24557a', '#4f8fb0'];
                return tones[idx % tones.length];
            },
            format: function (value) { return money(value); }
        });

        renderDeptMiniTable(deptGananciaMiniBody, deptProfitRows, 'profit');
        renderDonutChart(chartDeptProfitDonutNode, chartDeptProfitLegendNode, deptProfitRows, {
            ariaLabel: 'Ganancia por departamento',
            limit: 7,
            label: function (row) { return row.department; },
            value: function (row) { return row.profit; },
            color: function (_row, idx) {
                const tones = ['#9e4d7b', '#7c153f', '#9aa8c1', '#525b6b', '#283556', '#a60f3b', '#6f86a4'];
                return tones[idx % tones.length];
            },
            format: function (value) { return money(value); }
        });
    }

    function populateCajas() {
        const existing = new Set(['all']);
        const current = state.caja;
        const users = [];

        state.sales.forEach(function (sale) {
            const val = String(sale.cashier || sale.user || sale.openedBy || '').trim();
            if (val && !existing.has(val)) {
                existing.add(val);
                users.push(val);
            }
        });

        users.sort(function (a, b) {
            return a.localeCompare(b, 'es');
        });

        cajaSelect.innerHTML = '<option value="all">Todas</option>' + users.map(function (u) {
            return '<option value="' + esc(u) + '">' + esc(u) + '</option>';
        }).join('');

        if (existing.has(current)) {
            cajaSelect.value = current;
        } else {
            state.caja = 'all';
            cajaSelect.value = 'all';
        }
    }

    function render() {
        const rangeSales = applyRangeFilter(state.sales);
        const filtered = applyCajaFilter(rangeSales);
        const summary = aggregate(filtered, state.products, state.customers);

        renderKpis(summary);
        renderMetodo(summary.methodRows);
        renderDepartamentos(summary.deptRows);
        renderClientes(summary.customerRows);
        renderIva(summary.ivaRows);
        renderCharts(summary);

    }

    function bindEvents() {
        rangeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const selected = btn.getAttribute('data-range') || 'week';
                state.range = selected;
                rangeButtons.forEach(function (candidate) {
                    candidate.classList.toggle('active', candidate === btn);
                });
                render();
            });
        });

        cajaSelect.addEventListener('change', function () {
            state.caja = cajaSelect.value || 'all';
            render();
        });
    }

    async function init() {
        try {
            const payload = await Promise.all([
                fetchJson('../api/sales.php'),
                fetchJson('../api/products.php'),
                fetchJson('../api/customers.php')
            ]);

            state.sales = toArray(payload[0]);
            state.products = toArray(payload[1]);
            state.customers = toArray(payload[2]);

            populateCajas();
            bindEvents();
            render();
        } catch (err) {
            console.error(err);
            kpisNode.innerHTML = '<article class="reportes-error">No se pudieron cargar los reportes. Verifica la conexión con la API.</article>';
            metodoBody.innerHTML = '<tr><td colspan="4" class="muted">Sin datos</td></tr>';
            deptoBody.innerHTML = '<tr><td colspan="6" class="muted">Sin datos</td></tr>';
            clienteBody.innerHTML = '<tr><td colspan="5" class="muted">Sin datos</td></tr>';
            ivaBody.innerHTML = '<tr><td colspan="4" class="muted">Sin datos</td></tr>';
            if (chartStackedNode) chartStackedNode.innerHTML = '<p class="reportes-chart-empty">Sin datos</p>';
            if (chartStackedLegendNode) chartStackedLegendNode.innerHTML = '';
            if (chartDeptSalesDonutNode) chartDeptSalesDonutNode.innerHTML = '<p class="reportes-chart-empty">Sin datos</p>';
            if (chartDeptSalesLegendNode) chartDeptSalesLegendNode.innerHTML = '';
            if (chartDeptProfitDonutNode) chartDeptProfitDonutNode.innerHTML = '<p class="reportes-chart-empty">Sin datos</p>';
            if (chartDeptProfitLegendNode) chartDeptProfitLegendNode.innerHTML = '';
            if (metodoResumenBody) metodoResumenBody.innerHTML = '<tr><td colspan="2" class="muted">Sin datos</td></tr>';
            if (deptVentasMiniBody) deptVentasMiniBody.innerHTML = '<tr><td colspan="2" class="muted">Sin datos</td></tr>';
            if (deptGananciaMiniBody) deptGananciaMiniBody.innerHTML = '<tr><td colspan="2" class="muted">Sin datos</td></tr>';
        }
    }

    init();
})();
