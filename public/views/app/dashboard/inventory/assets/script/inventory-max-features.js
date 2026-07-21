/**
 * Features de exploracao maxima do inventario:
 * Set Codex, loadouts, journal de receitas, loadout de exploracao.
 */

let deps = null;
let setCodexOpen = false;
let setCodexData = { sets: [], wanted_definition_codes: [] };
let setCodexLoading = false;
let loadoutsCache = [];
let recipeJournal = [];
let explorationLoadout = null;

export function configureInventoryMaxFeatures(nextDeps = {}) {
    deps = nextDeps || {};
}

function d() {
    return deps || {};
}

function apiFetch(...args) {
    return d().apiFetch(...args);
}

function escapeHtml(value) {
    return (d().escapeHtml || ((v) => String(v ?? '')))(value);
}

function toast(...args) {
    return d().toast?.(...args);
}

function handleError(...args) {
    return d().handleError?.(...args);
}

function syncDrawerUi() {
    return d().syncDrawerUi?.();
}

function refreshEquipmentOnly() {
    return d().refreshEquipmentOnly?.();
}

function reloadContainerPanelsOnly() {
    return d().reloadContainerPanelsOnly?.();
}

export function isSetCodexOpen() {
    return setCodexOpen;
}

export function closeSetCodexPanel() {
    setCodexOpen = false;
    const root = document.querySelector('[data-inventory-set-codex]');
    if (root) {
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
    }
    syncDrawerUi();
}

export function openSetCodexPanel() {
    d().closeOverlappingPanels?.();
    setCodexOpen = true;
    const root = document.querySelector('[data-inventory-set-codex]');
    if (root) {
        root.hidden = false;
        root.setAttribute('aria-hidden', 'false');
    }
    syncDrawerUi();
    void loadSetCodex();
}

export function toggleSetCodexPanel() {
    if (setCodexOpen) closeSetCodexPanel();
    else openSetCodexPanel();
}

export async function loadSetCodex() {
    const listRoot = document.querySelector('[data-set-codex-list]');
    if (!listRoot) return;
    setCodexLoading = true;
    listRoot.innerHTML = '<p class="inventory-max-empty">Carregando sets...</p>';
    try {
        const response = await apiFetch('/api/inventory/sets/codex');
        setCodexData = response.data || { sets: [], wanted_definition_codes: [] };
        renderSetCodex();
    } catch (error) {
        setCodexData = { sets: [], wanted_definition_codes: [] };
        handleError(error, 'Nao foi possivel carregar o Set Codex.');
        listRoot.innerHTML = '<p class="inventory-max-empty">Falha ao carregar sets.</p>';
    } finally {
        setCodexLoading = false;
    }
}

function renderSetCodex() {
    const listRoot = document.querySelector('[data-set-codex-list]');
    if (!listRoot) return;
    const sets = setCodexData.sets || [];
    if (!sets.length) {
        listRoot.innerHTML = '<p class="inventory-max-empty">Nenhum set cadastrado ainda.</p>';
        return;
    }

    listRoot.innerHTML = sets.map((set) => {
        const color = /^#[0-9a-f]{6}$/i.test(String(set.aura_color || '')) ? set.aura_color : '#55c58a';
        const pieces = (set.pieces || []).map((piece) => {
            const statusLabel = piece.status === 'equipped' ? 'Equipado' : (piece.status === 'owned' ? 'Possui' : 'Falta');
            const wishLabel = piece.wishlisted ? 'Remover desejo' : 'Desejar';
            return `
                <li class="inventory-set-codex-piece is-${escapeHtml(piece.status)}${piece.wishlisted ? ' is-wishlisted' : ''}">
                    <div>
                        <strong>${escapeHtml(piece.definition_name)}</strong>
                        <small>${escapeHtml(piece.equip_slot_code || piece.piece_key)} · ${statusLabel}</small>
                    </div>
                    <button type="button" class="inventory-button inventory-button-ghost"
                        data-set-wish-code="${escapeHtml(piece.definition_code)}"
                        data-set-wish-next="${piece.wishlisted ? '0' : '1'}">${wishLabel}</button>
                </li>
            `;
        }).join('');
        const bonuses = (set.bonuses || []).map((bonus) => `
            <li>${escapeHtml(bonus.required_pieces)}p: ${escapeHtml(bonus.description || `${bonus.name} +${bonus.value}`)}</li>
        `).join('');

        return `
            <article class="inventory-set-codex-card" style="--set-aura:${escapeHtml(color)}">
                <header>
                    <div>
                        <p class="inventory-kicker">Set</p>
                        <h3>${escapeHtml(set.set_name)}</h3>
                    </div>
                    <strong>${Number(set.equipped_pieces || 0)}/${Number(set.total_pieces || 0)} equip · ${Number(set.owned_pieces || 0)} possui</strong>
                </header>
                <p class="inventory-set-codex-desc">${escapeHtml(set.description || '')}</p>
                <ul class="inventory-set-codex-pieces">${pieces}</ul>
                ${bonuses ? `<ul class="inventory-set-codex-bonuses">${bonuses}</ul>` : ''}
            </article>
        `;
    }).join('');

    listRoot.querySelectorAll('[data-set-wish-code]').forEach((button) => {
        button.addEventListener('click', async () => {
            const code = button.getAttribute('data-set-wish-code');
            const wishlisted = button.getAttribute('data-set-wish-next') === '1';
            try {
                await apiFetch('/api/inventory/sets/wishlist', {
                    method: 'POST',
                    body: { definition_code: code, wishlisted },
                });
                toast(wishlisted ? 'Peca adicionada aos desejos.' : 'Desejo removido.', 'success', 2200);
                await loadSetCodex();
            } catch (error) {
                handleError(error, 'Nao foi possivel atualizar o desejo.');
            }
        });
    });
}

export async function loadEquipmentLoadouts() {
    try {
        const response = await apiFetch('/api/inventory/loadouts');
        loadoutsCache = response.data?.loadouts || [];
        renderEquipmentLoadouts();
    } catch (error) {
        loadoutsCache = [];
        handleError(error, 'Nao foi possivel carregar loadouts.');
    }
}

export function renderEquipmentLoadouts(host = document.querySelector('[data-equipment-loadouts]')) {
    if (!host) return;
    const rows = (loadoutsCache.length ? loadoutsCache : Array.from({ length: 5 }, (_, index) => ({
        public_id: null,
        slot_index: index,
        name: `Loadout ${index + 1}`,
        items: [],
    }))).map((loadout) => {
        const count = (loadout.items || []).length;
        const canApply = Boolean(loadout.public_id) && count > 0;
        return `
            <div class="inventory-loadout-row" data-loadout-index="${Number(loadout.slot_index)}">
                <input type="text" maxlength="48" value="${escapeHtml(loadout.name || `Loadout ${Number(loadout.slot_index) + 1}`)}" data-loadout-name aria-label="Nome do loadout">
                <small>${count} peca(s)</small>
                <button type="button" class="inventory-button" data-loadout-save>Salvar</button>
                <button type="button" class="inventory-button is-primary" data-loadout-apply ${canApply ? '' : 'disabled'}>Usar</button>
            </div>
        `;
    }).join('');

    host.innerHTML = `
        <div class="inventory-loadouts-panel">
            <details>
                <summary>
                    <span>
                        <p class="inventory-kicker">Presets</p>
                        <strong>Loadouts</strong>
                    </span>
                    <small>5 slots</small>
                </summary>
                <div class="inventory-loadouts-list">${rows}</div>
            </details>
        </div>
    `;

    host.querySelectorAll('.inventory-loadout-row').forEach((row) => {
        const index = Number(row.getAttribute('data-loadout-index') || 0);
        row.querySelector('[data-loadout-save]')?.addEventListener('click', async () => {
            const name = row.querySelector('[data-loadout-name]')?.value?.trim() || `Loadout ${index + 1}`;
            try {
                await apiFetch('/api/inventory/loadouts/save', {
                    method: 'POST',
                    body: { slot_index: index, name },
                });
                toast(`Loadout "${name}" salvo.`, 'success', 2200);
                await loadEquipmentLoadouts();
            } catch (error) {
                handleError(error, 'Falha ao salvar loadout.');
            }
        });
        row.querySelector('[data-loadout-apply]')?.addEventListener('click', async () => {
            const loadout = loadoutsCache.find((entry) => Number(entry.slot_index) === index);
            if (!loadout?.public_id) return;
            try {
                const result = await apiFetch('/api/inventory/loadouts/apply', {
                    method: 'POST',
                    body: { loadout_public_id: loadout.public_id },
                });
                const applied = Number(result.data?.applied_count ?? result.data?.applied?.length ?? 0);
                toast(`Loadout aplicado (${applied} pecas).`, 'success', 2400);
                await refreshEquipmentOnly?.();
                await reloadContainerPanelsOnly?.();
            } catch (error) {
                handleError(error, 'Falha ao aplicar loadout.');
            }
        });
    });
}

export async function loadRecipeJournal(host = document.querySelector('[data-craft-recipe-journal]')) {
    if (!host) return;
    try {
        const response = await apiFetch('/api/inventory/crafting/recipes');
        recipeJournal = response.data?.recipes || [];
        host.innerHTML = `
            <div class="inventory-recipe-journal">
                <header>
                    <p class="inventory-kicker">Conhecimento</p>
                    <strong>Receitas conhecidas</strong>
                </header>
                <ul>
                    ${recipeJournal.length ? recipeJournal.map((recipe) => `
                        <li>
                            <div>
                                <strong>${escapeHtml(recipe.name)}</strong>
                                <small>${escapeHtml(recipe.workspace_code)} · ${escapeHtml(recipe.visibility || recipe.discovery)}</small>
                            </div>
                            ${recipe.can_share ? `<button type="button" class="inventory-button inventory-button-ghost" data-share-recipe="${escapeHtml(recipe.code)}">Compartilhar</button>` : ''}
                        </li>
                    `).join('') : '<li class="inventory-max-empty">Nenhuma receita conhecida.</li>'}
                </ul>
            </div>
        `;
        host.querySelectorAll('[data-share-recipe]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await apiFetch('/api/inventory/crafting/recipes/share', {
                        method: 'POST',
                        body: { recipe_code: button.getAttribute('data-share-recipe') },
                    });
                    toast('Receita compartilhada.', 'success', 2200);
                    await loadRecipeJournal(host);
                } catch (error) {
                    handleError(error, 'Nao foi possivel compartilhar a receita.');
                }
            });
        });
    } catch (error) {
        host.innerHTML = '<p class="inventory-max-empty">Falha ao carregar receitas.</p>';
        handleError(error, 'Nao foi possivel carregar o journal de receitas.');
    }
}

export async function loadExplorationLoadoutPanel(host = document.querySelector('[data-exploration-loadout]')) {
    if (!host) return;
    try {
        const response = await apiFetch('/api/inventory/exploration-loadout');
        explorationLoadout = response.data || {};
        const tools = Array.isArray(explorationLoadout.tool_item_public_ids) ? explorationLoadout.tool_item_public_ids : [];
        const potions = Array.isArray(explorationLoadout.potion_item_public_ids) ? explorationLoadout.potion_item_public_ids : [];
        host.innerHTML = `
            <div class="inventory-exploration-loadout-panel">
                <details>
                    <summary>
                        <span>
                            <p class="inventory-kicker">Expedicao</p>
                            <strong>Loadout de exploracao</strong>
                        </span>
                        <small>opcional</small>
                    </summary>
                    <label>Mochila (public_id)
                        <input type="text" data-exp-backpack value="${escapeHtml(explorationLoadout.backpack_item_public_id || '')}" placeholder="item da mochila">
                    </label>
                    <label>Ferramentas (ids separados por virgula)
                        <input type="text" data-exp-tools value="${escapeHtml(tools.join(', '))}" placeholder="tool ids">
                    </label>
                    <label>Pocoes (ids separados por virgula)
                        <input type="text" data-exp-potions value="${escapeHtml(potions.join(', '))}" placeholder="potion ids">
                    </label>
                    <label>Notas
                        <input type="text" data-exp-notes maxlength="180" value="${escapeHtml(explorationLoadout.notes || '')}">
                    </label>
                    <div class="inventory-exploration-loadout-actions">
                        <button type="button" class="inventory-button" data-exp-save>Salvar</button>
                        <button type="button" class="inventory-button is-primary" data-exp-apply>Preparar</button>
                    </div>
                </details>
            </div>
        `;
        host.querySelector('[data-exp-save]')?.addEventListener('click', async () => {
            const payload = readExplorationForm(host);
            try {
                await apiFetch('/api/inventory/exploration-loadout', { method: 'PUT', body: payload });
                toast('Loadout de exploracao salvo.', 'success', 2200);
                await loadExplorationLoadoutPanel(host);
            } catch (error) {
                handleError(error, 'Falha ao salvar loadout de exploracao.');
            }
        });
        host.querySelector('[data-exp-apply]')?.addEventListener('click', async () => {
            try {
                await apiFetch('/api/inventory/exploration-loadout/apply', { method: 'POST', body: {} });
                toast('Loadout de exploracao preparado.', 'success', 2400);
                await refreshEquipmentOnly?.();
                await reloadContainerPanelsOnly?.();
            } catch (error) {
                handleError(error, 'Falha ao preparar loadout de exploracao.');
            }
        });
    } catch (error) {
        host.innerHTML = '<p class="inventory-max-empty">Falha ao carregar loadout de exploracao.</p>';
        handleError(error, 'Nao foi possivel carregar o loadout de exploracao.');
    }
}

function readExplorationForm(host) {
    const splitIds = (value) => String(value || '')
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean);
    return {
        backpack_item_public_id: host.querySelector('[data-exp-backpack]')?.value?.trim() || null,
        tool_item_public_ids: splitIds(host.querySelector('[data-exp-tools]')?.value),
        potion_item_public_ids: splitIds(host.querySelector('[data-exp-potions]')?.value),
        notes: host.querySelector('[data-exp-notes]')?.value?.trim() || null,
    };
}

export function initInventoryMaxFeatures() {
    // Garante painel fechado no boot (CSS display:flex nao deve vazar o [hidden]).
    setCodexOpen = false;
    const root = document.querySelector('[data-inventory-set-codex]');
    if (root) {
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-set-codex-open]').forEach((button) => {
        button.addEventListener('click', () => toggleSetCodexPanel());
    });
    document.querySelector('[data-set-codex-close]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeSetCodexPanel();
    });
    document.querySelector('[data-set-codex-refresh]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        void loadSetCodex();
    });
    root?.addEventListener('click', (event) => {
        if (event.target === root) closeSetCodexPanel();
    });
}

export function compareBadgeForItem(item, findEquippedComparable, itemPowerValue) {
    if (!item || item.equipped || typeof findEquippedComparable !== 'function') return '';
    const equipped = findEquippedComparable(item);
    if (!equipped) return '';
    const delta = Number(itemPowerValue?.(item) || 0) - Number(itemPowerValue?.(equipped) || 0);
    if (!Number.isFinite(delta) || delta === 0) {
        return '<span class="inventory-compare-badge is-even" title="Poder equivalente">=</span>';
    }
    if (delta > 0) {
        return `<span class="inventory-compare-badge is-up" title="Upgrade +${delta}">+${delta}</span>`;
    }
    return `<span class="inventory-compare-badge is-down" title="Downgrade ${delta}">${delta}</span>`;
}

export async function previewSocketPlan(gemItem, targetItem) {
    const response = await apiFetch('/api/inventory/socket/preview', {
        method: 'POST',
        body: {
            gem_item_public_id: gemItem.public_id,
            target_item_public_id: targetItem.public_id,
        },
    });
    return response.data || {};
}

export async function unsocketGem(targetItem, socketIndex, confirmInventoryAction) {
    const preview = await apiFetch('/api/inventory/socket/unsocket/preview', {
        method: 'POST',
        body: {
            target_item_public_id: targetItem.public_id,
            socket_index: socketIndex,
        },
    });
    const cost = Number(preview.data?.cost?.amount || 150);
    const confirmed = await confirmInventoryAction({
        title: 'Remover gema',
        bodyHtml: `<p>Remover gema do engaste #${socketIndex + 1} de ${escapeHtml(targetItem.definition?.name || targetItem.definition_name || 'item')}.</p><p>Custo: <strong>${cost} G</strong>. A gema volta ao inventario.</p>`,
        confirmLabel: 'Remover',
        tone: 'warning',
    });
    if (!confirmed) return null;
    return apiFetch('/api/inventory/socket/unsocket', {
        method: 'POST',
        body: {
            target_item_public_id: targetItem.public_id,
            socket_index: socketIndex,
            confirm: true,
        },
    });
}
