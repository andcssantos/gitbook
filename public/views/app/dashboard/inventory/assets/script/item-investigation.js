/**
 * Modal de inspeção / investigação de item (extraído do main.js — Sprint J).
 */

import { renderMarketBreakdownHtml } from './market-breakdown.js';

let uiDeps = null;

export function configureItemInvestigation(deps) {
    uiDeps = deps || {};
}

function d() {
    return uiDeps || {};
}

function apiFetch(...args) {
    return d().apiFetch(...args);
}

function escapeHtml(value) {
    return (d().escapeHtml || ((v) => String(v ?? '')))(value);
}

function handleError(...args) {
    return d().handleError?.(...args);
}

function setStatus(...args) {
    return d().setStatus?.(...args);
}

function openModal(...args) {
    return d().openModal?.(...args);
}

function itemLabel(item) {
    return d().itemLabel?.(item) ?? String(item?.definition_name || item?.definition_code || 'Item');
}

function itemAssetUrl(item) {
    return d().itemAssetUrl?.(item) ?? null;
}

function upgradeLevelFromItem(item) {
    return Number(d().upgradeLevelFromItem?.(item) || 0);
}

function resolveItemTypeMeta(item) {
    return d().resolveItemTypeMeta?.(item) || { label: 'Item', icon: '◆' };
}

function isBusy() {
    return Boolean(d().isBusy?.());
}

function setActionInFlight(value) {
    return d().setActionInFlight?.(value);
}

function executeItemAction(...args) {
    return d().executeItemAction?.(...args);
}

function renderInvestigationSparkline(history = []) {
    if (!history.length) return '<span class="inventory-investigate-sparkline is-empty">Sem historico recente</span>';
    const values = history.map((entry) => Number(entry.market_value || 0));
    const min = Math.min(...values);
    const max = Math.max(...values);
    const chars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    const bars = values.map((value) => {
        const ratio = max === min ? 0.5 : (value - min) / (max - min);
        return chars[Math.min(chars.length - 1, Math.floor(ratio * (chars.length - 1)))];
    }).join('');

    return `<span class="inventory-investigate-sparkline" title="Ultimos ${history.length} registros">${escapeHtml(bars)}</span>`;
}

function detailActionGroup(action) {
    const code = String(action?.code || '');
    if (['LOCK_ITEM', 'UNLOCK_ITEM', 'FAVORITE_ITEM', 'UNFAVORITE_ITEM', 'WISHLIST_ITEM', 'UNWISHLIST_ITEM'].includes(code)) {
        return 'Seguranca';
    }
    if (['EQUIP', 'UNEQUIP', 'OPEN', 'USE'].includes(code)) {
        return 'Uso';
    }
    if (['SELL', 'LIST_MARKET'].includes(code)) {
        return 'Economia';
    }
    if (action?.is_destructive || ['DISMANTLE', 'DISCARD'].includes(code)) {
        return 'Risco';
    }

    return 'Outras';
}

function renderDetailActionGroups(actions = []) {
    const available = actions.filter((action) => action?.code && action.code !== 'INSPECT');
    if (!available.length) {
        return '<section class="inventory-investigate-section inventory-detail-actions"><h4>Acoes</h4><small>Nenhuma acao direta disponivel para este item.</small></section>';
    }

    const groups = new Map();
    for (const action of available) {
        const group = detailActionGroup(action);
        if (!groups.has(group)) groups.set(group, []);
        groups.get(group).push(action);
    }

    return `
        <section class="inventory-investigate-section inventory-detail-actions">
            <h4>Acoes</h4>
            ${Array.from(groups.entries()).map(([group, groupActions]) => `
                <div class="inventory-detail-action-group">
                    <span>${escapeHtml(group)}</span>
                    <div class="inventory-detail-action-list">
                        ${groupActions.map((action) => `
                            <button
                                type="button"
                                class="inventory-detail-action${action.is_destructive ? ' is-destructive' : ''}${action.requires_confirmation ? ' requires-confirmation' : ''}"
                                data-detail-action="${escapeHtml(action.code)}"
                            >
                                <strong>${escapeHtml(action.name || action.code)}</strong>
                                ${action.description ? `<small>${escapeHtml(action.description)}</small>` : ''}
                            </button>
                        `).join('')}
                    </div>
                </div>
            `).join('')}
        </section>
    `;
}

function renderDetailFlags(item) {
    const flags = [
        item.flags?.locked ? ['Travado', 'Protege contra venda, descarte, desmanche, craft e mercado.'] : null,
        item.flags?.favorite ? ['Favorito', 'Marcado como item importante.'] : null,
        item.flags?.wishlist ? ['Wishlist', 'Usado como referencia para buscas e alertas futuros.'] : null,
    ].filter(Boolean);

    if (!flags.length) {
        return '<div class="inventory-detail-flags is-empty"><span>Nenhuma protecao ativa</span><small>Use travar/favoritar/wishlist antes de mexer em itens raros.</small></div>';
    }

    return `
        <div class="inventory-detail-flags">
            ${flags.map(([label, description]) => `
                <span><strong>${escapeHtml(label)}</strong><small>${escapeHtml(description)}</small></span>
            `).join('')}
        </div>
    `;
}

function renderDetailRiskSummary(item, actions = []) {
    const destructive = actions.filter((action) => action?.is_destructive || ['DISMANTLE', 'DISCARD'].includes(String(action?.code || '')));
    if (item.flags?.locked) {
        return '<div class="inventory-detail-risk is-safe"><strong>Item protegido</strong><span>Acoes destrutivas e economicas sensiveis ficam bloqueadas enquanto ele estiver travado.</span></div>';
    }
    if (destructive.length) {
        return `<div class="inventory-detail-risk is-warning"><strong>Acoes com risco</strong><span>${destructive.map((action) => escapeHtml(action.name || action.code)).join(' | ')} exigem confirmacao e devem ser usadas com cuidado.</span></div>`;
    }

    return '<div class="inventory-detail-risk"><strong>Risco baixo</strong><span>Nenhuma acao destrutiva disponivel neste estado.</span></div>';
}

const HISTORY_FILTERS = [
    ['all', 'Todos'],
    ['safety', 'Seguranca'],
    ['economy', 'Economia'],
    ['crafting', 'Craft'],
    ['enhancement', 'Upgrade'],
    ['socketing', 'Gemas'],
    ['lifecycle', 'Ciclo'],
];

function historyCategoryLabel(category) {
    return (HISTORY_FILTERS.find(([code]) => code === category) || [category, 'Outros'])[1];
}

function formatHistoryDate(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';

    const parsed = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return raw;

    return parsed.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function renderHistoryMetadata(metadata) {
    if (!metadata || typeof metadata !== 'object') return '';

    const labels = {
        npc_value: 'NPC',
        market_value: 'Mercado',
        from_level: 'Antes',
        to_level: 'Depois',
        success: 'Sucesso',
        currency: 'Moeda',
        affix: 'Affix',
        source: 'Origem',
        gem: 'Gema',
    };

    const entries = Object.entries(metadata)
        .filter(([, value]) => value !== null && value !== undefined && typeof value !== 'object')
        .slice(0, 4);
    if (!entries.length) return '';

    return `<dl class="inventory-history-meta">
        ${entries.map(([key, value]) => `
            <div><dt>${escapeHtml(labels[key] || key)}</dt><dd>${escapeHtml(String(value))}</dd></div>
        `).join('')}
    </dl>`;
}

function renderInvestigationHistory(history = [], summary = {}) {
    if (!history.length) {
        return `
            <section class="inventory-investigate-section inventory-history-panel">
                <h4>Historico</h4>
                <p>Sem eventos registrados.</p>
            </section>
        `;
    }

    const categories = new Set(history.map((event) => event.category || 'other'));
    const filters = HISTORY_FILTERS.filter(([code]) => code === 'all' || categories.has(code));
    const total = Number(summary.total || history.length);

    return `
        <section class="inventory-investigate-section inventory-history-panel">
            <div class="inventory-history-header">
                <h4>Historico</h4>
                <span>${total.toLocaleString('pt-BR')} evento(s)</span>
            </div>
            <div class="inventory-history-filters" role="tablist" aria-label="Filtros do historico">
                ${filters.map(([code, label], index) => `
                    <button type="button" class="inventory-history-filter${index === 0 ? ' is-active' : ''}" data-history-filter="${escapeHtml(code)}">
                        ${escapeHtml(label)}
                    </button>
                `).join('')}
            </div>
            <ol class="inventory-history-timeline" data-history-timeline>
                ${history.map((event) => {
                    const category = event.category || 'other';
                    const tone = event.tone || 'info';
                    return `
                        <li class="inventory-history-event is-${escapeHtml(tone)}" data-history-category="${escapeHtml(category)}">
                            <span class="inventory-history-dot"></span>
                            <div>
                                <strong>${escapeHtml(event.label || event.type || 'Evento')}</strong>
                                <small>${escapeHtml(historyCategoryLabel(category))}${event.created_at ? ` · ${escapeHtml(formatHistoryDate(event.created_at))}` : ''}</small>
                                ${renderHistoryMetadata(event.metadata)}
                            </div>
                        </li>
                    `;
                }).join('')}
            </ol>
        </section>
    `;
}

function renderInvestigationModal(report, item, actions = []) {
    const data = report || {};
    const inspectedItem = data.item || item || {};
    const market = data.market || {};
    const dismantle = data.dismantle || {};
    const history = data.history || [];
    const historySummary = data.history_summary || {};
    const crafting = data.crafting || [];
    const supply = market.supply || {};
    const upgradeLevel = upgradeLevelFromItem(inspectedItem);
    const typeMeta = resolveItemTypeMeta(inspectedItem);
    const assetUrl = itemAssetUrl(inspectedItem);
    const quality = inspectedItem.quality_bucket || 'common';
    const stats = (inspectedItem.properties || []).filter((prop) => !['upgrade_level', 'upgrade_success_rate', 'socket_count'].includes(String(prop.code || ''))).slice(0, 6);
    const affixes = (inspectedItem.affixes || []).slice(0, 6);
    const sockets = inspectedItem.sockets || [];
    const hasDismantleAction = actions.some((action) => action?.code === 'DISMANTLE');
    const dismantleLines = (dismantle.materials || []).map((entry) => `${entry.label} x${entry.quantity}`).join(' · ') || 'Nenhum material previsto';

    return `
        <div class="inventory-investigate inventory-detail-sheet">
            <header class="inventory-investigate-hero rarity-${escapeHtml(quality)}">
                <div class="inventory-investigate-art${assetUrl ? '' : ' is-placeholder'}">
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="">` : `<span>${escapeHtml(typeMeta.icon)}</span>`}
                </div>
                <div>
                    <h3>${escapeHtml(itemLabel(inspectedItem))}${upgradeLevel > 0 ? ` <small>+${upgradeLevel}</small>` : ''}</h3>
                    <p>${escapeHtml(String(quality))} · Poder ${Number(data.power || 0).toLocaleString('pt-BR')} · ${escapeHtml(typeMeta.label)}</p>
                    ${renderDetailFlags(inspectedItem)}
                </div>
            </header>
            ${renderDetailActionGroups(actions)}
            ${renderDetailRiskSummary(inspectedItem, actions)}
            ${data.description ? `<section class="inventory-investigate-section"><h4>Descricao</h4><p>${escapeHtml(data.description)}</p></section>` : ''}
            <section class="inventory-investigate-grid">
                <div><h4>Stats</h4><ul>${stats.map((stat) => `<li>${escapeHtml(stat.name)}: <strong>${escapeHtml(String(stat.value ?? '-'))}</strong></li>`).join('') || '<li>Sem stats base.</li>'}</ul></div>
                <div><h4>Affixes</h4><ul>${affixes.map((affix) => `<li>${escapeHtml(affix.name)}: <strong>+${escapeHtml(String(affix.value ?? '-'))}</strong></li>`).join('') || '<li>Sem affixes.</li>'}</ul></div>
                <div><h4>Sockets</h4><ul>${sockets.map((socket, index) => `<li>${socket.gem
                    ? `● ${escapeHtml(socket.gem.name || 'Gema')} <button type="button" class="inventory-button inventory-button-ghost" data-unsocket-index="${Number(socket.index ?? index)}">Remover</button>`
                    : '○ Vazio'}</li>`).join('') || '<li>Sem sockets.</li>'}</ul></div>
            </section>
            <section class="inventory-investigate-section">
                <h4>Mercado</h4>
                <p>${Number(market.npc_value || 0).toLocaleString('pt-BR')} G (NPC) / ${Number(market.suggested_premium || 0).toLocaleString('pt-BR')} 💎 (P2P)</p>
                <p>Oferta: ${Number(supply.similar_listings || 0)} similares · Demanda: ${escapeHtml(supply.demand_label || 'Estavel')}</p>
                ${renderMarketBreakdownHtml(market.breakdown || {}, escapeHtml)}
                ${renderInvestigationSparkline(market.price_history || [])}
            </section>
            <section class="inventory-investigate-section">
                <h4>Desmanche</h4>
                <p>${escapeHtml(dismantleLines)}</p>
                ${dismantle.can_dismantle && hasDismantleAction ? `<button type="button" class="inventory-button inventory-investigate-dismantle" data-investigate-dismantle="${escapeHtml(inspectedItem.public_id)}">Desmanchar item</button>` : '<small>Item nao pode ser desmanchado neste estado.</small>'}
            </section>
            ${crafting.length ? `<section class="inventory-investigate-section"><h4>Crafting</h4><ul>${crafting.map((entry) => `<li><strong>${escapeHtml(entry.label)}:</strong> ${escapeHtml(entry.description || '')}</li>`).join('')}</ul></section>` : ''}
            ${renderInvestigationHistory(history, historySummary)}
        </div>
    `;
}

export async function openInvestigationModal(item) {
    if (!item?.public_id || isBusy()) return;

    setActionInFlight(true);
    try {
        setStatus('Investigando item...');
        const [reportResponse, actionsResponse] = await Promise.all([
            apiFetch(`/api/inventory/items/${encodeURIComponent(item.public_id)}/investigate`),
            apiFetch(`/api/items/${encodeURIComponent(item.public_id)}/actions`).catch(() => ({ data: { actions: [] } })),
        ]);
        const report = reportResponse.data || {};
        const actions = actionsResponse.data?.actions || [];
        const content = document.createElement('div');
        content.innerHTML = renderInvestigationModal(report, item, actions);

        const { close, element } = openModal(content.firstElementChild || content, { closeOnBackdrop: true });
        element.querySelectorAll('[data-history-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                const filter = button.getAttribute('data-history-filter') || 'all';
                element.querySelectorAll('[data-history-filter]').forEach((entry) => {
                    entry.classList.toggle('is-active', entry === button);
                });
                element.querySelectorAll('[data-history-category]').forEach((entry) => {
                    const category = entry.getAttribute('data-history-category') || 'other';
                    entry.hidden = filter !== 'all' && category !== filter;
                });
            });
        });

        element.querySelectorAll('[data-detail-action]').forEach((button) => {
            button.addEventListener('click', async () => {
                const code = button.getAttribute('data-detail-action');
                const action = actions.find((entry) => entry.code === code);
                if (!action) return;

                close();
                await executeItemAction(item, action);
            });
        });

        element.querySelector('[data-investigate-dismantle]')?.addEventListener('click', async () => {
            close();
            await executeItemAction(item, {
                code: 'DISMANTLE',
                name: 'Desmanchar',
                requires_confirmation: true,
                is_destructive: true,
            });
        });

        element.querySelectorAll('[data-unsocket-index]').forEach((button) => {
            button.addEventListener('click', async () => {
                const socketIndex = Number(button.getAttribute('data-unsocket-index') || 0);
                try {
                    const result = await d().unsocketGem?.(inspectedItem.public_id ? inspectedItem : item, socketIndex);
                    if (!result) return;
                    close();
                    setStatus('Sincronizado');
                    await d().refreshEquipmentOnly?.();
                    await d().softRefreshAfterEnhanceSocket?.(null, null);
                } catch (error) {
                    handleError(error, 'Nao foi possivel remover a gema.');
                }
            });
        });

        setStatus('Sincronizado');
    } catch (error) {
        handleError(error, 'Nao foi possivel investigar o item.');
    } finally {
        setActionInFlight(false);
    }
}
