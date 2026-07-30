<section class="reportes-wrap" id="reportes-module">
    <header class="reportes-head">
        <div>
            <h2>Reportes</h2>
        </div>
        <div class="reportes-filtros">
            <div class="reportes-range" aria-label="Rango de fechas">
                <button type="button" class="btn-range active" data-range="week">Semana actual</button>
                <button type="button" class="btn-range" data-range="month">Mes actual</button>
                <button type="button" class="btn-range" data-range="year">Año actual</button>
                <button type="button" class="btn-range" data-range="custom">Personalizado</button>
                <button type="button" class="btn-range" data-range="all">Todo</button>
            </div>
            <div class="reportes-date-range" aria-label="Fechas personalizadas">
                <label>Desde
                    <input type="date" id="reportes-fecha-desde">
                </label>
                <label>Hasta
                    <input type="date" id="reportes-fecha-hasta">
                </label>
            </div>
            <label class="reportes-caja-label" for="reportes-caja">Caja:</label>
            <select id="reportes-caja" class="reportes-caja">
                <option value="all">Todas</option>
            </select>
        </div>
    </header>

    <div class="reportes-quick-nav">
        <a href="#rep-resumen">Resumen</a>
        <a href="#rep-graficos">Gráficos</a>
        <a href="#rep-metodo">Por Forma de Pago</a>
        <a href="#rep-departamento">Por Departamento</a>
        <a href="#rep-cliente">Por Cliente</a>
        <a href="#rep-impuestos">Impuestos</a>
    </div>

    <section class="reportes-section" id="rep-resumen">
        <h3>Resumen General</h3>
        <div class="reportes-kpis" id="reportes-kpis"></div>
    </section>

    <section class="reportes-section" id="rep-graficos">
        <h3>Gráficos</h3>
        <div class="reportes-classic-grid">
            <article class="reportes-chart-panel">
                <header>Ventas por forma de pago (por día)</header>
                <div id="reportes-chart-stacked" class="reportes-svg-wrap"></div>
                <div id="reportes-chart-stacked-legend" class="reportes-legend"></div>
                <div class="reportes-mini-table-wrap">
                    <table class="grid" id="reportes-metodo-resumen-table">
                        <thead>
                        <tr>
                            <th>Método</th>
                            <th>Total</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </article>

            <article class="reportes-chart-panel">
                <header>Ventas por Departamento</header>
                <div class="reportes-side-stack">
                    <div class="reportes-mini-table-wrap">
                        <table class="grid" id="reportes-departamento-ventas-mini-table">
                            <thead>
                            <tr>
                                <th>Departamento</th>
                                <th>Ventas</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="reportes-donut-row">
                        <div id="reportes-chart-dept-sales-donut" class="reportes-donut-wrap"></div>
                        <div id="reportes-chart-dept-sales-legend" class="reportes-legend"></div>
                    </div>
                </div>
            </article>

            <article class="reportes-chart-panel reportes-chart-panel--full">
                <header>Ganancia por Departamento</header>
                <div class="reportes-side-stack">
                    <div class="reportes-mini-table-wrap">
                        <table class="grid" id="reportes-departamento-ganancia-mini-table">
                            <thead>
                            <tr>
                                <th>Departamento</th>
                                <th>Ganancia</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="reportes-donut-row">
                        <div id="reportes-chart-dept-profit-donut" class="reportes-donut-wrap"></div>
                        <div id="reportes-chart-dept-profit-legend" class="reportes-legend"></div>
                    </div>
                </div>
            </article>
        </div>
    </section>

    <section class="reportes-section" id="rep-metodo">
        <h3>Ventas Por Forma de Pago</h3>
        <div class="reportes-table-wrap">
            <table class="grid" id="reportes-metodo-table">
                <thead>
                <tr>
                    <th>Método</th>
                    <th>Tickets</th>
                    <th>Ventas</th>
                    <th>Promedio Ticket</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <section class="reportes-section" id="rep-departamento">
        <h3>Ventas Y Ganancia Por Departamento</h3>
        <div class="reportes-table-wrap">
            <table class="grid" id="reportes-departamento-table">
                <thead>
                <tr>
                    <th>Departamento</th>
                    <th>Unidades</th>
                    <th>Ventas</th>
                    <th>Costo</th>
                    <th>Ganancia</th>
                    <th>Margen</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <section class="reportes-section" id="rep-cliente">
        <h3>Ventas Y Ganancia Por Cliente</h3>
        <div class="reportes-table-wrap">
            <table class="grid" id="reportes-cliente-table">
                <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Tickets</th>
                    <th>Ventas</th>
                    <th>Ganancia</th>
                    <th>Promedio Ticket</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <section class="reportes-section" id="rep-impuestos">
        <h3>Impuestos Estimados (IVA)</h3>
        <div class="reportes-table-wrap">
            <table class="grid" id="reportes-impuestos-table">
                <thead>
                <tr>
                    <th>Tasa IVA</th>
                    <th>Base Gravada</th>
                    <th>IVA Estimado</th>
                    <th>Total Con IVA</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

</section>
