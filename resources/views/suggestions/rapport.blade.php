<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport — Suggestions</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page {
            margin: 20mm 15mm 25mm 15mm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #0f172a;
            line-height: 1.4;
        }

        /* ============================================================
           EN-TÊTE
           ============================================================ */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 12px;
            border-bottom: 3px solid #8b5cf6;
            margin-bottom: 20px;
        }

        .header-left h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 3px;
            letter-spacing: -0.02em;
        }

        .header-left p {
            font-size: 10px;
            color: #64748b;
        }

        .header-right {
            text-align: right;
            font-size: 9px;
            color: #64748b;
            line-height: 1.6;
        }

        .header-right strong {
            color: #0f172a;
            font-weight: 700;
        }

        /* ============================================================
           TITRES DE SECTION
           ============================================================ */
        h2 {
            font-size: 12px;
            font-weight: 800;
            color: #ffffff;
            background: #8b5cf6;
            padding: 7px 12px;
            border-radius: 5px;
            margin: 18px 0 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        h2.purple { background: #8b5cf6; }
        h2.blue   { background: #3b82f6; }
        h2.green  { background: #10b981; }
        h2.orange { background: #f59e0b; }
        h2.red    { background: #ef4444; }

        /* ============================================================
           STATS GLOBALES
           ============================================================ */
        .stats-grid {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-card {
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            background: #f8fafc;
            text-align: center;
        }

        .stat-card.purple { border-left: 4px solid #8b5cf6; }
        .stat-card.blue   { border-left: 4px solid #3b82f6; }
        .stat-card.green  { border-left: 4px solid #10b981; }
        .stat-card.orange { border-left: 4px solid #f59e0b; }
        .stat-card.red    { border-left: 4px solid #ef4444; }

        .stat-label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .stat-value.purple { color: #8b5cf6; }
        .stat-value.blue   { color: #3b82f6; }
        .stat-value.green  { color: #10b981; }
        .stat-value.orange { color: #f59e0b; }
        .stat-value.red    { color: #ef4444; }

        /* ============================================================
           TABLEAUX
           ============================================================ */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
        }

        thead th {
            background: #475569;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 8px;
            letter-spacing: 0.06em;
            padding: 7px 10px;
            text-align: left;
            border-bottom: 1px solid #334155;
        }

        thead th.center { text-align: center; }

        tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 9px;
            color: #334155;
            vertical-align: middle;
        }

        tbody td.center { text-align: center; }
        tbody td strong { color: #0f172a; font-weight: 700; }

        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:last-child td { border-bottom: none; }

        /* ============================================================
           BADGES PRIORITÉ
           ============================================================ */
        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
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
           BARRE DE PROGRESSION
           ============================================================ */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #34d399);
            border-radius: 4px;
        }

        /* ============================================================
           KPI PRO
           ============================================================ */
        .kpi-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .kpi-pro {
            flex: 1;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
        }

        .kpi-pro .kpi-pro-label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.06em;
            margin-bottom: 5px;
        }

        .kpi-pro .kpi-pro-value {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
        }

        .kpi-pro .kpi-pro-unit {
            font-size: 10px;
            font-weight: 500;
            color: #64748b;
            margin-left: 4px;
        }

        /* ============================================================
           PIED DE PAGE
           ============================================================ */
        .footer {
            position: fixed;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #94a3b8;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }

        .page-number:after {
            content: counter(page);
        }
    </style>
</head>
<body>

    {{-- ============================================================
         EN-TÊTE
         ============================================================ --}}
    <div class="header">
        <div class="header-left">
            <h1>Rapport — Suggestions</h1>
            <p>Statistiques, indicateurs et suivi des suggestions clients</p>
        </div>
        <div class="header-right">
            <div>Généré le : <strong>{{ $date_generation }}</strong></div>
            <div>Année : <strong>{{ $filtres['annee'] ?? 'Toutes' }}</strong></div>
            <div>Zone : <strong>{{ $filtres['zone'] ?? 'Toutes les zones' }}</strong></div>
        </div>
    </div>

    {{-- ============================================================
         STATISTIQUES GLOBALES
         ============================================================ --}}
    <h2 class="purple">Vue d'ensemble</h2>
    <div class="stats-grid">
        <div class="stat-card purple">
            <div class="stat-label">Total suggestions</div>
            <div class="stat-value purple">{{ $data['global']->nombre_total ?? 0 }}</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-label">Ouvertes</div>
            <div class="stat-value orange">{{ $data['global']->ouvertes ?? 0 }}</div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Clôturées</div>
            <div class="stat-value green">{{ $data['global']->cloturees ?? 0 }}</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-label">Délai moyen</div>
            <div class="stat-value">{{ $data['global']->delai_moyen_heures ?? 0 }} h</div>
        </div>
    </div>

    {{-- ============================================================
         KPI DÉTAILLÉS
         ============================================================ --}}
    <div class="kpi-row">
        <div class="kpi-pro">
            <div class="kpi-pro-label">Taux d'acceptation</div>
            <div class="kpi-pro-value">
                {{ $data['global']->nombre_total > 0 ? round(($data['global']->cloturees / $data['global']->nombre_total) * 100) : 0 }}<span class="kpi-pro-unit">%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: {{ $data['global']->nombre_total > 0 ? round(($data['global']->cloturees / $data['global']->nombre_total) * 100) : 0 }}%"></div>
            </div>
        </div>
        <div class="kpi-pro">
            <div class="kpi-pro-label">Suggestions prioritaires</div>
            <div class="kpi-pro-value" style="color: #ef4444;">
                {{ ($data['par_priorite']['CRITIQUE'] ?? 0) + ($data['par_priorite']['HAUTE'] ?? 0) }}
            </div>
            <div style="font-size: 8px; color: #64748b; margin-top: 4px;">Critique + Haute priorité</div>
        </div>
    </div>

    {{-- ============================================================
         RÉPARTITION PAR PRIORITÉ
         ============================================================ --}}
    <h2 class="red">Répartition par priorité</h2>
    <table>
        <thead>
            <tr>
                <th>Priorité</th>
                <th class="center">Nombre</th>
                <th class="center">Pourcentage</th>
            </tr>
        </thead>
        <tbody>
            @php
                $priorites = ['CRITIQUE', 'HAUTE', 'NORMALE', 'BASSE'];
                $badges = [
                    'CRITIQUE' => 'badge-critique',
                    'HAUTE' => 'badge-haute',
                    'NORMALE' => 'badge-normale',
                    'BASSE' => 'badge-basse',
                ];
                $totalPriorites = array_sum($data['par_priorite'] ?? []);
            @endphp
            @foreach($priorites as $p)
                @php
                    $nombre = $data['par_priorite'][$p] ?? 0;
                    $pct = $totalPriorites > 0 ? round(($nombre / $totalPriorites) * 100, 1) : 0;
                @endphp
                <tr>
                    <td><span class="badge {{ $badges[$p] }}">{{ $p }}</span></td>
                    <td class="center"><strong>{{ $nombre }}</strong></td>
                    <td class="center">{{ $pct }}%</td>
                </tr>
            @endforeach
            <tr style="background: #f1f5f9; font-weight: 700;">
                <td><strong>Total</strong></td>
                <td class="center"><strong>{{ $totalPriorites }}</strong></td>
                <td class="center"><strong>100%</strong></td>
            </tr>
        </tbody>
    </table>

    {{-- ============================================================
         PERFORMANCE PAR ZONE
         ============================================================ --}}
    <h2 class="purple">Performance par zone</h2>
    <table>
        <thead>
            <tr>
                <th>Zone</th>
                <th class="center">Total</th>
                <th class="center">Ouvertes</th>
                <th class="center">Clôturées</th>
                <th class="center">Rejetées</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['par_zone'] ?? [] as $z)
                <tr>
                    <td><strong>{{ $z->zone }}</strong></td>
                    <td class="center">{{ $z->nombre_total }}</td>
                    <td class="center">{{ $z->ouvertes }}</td>
                    <td class="center">{{ $z->cloturees }}</td>
                    <td class="center">{{ $z->rejetees ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center" style="color: #94a3b8;">Aucune donnée</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         PERFORMANCE PAR AGENT
         ============================================================ --}}
    <h2 class="green">Performance par agent</h2>
    <table>
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
                    <td class="center">{{ $a->ouvertes }}</td>
                    <td class="center">{{ $a->cloturees }}</td>
                    <td class="center">{{ $a->delai_moyen_heures ?? '—' }} h</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center" style="color: #94a3b8;">Aucune donnée</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         RÉPARTITION PAR PARTENAIRE
         ============================================================ --}}
    <h2 class="orange">Répartition par partenaire</h2>
    <table>
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
                    <td class="center">{{ $p->ouvertes }}</td>
                    <td class="center">{{ $p->cloturees }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="center" style="color: #94a3b8;">Aucune donnée</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         DERNIÈRES SUGGESTIONS
         ============================================================ --}}
    <h2 class="purple">Dernières suggestions reçues</h2>
    <table>
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
            @forelse($data['dernieres_suggestions'] ?? [] as $s)
                <tr>
                    <td><strong>{{ $s['reference'] }}</strong></td>
                    <td>{{ Str::limit($s['objet'], 45) }}</td>
                    <td>{{ $s['zone'] }}</td>
                    <td>{{ $s['client'] ?? '—' }}</td>
                    <td class="center">
                        <span class="badge {{ $badges[$s['priorite']] ?? '' }}">
                            {{ $s['priorite'] }}
                        </span>
                    </td>
                    <td class="center">
                        @php
                            $statutClass = match($s['statut']) {
                                'NOUVELLE' => 'badge-statut-nouvelle',
                                'EN_COURS' => 'badge-statut-encours',
                                'RESOLUE'  => 'badge-statut-resolue',
                                'REJETEE'  => 'badge-statut-rejetee',
                                default    => '',
                            };
                        @endphp
                        <span class="badge {{ $statutClass }}">
                            {{ str_replace('_', ' ', $s['statut']) }}
                        </span>
                    </td>
                    <td class="center">
                        {{ \Carbon\Carbon::parse($s['created_at'])->format('d/m/Y') }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="center" style="color: #94a3b8;">Aucune suggestion récente</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         PIED DE PAGE
         ============================================================ --}}
    <div class="footer">
        Rapport généré automatiquement — Système de gestion des suggestions
        &nbsp;•&nbsp; Page <span class="page-number"></span>
    </div>
</body>
</html>