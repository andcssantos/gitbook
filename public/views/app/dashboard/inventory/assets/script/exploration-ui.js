/**
 * UI do mapa-mundo / exploração (extraído do main.js — Sprint G).
 * Arena permanece em expedition-arena.js; este módulo orquestra painel, biomas e POIs.
 */

import {
    bindExplorationArenaInteractions,
    getExplorationArenaState,
    loadExplorationArena,
    renderExplorationArenaSection,
    resumeArenaCombatLoop,
    stopArenaWander,
} from './expedition-arena.js';

let uiDeps = null;

let explorationPanelOpen = false;
let explorationLoading = false;
let explorationActionInFlight = false;
let explorationBiomeCode = 'bosque_inicial';
let explorationObjects = [];
let explorationExpedition = null;
let explorationModifiers = null;
let explorationBiomes = [];
let explorationMap = null;
let explorationPosition = null;
let explorationVitals = null;
let explorationPositionInFlight = false;
let explorationStartInFlight = false;
let explorationRestInFlight = false;
let explorationControlsInitialized = false;
let explorationWorldOffset = { x: 0, y: 0 };

export function configureExplorationUi(deps) {
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

function toast(...args) {
    return d().toast?.(...args);
}

function handleError(...args) {
    return d().handleError?.(...args);
}

function formatGameMoney(...args) {
    return d().formatGameMoney?.(...args) ?? String(args[0] ?? '');
}

function syncDrawerUi() {
    return d().syncDrawerUi?.();
}

function closeMissionsPanel() {
    return d().closeMissionsPanel?.();
}

function reloadContainerPanelsOnly() {
    return d().reloadContainerPanelsOnly?.();
}

function invalidateContainerCache() {
    return d().invalidateContainerCache?.();
}

function explorationPanelRootEl() {
    return d().explorationPanelRoot || null;
}

function itemIndexMap() {
    return d().itemIndex || new Map();
}

export function isExplorationPanelOpen() {
    return explorationPanelOpen;
}

export function getExplorationBiomeCode() {
    return explorationBiomeCode;
}

export function getExplorationExpedition() {
    return explorationExpedition;
}

export function setExplorationExpedition(value) {
    explorationExpedition = value;
}

export function setExplorationPosition(value) {
    explorationPosition = value;
}

export function setExplorationObjects(value) {
    explorationObjects = value;
}

export function setExplorationMap(value) {
    explorationMap = value;
}

export function setExplorationModifiers(value) {
    explorationModifiers = value;
}

export function setExplorationPanelOpenFlag(value) {
    explorationPanelOpen = Boolean(value);
    if (!explorationPanelOpen) {
        document.body.classList.remove('inventory-exploration-fullscreen');
    }
}

function parseItemBaseConfig(item) {
    const raw = item?.definition?.base_config;
    if (!raw) return {};
    if (typeof raw === 'object') return raw;
    try {
        return JSON.parse(raw);
    } catch {
        return {};
    }
}

function toolTypeFromInventoryItem(item) {
    const config = parseItemBaseConfig(item);
    const toolType = String(config.tool_type || config.tool_family || '').toLowerCase().replace(/[^a-z0-9_]/g, '');
    if (toolType) return toolType;
    return String(item?.tool_mastery?.tool_type || '').toLowerCase();
}

function listOwnedToolsByType(toolType) {
    const normalized = String(toolType || '').toLowerCase();
    if (!normalized) return [];

    const matches = [];
    itemIndexMap().forEach((entry) => {
        const item = entry?.item;
        if (!item || item?.definition?.category_code !== 'tool') return;
        if (toolTypeFromInventoryItem(item) !== normalized) return;
        matches.push(item);
    });

    return matches.sort((left, right) => String(left?.item_name || left?.definition?.name || '').localeCompare(String(right?.item_name || right?.definition?.name || '')));
}

function explorationActionLabel(actionCode) {
    const labels = {
        analyze_magnifier: 'Analisar com lupa',
        harvest_shears: 'Colher com tesoura',
        clear_shears: 'Cortar com tesoura',
        chop_hatchet: 'Cortar com machadinha',
        mine_pickaxe: 'Minerar com picareta',
        pick_lock: 'Arrombar com lockpick',
        force_open: 'Forcar abertura (arriscado)',
        dig_shovel: 'Cavar com pa',
        pluck_tweezers: 'Coletar com pinca',
    };
    return labels[actionCode] || actionCode;
}

function explorationActionDisplayLabel(action) {
    if (action && typeof action === 'object' && typeof action.action_label === 'string' && action.action_label.trim() !== '') {
        return action.action_label;
    }
    const code = typeof action === 'object' ? action.action_code : action;
    return explorationActionLabel(code);
}

function explorationKindLabel(kind) {
    const labels = {
        flora: 'Flora',
        wood: 'Madeira',
        stone: 'Pedra',
        container: 'Container',
        water: 'Agua',
    };
    return labels[kind] || kind || 'Objeto';
}

function explorationConstellationEffectLabel(effects) {
    if (!effects || typeof effects !== 'object') return '';
    const parts = [];
    if (effects.discovery_radius_bonus) {
        parts.push(`Raio +${Number(effects.discovery_radius_bonus).toFixed(1)}`);
    }
    if (effects.trap_chance_reduction) {
        parts.push(`Armadilha -${Math.round(Number(effects.trap_chance_reduction) * 100)}%`);
    }
    if (effects.expedition_loot_bonus) {
        parts.push(`Loot +${Math.round(Number(effects.expedition_loot_bonus) * 100)}%`);
    }
    return parts.join(' · ');
}

function explorationActionRiskTooltip(risk) {
    if (!risk) return '';
    if (risk.tooltip) return String(risk.tooltip);
    const parts = [];
    if (risk.base_trap_chance !== undefined) {
        parts.push(`Chance base: ${Math.round(Number(risk.base_trap_chance) * 100)}%`);
    }
    if (Number(risk.trap_reduction_applied) > 0) {
        parts.push(`Mitigacao: -${Math.round(Number(risk.trap_reduction_applied) * 100)}%`);
    }
    if (risk.trap_chance !== undefined) {
        parts.push(`Chance efetiva: ${Math.round(Number(risk.trap_chance) * 100)}%`);
    }
    if (Number(risk.fail_chance) > 0) {
        parts.push(`Falha total: ${Math.round(Number(risk.fail_chance) * 100)}%`);
    }
    return parts.join(' · ');
}

async function loadExplorationBiomes() {
    try {
        const response = await apiFetch('/api/exploration/biomes');
        explorationBiomes = response.data?.biomes || [];
    } catch {
        explorationBiomes = [];
    }
}

async function loadExplorationObjects(options = {}) {
    if (explorationLoading) return;
    explorationLoading = true;
    const silent = Boolean(options?.silent);

    try {
        if (!silent) {
            renderExplorationPanel();
        }
        const response = await apiFetch(`/api/exploration/biomes/${encodeURIComponent(explorationBiomeCode)}/objects`);
        explorationObjects = response.data?.objects || [];
        explorationExpedition = response.data?.expedition || null;
        explorationModifiers = response.data?.modifiers || null;
        explorationMap = response.data?.map || null;
        explorationPosition = response.data?.position || null;
        explorationVitals = response.data?.vitals || null;
        await loadExplorationArena(explorationBiomeCode);
    } catch (error) {
        explorationObjects = [];
        handleError(error, 'Nao foi possivel carregar a exploracao.');
    } finally {
        explorationLoading = false;
        if (!silent) {
            renderExplorationPanel();
        }
    }
}

function formatRespawnCountdown(seconds) {
    const total = Math.max(0, Number(seconds || 0));
    if (total <= 0) return 'Respawn disponivel';
    const minutes = Math.floor(total / 60);
    const secs = total % 60;
    return `Respawn em ${minutes}m ${secs}s`;
}

function renderExplorationObjectCard(object) {
    const actions = Array.isArray(object?.available_actions) ? object.available_actions : [];
    const analyzeAction = actions.find((entry) => entry.action_code === 'analyze_magnifier');
    const interactActions = actions.filter((entry) => entry.action_code !== 'analyze_magnifier' && entry.available);
    const magnifier = listOwnedToolsByType('magnifier')[0] || null;
    const interactToolType = interactActions[0]?.required_tool_type || object?.recommended_tool?.tool_type || '';
    const interactTool = listOwnedToolsByType(interactToolType)[0] || null;
    const expeditionReady = !explorationExpedition?.required || explorationExpedition?.active;
    let stateLabel = object?.reveal_tier > 0 ? `Analisado ${object.reveal_tier}/${object.max_tier || '?'}` : 'Desconhecido';
    if (object?.state === 'depleted') {
        stateLabel = formatRespawnCountdown(object?.respawn_in_seconds);
    }
    const stateClass = object?.state === 'depleted'
        ? 'is-depleted'
        : (!object?.discovered ? 'is-fog' : (object?.reveal_tier > 0 ? 'is-revealed' : 'is-unknown'));
    if (!object?.discovered) {
        stateLabel = 'Longe demais';
    }

    const actionButtons = [];
    if (object?.discovered && analyzeAction?.available && expeditionReady) {
        actionButtons.push(`
            <button type="button" class="inventory-button inventory-exploration-action" data-exploration-analyze="${escapeHtml(object.public_id)}" ${magnifier ? '' : 'disabled title="Voce precisa de uma lupa no inventario"'}>
                ${explorationActionLabel('analyze_magnifier')}
            </button>
        `);
    }
    if (object?.discovered && interactActions.length && expeditionReady) {
        interactActions.forEach((interactAction) => {
            const toolType = interactAction?.required_tool_type || '';
            const tool = listOwnedToolsByType(toolType)[0] || null;
            const risk = interactAction?.risk;
            const riskClass = risk?.is_force_open ? 'is-danger' : (risk ? 'has-risk' : '');
            const riskHint = risk?.label ? ` · ${risk.label}` : '';
            const riskTooltip = explorationActionRiskTooltip(risk);
            const trapChanceLabel = risk?.trap_chance !== undefined
                ? ` (${Math.round(Number(risk.trap_chance) * 100)}%)`
                : '';
            actionButtons.push(`
                <button type="button"
                    class="inventory-button inventory-exploration-action is-primary ${riskClass}"
                    data-exploration-action="${escapeHtml(interactAction.action_code)}"
                    data-exploration-object="${escapeHtml(object.public_id)}"
                    ${tool ? '' : `disabled title="Ferramenta necessaria: ${escapeHtml(toolType)}"`}
                    ${riskTooltip ? `title="${escapeHtml(riskTooltip)}"` : ''}>
                    ${explorationActionDisplayLabel(interactAction)}${riskHint ? `<span class="inventory-exploration-risk">${escapeHtml(riskHint + trapChanceLabel)}</span>` : ''}
                </button>
            `);
        });
    }

    return `
        <article class="inventory-exploration-card ${stateClass}">
            <header class="inventory-exploration-card-head">
                <div>
                    <span class="inventory-exploration-kind">${escapeHtml(explorationKindLabel(object.kind))}</span>
                    <h3>${escapeHtml(object.name || '???')}</h3>
                    <p>${escapeHtml(object.position_label || object.summary || object.flavor || '')}</p>
                </div>
                <span class="inventory-exploration-state">${escapeHtml(stateLabel)}</span>
            </header>
            <p class="inventory-exploration-flavor">${escapeHtml(object.flavor || '')}</p>
            ${object?.recommended_tool?.label ? `<p class="inventory-exploration-hint">Ferramenta sugerida: <strong>${escapeHtml(object.recommended_tool.label)}</strong></p>` : ''}
            <div class="inventory-exploration-card-actions">
                ${actionButtons.join('') || `<span class="inventory-exploration-muted">${!object?.discovered ? 'Aproxime-se no mapa local para este ponto aparecer.' : (object?.state === 'depleted' ? 'Aguardando respawn.' : (expeditionReady ? 'Sem acoes disponiveis.' : 'Inicie uma expedicao para interagir.'))}</span>`}
            </div>
        </article>
    `;
}

function renderExplorationGuide() {
    const expedition = explorationExpedition || {};
    const discoveredCount = explorationObjects.filter((object) => object.discovered).length;
    const revealedCount = explorationObjects.filter((object) => Number(object.reveal_tier || 0) > 0).length;
    const steps = [];

    if (expedition.required && !expedition.active && !expedition.ready_to_claim && !expedition.claimable) {
        steps.push({
            state: 'current',
            title: 'Passo 1 · Inicie a expedicao',
            text: 'Sem expedicao ativa voce nao pode andar no mapa nem interagir com os pontos.',
        });
    } else if (expedition.ready_to_claim || expedition.claimable) {
        return `
            <section class="inventory-exploration-guide is-claim-only">
                <div class="inventory-exploration-guide-head">
                    <strong>Proximo passo</strong>
                    <span>1 acao pendente</span>
                </div>
                <ol class="inventory-exploration-guide-list">
                    <li class="inventory-exploration-guide-step is-current">
                        <strong>Reivindique a recompensa</strong>
                        <span>Use o botao dourado acima. Depois disso o mapa libera uma nova expedicao.</span>
                    </li>
                </ol>
            </section>
        `;
    } else {
        steps.push({
            state: 'done',
            title: 'Passo 1 · Expedicao',
            text: expedition.active ? 'Expedicao em andamento.' : 'Este bioma nao exige expedicao.',
        });
    }

    if (!expedition.active && expedition.required) {
        steps.push({
            state: 'locked',
            title: 'Passo 2 · Ande na arena',
            text: 'Clique no fundo do mapa para se mover. Monstros (◆) podem ser atacados; loot (□) cai ao derrotar.',
        });
        steps.push({
            state: 'locked',
            title: 'Passo 3 · Analise com lupa',
            text: 'Chegue perto dos pontos (◆) para revelar o que sao.',
        });
        steps.push({
            state: 'locked',
            title: 'Passo 4 · Use a ferramenta certa',
            text: 'Apos analisar, colete com tesoura, machado, lockpick etc.',
        });
    } else if (discoveredCount === 0) {
        steps.push({
            state: 'current',
            title: 'Passo 2 · Ande na arena',
            text: 'Clique no fundo do mapa para se mover. Voce so enxerga pontos dentro do raio de descoberta.',
        });
        steps.push({
            state: 'locked',
            title: 'Passo 3 · Analise com lupa',
            text: 'Quando um ponto aparecer perto de voce, use a lupa para identifica-lo.',
        });
        steps.push({
            state: 'locked',
            title: 'Passo 4 · Use a ferramenta certa',
            text: 'Depois da analise, o card mostra qual ferramenta usar.',
        });
    } else if (revealedCount === 0) {
        steps.push({
            state: 'done',
            title: 'Passo 2 · Ande na arena',
            text: `${discoveredCount} ponto(s) visivel(is). Continue se movendo se ainda houver "Longe demais".`,
        });
        steps.push({
            state: 'current',
            title: 'Passo 3 · Analise com lupa',
            text: 'Clique em "Analisar com lupa" nos cards revelados para saber o que coletar.',
        });
        steps.push({
            state: 'locked',
            title: 'Passo 4 · Use a ferramenta certa',
            text: 'A analise libera os botoes de colheita, minerio ou arrombamento.',
        });
    } else {
        steps.push({
            state: 'done',
            title: 'Passo 2 · Ande na arena',
            text: 'Voce ja esta explorando o bioma. Monstros podem ser atacados na arena.',
        });
        steps.push({
            state: 'done',
            title: 'Passo 3 · Analise com lupa',
            text: `${revealedCount} ponto(s) ja analisado(s).`,
        });
        steps.push({
            state: 'current',
            title: 'Passo 4 · Use a ferramenta certa',
            text: 'Use o botao de acao em cada card. Caixotes podem ter risco de armadilha.',
        });
    }

    const items = steps.map((step) => `
        <li class="inventory-exploration-guide-step is-${escapeHtml(step.state)}">
            <strong>${escapeHtml(step.title)}</strong>
            <span>${escapeHtml(step.text)}</span>
        </li>
    `).join('');

    return `
        <section class="inventory-exploration-guide">
            <div class="inventory-exploration-guide-head">
                <strong>Como explorar</strong>
                <span>● voce · ◆ monstro/ponto · □ loot · ??? ainda nao identificado</span>
            </div>
            <ol class="inventory-exploration-guide-list">${items}</ol>
        </section>
    `;
}

function explorationWorldArt(biomeCode, biome = null) {
    if (biome?.world_art_url) return String(biome.world_art_url);
    const map = {
        bosque_inicial: '/assets/game/words/World__12_.PNG',
        costa_salobra: '/assets/game/words/World__18_.PNG',
        pantano_venenoso: '/assets/game/words/World__15_.PNG',
        vale_dos_reis: '/assets/game/words/World__21_.PNG',
        gruta_ecoante: '/assets/game/words/World__09_.PNG',
        ruinas_afundadas: '/assets/game/words/World__07_.PNG',
    };
    return map[String(biomeCode || '')] || '/assets/game/words/World__12_.PNG';
}

function explorationWorldPin(biomeCode, biome = null) {
    if (biome?.world_pin_url) return String(biome.world_pin_url);
    const map = {
        bosque_inicial: '/assets/game/ui_icons/MapPins/MapPin__4_.PNG',
        costa_salobra: '/assets/game/ui_icons/MapPins/MapPin__21_.PNG',
        pantano_venenoso: '/assets/game/ui_icons/MapPins/MapPin__9_.PNG',
        vale_dos_reis: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG',
        gruta_ecoante: '/assets/game/ui_icons/MapPins/MapPin__11_.PNG',
        ruinas_afundadas: '/assets/game/ui_icons/MapPins/MapPin__14_.PNG',
    };
    return map[String(biomeCode || '')] || '/assets/game/ui_icons/MapPins/MapPin__4_.PNG';
}

function explorationWorldStructure(biomeCode, biome = null) {
    if (biome?.world_structure_url) return String(biome.world_structure_url);
    const map = {
        bosque_inicial: '/assets/game/buildings/Buildings/Building__23_.PNG',
        costa_salobra: '/assets/game/buildings/Wonders/Pit__3_.PNG',
        pantano_venenoso: '/assets/game/buildings/Wonders/Pit__1_.PNG',
        vale_dos_reis: '/assets/game/buildings/Church_Temples/PrayerSite__3_.PNG',
        gruta_ecoante: '/assets/game/buildings/Wonders/Pit__2_.PNG',
        ruinas_afundadas: '/assets/game/buildings/Buildings/Building__18_.PNG',
    };
    return map[String(biomeCode || '')] || '/assets/game/buildings/Buildings/Building__23_.PNG';
}

function explorationWorldLandmarks(biomeCode, biome = null) {
    if (Array.isArray(biome?.landmarks) && biome.landmarks.length) {
        return biome.landmarks.map((entry) => ({
            x: Number(entry.x ?? 0),
            y: Number(entry.y ?? 0),
            icon: String(entry.icon || entry.icon_url || ''),
            label: String(entry.label || ''),
        }));
    }
    const code = String(biomeCode || '');
    if (code === 'costa_salobra') {
        return [
            { x: 1.2, y: 2.5, icon: '/assets/game/ui_icons/MapPins/MapPin__14_.PNG', label: 'Ruinas Costeiras' },
            { x: 2.8, y: 1.7, icon: '/assets/game/buildings/Wonders/Pit__3_.PNG', label: 'Fenda Salobra' },
            { x: 4.3, y: 2.6, icon: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG', label: 'Cofre Naufragado' },
            { x: 5.1, y: 1.2, icon: '/assets/game/ui_icons/MapPins/MapPin__21_.PNG', label: 'Ponto de Vigia' },
            { x: 0.9, y: 1.2, icon: '/assets/game/buildings/Buildings/Building__18_.PNG', label: 'Acampamento de Areia' },
            { x: 3.7, y: 3.0, icon: '/assets/game/ui_icons/MapPins/MapPin__9_.PNG', label: 'Poente Afogado' },
            { x: 5.4, y: 2.4, icon: '/assets/game/buildings/Wonders/Pit__1_.PNG', label: 'Poço Salgado' },
        ];
    }

    if (code === 'pantano_venenoso') {
        return [
            { x: 1.5, y: 1.5, icon: '/assets/game/ui_icons/MapPins/MapPin__9_.PNG', label: 'Junco Toxico' },
            { x: 3.5, y: 1.0, icon: '/assets/game/buildings/Wonders/Pit__1_.PNG', label: 'Lamaçal' },
            { x: 4.5, y: 2.5, icon: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG', label: 'Caixote Afundado' },
            { x: 2.0, y: 3.0, icon: '/assets/game/ui_icons/MapPins/MapPin__11_.PNG', label: 'Floracao Fungica' },
            { x: 5.5, y: 1.5, icon: '/assets/game/buildings/Wonders/Pit__4_.PNG', label: 'Cache do Brejo' },
            { x: 2.5, y: 2.0, icon: '/assets/game/buildings/Buildings/Building__8_.PNG', label: 'Toco Podre' },
            { x: 6.0, y: 3.0, icon: '/assets/game/buildings/Wonders/Pit__2_.PNG', label: 'Poca Venenosa' },
        ];
    }

    if (code === 'vale_dos_reis') {
        return [
            { x: 2.0, y: 2.0, icon: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG', label: 'Obelisco Real' },
            { x: 4.5, y: 1.5, icon: '/assets/game/ui_icons/MapPins/MapPin__14_.PNG', label: 'Bau Cerimonial' },
            { x: 3.0, y: 3.0, icon: '/assets/game/buildings/Church_Temples/PrayerSite__3_.PNG', label: 'Altar de Oferendas' },
            { x: 1.5, y: 1.0, icon: '/assets/game/ui_icons/MapPins/MapPin__4_.PNG', label: 'Estela do Guardiao' },
            { x: 5.5, y: 2.5, icon: '/assets/game/ui_icons/MapPins/MapPin__11_.PNG', label: 'Jardim da Coroa' },
            { x: 6.5, y: 1.5, icon: '/assets/game/ui_icons/MapPins/MapPin__21_.PNG', label: 'Urna Selada' },
            { x: 5.0, y: 3.5, icon: '/assets/game/buildings/Buildings/Building__23_.PNG', label: 'Pavilhao Caido' },
        ];
    }

    return [
        { x: 1.1, y: 1.6, icon: '/assets/game/ui_icons/MapPins/MapPin__4_.PNG', label: 'Clareira Selvagem' },
        { x: 2.7, y: 2.2, icon: '/assets/game/buildings/Church_Temples/PrayerSite__3_.PNG', label: 'Altar Antigo' },
        { x: 4.1, y: 1.1, icon: '/assets/game/buildings/Wonders/Pit__4_.PNG', label: 'Entrada Oculta' },
        { x: 4.8, y: 2.7, icon: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG', label: 'Cache Perdido' },
        { x: 1.8, y: 2.9, icon: '/assets/game/buildings/Buildings/Building__8_.PNG', label: 'Cabana Caida' },
        { x: 3.4, y: 0.9, icon: '/assets/game/ui_icons/MapPins/MapPin__11_.PNG', label: 'Ninho Elevado' },
        { x: 5.2, y: 1.9, icon: '/assets/game/buildings/Wonders/Pit__2_.PNG', label: 'Grota de Espinhos' },
    ];
}

function renderExplorationWorldMap() {
    const expeditionActive = Boolean(explorationExpedition?.active);
    const worldWidth = 8;
    const worldHeight = 6;
    const selectedBiome = (explorationBiomes || []).find((entry) => entry.code === explorationBiomeCode) || null;
    const biomeNodes = (explorationBiomes.length ? explorationBiomes : [{
        code: 'bosque_inicial',
        name: 'Bosque Inicial',
        status: 'available',
        map_node: { x: 2, y: 3 },
    }]).map((biome) => {
        const active = biome.code === explorationBiomeCode;
        const locked = biome.status === 'locked' || biome.unlocked === false;
        const entry = biome.entry || {};
        const softRisk = !locked && entry.mode === 'soft' && entry.met === false;
        const hardBlocked = !locked && entry.mode === 'hard' && entry.allowed === false;
        const nodeTitle = explorationBiomeNodeTitle(biome, locked, softRisk, hardBlocked);
        const nodeX = Number(biome.map_node?.x ?? 0);
        const nodeY = Number(biome.map_node?.y ?? 0);
        return `
            <button type="button"
                class="inventory-exploration-world-node ${active ? 'is-active' : ''} ${locked ? 'is-locked' : ''} ${softRisk ? 'is-soft-risk' : ''} ${hardBlocked ? 'is-entry-blocked' : ''}"
                style="--node-x:${nodeX}; --node-y:${nodeY};"
                data-exploration-biome="${escapeHtml(biome.code)}"
                data-world-interactive="biome"
                data-exploration-enter="${active && !locked && !hardBlocked ? '1' : '0'}"
                title="${escapeHtml(nodeTitle)}"
                ${locked ? 'disabled' : ''}>
                <img src="${escapeHtml(explorationWorldPin(biome.code, biome))}" alt="" aria-hidden="true">
                <span>${escapeHtml(biome.name || biome.code)}</span>
                ${softRisk ? '<i class="inventory-exploration-world-badge is-soft">Risco</i>' : ''}
                ${hardBlocked ? '<i class="inventory-exploration-world-badge is-hard">Kit</i>' : ''}
            </button>
        `;
    }).join('');

    const structures = (explorationBiomes.length ? explorationBiomes : [{
        code: 'bosque_inicial',
        name: 'Bosque Inicial',
        map_node: { x: 2, y: 3 },
    }]).map((biome) => {
        const nodeX = Number(biome.map_node?.x ?? 0) + 0.25;
        const nodeY = Number(biome.map_node?.y ?? 0) - 0.35;
        return `
            <span class="inventory-exploration-world-structure" style="--node-x:${nodeX}; --node-y:${nodeY};">
                <img src="${escapeHtml(explorationWorldStructure(biome.code, biome))}" alt="" aria-hidden="true">
            </span>
        `;
    }).join('');

    const landmarks = explorationWorldLandmarks(explorationBiomeCode, selectedBiome).map((entry) => `
        <span class="inventory-exploration-world-landmark" style="--node-x:${Number(entry.x)}; --node-y:${Number(entry.y)};">
            <img src="${escapeHtml(entry.icon)}" alt="" aria-hidden="true">
            <span>${escapeHtml(entry.label)}</span>
        </span>
    `).join('');

    const arenaSection = renderExplorationArenaSection({
        biomeCode: explorationBiomeCode,
        expedition: explorationExpedition,
        fallbackMap: explorationMap,
        fallbackPosition: explorationPosition,
    });

    if (expeditionActive) {
        return `
            <section class="inventory-exploration-stage is-arena-only">
                ${arenaSection}
            </section>
        `;
    }

    const prep = explorationBiomePrepSummary(selectedBiome || {
        code: explorationBiomeCode,
        name: 'Regiao',
    });
    const readyToClaim = Boolean(explorationExpedition?.ready_to_claim || explorationExpedition?.claimable);
    const energy = explorationVitals?.energy || {};
    const energyCurrent = Number(energy.current || 0);
    const energyMax = Number(energy.max || 0);
    const minEnergy = Number(explorationVitals?.min_energy_to_start || 5);
    const canStartEnergy = explorationVitals
        ? Boolean(explorationVitals.can_start_expedition)
        : true;
    const lowEnergy = !readyToClaim && !canStartEnergy;
    const startBlocked = prep.hardBlocked || lowEnergy;
    const startLabel = explorationStartInFlight
        ? 'Entrando...'
        : (readyToClaim
            ? 'Reivindicar recompensas'
            : (lowEnergy
                ? `Energia baixa (${energyCurrent}/${energyMax})`
                : (prep.hardBlocked
                    ? 'Prepare o kit'
                    : (explorationExpedition?.required ? `Iniciar expedicao (${Number(explorationExpedition?.default_duration_minutes || 30)} min)` : 'Entrar no mapa'))));
    const ctaSummary = readyToClaim
        ? 'Expedicao encerrada. Reivindique antes de entrar de novo.'
        : (lowEnergy
            ? `Precisa de pelo menos ${minEnergy} energia para iniciar. Descansar recupera ~4/min.`
            : prep.summary);
    const ctaClass = `${prep.hardBlocked || lowEnergy ? 'is-blocked' : ''} ${prep.softRisk ? 'is-soft-risk' : ''}`.trim();
    const energyLine = !readyToClaim && energyMax > 0
        ? `<small class="inventory-exploration-energy-line${lowEnergy ? ' is-low' : ''}">Energia ${energyCurrent}/${energyMax} · minimo ${minEnergy}</small>`
        : '';

    return `
        <section class="inventory-exploration-stage is-world-only">
            <div class="inventory-exploration-world-map" style="--world-width:${worldWidth}; --world-height:${worldHeight};">
                <p class="inventory-exploration-map-label"><strong>Mapa do Mundo</strong><span>Arraste para navegar · clique num bioma · use o botao para entrar</span></p>
                <div class="inventory-exploration-world-viewport" data-exploration-world-viewport>
                    <div class="inventory-exploration-world-camera" data-exploration-world-camera>
                        <div class="inventory-exploration-world-grid" style="background-image:url('${escapeHtml(explorationWorldArt(explorationBiomeCode, selectedBiome))}')">
                            ${landmarks}
                            ${structures}
                            ${biomeNodes}
                        </div>
                    </div>
                    <div class="inventory-exploration-world-cta ${escapeHtml(ctaClass)}" data-world-interactive="cta">
                        <strong>${escapeHtml(selectedBiome?.name || selectedBiome?.code || 'Regiao')}</strong>
                        <span>${escapeHtml(ctaSummary)}</span>
                        ${energyLine}
                        ${readyToClaim || lowEnergy ? '' : prep.detailsHtml}
                        <div class="inventory-exploration-cta-actions">
                            <button
                                type="button"
                                class="inventory-button is-primary"
                                ${readyToClaim ? 'data-exploration-claim' : 'data-exploration-start-map'}
                                data-world-interactive="cta"
                                ${explorationStartInFlight || (!readyToClaim && startBlocked) ? 'disabled' : ''}>
                                ${escapeHtml(startLabel)}
                            </button>
                            ${lowEnergy ? `
                                <button type="button" class="inventory-button" data-exploration-rest data-world-interactive="cta" ${explorationRestInFlight ? 'disabled' : ''}>
                                    ${explorationRestInFlight ? 'Descansando...' : 'Descansar 5 min'}
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    `;
}


function explorationBiomePrepSummary(biome) {
    const locked = biome?.status === 'locked' || biome?.unlocked === false;
    const entry = biome?.entry || {};
    const requirements = biome?.requirements || {};
    const progress = biome?.progress || {};
    const softRisk = !locked && entry.mode === 'soft' && entry.met === false;
    const hardBlocked = !locked && (entry.mode === 'hard' && entry.allowed === false);
    const entryReqs = Array.isArray(entry.requirements) ? entry.requirements : [];

    if (locked) {
        const unlockBits = [];
        if (requirements.exploration_level_min) {
            unlockBits.push(`Exploracao nv.${requirements.exploration_level_min} (agora ${progress.exploration_level || 1})`);
        }
        if (requirements.completed_expeditions_min) {
            unlockBits.push(`${requirements.completed_expeditions_min} expedicao(oes) (agora ${progress.completed_expeditions || 0})`);
        }
        if (Array.isArray(requirements) && requirements.length) {
            requirements.forEach((req) => {
                if (req?.label) unlockBits.push(req.label);
                else if (req?.type) unlockBits.push(String(req.type));
            });
        }
        return {
            hardBlocked: true,
            softRisk: false,
            summary: unlockBits.length
                ? `Regiao bloqueada. ${unlockBits.join(' · ')}`
                : 'Regiao ainda nao desbloqueada.',
            detailsHtml: '',
        };
    }

    const lines = entryReqs.map((req) => {
        const met = req.met === true;
        const label = req.label || req.item_definition_code || req.stat_code || req.type || 'Requisito';
        return `<li class="${met ? 'is-met' : 'is-missing'}">${escapeHtml(label)}</li>`;
    });

    if (hardBlocked) {
        return {
            hardBlocked: true,
            softRisk: false,
            summary: 'Kit de entrada incompleto. Equipe ou obtenha o item exigido.',
            detailsHtml: lines.length ? `<ul class="inventory-exploration-entry-list">${lines.join('')}</ul>` : '',
        };
    }

    if (softRisk) {
        const multiplier = Number(entry.soft_penalties?.energy_cost_multiplier || 1.5);
        return {
            hardBlocked: false,
            softRisk: true,
            summary: `Pode entrar desprotegido, mas o terreno cobra mais (${multiplier.toFixed(2)}x energia) e pode causar dano ambiental.`,
            detailsHtml: lines.length ? `<ul class="inventory-exploration-entry-list">${lines.join('')}</ul>` : '',
        };
    }

    return {
        hardBlocked: false,
        softRisk: false,
        summary: entryReqs.length
            ? 'Kit de entrada completo. Pronto para iniciar.'
            : 'Entre no mundo e comece a expedicao.',
        detailsHtml: lines.length ? `<ul class="inventory-exploration-entry-list">${lines.join('')}</ul>` : '',
    };
}

function explorationBiomeNodeTitle(biome, locked, softRisk, hardBlocked) {
    if (locked) {
        const requirements = biome.requirements || {};
        const progress = biome.progress || {};
        return `Bloqueado. Exploracao nv.${Number(requirements.exploration_level_min || 0)} e ${Number(requirements.completed_expeditions_min || 0)} expedicao(oes). Progresso: nv.${Number(progress.exploration_level || 1)}, ${Number(progress.completed_expeditions || 0)}.`;
    }
    if (hardBlocked) {
        const missing = (biome.entry?.missing || []).map((row) => row.label || row.type).filter(Boolean);
        return `Kit necessario: ${missing.join(', ') || 'requisitos de entrada'}`;
    }
    if (softRisk) {
        return biome.entry?.soft_penalties?.label || 'Entrada arriscada sem protecao';
    }
    return biome.summary || biome.name || '';
}

function renderExplorationModifiersBanner() {
    const modifiers = explorationModifiers;
    if (!modifiers) return '';

    const loadout = Array.isArray(modifiers.constellation_loadout) ? modifiers.constellation_loadout : [];
    const mitigation = modifiers.trap_mitigation || {};
    const mitigationSources = Array.isArray(modifiers.trap_mitigation_sources) ? modifiers.trap_mitigation_sources : [];
    const loadoutCards = loadout.map((entry) => {
        const statusClass = entry.status === 'active'
            ? 'is-active'
            : (entry.status === 'dormant' ? 'is-dormant' : 'is-locked');
        const progressLabel = entry.status === 'locked'
            ? `Precisa ${escapeHtml(entry.attribute_name || entry.attribute_code || 'Atributo')} nv.${Number(entry.min_level || 1)}`
            : (entry.status === 'dormant'
                ? 'Só ativa em outro bioma'
                : 'Ativo agora');
        const effectLabel = explorationConstellationEffectLabel(entry.effects);
        return `
            <article class="inventory-exploration-loadout-card ${statusClass}" title="${escapeHtml(entry.summary || '')}">
                <div class="inventory-exploration-loadout-card-head">
                    <strong>${escapeHtml(entry.name || entry.code || 'Constelacao')}</strong>
                    <span>${progressLabel}</span>
                </div>
                <p>${escapeHtml(entry.summary || '')}</p>
                ${effectLabel ? `<small>${escapeHtml(effectLabel)}</small>` : ''}
            </article>
        `;
    }).join('');

    const mitigationLabel = mitigation.active
        ? `Protecao: ${escapeHtml(mitigation.item?.name || 'Luvas equipadas')} (-${Math.round(Number(mitigation.trap_reduction || 0) * 100)}% armadilha)`
        : 'Sem luvas anti-armadilha equipadas';
    const sourceChips = mitigationSources.map((entry) => `
        <span class="inventory-exploration-mitigation-chip">${escapeHtml(entry.label || entry.code || 'Mitigacao')} -${Math.round(Number(entry.reduction || 0) * 100)}%</span>
    `).join('');

    return `
        <details class="inventory-exploration-modifiers">
            <summary>
                <span>Bonus passivos</span>
                <em>Raio +${Number(modifiers.discovery_radius_bonus || 0).toFixed(1)} · Loot +${Math.round(Number(modifiers.expedition_loot_bonus || 0) * 100)}% · Armadilha -${Math.round(Number(modifiers.trap_chance_reduction || 0) * 100)}%</em>
            </summary>
            <div class="inventory-exploration-modifiers-body">
                <p class="inventory-exploration-muted">Constelacoes desbloqueiam sozinhas conforme seus atributos sobem. Nao precisa equipar nada aqui.</p>
                <div class="inventory-exploration-loadout-grid">
                    ${loadoutCards || '<span class="inventory-exploration-muted">Nenhum bonus disponivel ainda.</span>'}
                </div>
                <div class="inventory-exploration-mitigation-row">
                    <p class="inventory-exploration-muted">${mitigationLabel}</p>
                    ${sourceChips ? `<div class="inventory-exploration-mitigation-list">${sourceChips}</div>` : ''}
                </div>
            </div>
        </details>
    `;
}

function renderExplorationExpeditionBanner() {
    const expedition = explorationExpedition;
    if (!expedition) {
        return '<p class="inventory-exploration-muted">Status da expedicao indisponivel.</p>';
    }

    if (expedition.ready_to_claim || expedition.claimable) {
        const rewards = expedition.pending_completion?.preview_rewards || {};
        const offline = expedition.pending_completion?.offline_combat || {};
        const offlineBits = [];
        if (Number(rewards.offline_kills || offline.kills || 0) > 0) {
            offlineBits.push(`${Number(rewards.offline_kills || offline.kills || 0)} kills offline`);
        }
        if (Number(rewards.offline_gold_already_granted || offline.gold || 0) > 0) {
            offlineBits.push(`${Number(rewards.offline_gold_already_granted || offline.gold || 0)}G ja creditados`);
        }
        if (Number(rewards.offline_xp_already_granted || offline.exploration_xp || 0) > 0) {
            offlineBits.push(`${Number(rewards.offline_xp_already_granted || offline.exploration_xp || 0)} XP ja creditados`);
        }
        const offlineNote = offlineBits.length
            ? `<em>${escapeHtml(offlineBits.join(' · '))} (ja aplicados)</em>`
            : '<em>Simulacao offline concluida</em>';
        return `
            <div class="inventory-exploration-expedition is-complete" data-exploration-claim-banner>
                <div>
                    <strong>Expedicao pronta para reivindicar</strong>
                    <span>Bonus de conclusao: +${Number(rewards.exploration_xp || 0)} XP · ${formatGameMoney(rewards.gold || 0)}</span>
                    ${offlineNote}
                </div>
                <button type="button" class="inventory-button is-primary" data-exploration-claim>Reivindicar recompensas</button>
            </div>
        `;
    }

    if (expedition.active) {
        const endsAt = expedition.ends_at ? new Date(expedition.ends_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '—';
        const biomeLabel = explorationBiomes.find((entry) => entry.code === explorationBiomeCode)?.name || 'bioma atual';
        return `
            <div class="inventory-exploration-expedition is-active">
                <strong>Expedicao ativa em ${escapeHtml(biomeLabel)}</strong>
                <span>Termina as ${escapeHtml(endsAt)} · bonus de loot +25%</span>
            </div>
        `;
    }

    if (expedition.last_failure && !expedition.active && !expedition.ready_to_claim) {
        const endedAt = expedition.last_failure.ended_at
            ? new Date(expedition.last_failure.ended_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
            : '';
        return `
            <div class="inventory-exploration-expedition is-failed">
                <div>
                    <strong>Ultima expedicao falhou</strong>
                    <span>${escapeHtml(expedition.last_failure.message || 'Voce foi derrotado na arena.')}${endedAt ? ` · ${escapeHtml(endedAt)}` : ''}</span>
                </div>
                <button type="button" class="inventory-button is-primary" data-exploration-start>Tentar novamente (${Number(expedition.default_duration_minutes || 30)} min)</button>
            </div>
        `;
    }

    // Idle/required: o CTA do mapa ja inicia a expedicao — evita banner duplicado por cima.
    return '';
}

function renderExplorationObjectsSection() {
    const objects = Array.isArray(explorationObjects) ? explorationObjects : [];
    const discovered = objects.filter((entry) => entry?.discovered);
    const visible = discovered.length ? discovered : objects.slice(0, 6);
    const cards = visible.map((object) => renderExplorationObjectCard(object)).join('');

    return `
        <section class="inventory-exploration-objects-section">
            <div class="inventory-exploration-objects-head">
                <strong>Pontos de interesse</strong>
                <span>${discovered.length}/${objects.length} visiveis</span>
            </div>
            <div class="inventory-exploration-objects-grid">
                ${cards || '<p class="inventory-exploration-muted">Nenhum ponto carregado ainda. Atualize ou aproxime-se no mapa.</p>'}
            </div>
        </section>
    `;
}

function renderExplorationArenaStatusStrip() {
    const expedition = explorationExpedition || {};
    if (!expedition.active && !expedition.ready_to_claim && !expedition.claimable) {
        return '';
    }

    if (expedition.ready_to_claim || expedition.claimable) {
        const rewards = expedition.pending_completion?.preview_rewards || {};
        return `
            <div class="inventory-exploration-arena-status is-claimable" data-exploration-arena-status>
                <div>
                    <strong>Expedicao pronta para reivindicar</strong>
                    <span>+${Number(rewards.exploration_xp || 0)} XP · ${formatGameMoney(rewards.gold || 0)}${Number(rewards.offline_kills || 0) > 0 ? ` · offline ${Number(rewards.offline_kills)} kills` : ''}</span>
                </div>
                <button type="button" class="inventory-button is-primary" data-exploration-claim>Reivindicar</button>
            </div>
        `;
    }

    const endsAt = expedition.ends_at
        ? new Date(expedition.ends_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
        : '—';
    const remainingMs = expedition.ends_at ? (new Date(expedition.ends_at).getTime() - Date.now()) : 0;
    const remainingMin = Math.max(0, Math.ceil(remainingMs / 60000));
    const carry = getExplorationArenaState()?.expedition_carry || null;
    const carryUsed = Number(carry?.occupied_cells || 0);
    const carryMax = Number(carry?.capacity_cells || 0);
    const carryLabel = carryMax > 0
        ? ` · carry ${carryUsed}/${carryMax} espacos`
        : '';

    return `
        <div class="inventory-exploration-arena-status is-active" data-exploration-arena-status>
            <div>
                <strong>Expedicao ativa</strong>
                <span>Termina as ${escapeHtml(endsAt)} · ~${remainingMin} min restantes · loot +25%${escapeHtml(carryLabel)}</span>
            </div>
            <small data-arena-sync-status>Combate sincronizando</small>
        </div>
    `;
}

function renderExplorationPanel() {
    if (!explorationPanelRootEl()) return;

    try {
        const biomeName = explorationBiomes.find((entry) => entry.code === explorationBiomeCode)?.name || 'Bosque Inicial';
        const expeditionActive = Boolean(explorationExpedition?.active);
        const readyToClaim = Boolean(explorationExpedition?.ready_to_claim || explorationExpedition?.claimable);
        const worldMode = !expeditionActive && !readyToClaim;
        const stageOnly = expeditionActive || readyToClaim;

        explorationPanelRootEl().innerHTML = `
        <div class="inventory-exploration-shell${expeditionActive ? ' is-arena-mode' : (readyToClaim ? ' is-claim-mode' : ' is-world-mode')}">
            <header class="inventory-exploration-header">
                <div>
                    <p class="inventory-kicker">${expeditionActive ? 'Expedicao' : (readyToClaim ? 'Recompensa' : 'Mundo')}</p>
                    <h2>${escapeHtml(biomeName)}</h2>
                    <p class="inventory-exploration-lead">${
                        expeditionActive
                            ? 'O mouse e o jogador. Clique para atacar e arraste para navegar.'
                            : (readyToClaim
                                ? 'Sua expedicao terminou. Reivindique antes de iniciar outra.'
                                : 'Escolha um bioma, inicie a expedicao e use lupa/ferramentas nos pontos de interesse.')
                    }</p>
                </div>
                <div class="inventory-exploration-header-actions">
                    ${readyToClaim ? '<button type="button" class="inventory-button is-primary" data-exploration-claim>Reivindicar</button>' : ''}
                    <button type="button" class="inventory-button" data-exploration-refresh>Atualizar</button>
                    <button type="button" class="inventory-drawer-close" data-exploration-close aria-label="Fechar exploracao">×</button>
                </div>
            </header>
            <div class="inventory-exploration-scroll${stageOnly ? ' is-stage-only' : ''}">
                ${readyToClaim ? renderExplorationExpeditionBanner() : ''}
                ${!expeditionActive && !readyToClaim ? renderExplorationExpeditionBanner() : ''}
                ${expeditionActive ? renderExplorationArenaStatusStrip() : ''}
                ${worldMode ? renderExplorationGuide() : ''}
                ${worldMode ? renderExplorationModifiersBanner() : ''}
                ${renderExplorationWorldMap()}
                ${worldMode ? renderExplorationObjectsSection() : ''}
            </div>
        </div>
    `;

        bindExplorationPanelInteractions();
    } catch (error) {
        console.error('Exploration panel render failed', error);
        explorationPanelRootEl().innerHTML = `
            <div class="inventory-exploration-shell">
                <header class="inventory-exploration-header">
                    <div>
                        <p class="inventory-kicker">Mundo</p>
                        <h2>Exploracao</h2>
                        <p class="inventory-exploration-muted">Nao foi possivel renderizar o painel. Tente atualizar.</p>
                    </div>
                    <div class="inventory-exploration-header-actions">
                        <button type="button" class="inventory-button" data-exploration-refresh>Atualizar</button>
                        <button type="button" class="inventory-drawer-close" data-exploration-close aria-label="Fechar exploracao">×</button>
                    </div>
                </header>
            </div>
        `;
        bindExplorationPanelInteractions();
    }
}

function softRefreshArenaStage() {
    if (!explorationPanelRootEl() || !explorationExpedition?.active) return;
    const stage = explorationPanelRootEl().querySelector('.inventory-exploration-stage.is-arena-only');
    if (!stage) return;

    stage.innerHTML = renderExplorationArenaSection({
        biomeCode: explorationBiomeCode,
        expedition: explorationExpedition,
        fallbackMap: explorationMap,
        fallbackPosition: explorationPosition,
    });

    bindExplorationArenaInteractions(explorationPanelRootEl());
}

async function moveExplorationPosition(mapX, mapY) {
    if (explorationPositionInFlight || !explorationExpedition?.active) return;
    explorationPositionInFlight = true;

    try {
        const response = await apiFetch(`/api/exploration/biomes/${encodeURIComponent(explorationBiomeCode)}/position`, {
            method: 'POST',
            body: {
                map_x: mapX,
                map_y: mapY,
            },
        });
        explorationPosition = response.data?.position || explorationPosition;
        explorationMap = response.data?.map || explorationMap;
        await Promise.all([loadExplorationObjects(), loadExplorationArena(explorationBiomeCode)]);
    } catch (error) {
        handleError(error, 'Nao foi possivel mover no mapa.');
    } finally {
        explorationPositionInFlight = false;
    }
}

async function claimExplorationExpedition() {
    try {
        const response = await apiFetch('/api/expeditions/complete', { method: 'POST' });
        const rewards = response.data?.rewards || {};
        const offlineNote = Number(rewards.offline_kills || 0) > 0
            ? ` (offline: ${Number(rewards.offline_kills)} kills)`
            : '';
        toast(`Recompensas recebidas: +${Number(rewards.exploration_xp || 0)} XP e ${formatGameMoney(rewards.gold || 0)}.${offlineNote}`, 'success');
        invalidateContainerCache();
        await reloadContainerPanelsOnly();
        await loadExplorationObjects();
    } catch (error) {
        handleError(error, 'Nao foi possivel reivindicar as recompensas.');
    }
}

async function startExplorationExpedition() {
    if (explorationStartInFlight || explorationExpedition?.active) return;
    if (explorationExpedition?.ready_to_claim || explorationExpedition?.claimable) {
        toast('Reivindique a recompensa da expedicao anterior antes de iniciar outra.', 'warning');
        return;
    }
    if (explorationVitals && !explorationVitals.can_start_expedition) {
        const energy = explorationVitals.energy || {};
        toast(`Energia insuficiente (${Number(energy.current || 0)}/${Number(energy.max || 0)}). Descanse ou use um consumivel.`, 'warning');
        renderExplorationPanel();
        return;
    }

    const selectedBiome = explorationBiomes.find((entry) => entry.code === explorationBiomeCode);
    const prep = explorationBiomePrepSummary(selectedBiome || { code: explorationBiomeCode });
    if (prep.hardBlocked) {
        toast(prep.summary || 'Prepare o kit de entrada antes de iniciar.', 'warning');
        renderExplorationPanel();
        return;
    }

    explorationStartInFlight = true;
    renderExplorationPanel();

    try {
        await apiFetch('/api/expeditions/start', {
            method: 'POST',
            body: {
                biome_code: explorationBiomeCode,
            },
        });
        const biomeName = selectedBiome?.name || 'Bosque Inicial';
        toast(
            prep.softRisk
                ? `Expedicao iniciada em ${biomeName} (entrada arriscada).`
                : `Expedicao iniciada em ${biomeName}.`,
            prep.softRisk ? 'warning' : 'success'
        );
        await loadExplorationBiomes();
        await loadExplorationObjects();
    } catch (error) {
        const raw = String(error?.message || error?.payload?.message || '');
        if (/not enough energy|energia/i.test(raw)) {
            toast('Energia insuficiente. Descanse (recupera ~4/min) ou use comida/pocao.', 'warning');
            await loadExplorationObjects();
        } else {
            handleError(error, 'Nao foi possivel iniciar a expedicao.');
        }
    } finally {
        explorationStartInFlight = false;
        renderExplorationPanel();
    }
}

async function startExplorationRest() {
    if (explorationRestInFlight || explorationExpedition?.active) return;
    explorationRestInFlight = true;
    renderExplorationPanel();
    try {
        await apiFetch('/api/player/rest', {
            method: 'POST',
            body: { duration_minutes: 5 },
        });
        toast('Descanso de 5 min iniciado (~+20 energia). Atualize depois para ver a recuperacao.', 'success');
        await loadExplorationObjects();
        d().refreshPlayerHud?.();
    } catch (error) {
        handleError(error, 'Nao foi possivel iniciar o descanso.');
    } finally {
        explorationRestInFlight = false;
        renderExplorationPanel();
    }
}

async function executeExplorationAnalyze(objectPublicId, options = {}) {
    if (explorationActionInFlight) return;
    const silent = Boolean(options?.silent);
    const magnifier = listOwnedToolsByType('magnifier')[0];
    if (!magnifier?.public_id) {
        toast('Voce precisa de uma lupa no inventario.', 'error');
        return;
    }

    explorationActionInFlight = true;
    if (!silent) {
        renderExplorationPanel();
    }

    try {
        const response = await apiFetch(`/api/exploration/objects/${encodeURIComponent(objectPublicId)}/analyze`, {
            method: 'POST',
            body: { tool_item_public_id: magnifier.public_id },
        });
        const tier = response.data?.analysis?.tier;
        toast(tier?.title ? `Analise: ${tier.title}` : 'Objeto analisado.', 'success');
        await Promise.all([loadExplorationObjects({ silent }), reloadContainerPanelsOnly()]);
    } catch (error) {
        handleError(error, 'Nao foi possivel analisar o objeto.');
        if (!silent) {
            renderExplorationPanel();
        }
    } finally {
        explorationActionInFlight = false;
    }
}

async function executeExplorationAction(objectPublicId, actionCode, options = {}) {
    if (explorationActionInFlight) return;
    const silent = Boolean(options?.silent);

    const object = explorationObjects.find((entry) => entry.public_id === objectPublicId);
    const action = object?.available_actions?.find((entry) => entry.action_code === actionCode);
    const toolType = action?.required_tool_type || '';
    const tool = listOwnedToolsByType(toolType)[0];
    if (!tool?.public_id) {
        toast(`Ferramenta necessaria: ${toolType || actionCode}.`, 'error');
        return;
    }

    explorationActionInFlight = true;
    if (!silent) {
        renderExplorationPanel();
    }

    try {
        const response = await apiFetch(`/api/exploration/objects/${encodeURIComponent(objectPublicId)}/actions/${encodeURIComponent(actionCode)}`, {
            method: 'POST',
            body: { tool_item_public_id: tool.public_id },
        });
        const loot = response.data?.action?.loot || [];
        const trap = response.data?.action?.trap;
        const successMessage = response.data?.action?.success_message || '';
        const actionLabel = response.data?.action?.action_label || explorationActionDisplayLabel(action);
        const lootLabel = loot.map((entry) => `${entry.quantity}x ${entry.item_definition_code}`).join(', ');
        if (trap?.trap_triggered) {
            toast(trap.message || 'Armadilha disparou durante a abertura.', 'warning');
            if (lootLabel) {
                toast(`Voce recuperou: ${lootLabel}`, 'success');
            }
        } else {
            toast(successMessage || (lootLabel ? `${actionLabel}: ${lootLabel}` : 'Acao de exploracao concluida.'), 'success');
        }
        await Promise.all([loadExplorationObjects({ silent }), reloadContainerPanelsOnly()]);
    } catch (error) {
        handleError(error, 'Nao foi possivel executar a acao de exploracao.');
        if (!silent) {
            renderExplorationPanel();
        }
    } finally {
        explorationActionInFlight = false;
    }
}

function openExplorationPanel() {
    d().closeSiblingPanels?.();
    closeMissionsPanel();
    explorationPanelOpen = true;
    document.body.classList.add('inventory-exploration-fullscreen');
    syncDrawerUi();
    renderExplorationPanel();
    loadExplorationBiomes().finally(async () => {
        await loadExplorationObjects({ silent: true });
        if (explorationPanelOpen) {
            renderExplorationPanel();
        }
    });
}

function closeExplorationPanel() {
    explorationPanelOpen = false;
    stopArenaWander();
    document.body.classList.remove('inventory-exploration-fullscreen');
    syncDrawerUi();
}

function toggleExplorationPanel() {
    if (explorationPanelOpen) closeExplorationPanel();
    else openExplorationPanel();
}

function bindExplorationPanelInteractions() {
    if (!explorationPanelRootEl()) return;

    explorationPanelRootEl().querySelector('[data-exploration-close]')?.addEventListener('click', closeExplorationPanel);
    explorationPanelRootEl().querySelector('[data-exploration-refresh]')?.addEventListener('click', async () => {
        await loadExplorationObjects();
        if (explorationExpedition?.active) {
            softRefreshArenaStage();
            resumeArenaCombatLoop(explorationPanelRootEl());
        } else {
            renderExplorationPanel();
        }
    });
    explorationPanelRootEl().querySelector('[data-exploration-context-toggle]')?.addEventListener('click', () => {
        const shell = explorationPanelRootEl()?.querySelector('.inventory-exploration-shell');
        if (!shell) return;
        const open = shell.classList.toggle('is-context-open');
        const toggle = explorationPanelRootEl().querySelector('[data-exploration-context-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.textContent = open ? 'Ocultar guia' : 'Guia e POIs';
        }
    });
    explorationPanelRootEl().querySelector('[data-exploration-start]')?.addEventListener('click', () => startExplorationExpedition());
    explorationPanelRootEl().querySelector('[data-exploration-start-map]')?.addEventListener('click', () => startExplorationExpedition());
    explorationPanelRootEl().querySelector('[data-exploration-rest]')?.addEventListener('click', () => startExplorationRest());
    explorationPanelRootEl().querySelectorAll('[data-exploration-claim]').forEach((button) => {
        button.addEventListener('click', () => claimExplorationExpedition());
    });

    explorationPanelRootEl().querySelectorAll('[data-exploration-biome]').forEach((button) => {
        button.addEventListener('click', async () => {
            const biomeCode = button.getAttribute('data-exploration-biome');
            if (!biomeCode || button.disabled) return;
            if (biomeCode === explorationBiomeCode) {
                if (explorationExpedition?.ready_to_claim || explorationExpedition?.claimable) {
                    claimExplorationExpedition();
                    return;
                }
                if (!explorationExpedition?.active && !explorationStartInFlight) {
                    startExplorationExpedition();
                }
                return;
            }
            explorationBiomeCode = biomeCode;
            explorationWorldOffset = { x: 0, y: 0 };
            await loadExplorationObjects();
        });
    });

    explorationPanelRootEl().querySelectorAll('[data-exploration-move-x]').forEach((button) => {
        button.addEventListener('click', () => {
            const mapX = Number(button.getAttribute('data-exploration-move-x'));
            const mapY = Number(button.getAttribute('data-exploration-move-y'));
            if (!Number.isFinite(mapX) || !Number.isFinite(mapY) || button.disabled) return;
            moveExplorationPosition(mapX, mapY);
        });
    });

    explorationPanelRootEl().querySelectorAll('[data-exploration-analyze]').forEach((button) => {
        button.addEventListener('click', () => {
            const objectPublicId = button.getAttribute('data-exploration-analyze');
            if (!objectPublicId || explorationActionInFlight) return;
            executeExplorationAnalyze(objectPublicId);
        });
    });

    explorationPanelRootEl().querySelectorAll('[data-exploration-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const objectPublicId = button.getAttribute('data-exploration-object');
            const actionCode = button.getAttribute('data-exploration-action');
            if (!objectPublicId || !actionCode || explorationActionInFlight) return;
            executeExplorationAction(objectPublicId, actionCode);
        });
    });

    bindExplorationArenaInteractions(explorationPanelRootEl());
    bindExplorationWorldDrag();
}

function bindExplorationWorldDrag() {
    const viewport = explorationPanelRootEl()?.querySelector('[data-exploration-world-viewport]');
    const camera = explorationPanelRootEl()?.querySelector('[data-exploration-world-camera]');
    if (!viewport || !camera) return;
    if (viewport.dataset.dragBound === '1') return;
    viewport.dataset.dragBound = '1';

    const selected = (explorationBiomes || []).find((entry) => entry.code === explorationBiomeCode);
    if (selected?.map_node && explorationWorldOffset.x === 0 && explorationWorldOffset.y === 0) {
        const worldW = Number(viewport.closest('.inventory-exploration-world-map')?.style.getPropertyValue('--world-width')) || 8;
        const worldH = Number(viewport.closest('.inventory-exploration-world-map')?.style.getPropertyValue('--world-height')) || 6;
        const nodeX = Number(selected.map_node.x || 0);
        const nodeY = Number(selected.map_node.y || 0);
        // Centraliza o bioma selecionado no viewport.
        explorationWorldOffset = {
            x: (0.5 - (nodeX / worldW)) * viewport.clientWidth * 0.85,
            y: (0.5 - (nodeY / worldH)) * viewport.clientHeight * 0.85,
        };
    }

    camera.style.setProperty('--world-camera-x', `${explorationWorldOffset.x}px`);
    camera.style.setProperty('--world-camera-y', `${explorationWorldOffset.y}px`);

    let dragState = null;

    const clampWorldOffset = (nextX, nextY) => {
        const grid = camera.querySelector('.inventory-exploration-world-grid');
        const overflowX = Math.max(120, ((grid?.offsetWidth || viewport.clientWidth) - viewport.clientWidth) / 2 + 80);
        const overflowY = Math.max(80, ((grid?.offsetHeight || viewport.clientHeight) - viewport.clientHeight) / 2 + 80);
        return {
            x: Math.max(-overflowX, Math.min(overflowX, nextX)),
            y: Math.max(-overflowY, Math.min(overflowY, nextY)),
        };
    };

    const applyWorldOffset = (nextX, nextY) => {
        explorationWorldOffset = clampWorldOffset(nextX, nextY);
        camera.style.setProperty('--world-camera-x', `${explorationWorldOffset.x}px`);
        camera.style.setProperty('--world-camera-y', `${explorationWorldOffset.y}px`);
    };

    const endDrag = () => {
        if (!dragState) return;
        viewport.classList.remove('is-dragging');
        dragState = null;
    };

    viewport.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        if (event.target instanceof Element && event.target.closest('[data-world-interactive]')) {
            return;
        }
        dragState = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            baseX: explorationWorldOffset.x,
            baseY: explorationWorldOffset.y,
        };
        viewport.classList.add('is-dragging');
        viewport.setPointerCapture?.(event.pointerId);
    });

    viewport.addEventListener('pointermove', (event) => {
        if (!dragState || dragState.pointerId !== event.pointerId) return;
        applyWorldOffset(
            dragState.baseX + (event.clientX - dragState.startX),
            dragState.baseY + (event.clientY - dragState.startY),
        );
    });

    viewport.addEventListener('pointerup', endDrag);
    viewport.addEventListener('pointercancel', endDrag);
    viewport.addEventListener('lostpointercapture', endDrag);
}

function initExplorationControls() {
    if (explorationControlsInitialized) return;
    explorationControlsInitialized = true;

    document.querySelectorAll('[data-exploration-open]').forEach((button) => {
        button.addEventListener('click', () => toggleExplorationPanel());
    });

    explorationPanelRootEl()?.addEventListener('click', (event) => {
        if (event.target === explorationPanelRootEl()) {
            closeExplorationPanel();
        }
    });
}


export {
    listOwnedToolsByType,
    explorationActionLabel,
    loadExplorationBiomes,
    loadExplorationObjects,
    renderExplorationPanel,
    softRefreshArenaStage,
    executeExplorationAnalyze,
    executeExplorationAction,
    openExplorationPanel,
    closeExplorationPanel,
    toggleExplorationPanel,
    initExplorationControls,
};
