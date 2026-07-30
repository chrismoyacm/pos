(function () {
    const root = document.getElementById('config-module');
    if (!root) {
        return;
    }

    const navButtons = Array.from(root.querySelectorAll('.config-nav'));
    const panels = Array.from(root.querySelectorAll('.config-panel'));
    const statusNode = document.getElementById('cfg-status');
    const taxStatusNode = document.getElementById('cfg-tax-status');

    const usersTableBody = document.querySelector('#cfg-users-table tbody');
    const searchInput = document.getElementById('cfg-user-search');
    const permissionTabs = Array.from(root.querySelectorAll('.config-perm-tab'));
    const permissionPanels = Array.from(root.querySelectorAll('.config-perm-panel'));
    const ticketPreviewNode = document.getElementById('cfg-ticket-preview');
    const readerTestInput = document.getElementById('cfg-reader-test-input');
    const readerTestStatus = document.getElementById('cfg-reader-test-status');
    const readerTestSpeed = document.getElementById('cfg-reader-test-speed');
    const readerTestLast = document.getElementById('cfg-reader-test-last');
    const readerTestLogBody = document.querySelector('#cfg-reader-test-log tbody');

    const state = {
        settings: null,
        users: [],
        currentUser: null,
        selectedUserId: 0,
        userFilter: '',
        taxDraftOptions: [],
        taxDraftDefaultRate: 0,
        taxDraftIncludeNew: false,
        taxEditIndex: -1,
        scannerProbe: {
            buffer: '',
            deltas: [],
            lastTs: 0,
            timer: null,
        },
    };

    function normalizeTaxOption(option) {
        if (!option || typeof option !== 'object') {
            return null;
        }
        const rate = Number(option.percentage || 0);
        if (!Number.isFinite(rate) || rate < 0) {
            return null;
        }
        const label = (option.name || '').toString().trim() || ('IVA ' + rate + '%');
        return {
            id: (option.id || '').toString(),
            name: label,
            percentage: rate,
            active: option.active !== false,
        };
    }

    function taxRateToNumber(value) {
        const parsed = Number(value || 0);
        if (!Number.isFinite(parsed) || parsed < 0) {
            return 0;
        }
        return Math.round(parsed * 10000) / 10000;
    }

    function taxRatesEqual(a, b) {
        return Math.abs(Number(a || 0) - Number(b || 0)) < 0.0001;
    }

    function taxNameWithoutRate(label) {
        return (label || '').toString().replace(/\s*\d+([.,]\d+)?\s*%$/i, '').trim();
    }

    function formatTaxRateLabel(rate) {
        const num = Number(rate || 0);
        return Number.isInteger(num) ? String(num) : String(num.toFixed(2)).replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
    }

    function taxOptionLabel(option) {
        const baseName = taxNameWithoutRate(option && option.name ? option.name : 'IVA') || 'IVA';
        return baseName + ' ' + formatTaxRateLabel(option && option.percentage ? option.percentage : 0) + '%';
    }

    function renderTaxEditor(option, index) {
        const opt = option || null;
        const rate = taxRateToNumber(opt ? opt.percentage : 0);
        $('#cfg-tax-edit-index').val(String(typeof index === 'number' ? index : -1));
        $('#cfg-tax-name').val(opt ? taxNameWithoutRate(opt.name || 'IVA') : 'IVA');
        $('#cfg-tax-rate').val(opt ? rate : '');
        $('#cfg-tax-include-new').prop('checked', !!(opt && state.taxDraftIncludeNew && taxRatesEqual(state.taxDraftDefaultRate, rate)));
        $('#cfg-tax-add-btn').text((typeof index === 'number' && index >= 0) ? 'Guardar cambios' : 'Guardar impuesto');
        state.taxEditIndex = typeof index === 'number' ? index : -1;
    }

    function renderTaxesTable() {
        const $body = $('#cfg-tax-list-body').empty();
        if (!Array.isArray(state.taxDraftOptions) || state.taxDraftOptions.length === 0) {
            $body.append('<tr><td colspan="5" class="cfg-tax-empty">No hay impuestos registrados.</td></tr>');
            return;
        }

        state.taxDraftOptions.forEach(function (option, index) {
            const rate = taxRateToNumber(option.percentage);
            const isDefault = !!state.taxDraftIncludeNew && taxRatesEqual(state.taxDraftDefaultRate, rate);
            $body.append(
                '<tr>' +
                    '<td>' + escapeHtml(taxOptionLabel(option)) + '</td>' +
                    '<td>' + escapeHtml(formatTaxRateLabel(rate)) + '%</td>' +
                    '<td>' + (isDefault ? '<span class="cfg-tax-default-badge">Si</span>' : 'No') + '</td>' +
                    '<td>' + (option.active === false ? 'Inactivo' : 'Activo') + '</td>' +
                    '<td>' +
                        '<div class="cfg-tax-actions">' +
                            '<button type="button" class="btn-secondary cfg-tax-edit-btn" data-tax-idx="' + index + '">Modificar</button>' +
                            '<button type="button" class="btn-danger cfg-tax-delete-btn" data-tax-idx="' + index + '">Eliminar</button>' +
                        '</div>' +
                    '</td>' +
                '</tr>'
            );
        });
    }

    function loadTaxesDraftFromSettings(taxes) {
        const taxOptions = Array.isArray(taxes && taxes.iva_options)
            ? taxes.iva_options.map(normalizeTaxOption).filter(Boolean)
            : [];
        state.taxDraftOptions = taxOptions;
        state.taxDraftDefaultRate = taxRateToNumber(taxes && taxes.default_vat ? taxes.default_vat : 0);
        state.taxDraftIncludeNew = !!(taxes && taxes.included_new_products);
        state.taxEditIndex = -1;
        renderTaxEditor(null, -1);
        renderTaxesTable();
    }

    async function upsertTaxFromEditor(autoPersist) {
        const shouldPersist = autoPersist !== false;
        const nameRaw = ($('#cfg-tax-name').val() || '').toString().trim();
        const rate = taxRateToNumber($('#cfg-tax-rate').val() || 0);
        const useDefault = $('#cfg-tax-include-new').is(':checked');
        const editIndex = Number($('#cfg-tax-edit-index').val() || -1);

        if (!nameRaw) {
            setTaxStatus('El nombre del impuesto es requerido.', true);
            return false;
        }
        if (rate < 0 || rate > 100) {
            setTaxStatus('El porcentaje de impuesto debe estar entre 0 y 100.', true);
            return false;
        }

        const cleanName = taxNameWithoutRate(nameRaw) || 'IVA';

        const option = {
            id: '',
            name: cleanName + ' ' + formatTaxRateLabel(rate) + '%',
            percentage: rate,
            active: true,
        };

        const duplicateIndex = state.taxDraftOptions.findIndex(function (opt, idx) {
            if (editIndex >= 0 && idx === editIndex) return false;
            return taxRatesEqual(opt.percentage, rate);
        });

        if (editIndex >= 0 && editIndex < state.taxDraftOptions.length) {
            const currentId = (state.taxDraftOptions[editIndex].id || '').toString();
            state.taxDraftOptions[editIndex] = $.extend({}, option, { id: currentId });
        } else if (duplicateIndex >= 0) {
            const currentId = (state.taxDraftOptions[duplicateIndex].id || '').toString();
            state.taxDraftOptions[duplicateIndex] = $.extend({}, option, { id: currentId });
        } else {
            state.taxDraftOptions.push(option);
        }

        if (useDefault) {
            state.taxDraftDefaultRate = rate;
            state.taxDraftIncludeNew = true;
        } else if (state.taxDraftIncludeNew && taxRatesEqual(state.taxDraftDefaultRate, rate)) {
            state.taxDraftIncludeNew = false;
            state.taxDraftDefaultRate = 0;
        }

        renderTaxesTable();
        renderTaxEditor(null, -1);
        if (shouldPersist) {
            const ok = await saveSettingsSection('taxes', {
                useGlobalStatus: false,
                savingMessage: editIndex >= 0 ? 'Guardando cambios del impuesto...' : 'Guardando impuesto...',
                onError: function (err) {
                    setTaxStatus(err && err.message ? err.message : 'No se pudo guardar el impuesto.', true);
                }
            });
            if (ok) {
                setTaxStatus(editIndex >= 0 ? 'Impuesto actualizado correctamente.' : 'Impuesto guardado correctamente.', false);
            }
        } else {
            setTaxStatus(editIndex >= 0 ? 'Impuesto actualizado correctamente.' : 'Impuesto agregado correctamente.', false);
        }
        return true;
    }

    async function deleteTaxByIndex(index, autoPersist) {
        const shouldPersist = autoPersist !== false;
        const idx = Number(index);
        if (!(idx >= 0) || idx >= state.taxDraftOptions.length) {
            setTaxStatus('No se pudo identificar el impuesto a eliminar.', true);
            return false;
        }

        const removed = state.taxDraftOptions[idx];
        const removedLabel = taxOptionLabel(removed);
        if (!window.confirm('Eliminar el impuesto "' + removedLabel + '"?')) {
            setTaxStatus('Eliminacion cancelada.', false);
            return false;
        }
        const removedRate = taxRateToNumber(removed && removed.percentage ? removed.percentage : 0);
        state.taxDraftOptions.splice(idx, 1);

        if (state.taxDraftIncludeNew && taxRatesEqual(state.taxDraftDefaultRate, removedRate)) {
            if (state.taxDraftOptions.length > 0) {
                state.taxDraftDefaultRate = taxRateToNumber(state.taxDraftOptions[0].percentage);
            } else {
                state.taxDraftDefaultRate = 0;
                state.taxDraftIncludeNew = false;
            }
        }

        if (state.taxDraftOptions.length === 0) {
            state.taxDraftIncludeNew = false;
            state.taxDraftDefaultRate = 0;
        }

        renderTaxesTable();
        renderTaxEditor(null, -1);
        if (shouldPersist) {
            const ok = await saveSettingsSection('taxes', {
                useGlobalStatus: false,
                savingMessage: 'Guardando eliminacion del impuesto...',
                onError: function (err) {
                    setTaxStatus(err && err.message ? err.message : 'No se pudo eliminar el impuesto.', true);
                }
            });
            if (ok) {
                setTaxStatus('Impuesto eliminado correctamente.', false);
            }
        } else {
            setTaxStatus('Impuesto eliminado correctamente.', false);
        }
        return true;
    }

    function setStatus(message, isError) {
        if (!statusNode) {
            return;
        }
        statusNode.textContent = message || '';
        statusNode.style.color = isError ? '#b91c1c' : '#0f766e';
    }

    function setTaxStatus(message, isError) {
        if (!taxStatusNode) {
            return;
        }
        taxStatusNode.textContent = message || '';
        taxStatusNode.style.color = isError ? '#b91c1c' : '#0f766e';
    }

    async function apiGet(action) {
        const res = await fetch('../api/configuracion.php?action=' + encodeURIComponent(action), { cache: 'no-store' });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'No se pudo cargar la configuración');
        }
        return data.data || {};
    }

    async function apiPost(action, payload) {
        const res = await fetch('../api/configuracion.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {}),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'No se pudo guardar la configuración');
        }
        return data.data || {};
    }

    function getPermission(key) {
        const perms = state.currentUser && state.currentUser.permissions ? state.currentUser.permissions : {};
        return !!perms[key] || (state.currentUser && state.currentUser.role === 'admin');
    }

    function panelPermission(view) {
        const map = {
            opciones_habilitadas: 'config_options_enabled',
            cajeros: 'config_cashiers',
            modificar_folios: 'config_modify_folios',
            administrar_cajas: 'config_manage_boxes',
            logo_programa: 'config_logo',
            ticket: 'config_ticket',
            factura: 'otros_access_facturas',
            impuestos: 'config_taxes',
            corte: 'config_corte',
            unidades_medida: 'config_units',
            impresora_tickets: 'config_ticket_printer',
            lector_codigo: 'config_barcode_reader',
        };
        const key = map[view];
        return key ? getPermission(key) : false;
    }

    function switchPermissionTab(tabName) {
        permissionTabs.forEach(function (tab) {
            const active = tab.getAttribute('data-perm-tab') === tabName;
            tab.classList.toggle('active', active);
        });

        permissionPanels.forEach(function (panel) {
            const active = panel.getAttribute('data-perm-panel') === tabName;
            panel.classList.toggle('active', active);
        });
    }

    function switchPanel(view) {
        navButtons.forEach(function (btn) {
            const active = btn.getAttribute('data-view') === view;
            btn.classList.toggle('active', active);
        });

        panels.forEach(function (panel) {
            const active = panel.getAttribute('data-panel') === view;
            panel.classList.toggle('active', active);
        });
    }

    function enforcePermissionVisibility() {
        navButtons.forEach(function (btn) {
            const view = btn.getAttribute('data-view') || '';
            const allowed = panelPermission(view);
            btn.style.display = allowed ? '' : 'none';
        });

        const firstAllowed = navButtons.find(function (btn) {
            const view = btn.getAttribute('data-view') || '';
            return panelPermission(view);
        });

        if (firstAllowed) {
            switchPanel(firstAllowed.getAttribute('data-view') || 'opciones_habilitadas');
        } else {
            setStatus('No tienes permisos para acceder a Configuración.', true);
        }
    }

    function fillSettingsForm() {
        const settings = state.settings || {};
        const enabled = settings.enabledOptions || {};
        const folios = settings.folios || {};
        const boxes = settings.boxes || {};
        const branding = settings.branding || {};
        const ticket = settings.ticket || {};
        const taxes = settings.taxes || {};
        const corte = settings.corte || {};
        const units = settings.units || {};
        const devices = settings.devices || {};

        $('#cfg-inventory-control').prop('checked', !!enabled.inventory_control);
        $('#cfg-offer-credit').prop('checked', !!enabled.offer_credit);
        $('#cfg-common-product').prop('checked', !!enabled.allow_common_product);
        $('#cfg-auto-price').prop('checked', !!enabled.auto_sale_price);
        $('#cfg-auto-margin').val(Number(enabled.auto_sale_margin || 0));

        $('#cfg-folio-invoice-prefix').val(folios.invoice_prefix || '');
        $('#cfg-folio-next-invoice').val(Number(folios.next_invoice || 1));
        $('#cfg-folio-credit-prefix').val(folios.credit_note_prefix || '');
        $('#cfg-folio-next-credit').val(Number(folios.next_credit_note || 1));

        $('#cfg-box-require-opening').prop('checked', !!boxes.require_opening);
        $('#cfg-box-close-diff').prop('checked', !!boxes.allow_close_with_difference);
        $('#cfg-box-drawer-printer-model').val((boxes.drawer_printer_model || '').toString());
        $('#cfg-box-drawer-connection').val((boxes.drawer_connection || 'USB').toString());

        $('#cfg-brand-store-name').val(branding.store_name || '');
        $('#cfg-brand-logo-text').val(branding.logo_text || '');

        $('#cfg-ticket-header').val(ticket.header || '');
        $('#cfg-ticket-footer').val(ticket.footer || '');
        $('#cfg-ticket-show-customer').prop('checked', !!ticket.show_customer);
        $('#cfg-ticket-include-unit-price').prop('checked', ticket.include_unit_price !== false);
        $('#cfg-ticket-full-description').prop('checked', !!ticket.full_description);
        $('#cfg-ticket-extra-top').val(ticket.extra_top_line || '');
        $('#cfg-ticket-extra-bottom').val(ticket.extra_bottom_line || '');
        $('#cfg-ticket-logo-url').val(ticket.logo_url || '');

        loadTaxesDraftFromSettings(taxes);
        $('#cfg-tax-enabled').prop('checked', taxes.enabled !== false);
        $('#cfg-tax-country').val(taxes.country || 'EC');
        $('#cfg-tax-breakdown-ticket').prop('checked', taxes.breakdown_on_ticket !== false);
        $('#cfg-tax-prices-include').prop('checked', !!taxes.prices_include_taxes);
        $('#cfg-tax-withholding-mode').val(taxes.withholding_mode || 'none');

        $('#cfg-corte-negative').prop('checked', !!corte.allow_negative_close);
        $('#cfg-corte-print').prop('checked', !!corte.print_summary);

        $('#cfg-unit-hmin').prop('checked', Array.isArray(units.enabled_list) ? units.enabled_list.indexOf('H/MIN') >= 0 : false);
        $('#cfg-unit-kgg').prop('checked', Array.isArray(units.enabled_list) ? units.enabled_list.indexOf('KG/G') >= 0 : false);
        $('#cfg-unit-lml').prop('checked', Array.isArray(units.enabled_list) ? units.enabled_list.indexOf('L/ML') >= 0 : false);
        $('#cfg-unit-mcm').prop('checked', Array.isArray(units.enabled_list) ? units.enabled_list.indexOf('M/CM') >= 0 : false);
        $('#cfg-unit-na').prop('checked', Array.isArray(units.enabled_list) ? units.enabled_list.indexOf('NO_APLICA') >= 0 : false);
        $('#cfg-unit-pza').prop('checked', !Array.isArray(units.enabled_list) || units.enabled_list.indexOf('PZA') >= 0);
        $('#cfg-units-default').val(units.default_unit || 'PZA');
        $('#cfg-units-decimal').prop('checked', !!units.allow_decimal);

        const printer = devices.ticket_printer || {};
        $('#cfg-printer-enabled').prop('checked', !!printer.enabled);
        $('#cfg-printer-model').val((printer.model || printer.name || 'POS-80C').toString());
        $('#cfg-printer-connection').val((printer.connection || 'USB').toString());
        $('#cfg-printer-name').val(printer.name || '');
        $('#cfg-printer-font-family').val(printer.font_family || 'Consolas');
        $('#cfg-printer-font-size').val(Number(printer.font_size || 10));
        $('#cfg-printer-columns').val(Number(printer.columns || 45));
        $('#cfg-printer-use-normal-totals').prop('checked', !!printer.use_normal_for_totals);
        $('#cfg-printer-bold-letters').prop('checked', printer.bold_letters !== false);

        const reader = devices.barcode_reader || {};
        $('#cfg-reader-enabled').prop('checked', !!reader.enabled);
        $('#cfg-reader-model').val((reader.model || 'SU13').toString());
        $('#cfg-reader-name').val(reader.name || '');
        $('#cfg-reader-suffix-key').val((reader.suffix_key || 'ENTER').toString());
        $('#cfg-reader-serial-enabled').prop('checked', !!reader.serial_enabled);
        $('#cfg-reader-serial-port').val((reader.serial_port || '').toString());
        $('#cfg-reader-serial-baud').val(String(reader.serial_baud || 9600));
        updateReaderSerialVisibility();

        renderTicketPreview();
    }

    function collectSettings() {
        const editorName = ($('#cfg-tax-name').val() || '').toString().trim();
        const taxOptions = Array.isArray(state.taxDraftOptions) ? state.taxDraftOptions.slice() : [];
        const selectedRate = state.taxDraftIncludeNew ? taxRateToNumber(state.taxDraftDefaultRate) : 0;
        const defaultOption = taxOptions.find(function (opt) {
            return taxRatesEqual(opt.percentage, selectedRate);
        }) || null;
        const selectedName = taxNameWithoutRate((defaultOption && defaultOption.name) || editorName || 'IVA') || 'IVA';
        const selectedInclude = !!state.taxDraftIncludeNew;

        return {
            enabledOptions: {
                inventory_control: $('#cfg-inventory-control').is(':checked'),
                offer_credit: $('#cfg-offer-credit').is(':checked'),
                allow_common_product: $('#cfg-common-product').is(':checked'),
                auto_sale_price: $('#cfg-auto-price').is(':checked'),
                auto_sale_margin: Number($('#cfg-auto-margin').val() || 0),
            },
            folios: {
                invoice_prefix: ($('#cfg-folio-invoice-prefix').val() || '').toString().trim(),
                next_invoice: Math.max(1, Number($('#cfg-folio-next-invoice').val() || 1)),
                credit_note_prefix: ($('#cfg-folio-credit-prefix').val() || '').toString().trim(),
                next_credit_note: Math.max(1, Number($('#cfg-folio-next-credit').val() || 1)),
            },
            boxes: {
                require_opening: $('#cfg-box-require-opening').is(':checked'),
                allow_close_with_difference: $('#cfg-box-close-diff').is(':checked'),
                drawer_printer_model: ($('#cfg-box-drawer-printer-model').val() || '').toString().trim(),
                drawer_connection: ($('#cfg-box-drawer-connection').val() || 'USB').toString().trim(),
            },
            branding: {
                store_name: ($('#cfg-brand-store-name').val() || '').toString().trim(),
                logo_text: ($('#cfg-brand-logo-text').val() || '').toString().trim(),
            },
            ticket: {
                header: ($('#cfg-ticket-header').val() || '').toString().trim(),
                footer: ($('#cfg-ticket-footer').val() || '').toString().trim(),
                show_customer: $('#cfg-ticket-show-customer').is(':checked'),
                include_unit_price: $('#cfg-ticket-include-unit-price').is(':checked'),
                full_description: $('#cfg-ticket-full-description').is(':checked'),
                extra_top_line: ($('#cfg-ticket-extra-top').val() || '').toString().trim(),
                extra_bottom_line: ($('#cfg-ticket-extra-bottom').val() || '').toString().trim(),
                logo_url: ($('#cfg-ticket-logo-url').val() || '').toString().trim(),
            },
            taxes: {
                vat_name: selectedName,
                default_vat: selectedRate,
                enabled: $('#cfg-tax-enabled').is(':checked'),
                country: ($('#cfg-tax-country').val() || 'EC').toString(),
                included_new_products: selectedInclude,
                breakdown_on_ticket: $('#cfg-tax-breakdown-ticket').is(':checked'),
                prices_include_taxes: $('#cfg-tax-prices-include').is(':checked'),
                withholding_mode: ($('#cfg-tax-withholding-mode').val() || 'none').toString(),
                iva_options: taxOptions,
            },
            corte: {
                allow_negative_close: $('#cfg-corte-negative').is(':checked'),
                print_summary: $('#cfg-corte-print').is(':checked'),
            },
            units: {
                enabled_list: [
                    $('#cfg-unit-hmin').is(':checked') ? 'H/MIN' : null,
                    $('#cfg-unit-kgg').is(':checked') ? 'KG/G' : null,
                    $('#cfg-unit-lml').is(':checked') ? 'L/ML' : null,
                    $('#cfg-unit-mcm').is(':checked') ? 'M/CM' : null,
                    $('#cfg-unit-na').is(':checked') ? 'NO_APLICA' : null,
                    $('#cfg-unit-pza').is(':checked') ? 'PZA' : null,
                ].filter(Boolean),
                default_unit: ($('#cfg-units-default').val() || 'PZA').toString().trim(),
                allow_decimal: $('#cfg-units-decimal').is(':checked'),
            },
            devices: {
                ticket_printer: {
                    enabled: $('#cfg-printer-enabled').is(':checked'),
                    model: ($('#cfg-printer-model').val() || 'POS-80C').toString().trim(),
                    connection: ($('#cfg-printer-connection').val() || 'USB').toString().trim(),
                    name: ($('#cfg-printer-name').val() || '').toString().trim(),
                    font_family: ($('#cfg-printer-font-family').val() || 'Consolas').toString().trim(),
                    font_size: Number($('#cfg-printer-font-size').val() || 10),
                    columns: Number($('#cfg-printer-columns').val() || 45),
                    use_normal_for_totals: $('#cfg-printer-use-normal-totals').is(':checked'),
                    bold_letters: $('#cfg-printer-bold-letters').is(':checked'),
                },
                barcode_reader: {
                    enabled: $('#cfg-reader-enabled').is(':checked'),
                    model: ($('#cfg-reader-model').val() || 'SU13').toString().trim(),
                    name: ($('#cfg-reader-name').val() || '').toString().trim(),
                    suffix_key: ($('#cfg-reader-suffix-key').val() || 'ENTER').toString().trim(),
                    serial_enabled: $('#cfg-reader-serial-enabled').is(':checked'),
                    serial_port: ($('#cfg-reader-serial-port').val() || '').toString().trim(),
                    serial_baud: Number($('#cfg-reader-serial-baud').val() || 9600),
                },
            },
        };
    }

    function updateReaderSerialVisibility() {
        const enabled = $('#cfg-reader-serial-enabled').is(':checked');
        const serialPort = $('#cfg-reader-serial-port').closest('label');
        const serialBaud = $('#cfg-reader-serial-baud').closest('label');

        if (enabled) {
            serialPort.show();
            serialBaud.show();
        } else {
            serialPort.hide();
            serialBaud.hide();
        }
    }

    function getSelectedUser() {
        return state.users.find(function (u) { return Number(u.id) === Number(state.selectedUserId); }) || null;
    }

    function scannerReset() {
        if (state.scannerProbe.timer) {
            window.clearTimeout(state.scannerProbe.timer);
        }
        state.scannerProbe.buffer = '';
        state.scannerProbe.deltas = [];
        state.scannerProbe.lastTs = 0;
        state.scannerProbe.timer = null;
    }

    function scannerSetStatus(text, isError) {
        if (readerTestStatus) {
            readerTestStatus.textContent = text;
            readerTestStatus.style.color = isError ? '#b91c1c' : '#1f3b6b';
        }
    }

    function scannerAppendLog(entry) {
        if (!readerTestLogBody) {
            return;
        }

        const row = '<tr>' +
            '<td>' + escapeHtml(entry.time) + '</td>' +
            '<td>' + escapeHtml(entry.code) + '</td>' +
            '<td>' + entry.length + '</td>' +
            '<td>' + entry.avgMs + '</td>' +
            '<td>' + escapeHtml(entry.classification) + '</td>' +
            '</tr>';

        readerTestLogBody.insertAdjacentHTML('afterbegin', row);
    }

    function scannerFinalize(reason) {
        const code = state.scannerProbe.buffer.trim();
        if (!code) {
            scannerReset();
            return;
        }

        const deltas = state.scannerProbe.deltas;
        const avg = deltas.length ? (deltas.reduce(function (acc, v) { return acc + v; }, 0) / deltas.length) : 0;
        const looksScanner = code.length >= 6 && avg > 0 && avg <= 45;
        const classification = looksScanner ? 'Lector (probable)' : 'Manual / indeterminado';

        if (readerTestSpeed) {
            readerTestSpeed.textContent = avg > 0 ? ('Promedio: ' + avg.toFixed(1) + ' ms/tecla') : '';
        }
        if (readerTestLast) {
            readerTestLast.textContent = 'Último código (' + reason + '): ' + code;
        }

        scannerSetStatus(looksScanner ? 'Lectura detectada correctamente.' : 'Lectura lenta: parece ingreso manual.', !looksScanner);
        scannerAppendLog({
            time: new Date().toLocaleTimeString('es-EC'),
            code: code,
            length: code.length,
            avgMs: avg > 0 ? avg.toFixed(1) : '-',
            classification: classification,
        });

        scannerReset();
        if (readerTestInput) {
            readerTestInput.value = '';
        }
    }

    function initScannerProbe() {
        if (!readerTestInput) {
            return;
        }

        scannerSetStatus('Esperando lectura...', false);

        readerTestInput.addEventListener('keydown', function (event) {
            const now = window.performance ? window.performance.now() : Date.now();

            if (event.key === 'Enter') {
                event.preventDefault();
                scannerFinalize('enter');
                return;
            }

            if (event.key === 'Tab') {
                scannerFinalize('tab');
                return;
            }

            if (event.key === 'Backspace') {
                state.scannerProbe.buffer = state.scannerProbe.buffer.slice(0, -1);
                return;
            }

            if (event.key.length === 1) {
                if (state.scannerProbe.lastTs > 0) {
                    state.scannerProbe.deltas.push(now - state.scannerProbe.lastTs);
                }
                state.scannerProbe.lastTs = now;
                state.scannerProbe.buffer += event.key;

                if (state.scannerProbe.timer) {
                    window.clearTimeout(state.scannerProbe.timer);
                }
                state.scannerProbe.timer = window.setTimeout(function () {
                    scannerFinalize('timeout');
                }, 90);
            }
        });

        $('#cfg-reader-start-test').on('click', function () {
            scannerReset();
            if (readerTestInput) {
                readerTestInput.value = '';
                readerTestInput.focus();
            }
            if (readerTestLast) {
                readerTestLast.textContent = '';
            }
            if (readerTestSpeed) {
                readerTestSpeed.textContent = '';
            }
            scannerSetStatus('Prueba iniciada. Escanea un código...', false);
        });

        $('#cfg-reader-clear-test').on('click', function () {
            scannerReset();
            if (readerTestInput) {
                readerTestInput.value = '';
            }
            if (readerTestLogBody) {
                readerTestLogBody.innerHTML = '';
            }
            if (readerTestLast) {
                readerTestLast.textContent = '';
            }
            if (readerTestSpeed) {
                readerTestSpeed.textContent = '';
            }
            scannerSetStatus('Esperando lectura...', false);
        });
    }

    function renderTicketPreview() {
        if (!ticketPreviewNode) {
            return;
        }

        const header = ($('#cfg-ticket-header').val() || 'Gracias por su compra').toString();
        const footer = ($('#cfg-ticket-footer').val() || 'Vuelva pronto').toString();
        const extraTop = ($('#cfg-ticket-extra-top').val() || '').toString();
        const extraBottom = ($('#cfg-ticket-extra-bottom').val() || '').toString();
        const includePrice = $('#cfg-ticket-include-unit-price').is(':checked');
        const fullDescription = $('#cfg-ticket-full-description').is(':checked');
        const showCustomer = $('#cfg-ticket-show-customer').is(':checked');
        const logoUrl = ($('#cfg-ticket-logo-url').val() || '').toString().trim();

        const line1 = fullDescription ? 'AGUA CIELO 600ml (BOTELLA)' : 'AGUA CIELO';
        const line2 = fullDescription ? 'COCA COLA LIGHT 500ml' : 'COCA LIGHT';

        const rows = [
            '<div class="config-ticket-line config-ticket-center">POS MINIMARKET</div>',
            '<div class="config-ticket-line config-ticket-center">SEIVA ALFEREZ - OTAVALO</div>',
            '<div class="config-ticket-sep"></div>',
            extraTop ? '<div class="config-ticket-line config-ticket-center">' + escapeHtml(extraTop) + '</div>' : '',
            '<div class="config-ticket-line"><span>Cant.</span><span>Descripcion</span><span>Importe</span></div>',
            '<div class="config-ticket-sep"></div>',
            '<div class="config-ticket-line"><span>1</span><span>' + escapeHtml(line1) + '</span><span>' + (includePrice ? '$ 0.70' : '$ 0.70') + '</span></div>',
            '<div class="config-ticket-line"><span>2</span><span>' + escapeHtml(line2) + '</span><span>' + (includePrice ? '$ 1.00' : '$ 1.00') + '</span></div>',
            showCustomer ? '<div class="config-ticket-line config-ticket-center">Cliente: Consumidor final</div>' : '',
            '<div class="config-ticket-sep"></div>',
            '<div class="config-ticket-line"><span></span><span>Total</span><span>$ 1.70</span></div>',
            '<div class="config-ticket-sep"></div>',
            '<div class="config-ticket-line config-ticket-center">' + escapeHtml(header || ' ') + '</div>',
            '<div class="config-ticket-line config-ticket-center">' + escapeHtml(footer || ' ') + '</div>',
            extraBottom ? '<div class="config-ticket-line config-ticket-center">' + escapeHtml(extraBottom) + '</div>' : '',
        ].filter(Boolean);

        const logoBlock = logoUrl ? '<div class="config-ticket-logo"><img src="' + escapeHtml(logoUrl) + '" alt="logo"></div>' : '';
        ticketPreviewNode.innerHTML = logoBlock + rows.join('');
    }

    function printTicketPreview() {
        if (!ticketPreviewNode) {
            return;
        }

        const win = window.open('', '_blank', 'width=420,height=700');
        if (!win) {
            setStatus('No se pudo abrir la ventana de impresión', true);
            return;
        }

        win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Ticket de prueba</title><style>body{font-family:monospace;padding:12px}.ticket{max-width:300px;margin:0 auto}.line{display:flex;justify-content:space-between;gap:8px;font-size:12px}.center{text-align:center}.sep{border-top:1px dashed #555;margin:6px 0}.logo{text-align:center;margin-bottom:8px}.logo img{max-width:140px;max-height:60px;object-fit:contain}</style></head><body><div class="ticket">' + ticketPreviewNode.innerHTML.replace(/config-ticket-line/g, 'line').replace(/config-ticket-center/g, 'center').replace(/config-ticket-sep/g, 'sep').replace(/config-ticket-logo/g, 'logo') + '</div></body></html>');
        win.document.close();
        win.focus();
        win.print();
    }

    function testDrawerOpen() {
        const printerEnabled = $('#cfg-printer-enabled').is(':checked');
        const drawerStatus = document.getElementById('cfg-box-drawer-status');

        if (!drawerStatus) {
            return;
        }

        if (!printerEnabled) {
            drawerStatus.textContent = 'Activa la impresora de tickets antes de probar el cajon.';
            drawerStatus.style.color = '#b91c1c';
            return;
        }

        drawerStatus.textContent = 'Se abrio prueba de impresion. Si el cajon esta conectado al puerto RJ11 de la impresora, deberia abrirse.';
        drawerStatus.style.color = '#1f3b6b';
        printTicketPreview();
    }

    function renderUsers() {
        if (!usersTableBody) {
            return;
        }

        const q = state.userFilter.toLowerCase();
        const rows = state.users.filter(function (u) {
            const text = ((u.username || '') + ' ' + (u.name || '') + ' ' + (u.role || '')).toLowerCase();
            return !q || text.indexOf(q) >= 0;
        });

        if (!rows.length) {
            usersTableBody.innerHTML = '<tr><td colspan="5" class="muted">Sin usuarios</td></tr>';
            return;
        }

        usersTableBody.innerHTML = rows.map(function (u) {
            const selected = Number(u.id) === Number(state.selectedUserId) ? ' class="row-selected"' : '';
            return '<tr data-id="' + Number(u.id) + '"' + selected + '>' +
                '<td>' + Number(u.id) + '</td>' +
                '<td>' + escapeHtml(u.username || '') + '</td>' +
                '<td>' + escapeHtml(u.name || '') + '</td>' +
                '<td>' + escapeHtml(u.role || '') + '</td>' +
                '<td>' + (u.active ? 'Activo' : 'Inactivo') + '</td>' +
                '</tr>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch] || ch;
        });
    }

    function resetUserEditor() {
        state.selectedUserId = 0;
        $('#cfg-user-editor-title').text('Nuevo cajero');
        $('#cfg-user-username').val('');
        $('#cfg-user-name').val('');
        $('#cfg-user-password').val('');
        $('#cfg-user-role').val('cashier');
        $('#cfg-user-active').prop('checked', true);
        $('#cfg-permissions-grid input[type=checkbox]').prop('checked', false);
        switchPermissionTab('ventas');
        renderUsers();
    }

    function fillUserEditor(user) {
        if (!user) {
            resetUserEditor();
            return;
        }

        $('#cfg-user-editor-title').text('Editar cajero #' + Number(user.id));
        $('#cfg-user-username').val(user.username || '');
        $('#cfg-user-name').val(user.name || '');
        $('#cfg-user-password').val('');
        $('#cfg-user-role').val(user.role || 'cashier');
        $('#cfg-user-active').prop('checked', !!user.active);

        const permissions = user.permissions || {};
        $('#cfg-permissions-grid input[type=checkbox]').each(function () {
            const key = $(this).data('perm');
            $(this).prop('checked', !!permissions[key]);
        });
        switchPermissionTab('ventas');
    }

    function collectUserPayload() {
        const permissions = {};
        $('#cfg-permissions-grid input[type=checkbox]').each(function () {
            const key = ($(this).data('perm') || '').toString();
            if (key) {
                permissions[key] = $(this).is(':checked');
            }
        });

        return {
            id: Number(state.selectedUserId || 0),
            username: ($('#cfg-user-username').val() || '').toString().trim(),
            name: ($('#cfg-user-name').val() || '').toString().trim(),
            password: ($('#cfg-user-password').val() || '').toString(),
            role: ($('#cfg-user-role').val() || 'cashier').toString(),
            active: $('#cfg-user-active').is(':checked'),
            permissions: permissions,
        };
    }

    async function saveSettingsSection(section, messages) {
        const msg = messages && typeof messages === 'object' ? messages : {};
        const useGlobalStatus = msg.useGlobalStatus !== false;
        try {
            if (useGlobalStatus) {
                setStatus(msg.savingMessage || 'Guardando configuración...', false);
            }
            const all = collectSettings();
            const payloadSettings = {};
            payloadSettings[section] = all[section];
            const data = await apiPost('save_settings', { settings: payloadSettings });
            state.settings = data.settings || state.settings;
            fillSettingsForm();
            if (useGlobalStatus) {
                setStatus(msg.successMessage || 'Configuración guardada', false);
            }
            return true;
        } catch (err) {
            if (useGlobalStatus) {
                setStatus(err.message || 'No se pudo guardar', true);
            }
            if (msg.onError && typeof msg.onError === 'function') {
                msg.onError(err);
            }
            return false;
        }
    }

    async function saveUser() {
        try {
            setStatus('Guardando usuario...', false);
            const payload = collectUserPayload();
            const data = await apiPost('save_user', payload);
            state.users = Array.isArray(data.users) ? data.users : state.users;
            if (payload.id > 0) {
                state.selectedUserId = payload.id;
            } else {
                const found = state.users.find(function (u) { return u.username === payload.username; });
                state.selectedUserId = found ? Number(found.id) : 0;
            }
            renderUsers();
            fillUserEditor(getSelectedUser());
            setStatus('Usuario guardado', false);
        } catch (err) {
            setStatus(err.message || 'No se pudo guardar usuario', true);
        }
    }

    async function toggleUser() {
        const current = getSelectedUser();
        if (!current) {
            setStatus('Selecciona un usuario primero', true);
            return;
        }
        try {
            setStatus('Actualizando estado del usuario...', false);
            const data = await apiPost('toggle_user', { id: Number(current.id) });
            state.users = Array.isArray(data.users) ? data.users : state.users;
            renderUsers();
            fillUserEditor(getSelectedUser());
            setStatus('Estado actualizado', false);
        } catch (err) {
            setStatus(err.message || 'No se pudo actualizar', true);
        }
    }

    function bindEvents() {
        navButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const view = btn.getAttribute('data-view') || '';
                if (!panelPermission(view)) {
                    setStatus('No tienes permiso para esta sección', true);
                    return;
                }
                switchPanel(view);
            });
        });

        $('#cfg-save-options').on('click', function () { saveSettingsSection('enabledOptions'); });
        $('#cfg-save-folios').on('click', function () { saveSettingsSection('folios'); });
        $('#cfg-save-boxes').on('click', function () { saveSettingsSection('boxes'); });
        $('#cfg-save-branding').on('click', function () { saveSettingsSection('branding'); });
        $('#cfg-save-ticket').on('click', function () { saveSettingsSection('ticket'); });
        $('#cfg-tax-add-btn').on('click', async function () {
            await upsertTaxFromEditor(true);
        });
        $('#cfg-tax-clear-btn').on('click', function () {
            renderTaxEditor(null, -1);
            setTaxStatus('Editor de impuesto limpio.', false);
        });
        $('#cfg-save-tax').on('click', async function () {
            const ok = await saveSettingsSection('taxes', {
                useGlobalStatus: false,
                savingMessage: 'Guardando preferencias de impuestos...',
                onError: function (err) {
                    setTaxStatus(err && err.message ? err.message : 'No se pudo guardar las preferencias de impuestos.', true);
                }
            });
            if (ok) {
                setTaxStatus('Preferencias de impuestos guardadas correctamente.', false);
            }
        });
        $(root).on('click', '.cfg-tax-edit-btn', function () {
            const idx = Number($(this).data('tax-idx'));
            const tax = Array.isArray(state.taxDraftOptions) ? state.taxDraftOptions[idx] : null;
            if (!tax) {
                return;
            }
            renderTaxEditor(tax, idx);
            setTaxStatus('Editando impuesto. Actualiza y presiona "Guardar impuesto".', false);
        });
        $(root).on('click', '.cfg-tax-delete-btn', async function () {
            const idx = Number($(this).data('tax-idx'));
            await deleteTaxByIndex(idx, true);
        });
        $('#cfg-save-corte').on('click', function () { saveSettingsSection('corte'); });
        $('#cfg-save-units').on('click', function () { saveSettingsSection('units'); });
        $('#cfg-save-printer').on('click', function () { saveSettingsSection('devices'); });
        $('#cfg-save-reader').on('click', function () { saveSettingsSection('devices'); });
        $('#cfg-ticket-test-print').on('click', printTicketPreview);
        $('#cfg-printer-test').on('click', printTicketPreview);
        $('#cfg-box-drawer-test').on('click', testDrawerOpen);
        $('#cfg-reader-serial-enabled').on('change', updateReaderSerialVisibility);

        $('#cfg-user-new').on('click', function () {
            resetUserEditor();
            switchPanel('cajeros');
        });

        $('#cfg-user-cancel').on('click', function () {
            fillUserEditor(getSelectedUser());
        });

        $('#cfg-user-save').on('click', saveUser);
        $('#cfg-user-toggle').on('click', toggleUser);

        $(usersTableBody).on('click', 'tr[data-id]', function () {
            state.selectedUserId = Number($(this).data('id') || 0);
            renderUsers();
            fillUserEditor(getSelectedUser());
        });

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.userFilter = (searchInput.value || '').toString().trim();
                renderUsers();
            });
        }

        $('#cfg-user-role').on('change', function () {
            const role = ($(this).val() || 'cashier').toString();
            if (role === 'admin') {
                $('#cfg-permissions-grid input[type=checkbox]').prop('checked', true);
            }
        });

        permissionTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                switchPermissionTab(tab.getAttribute('data-perm-tab') || 'ventas');
            });
        });

        $('#cfg-ticket-header,#cfg-ticket-footer,#cfg-ticket-extra-top,#cfg-ticket-extra-bottom,#cfg-ticket-logo-url').on('input', renderTicketPreview);
        $('#cfg-ticket-show-customer,#cfg-ticket-include-unit-price,#cfg-ticket-full-description').on('change', renderTicketPreview);
    }

    async function init() {
        try {
            const data = await apiGet('dashboard');
            state.settings = data.settings || {};
            state.users = Array.isArray(data.users) ? data.users : [];
            state.currentUser = data.currentUser || { role: 'user', permissions: {} };

            fillSettingsForm();
            if (state.users.length > 0) {
                state.selectedUserId = Number(state.users[0].id || 0);
            }
            renderUsers();
            fillUserEditor(getSelectedUser());
            bindEvents();
            enforcePermissionVisibility();
            initScannerProbe();
            setStatus('', false);
        } catch (err) {
            setStatus(err.message || 'No se pudo cargar Configuración', true);
        }
    }

    init();
})();

