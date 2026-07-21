/**
 * Journal / drawer de missões.
 */

let missionDeps = null;
let missionPanelOpen = false;
let missionFilter = 'active';
let missionCache = { missions: [], grouped: {}, tracker: [] };
let missionLoading = false;

export function configureMissionsUi(deps) {
    missionDeps = deps || {};
}

export function isMissionsPanelOpen() {
    return missionPanelOpen;
}

export async function openMissionsPanel() {
    missionPanelOpen = true;
    syncMissionsPanelVisibility();
    await loadMissionsJournal();
}

export function closeMissionsPanel() {
    missionPanelOpen = false;
    syncMissionsPanelVisibility();
}

export function toggleMissionsPanel() {
    if (missionPanelOpen) {
        closeMissionsPanel();
        return;
    }
    openMissionsPanel();
}

function syncMissionsPanelVisibility() {
    const root = missionDeps?.missionsPanelRoot?.();
    if (!root) return;
    root.hidden = !missionPanelOpen;
    root.classList.toggle('is-open', missionPanelOpen);
    missionDeps?.onPanelVisibilityChange?.(missionPanelOpen);
}

export async function loadMissionsJournal() {
    const listRoot = missionDeps?.missionsListRoot?.();
    if (!listRoot || !missionDeps?.apiFetch) return;

    missionLoading = true;
    renderMissionsJournal();
    try {
        const response = await missionDeps.apiFetch('/api/missions');
        const data = response.data || {};
        missionCache = {
            missions: Array.isArray(data.missions) ? data.missions : [],
            grouped: data.grouped || {},
            tracker: Array.isArray(data.tracker) ? data.tracker : [],
        };
    } catch (error) {
        missionDeps.handleError?.(error, 'Nao foi possivel carregar missoes.');
    } finally {
        missionLoading = false;
        renderMissionsJournal();
    }
}

function escapeHtml(value) {
    return (missionDeps?.escapeHtml || ((v) => String(v ?? '')))(value);
}

function missionTypeLabel(type) {
    if (type === 'season') return 'Temporada';
    if (type === 'side') return 'Secundaria';
    return 'Principal';
}

function objectiveLabel(objective) {
    const type = String(objective?.type || '');
    const current = Number(objective?.current || 0);
    const required = Number(objective?.required || 1);
    const detail = objective?.detail || {};
    if (type === 'kills') return `Abates ${current}/${required}`;
    if (type === 'expedition_complete') return `Expedicoes ${current}/${required}${detail.biome_code ? ` (${detail.biome_code})` : ''}`;
    if (type === 'item_owned') return `Possuir ${detail.item_definition_code || 'item'} ${current}/${required}`;
    if (type === 'craft_recipe') return `Craft ${detail.recipe_code || 'receita'} ${current}/${required}`;
    return `${type || 'objetivo'} ${current}/${required}`;
}

function filteredMissions() {
    const grouped = missionCache.grouped || {};
    if (missionFilter === 'main') return grouped.main || [];
    if (missionFilter === 'season') return grouped.season || [];
    if (missionFilter === 'side') return grouped.side || [];
    if (missionFilter === 'completed') return grouped.completed || [];
    return grouped.active || missionCache.missions.filter((m) => m.status !== 'completed');
}

export function renderMissionsJournal() {
    const listRoot = missionDeps?.missionsListRoot?.();
    const tabsRoot = missionDeps?.missionsTabsRoot?.();
    if (!listRoot) return;

    if (tabsRoot) {
        tabsRoot.querySelectorAll('[data-mission-filter]').forEach((button) => {
            const active = button.getAttribute('data-mission-filter') === missionFilter;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    if (missionLoading) {
        listRoot.innerHTML = '<p class="inventory-missions-empty">Carregando journal...</p>';
        return;
    }

    const missions = filteredMissions();
    if (!missions.length) {
        listRoot.innerHTML = '<p class="inventory-missions-empty">Nenhuma missao neste filtro.</p>';
        return;
    }

    listRoot.innerHTML = missions.map((mission) => {
        const ratio = Math.round(Number(mission.progress_ratio || 0) * 100);
        const objectives = Array.isArray(mission.objectives) ? mission.objectives : [];
        const rewards = Array.isArray(mission.rewards) ? mission.rewards : [];
        const canClaim = Boolean(mission.can_claim);
        const claimed = Boolean(mission.rewards_claimed);
        const status = String(mission.status || 'active');

        return `
            <article class="inventory-mission-card is-${escapeHtml(status)}${canClaim ? ' is-claimable' : ''}">
                <header>
                    <div>
                        <span class="inventory-mission-type">${escapeHtml(missionTypeLabel(mission.mission_type))}</span>
                        <h3>${escapeHtml(mission.name || mission.code)}</h3>
                    </div>
                    <strong>${ratio}%</strong>
                </header>
                <p>${escapeHtml(mission.summary || '')}</p>
                <div class="inventory-mission-progress"><i style="width:${ratio}%"></i></div>
                <ul class="inventory-mission-objectives">
                    ${objectives.map((objective) => `
                        <li class="${objective.met ? 'is-met' : ''}">${escapeHtml(objectiveLabel(objective))}</li>
                    `).join('') || '<li>Sem objetivos configurados.</li>'}
                </ul>
                ${rewards.length ? `<p class="inventory-mission-rewards"><small>Recompensas: ${escapeHtml(rewards.map((reward) => {
                    if (reward.type === 'gold') return `${reward.amount} G`;
                    if (reward.type === 'item') return `${reward.quantity || 1}x ${reward.item_definition_code}`;
                    if (reward.type === 'unlock_hint') return `unlock ${reward.biome_code || ''}`;
                    return reward.type || 'bonus';
                }).join(' · '))}</small></p>` : ''}
                <footer>
                    ${canClaim ? `<button type="button" class="inventory-button is-primary" data-mission-claim="${escapeHtml(mission.code)}">Reivindicar</button>` : ''}
                    ${claimed ? '<span class="inventory-mission-claimed">Recompensa coletada</span>' : ''}
                    ${status === 'completed' && !canClaim && !claimed ? '<span class="inventory-mission-claimed">Concluida</span>' : ''}
                </footer>
            </article>
        `;
    }).join('');
}

export function bindMissionsUi() {
    const root = missionDeps?.missionsPanelRoot?.();
    if (!root || root.dataset.boundMissions === '1') return;
    root.dataset.boundMissions = '1';

    root.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const filterButton = target?.closest('[data-mission-filter]');
        if (filterButton) {
            missionFilter = filterButton.getAttribute('data-mission-filter') || 'active';
            renderMissionsJournal();
            return;
        }

        const claimButton = target?.closest('[data-mission-claim]');
        if (claimButton) {
            const code = claimButton.getAttribute('data-mission-claim');
            if (!code || !missionDeps?.apiFetch) return;
            claimButton.disabled = true;
            try {
                await missionDeps.apiFetch('/api/missions/claim', {
                    method: 'POST',
                    body: { mission_code: code },
                });
                missionDeps.toast?.('Recompensa reivindicada.', 'success');
                await loadMissionsJournal();
                await missionDeps.loadInventory?.();
            } catch (error) {
                missionDeps.handleError?.(error, 'Nao foi possivel reivindicar a recompensa.');
            } finally {
                claimButton.disabled = false;
            }
        }
    });
}
