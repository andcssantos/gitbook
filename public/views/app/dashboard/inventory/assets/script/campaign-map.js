/**
 * Mapa de campanha idle (Mundo 1) — Fase 0/1 do roadmap.
 */

let campaignDeps = null;
let campaignWorld = null;
let campaignLoading = false;
let selectedNodeCode = null;

export function configureCampaignMap(deps) {
    campaignDeps = deps || {};
}

function apiFetch(...args) {
    return campaignDeps?.apiFetch?.(...args);
}

function escapeHtml(value) {
    return (campaignDeps?.escapeHtml || ((v) => String(v ?? '')))(value);
}

export function getCampaignWorld() {
    return campaignWorld;
}

export async function loadCampaignWorld(worldCode = 'mundo_1_bosque') {
    if (!apiFetch || campaignLoading) return campaignWorld;
    campaignLoading = true;
    try {
        const response = await apiFetch(`/api/campaign/worlds/${encodeURIComponent(worldCode)}`);
        campaignWorld = response.data?.world || null;
        return campaignWorld;
    } catch (error) {
        campaignWorld = null;
        campaignDeps?.handleError?.(error, 'Nao foi possivel carregar a campanha.');
        return null;
    } finally {
        campaignLoading = false;
    }
}

function statusLabel(status) {
    if (status === 'cleared') return 'Concluida';
    if (status === 'available') return 'Disponivel';
    if (status === 'teaser') return 'Em breve';
    return 'Bloqueada';
}

function renderLobby(node) {
    if (!node) {
        return `
            <div class="inventory-campaign-lobby is-empty">
                <strong>Mundo 1 · Bosque</strong>
                <p>Clique em um pin para ver a fase, o vilarejo ou o teaser.</p>
            </div>
        `;
    }

    const lobby = node.lobby || {};
    const preview = lobby.preview || {};
    const scene = node.scene_url
        ? `<div class="inventory-campaign-lobby-scene" style="background-image:url('${escapeHtml(node.scene_url)}')"></div>`
        : '';

    return `
        <div class="inventory-campaign-lobby" data-campaign-lobby="${escapeHtml(node.code)}">
            ${scene}
            <div class="inventory-campaign-lobby-body">
                <p class="inventory-kicker">${escapeHtml(statusLabel(node.status))} · ${escapeHtml(node.type)}</p>
                <strong>${escapeHtml(lobby.title || node.label)}</strong>
                <p>${escapeHtml(lobby.body || '')}</p>
                ${preview.waves ? `<span class="inventory-campaign-lobby-meta">${Number(preview.waves)} ondas · chefes: ${(preview.boss_waves || []).join(', ') || '—'}</span>` : ''}
                <button type="button" class="inventory-button is-primary" data-campaign-lobby-cta ${lobby.cta_enabled ? '' : 'disabled'}>
                    ${escapeHtml(lobby.cta || 'OK')}
                </button>
            </div>
        </div>
    `;
}

export function renderCampaignWorldMap() {
    const world = campaignWorld;
    if (!world) {
        return `
            <section class="inventory-campaign-stage">
                <p class="inventory-exploration-muted">Campanha indisponivel. Rode migrate + seed 017.</p>
            </section>
        `;
    }

    const selected = (world.nodes || []).find((node) => node.code === selectedNodeCode)
        || (world.nodes || []).find((node) => node.status === 'available')
        || (world.nodes || [])[0]
        || null;

    const pins = (world.nodes || []).map((node) => {
        const active = selected?.code === node.code;
        return `
            <button type="button"
                class="inventory-campaign-pin is-${escapeHtml(node.status)}${active ? ' is-active' : ''} is-${escapeHtml(node.type)}"
                style="left:${Number(node.map_x)}%; top:${Number(node.map_y)}%;"
                data-campaign-node="${escapeHtml(node.code)}"
                title="${escapeHtml(node.label)}">
                <img src="${escapeHtml(node.pin_url)}" alt="" aria-hidden="true">
                <span>${escapeHtml(node.label)}</span>
            </button>
        `;
    }).join('');

    return `
        <section class="inventory-campaign-stage">
            <div class="inventory-campaign-map" style="background-image:url('${escapeHtml(world.background_url)}')">
                <div class="inventory-campaign-map-label">
                    <strong>${escapeHtml(world.name)}</strong>
                    <span>${escapeHtml(world.summary || 'Clique nos pins para explorar fases e o vilarejo.')}</span>
                </div>
                <div class="inventory-campaign-pins">${pins}</div>
            </div>
            ${renderLobby(selected)}
        </section>
    `;
}

export function bindCampaignMapInteractions(root) {
    if (!root) return;
    root.querySelectorAll('[data-campaign-node]').forEach((button) => {
        button.addEventListener('click', () => {
            selectedNodeCode = button.getAttribute('data-campaign-node');
            campaignDeps?.renderExplorationPanel?.();
        });
    });
}
