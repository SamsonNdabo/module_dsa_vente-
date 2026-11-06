<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport — Plaintes</title>
    <style>
        /* ============================================================
           RESET & BASE
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page {
            margin: 18mm 14mm 22mm 14mm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5px;
            color: #1e293b;
            line-height: 1.5;
            background: #ffffff;
        }

        /* ============================================================
           VARIABLES COULEURS
           ============================================================ */
        /* Bleu   : #2563eb / #3b82f6 / #dbeafe */
        /* Vert   : #059669 / #10b981 / #d1fae5 */
        /* Orange : #d97706 / #f59e0b / #fef3c7 */
        /* Rouge  : #dc2626 / #ef4444 / #fee2e2 */
        /* Violet : #7c3aed / #8b5cf6 / #ede9fe */
        /* Slate  : #0f172a / #334155 / #64748b / #e2e8f0 / #f8fafc */

        /* ============================================================
           EN-TÊTE PRINCIPAL
           ============================================================ */
        .header {
            display: table;
            width: 100%;
            padding-bottom: 14px;
            border-bottom: 3px solid #2563eb;
            margin-bottom: 18px;
        }

        .header-left {
            display: table-cell;
            vertical-align: middle;
            width: 60%;
        }

        .header-left h1 {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }

        .header-left h1 span {
            color: #2563eb;
        }

        .header-left .subtitle {
            font-size: 9.5px;
            color: #64748b;
            margin-top: 3px;
            font-weight: 500;
        }

        .header-right {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
            width: 40%;
        }

        .header-right .meta {
            display: inline-block;
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
        }

        .header-right .meta-row {
            font-size: 8.5px;
            color: #64748b;
            line-height: 1.7;
        }

        .header-right .meta-row strong {
            color: #0f172a;
            font-weight: 700;
        }

        /* ============================================================
           TITRES DE SECTION
           ============================================================ */
        .section-title {
            display: table;
            width: 100%;
            background: #0f172a;
            border-radius: 6px;
            padding: 8px 14px;
            margin: 16px 0 10px;
        }

        .section-title .num {
            display: table-cell;
            width: 22px;
            font-size: 10px;
            font-weight: 800;
            color: #60a5fa;
            vertical-align: middle;
        }

        .section-title .label {
            display: table-cell;
            vertical-align: middle;
            font-size: 11px;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .section-title.blue   { background: #1e40af; }
        .section-title.green  { background: #065f46; }
        .section-title.orange { background: #92400e; }
        .section-title.purple { background: #5b21b6; }
        .section-title.red    { background: #991b1b; }

        /* ============================================================
           CARTES KPI PRINCIPALES
           ============================================================ */
        .kpi-grid {
            display: table;
            width: 100%;
            border-spacing: 8px 0;
            margin-bottom: 12px;
        }

        .kpi-card {
            display: table-cell;
            width: 25%;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            vertical-align: top;
            position: relative;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 8px 8px 0 0;
        }

        .kpi-card.blue::before   { background: #2563eb; }
        .kpi-card.orange::before { background: #d97706; }
        .kpi-card.green::before  { background: #059669; }
        .kpi-card.purple::before { background: #7c3aed; }

        .kpi-label {
            font-size: 7.5px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .kpi-value {
            font-size: 24px;
            font-weight: 800;
            line-height: 1;
            color: #0f172a;
        }

        .kpi-value.blue   { color: #2563eb; }
        .kpi-value.orange { color: #d97706; }
        .kpi-value.green  { color: #059669; }
        .kpi-value.purple { color: #7c3aed; }

        .kpi-unit {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            margin-left: 2px;
        }

        .kpi-sub {
            font-size: 7.5px;
            color: #94a3b8;
            margin-top: 4px;
            font-weight: 500;
        }

        /* ============================================================
           BARRE DE PROGRESSION
           ============================================================ */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 6px;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #059669, #34d399);
            border-radius: 3px;
        }

        /* ============================================================
           GRAPHIQUES
           ============================================================ */
        .charts-row {
            display: table;
            width: 100%;
            border-spacing: 8px 0;
            margin-bottom: 10px;
        }

        .chart-box {
            display: table-cell;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            vertical-align: top;
            text-align: center;
        }

        .chart-box.half { width: 50%; }
        .chart-box.full { width: 100%; }

        .chart-box .chart-title {
            font-size: 8.5px;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f1f5f9;
        }

        .chart-box img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        /* ============================================================
           TABLEAUX
           ============================================================ */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
        }

        table.data-table thead th {
            background: #334155;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 7.5px;
            letter-spacing: 0.06em;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #1e293b;
        }

        table.data-table thead th.center { text-align: center; }
        table.data-table thead th.right  { text-align: right; }

        table.data-table tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 8.5px;
            color: #334155;
            vertical-align: middle;
        }

        table.data-table tbody td.center { text-align: center; }
        table.data-table tbody td.right  { text-align: right; }
        table.data-table tbody td strong { color: #0f172a; font-weight: 700; }

        table.data-table tbody tr:nth-child(even) { background: #f8fafc; }
        table.data-table tbody tr:last-child td { border-bottom: none; }

        table.data-table tfoot td {
            background: #f1f5f9;
            padding: 8px 10px;
            font-size: 8.5px;
            font-weight: 700;
            color: #0f172a;
            border-top: 2px solid #cbd5e1;
        }

        /* ============================================================
           BADGES PRIORITÉ
           ============================================================ */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 7.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .badge-critique { background: #fee2e2; color: #b91c1c; }
        .badge-haute    { background: #fef3c7; color: #b45309; }
        .badge-normale  { background: #dbeafe; color: #1d4ed8; }
        .badge-basse    { background: #d1fae5; color: #047857; }

        /* ============================================================
           BADGES STATUT
           ============================================================ */
        .badge-statut-nouvelle { background: #dbeafe; color: #1d4ed8; }
        .badge-statut-encours  { background: #fef3c7; color: #b45309; }
        .badge-statut-resolue  { background: #d1fae5; color: #047857; }
        .badge-statut-rejetee  { background: #fee2e2; color: #b91c1c; }

        /* ============================================================
           PIED DE PAGE
           ============================================================ */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 16mm;
            padding: 6px 14mm 0;
            font-size: 7.5px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }

        .footer-left {
            display: table-cell;
            vertical-align: middle;
            text-align: left;
        }

        .footer-right {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
            font-weight: 600;
            color: #475569;
        }

        .footer-table {
            display: table;
            width: 100%;
        }

        .page-num:after {
            content: counter(page);
        }

        .page-total:after {
            content: counter(pages);
        }

        /* ============================================================
           UTILITAIRES
           ============================================================ */
        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .text-muted  { color: #94a3b8; }
        .text-red    { color: #dc2626; }
        .text-green  { color: #059669; }
        .text-orange { color: #d97706; }
        .text-blue   { color: #2563eb; }

        .empty-state {
            text-align: center;
            color: #94a3b8;
            font-style: italic;
            padding: 14px;
            font-size: 8.5px;
        }

        .page-break { page-break-after: always; }

        .no-break { page-break-inside: avoid; }
    </style>
</head>
<body>

    {{-- ============================================================
         EN-TÊTE
         ============================================================ --}}
    <div class="header">
        <div class="header-left">
            <h1>Rapport <span>Plaintes</span></h1>
            <div class="subtitle">Statistiques, indicateurs et suivi des plaintes clients</div>
        </div>
        <div class="header-right">
            <div class="meta">
                <div class="meta-row">Généré le : <strong>{{ $date_generation }}</strong></div>
                <div class="meta-row">Année : <strong>{{ $filtres['annee'] ?? 'Toutes' }}</strong></div>
                <div class="meta-row">Zone : <strong>{{ $filtres['zone'] ?? 'Toutes les zones' }}</strong></div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         1. VUE D'ENSEMBLE
         ============================================================ --}}
    <div class="section-title blue">
        <div class="num">01</div>
        <div class="label">Vue d'ensemble</div>
    </div>

    @php
        $global = $data['global'] ?? null;
        $total = $global->nombre_total ?? 0;
        $ouvertes = $global->ouvertes ?? 0;
        $cloturees = $global->cloturees ?? 0;
        $delai = $global->delai_moyen_heures ?? 0;
        $taux = $total > 0 ? round(($cloturees / $total) * 100) : 0;
        $prioritaires = ($data['par_priorite']['CRITIQUE'] ?? 0) + ($data['par_priorite']['HAUTE'] ?? 0);
    @endphp

    <div class="kpi-grid">
        <div class="kpi-card blue">
            <div class="kpi-label">Total plaintes</div>
            <div class="kpi-value blue">{{ $total }}</div>
            <div class="kpi-sub">Toutes catégories</div>
        </div>
        <div class="kpi-card orange">
            <div class="kpi-label">Ouvertes</div>
            <div class="kpi-value orange">{{ $ouvertes }}</div>
            <div class="kpi-sub">En cours de traitement</div>
        </div>
        <div class="kpi-card green">
            <div class="kpi-label">Clôturées</div>
            <div class="kpi-value green">{{ $cloturees }}</div>
            <div class="kpi-sub">Résolues ou rejetées</div>
        </div>
        <div class="kpi-card purple">
            <div class="kpi-label">Délai moyen</div>
            <div class="kpi-value purple">{{ $delai }}<span class="kpi-unit">h</span></div>
            <div class="kpi-sub">Heures par plainte</div>
        </div>
    </div>

    {{-- KPI secondaires --}}
    <div class="kpi-grid">
        <div class="kpi-card green" style="width: 50%;">
            <div class="kpi-label">Taux de résolution</div>
            <div class="kpi-value green">{{ $taux }}<span class="kpi-unit">%</span></div>
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: {{ $taux }}%"></div>
            </div>
        </div>
        <div class="kpi-card blue" style="width: 50%;">
            <div class="kpi-label">Plaintes prioritaires</div>
            <div class="kpi-value" style="color: #dc2626;">{{ $prioritaires }}</div>
            <div class="kpi-sub">Critique + Haute priorité</div>
        </div>
    </div>

    {{-- ============================================================
         2. VISUALISATION DES DONNÉES
         ============================================================ --}}
    <div class="section-title purple">
        <div class="num">02</div>
        <div class="label">Visualisation des données</div>
    </div>

    {{-- Bar + Doughnut côte à côte --}}
    <div class="charts-row">
        <div class="chart-box half">
            <div class="chart-title">Plaintes par zone</div>
            <img src="{{ $charts['bar_zones'] ?? '' }}" alt="Plaintes par zone" />
        </div>
        <div class="chart-box half">
            <div class="chart-title">Répartition par statut</div>
            <img src="{{ $charts['doughnut_statut'] ?? '' }}" alt="Par statut" />
        </div>
    </div>

    {{-- Line chart pleine largeur --}}
    <div class="charts-row">
        <div class="chart-box full">
            <div class="chart-title">Évolution mensuelle des plaintes</div>
            <img src="{{ $charts['line_evolution'] ?? '' }}" alt="Évolution mensuelle" />
        </div>
    </div>

    {{-- ============================================================
         3. RÉPARTITION PAR PRIORITÉ
         ============================================================ --}}
    <div class="section-title red">
        <div class="num">03</div>
        <div class="label">Répartition par priorité</div>
    </div>

    @php
        $priorites = ['CRITIQUE', 'HAUTE', 'NORMALE', 'BASSE'];
        $badges = [
            'CRITIQUE' => 'badge-critique',
            'HAUTE'    => 'badge-haute',
            'NORMALE'  => 'badge-normale',
            'BASSE'    => 'badge-basse',
        ];
        $totalPriorites = array_sum($data['par_priorite'] ?? []);
    @endphp

    <table class="data-table no-break">
        <thead>
            <tr>
                <th>Priorité</th>
                <th class="center">Nombre</th>
                <th class="center">Pourcentage</th>
                <th class="center">Répartition</th>
            </tr>
        </thead>
        <tbody>
            @foreach($priorites as $p)
                @php
                    $nombre = $data['par_priorite'][$p] ?? 0;
                    $pct = $totalPriorites > 0 ? round(($nombre / $totalPriorites) * 100, 1) : 0;
                @endphp
                <tr>
                    <td><span class="badge {{ $badges[$p] }}">{{ $p }}</span></td>
                    <td class="center"><strong>{{ $nombre }}</strong></td>
                    <td class="center">{{ $pct }}%</td>
                    <td class="center">
                        <div class="progress-bar" style="margin-top: 0;">
                            <div class="progress-bar-fill" style="width: {{ $pct }}%; background: {{ $p === 'CRITIQUE' ? '#dc2626' : ($p === 'HAUTE' ? '#d97706' : ($p === 'NORMALE' ? '#2563eb' : '#059669')) }};"></div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="center">{{ $totalPriorites }}</td>
                <td class="center">100%</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    {{-- ============================================================
         4. PERFORMANCE PAR ZONE
         ============================================================ --}}
    <div class="section-title blue">
        <div class="num">04</div>
        <div class="label">Performance par zone</div>
    </div>

    <table class="data-table no-break">
        <thead>
            <tr>
                <th>Zone</th>
                <th class="center">Total</th>
                <th class="center">Ouvertes</th>
                <th class="center">Clôturées</th>
                <th class="center">Rejetées</th>
                <th class="center">Taux</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['par_zone'] ?? [] as $z)
                @php
                    $zTotal = $z->nombre_total ?? 0;
                    $zClot = $z->cloturees ?? 0;
                    $zTaux = $zTotal > 0 ? round(($zClot / $zTotal) * 100) : 0;
                @endphp
                <tr>
                    <td><strong>{{ $z->zone }}</strong></td>
                    <td class="center">{{ $zTotal }}</td>
                    <td class="center text-orange">{{ $z->ouvertes ?? 0 }}</td>
                    <td class="center text-green">{{ $zClot }}</td>
                    <td class="center text-red">{{ $z->rejetees ?? 0 }}</td>
                    <td class="center"><strong>{{ $zTaux }}%</strong></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-state">Aucune donnée disponible</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         5. PERFORMANCE PAR AGENT
         ============================================================ --}}
    <div class="section-title green">
        <div class="num">05</div>
        <div class="label">Performance par agent</div>
    </div>

    <table class="data-table no-break">
        <thead>
            <tr>
                <th>Agent</th>
                <th class="center">Total</th>
                <th class="center">Ouvertes</th>
                <th class="center">Clôturées</th>
                <th class="center">Délai moyen</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['par_agent'] ?? [] as $a)
                <tr>
                    <td><strong>{{ $a->agent }}</strong></td>
                    <td class="center">{{ $a->nombre_total }}</td>
                    <td class="center text-orange">{{ $a->ouvertes }}</td>
                    <td class="center text-green">{{ $a->cloturees }}</td>
                    <td class="center">{{ $a->delai_moyen_heures ? $a->delai_moyen_heures . ' h' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-state">Aucune donnée disponible</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         6. RÉPARTITION PAR PARTENAIRE
         ============================================================ --}}
    <div class="section-title purple">
        <div class="num">06</div>
        <div class="label">Répartition par partenaire</div>
    </div>

    <table class="data-table no-break">
        <thead>
            <tr>
                <th>Partenaire</th>
                <th class="center">Total</th>
                <th class="center">Ouvertes</th>
                <th class="center">Clôturées</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['par_partenaire'] ?? [] as $p)
                <tr>
                    <td><strong>{{ $p->partenaire ?? '—' }}</strong></td>
                    <td class="center">{{ $p->nombre_total }}</td>
                    <td class="center text-orange">{{ $p->ouvertes }}</td>
                    <td class="center text-green">{{ $p->cloturees }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty-state">Aucune donnée disponible</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         7. DERNIÈRES PLAINTES
         ============================================================ --}}
    <div class="section-title blue">
        <div class="num">07</div>
        <div class="label">Dernières plaintes reçues</div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Réf.</th>
                <th>Objet</th>
                <th>Zone</th>
                <th>Client</th>
                <th class="center">Priorité</th>
                <th class="center">Statut</th>
                <th class="center">Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['dernieres_plaintes'] ?? [] as $p)
                <tr>
                    <td><strong>{{ $p['reference'] }}</strong></td>
                    <td>{{ Str::limit($p['objet'], 42) }}</td>
                    <td>{{ $p['zone'] }}</td>
                    <td>{{ $p['client'] ?? '—' }}</td>
                    <td class="center">
                        <span class="badge {{ $badges[$p['priorite']] ?? '' }}">
                            {{ $p['priorite'] }}
                        </span>
                    </td>
                    <td class="center">
                        @php
                            $statutClass = match($p['statut']) {
                                'NOUVELLE' => 'badge-statut-nouvelle',
                                'EN_COURS' => 'badge-statut-encours',
                                'RESOLUE'  => 'badge-statut-resolue',
                                'REJETEE'  => 'badge-statut-rejetee',
                                default    => '',
                            };
                        @endphp
                        <span class="badge {{ $statutClass }}">
                            {{ str_replace('_', ' ', $p['statut']) }}
                        </span>
                    </td>
                    <td class="center">
                        {{ \Carbon\Carbon::parse($p['created_at'])->format('d/m/Y') }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-state">Aucune plainte récente</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         PIED DE PAGE
         ============================================================ --}}
    <div class="footer">
        <div class="footer-table">
            <div class="footer-left">
                Rapport généré automatiquement — Système de gestion des plaintes
            </div>
            <div class="footer-right">
                Page <span class="page-num"></span> / <span class="page-total"></span>
            </div>
        </div>
    </div>

</body>
</html>