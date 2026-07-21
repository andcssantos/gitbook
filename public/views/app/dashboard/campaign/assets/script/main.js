/**
 * Tela /campaign — mapa idle limpo + overlay de batalha + triagem de loot + placar.
 */

import { apiFetch, ApiError } from '/assets/framework/api.js';
import { createLootGridKit } from '/assets/game/loot-grid-kit.js';
import { createCampaignMapFx } from './map-fx.js';
import { createCampaignMap3D } from './map-3d.js';

const WORLD_CODE = 'mundo_1_bosque';
const TICK_MS = 1750;
const CELL_PX = 40;
const MONSTER_DIE_MS = 700;
const MONSTER_ENTER_MS = 750;
const BEAT_PAUSE_MS = 700;
const PLAYER_ACTION_PAUSE_MS = 550;
const MONSTER_ACTION_PAUSE_MS = 850;

const lootKit = createLootGridKit({ cellPx: CELL_PX, margin: 0 });
let lootFeedbackCleanups = [];
const campaignPage = document.querySelector('[data-campaign-page]');
let mapFx = null;
const map3d = createCampaignMap3D({
    page: campaignPage,
    getWeather: () => mapFx?.weather,
});
mapFx = createCampaignMapFx({
    page: campaignPage,
    onToggleMap3D: () => map3d.toggle(),
    isMap3DEnabled: () => map3d.enabled,
});

const page = campaignPage;
const stage = page?.querySelector('[data-campaign-stage]');
const lobbyRoot = page?.querySelector('[data-campaign-lobby]');
const pinTooltip = page?.querySelector('[data-campaign-pin-tooltip]');
const albumRoot = page?.querySelector('[data-campaign-album]');
const albumOpenBtn = page?.querySelector('[data-campaign-album-open]');
const albumCountEl = page?.querySelector('[data-campaign-album-count]');
const dossierRoot = page?.querySelector('[data-campaign-dossier]');
const battleRoot = page?.querySelector('[data-campaign-battle]');
const lootRoot = page?.querySelector('[data-campaign-loot]');
const scoreRoot = page?.querySelector('[data-campaign-score]');
const worldNameEl = page?.querySelector('[data-campaign-world-name]');
const worldHintEl = page?.querySelector('[data-campaign-world-hint]');

let world = null;
let selectedNodeCode = null;
let lobbyDetailsOpen = false;
let dossierTab = 'overview';
let toastTimer = null;
let battleRun = null;
let tickTimer = null;
let tickInFlight = false;
let startInFlight = false;
let lootState = null;
let scoreboard = null;
let dropGrid = null;
let bagGrid = null;
let lootCommitInFlight = false;
/** public_ids da bag descartados com Del (ainda precisam abandon no commit). */
let lootDiscardedPublicIds = new Set();
let battlePotions = [];
let waveRemainingMs = 0;
let waveLimitMs = 75000;
let waveTimerUi = null;
let potionInFlight = false;

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function errorMessage(error, fallback = 'Algo deu errado.') {
    if (error instanceof ApiError) return error.payload?.message || error.message || fallback;
    return error?.message || fallback;
}

function statusLabel(status) {
    if (status === 'cleared') return 'Concluida';
    if (status === 'available') return 'Disponivel';
    if (status === 'teaser') return 'Em breve';
    return 'Bloqueada';
}

function typeLabel(type) {
    if (type === 'village') return 'Vilarejo';
    if (type === 'teaser') return 'Teaser';
    return 'Fase';
}

/** Chips resumidos compartilhados entre lobby e tooltip do pin. */
function nodeSummaryChipBits(node) {
    const lobby = node?.lobby || {};
    const chips = lobby.summary_chips || {};
    const combat = world?.player?.combat || {};
    const chipBits = [];
    if (chips.map_level) chipBits.push(`<span class="campaign-lobby-chip">Nv.${Number(chips.map_level)}</span>`);
    if (chips.power) {
        const yours = Number(chips.player_power ?? combat.power ?? 0);
        chipBits.push(`<span class="campaign-lobby-chip">Poder ${yours}/${Number(chips.power)}</span>`);
    }
    if (combat.attack != null && node?.type === 'stage') {
        chipBits.push(`<span class="campaign-lobby-chip">ATK ${Math.round(Number(combat.attack))}</span>`);
    }
    if (combat.defense != null && node?.type === 'stage') {
        chipBits.push(`<span class="campaign-lobby-chip">DEF ${Math.round(Number(combat.defense))}</span>`);
    }
    if (chips.energy_start != null && node?.type === 'stage') {
        chipBits.push(`<span class="campaign-lobby-chip">Energia ${Number(chips.energy_start)}</span>`);
    }
    if (chips.waves) {
        const bosses = Array.isArray(chips.boss_waves) ? chips.boss_waves.join(', ') : '—';
        chipBits.push(`<span class="campaign-lobby-chip is-accent">${Number(chips.waves)} ondas · chefes ${escapeHtml(String(bosses))}</span>`);
    }
    return chipBits;
}

function hidePinTooltip() {
    if (!pinTooltip) return;
    pinTooltip.hidden = true;
    pinTooltip.innerHTML = '';
    pinTooltip.setAttribute('aria-hidden', 'true');
}

function positionPinTooltip(anchorEl) {
    if (!pinTooltip || !anchorEl || pinTooltip.hidden) return;
    const rect = anchorEl.getBoundingClientRect();
    const tip = pinTooltip.getBoundingClientRect();
    const gap = 10;
    let left = rect.left + (rect.width / 2) - (tip.width / 2);
    let top = rect.top - tip.height - gap;
    // Se nao cabe acima, joga abaixo do pin.
    if (top < 12) {
        top = rect.bottom + gap;
    }
    left = Math.max(12, Math.min(left, window.innerWidth - tip.width - 12));
    top = Math.max(12, Math.min(top, window.innerHeight - tip.height - 12));
    pinTooltip.style.left = `${Math.round(left)}px`;
    pinTooltip.style.top = `${Math.round(top)}px`;
}

function showPinTooltip(node, anchorEl) {
    if (!pinTooltip || !node || !anchorEl) return;
    if (battleRun || lootState || scoreboard) {
        hidePinTooltip();
        return;
    }

    const lobby = node.lobby || {};
    const chipBits = nodeSummaryChipBits(node);
    const scene = node.scene_url
        ? `<div class="campaign-pin-tooltip-scene" style="background-image:url('${escapeHtml(node.scene_url)}')"></div>`
        : '';
    const soft = node.type === 'stage' && lobby.soft_ready === false
        ? '<p class="campaign-pin-tooltip-soft">Abaixo do recomendado</p>'
        : '';

    pinTooltip.hidden = false;
    pinTooltip.setAttribute('aria-hidden', 'false');
    pinTooltip.innerHTML = `
        ${scene}
        <div class="campaign-pin-tooltip-body">
            <p class="campaign-lobby-kicker">${escapeHtml(statusLabel(node.status))} · ${escapeHtml(typeLabel(node.type))}</p>
            <strong>${escapeHtml(lobby.title || node.label)}</strong>
            <p>${escapeHtml(lobby.body || '')}</p>
            ${chipBits.length ? `<div class="campaign-lobby-chips">${chipBits.join('')}</div>` : ''}
            ${soft}
        </div>
    `;
    positionPinTooltip(anchorEl);
    mapFx.onTooltipShow(pinTooltip);
}

function showToast(message, tone = 'info') {
    if (!page) return;
    page.querySelector('.campaign-toast')?.remove();
    const toast = document.createElement('div');
    toast.className = `campaign-toast${tone === 'warning' ? ' is-warning' : ''}${tone === 'success' ? ' is-success' : ''}`;
    toast.textContent = message;
    page.appendChild(toast);
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.remove(), 2800);
}

function selectedNode() {
    if (!world || !selectedNodeCode) return null;
    return (world.nodes || []).find((node) => node.code === selectedNodeCode) || null;
}

function stopTickLoop() {
    if (tickTimer) {
        window.clearInterval(tickTimer);
    }
    tickTimer = null;
    tickInFlight = false;
    stopWaveTimerUi();
}

function startTickLoop() {
    stopTickLoop();
    tickTimer = window.setInterval(() => {
        runTick().catch(() => {});
    }, TICK_MS);
    startWaveTimerUi();
}

function stopWaveTimerUi() {
    if (waveTimerUi) {
        window.clearInterval(waveTimerUi);
        waveTimerUi = null;
    }
}

function startWaveTimerUi() {
    stopWaveTimerUi();
    waveTimerUi = window.setInterval(() => {
        if (!battleRun || battleRun.status !== 'active') return;
        waveRemainingMs = Math.max(0, waveRemainingMs - 250);
        updateWaveTimerDom();
    }, 250);
}

function formatWaveTimer(ms) {
    const total = Math.max(0, Math.ceil(Number(ms || 0) / 1000));
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function updateWaveTimerDom() {
    const el = battleRoot?.querySelector('[data-campaign-wave-timer]');
    if (!el) return;
    el.textContent = formatWaveTimer(waveRemainingMs);
    const ratio = waveLimitMs > 0 ? waveRemainingMs / waveLimitMs : 1;
    el.classList.toggle('is-warn', ratio <= 0.35);
    el.classList.toggle('is-critical', ratio <= 0.15);
}

function syncWaveTimerFromPayload(payload) {
    const wave = payload?.wave || {};
    if (wave.remaining_ms != null) {
        waveRemainingMs = Math.max(0, Number(wave.remaining_ms || 0));
    } else if (battleRun?.combat?.wave_started_at_ms && battleRun?.combat?.wave_limit_ms) {
        const started = Number(battleRun.combat.wave_started_at_ms);
        const limit = Number(battleRun.combat.wave_limit_ms);
        waveRemainingMs = Math.max(0, limit - (Date.now() - started));
        waveLimitMs = limit;
    }
    if (wave.limit_ms != null) {
        waveLimitMs = Math.max(1, Number(wave.limit_ms || 75000));
    }
    updateWaveTimerDom();
}

function hpPercent(current, max) {
    const m = Math.max(1, Number(max || 1));
    return Math.max(0, Math.min(100, Math.round((Number(current || 0) / m) * 100)));
}

function destroyLootGrids() {
    lootFeedbackCleanups.forEach((fn) => {
        try { fn(); } catch { /* ignore */ }
    });
    lootFeedbackCleanups = [];
    try {
        lootKit.finishDragSession?.(dropGrid);
        lootKit.finishDragSession?.(bagGrid);
    } catch {
        // ignore
    }
    try {
        dropGrid?.destroy?.(false);
    } catch {
        // ignore
    }
    try {
        bagGrid?.destroy?.(false);
    } catch {
        // ignore
    }
    dropGrid = null;
    bagGrid = null;
    lootDiscardedPublicIds = new Set();
}

function hideOverlays({ keepBattle = false } = {}) {
    hidePinTooltip();
    closeAlbum();
    if (!keepBattle && battleRoot) {
        battleRoot.hidden = true;
        battleRoot.innerHTML = '';
    }
    if (lootRoot) {
        destroyLootGrids();
        lootRoot.hidden = true;
        lootRoot.innerHTML = '';
    }
    if (scoreRoot) {
        scoreRoot.hidden = true;
        scoreRoot.innerHTML = '';
    }
    closeDossier();
}

function floaterLabel(entry) {
    const type = String(entry?.type || '');
    if (type === 'reward_gold' || type === 'reward_xp' || type === 'item_drop') {
        return String(entry.message || '');
    }
    if (type.includes('kill')) {
        return String(entry.message || 'Kill!');
    }
    const damage = Number(entry?.damage || 0);
    if (damage > 0) {
        return type.includes('crit') ? `Crit! ${damage}` : String(damage);
    }
    return String(entry?.message || type);
}

function showCombatFloaters(events) {
    if (!Array.isArray(events)) return;

    events.forEach((entry, index) => {
        const type = String(entry?.type || '');
        const damage = Number(entry?.damage || 0);
        const isReward = ['reward_gold', 'reward_xp', 'item_drop', 'potion_heal'].includes(type);
        const isHit = damage > 0 || type.includes('hit') || type.includes('crit') || type.includes('kill');
        if (!isReward && !isHit) return;

        const encounterId = String(entry?.encounter_public_id || '');
        let host = null;
        if (encounterId) {
            const card = battleRoot?.querySelector(`[data-campaign-monster-card="${encounterId}"]`);
            host = card?.querySelector('[data-campaign-floaters]');
        }
        host = host || battleRoot?.querySelector('[data-campaign-floaters]');
        if (!host) return;

        const node = document.createElement('span');
        const target = String(entry?.target || 'monster');
        const onMonster = target === 'monster' || type.includes('kill') || type === 'player_hit' || type === 'player_crit';
        node.className = `campaign-floater is-${target}${type.includes('crit') ? ' is-crit' : ''}${isReward ? ` is-reward is-${type}` : ''}${onMonster ? ' is-on-monster' : ''}`;
        node.textContent = floaterLabel(entry);

        const baseLeft = onMonster ? 50 : (target === 'player' ? 22 : 50);
        const baseTop = isReward ? 62 : (onMonster ? 38 : 28);
        node.style.left = `${Math.max(12, Math.min(88, baseLeft + ((index % 3) - 1) * 10))}%`;
        node.style.top = `${Math.max(12, Math.min(78, baseTop + Math.floor(index / 3) * 8))}%`;
        host.appendChild(node);
        window.setTimeout(() => node.remove(), 1200);

        if (type.includes('crit') || type.includes('hit') || type.includes('kill')) {
            mapFx.combatHit(type);
        }
    });
}

function monsterCardMarkup(monster, multi = false) {
    const id = escapeHtml(monster.public_id || '');
    return `
        <div class="campaign-battle-monster-card${monster.is_boss ? ' is-boss' : ''}${multi ? ' is-multi' : ''}" data-campaign-monster-card="${id}">
            <div class="campaign-battle-monster-mini-bar">
                <span>${escapeHtml(monster.name || 'Inimigo')}</span>
                <div><i style="width:${hpPercent(monster.current_hp, monster.max_hp)}%"></i></div>
                <em>${Number(monster.current_hp || 0)}/${Number(monster.max_hp || 0)}</em>
            </div>
            <div class="campaign-battle-monster-wrap${monster.is_boss ? ' is-boss' : ''}${multi ? ' is-multi' : ''}" data-campaign-monster-wrap>
                <img class="campaign-battle-monster${monster.is_boss ? ' is-boss' : ''}" data-campaign-monster src="${escapeHtml(monster.art_url || '')}" alt="">
                <div class="campaign-battle-floaters" data-campaign-floaters></div>
            </div>
        </div>
    `;
}

function potionBeltMarkup(potions) {
    const slots = ['potion_1', 'potion_2', 'potion_3', 'potion_4'];
    const bySlot = Object.fromEntries((potions || []).map((p) => [p.slot_code, p]));
    return slots.map((slot, index) => {
        const potion = bySlot[slot];
        if (!potion) {
            return `<button type="button" class="campaign-potion-slot is-empty" disabled title="Slot ${index + 1} vazio"><span>${index + 1}</span></button>`;
        }
        return `
            <button type="button" class="campaign-potion-slot" data-campaign-potion-slot="${escapeHtml(slot)}" title="${escapeHtml(potion.name)}">
                <strong>${escapeHtml((potion.name || 'P').slice(0, 1).toUpperCase())}</strong>
                <em>x${Number(potion.quantity || 1)}</em>
                <span>${index + 1}</span>
            </button>
        `;
    }).join('');
}

function bindPotionBelt() {
    battleRoot?.querySelectorAll('[data-campaign-potion-slot]').forEach((button) => {
        button.addEventListener('click', () => {
            const slot = button.getAttribute('data-campaign-potion-slot');
            usePotion(slot).catch(() => {});
        });
    });
}

function renderPotionBelt() {
    const host = battleRoot?.querySelector('[data-campaign-potion-belt]');
    if (!host) return;
    host.innerHTML = potionBeltMarkup(battlePotions);
    bindPotionBelt();
}

async function usePotion(slotCode) {
    if (!battleRun || potionInFlight) return;
    potionInFlight = true;
    try {
        const response = await apiFetch('/api/campaign/stages/potions/use', {
            method: 'POST',
            body: { slot_code: slotCode },
        });
        battleRun = response.data?.run || battleRun;
        battlePotions = response.data?.potions || battlePotions;
        showCombatFloaters(response.data?.events || []);
        pushFeed(response.data?.events || []);
        updateBattleHud();
        renderPotionBelt();
    } catch (error) {
        showToast(errorMessage(error, 'Nao foi possivel usar a pocao.'), 'warning');
    } finally {
        potionInFlight = false;
    }
}

function battleModsMarkup(run) {
    const mods = Array.isArray(run?.stage_modifiers) ? run.stage_modifiers : [];
    const vitals = run?.vital_penalties || {};
    const notes = Array.isArray(vitals.notes) ? vitals.notes : [];
    const chips = [];
    mods.forEach((m) => {
        const kind = String(m.kind || 'buff');
        chips.push(`<span class="campaign-battle-mod is-${escapeHtml(kind)}" title="${escapeHtml(m.detail || '')}">${escapeHtml(m.label || kind)}</span>`);
    });
    notes.forEach((note) => {
        chips.push(`<span class="campaign-battle-mod is-vital" title="${escapeHtml(note)}">${escapeHtml(note)}</span>`);
    });
    if (!chips.length) return '';
    return `<div class="campaign-battle-mods" data-campaign-battle-mods>${chips.join('')}</div>`;
}

function renderBattle() {
    if (!battleRoot) return;
    if (!battleRun || battleRun.status !== 'active') {
        if (!lootState && !scoreboard) {
            battleRoot.hidden = true;
            battleRoot.innerHTML = '';
        }
        return;
    }

    const monsters = Array.isArray(battleRun.encounters) ? battleRun.encounters.filter((m) => Number(m?.current_hp || 0) > 0) : [];
    const monster = monsters[0] || null;
    const multi = monsters.length > 1;
    const wave = Number(battleRun.current_wave || 1);
    const total = Number(battleRun.wave_count || 6);
    const scene = battleRun.scene_url || '';
    const enemyLabel = multi
        ? `${monsters.length} inimigos`
        : (monster?.name || 'Inimigo');
    const enemyHpCurrent = monsters.reduce((sum, m) => sum + Number(m.current_hp || 0), 0);
    const enemyHpMax = monsters.reduce((sum, m) => sum + Number(m.max_hp || 0), 0);

    battleRoot.hidden = false;
    battleRoot.innerHTML = `
        <div class="campaign-battle-scene" style="${scene ? `background-image:url('${escapeHtml(scene)}')` : ''}">
            <aside class="campaign-battle-log" aria-label="Historico de combate">
                <p class="campaign-battle-log-title">Historico</p>
                <ul class="campaign-battle-feed" data-campaign-feed></ul>
            </aside>
            <div class="campaign-battle-main">
                <div class="campaign-battle-hud">
                    <div class="campaign-battle-wave-row">
                        <div class="campaign-battle-wave">Onda ${wave}/${total}</div>
                        <div class="campaign-battle-timer" data-campaign-wave-timer>${formatWaveTimer(waveRemainingMs)}</div>
                    </div>
                    <strong>${escapeHtml(battleRun.node_label || 'Fase')}</strong>
                    ${battleModsMarkup(battleRun)}
                    <div class="campaign-battle-bars">
                        <div class="campaign-battle-bar is-player">
                            <span>Voce</span>
                            <div><i style="width:${hpPercent(battleRun.current_hp, battleRun.max_hp)}%"></i></div>
                            <em>${Number(battleRun.current_hp || 0)}/${Number(battleRun.max_hp || 0)}</em>
                        </div>
                        <div class="campaign-battle-bar is-enemy">
                            <span>${escapeHtml(enemyLabel)}</span>
                            <div><i style="width:${monsters.length ? hpPercent(enemyHpCurrent, enemyHpMax) : 0}%"></i></div>
                            <em>${monsters.length ? `${enemyHpCurrent}/${enemyHpMax}` : '—'}</em>
                        </div>
                    </div>
                </div>
                <div class="campaign-battle-arena">
                    ${monsters.length
                        ? `<div class="campaign-battle-party${multi ? ' is-multi' : ''}" data-campaign-party>${monsters.map((m) => monsterCardMarkup(m, multi)).join('')}</div>`
                        : '<p class="campaign-battle-wait">Preparando onda...</p>'}
                </div>
                <div class="campaign-battle-dock">
                    <div class="campaign-potion-belt" data-campaign-potion-belt>${potionBeltMarkup(battlePotions)}</div>
                    <button type="button" class="campaign-button is-primary" data-campaign-leave>Sair</button>
                </div>
            </div>
        </div>
    `;

    battleRoot.querySelector('[data-campaign-leave]')?.addEventListener('click', () => leaveBattle());
    bindPotionBelt();
    updateWaveTimerDom();
    battleRoot.querySelectorAll('[data-campaign-monster-wrap]').forEach((wrap) => playMonsterEnter(wrap));
}

function pushFeed(events) {
    const feed = battleRoot?.querySelector('[data-campaign-feed]');
    if (!feed || !Array.isArray(events)) return;
    events.forEach((event) => {
        const li = document.createElement('li');
        const type = String(event.type || '');
        li.className = `is-${escapeHtml(type)}`;
        li.textContent = event.message || event.type || '';
        feed.prepend(li);
    });
    while (feed.children.length > 40) feed.lastChild?.remove();
    feed.scrollTop = 0;
}

function sleep(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function playMonsterEnter(wrap) {
    if (!wrap) return;
    wrap.classList.remove('is-dying', 'is-enter');
    wrap.style.opacity = '';
    wrap.style.transform = '';
    wrap.style.filter = '';
    // reflow para reiniciar animacao mesmo com o mesmo sprite
    void wrap.offsetWidth;
    wrap.classList.add('is-enter');
    window.setTimeout(() => {
        wrap.classList.remove('is-enter');
        wrap.style.opacity = '1';
    }, MONSTER_ENTER_MS);
}

function livingMonsters() {
    return Array.isArray(battleRun?.encounters)
        ? battleRun.encounters.filter((m) => Number(m?.current_hp || 0) > 0)
        : [];
}

function syncMonsterCardBars(card, monster) {
    if (!card || !monster) return;
    const fill = card.querySelector('.campaign-battle-monster-mini-bar i');
    const em = card.querySelector('.campaign-battle-monster-mini-bar em');
    const name = card.querySelector('.campaign-battle-monster-mini-bar span');
    if (fill) fill.style.width = `${hpPercent(monster.current_hp, monster.max_hp)}%`;
    if (em) em.textContent = `${Number(monster.current_hp || 0)}/${Number(monster.max_hp || 0)}`;
    if (name) name.textContent = monster.name || 'Inimigo';
}

function rebuildMonsterParty({ enter = false } = {}) {
    const arena = battleRoot?.querySelector('.campaign-battle-arena');
    if (!arena) return;
    const monsters = livingMonsters();
    const multi = monsters.length > 1;
    arena.querySelector('.campaign-battle-wait')?.remove();
    arena.querySelector('[data-campaign-queue]')?.remove();

    let party = arena.querySelector('[data-campaign-party]');
    if (!monsters.length) {
        party?.remove();
        if (!arena.querySelector('.campaign-battle-wait')) {
            const wait = document.createElement('p');
            wait.className = 'campaign-battle-wait';
            wait.textContent = 'Preparando onda...';
            arena.appendChild(wait);
        }
        return;
    }

    if (!party) {
        party = document.createElement('div');
        party.setAttribute('data-campaign-party', '');
        arena.prepend(party);
    }
    party.className = `campaign-battle-party${multi ? ' is-multi' : ''}`;
    party.innerHTML = monsters.map((m) => monsterCardMarkup(m, multi)).join('');
    if (enter) {
        party.querySelectorAll('[data-campaign-monster-wrap]').forEach((wrap) => playMonsterEnter(wrap));
    }
}

function updateBattleHud({ dyingIds = [], forceRebuild = false } = {}) {
    if (!battleRoot || !battleRun || battleRun.status !== 'active') return;
    const monsters = livingMonsters();
    const multi = monsters.length > 1;
    const waveEl = battleRoot.querySelector('.campaign-battle-wave');
    if (waveEl) {
        waveEl.textContent = `Onda ${Number(battleRun.current_wave || 1)}/${Number(battleRun.wave_count || 6)}`;
    }
    updateWaveTimerDom();

    const bars = battleRoot.querySelectorAll('.campaign-battle-bar');
    const playerBar = bars[0];
    const enemyBar = bars[1];
    if (playerBar) {
        const fill = playerBar.querySelector('i');
        const em = playerBar.querySelector('em');
        if (fill) fill.style.width = `${hpPercent(battleRun.current_hp, battleRun.max_hp)}%`;
        if (em) em.textContent = `${Number(battleRun.current_hp || 0)}/${Number(battleRun.max_hp || 0)}`;
    }
    if (enemyBar) {
        const name = enemyBar.querySelector('span');
        const fill = enemyBar.querySelector('i');
        const em = enemyBar.querySelector('em');
        const hpNow = monsters.reduce((sum, m) => sum + Number(m.current_hp || 0), 0);
        const hpMax = monsters.reduce((sum, m) => sum + Number(m.max_hp || 0), 0);
        if (name) name.textContent = multi ? `${monsters.length} inimigos` : (monsters[0]?.name || 'Inimigo');
        if (fill) fill.style.width = `${monsters.length ? hpPercent(hpNow, hpMax) : 0}%`;
        if (em) em.textContent = monsters.length ? `${hpNow}/${hpMax}` : '—';
    }

    const arena = battleRoot.querySelector('.campaign-battle-arena');
    if (!arena) return;
    const party = arena.querySelector('[data-campaign-party]');
    const dying = Array.isArray(dyingIds) ? dyingIds.filter(Boolean) : [];

    if (forceRebuild || !party) {
        rebuildMonsterParty({ enter: true });
        return;
    }

    // Atualiza barras dos vivos; remove mortos apos animacao.
    const aliveIds = new Set(monsters.map((m) => String(m.public_id || '')));
    party.classList.toggle('is-multi', multi);
    party.querySelectorAll('[data-campaign-monster-card]').forEach((card) => {
        const id = card.getAttribute('data-campaign-monster-card') || '';
        const monster = monsters.find((m) => String(m.public_id || '') === id);
        if (monster) {
            card.classList.toggle('is-multi', multi);
            card.querySelector('[data-campaign-monster-wrap]')?.classList.toggle('is-multi', multi);
            syncMonsterCardBars(card, monster);
            return;
        }
        if (dying.includes(id)) {
            const wrap = card.querySelector('[data-campaign-monster-wrap]');
            wrap?.classList.remove('is-enter');
            wrap?.classList.add('is-dying');
            window.setTimeout(() => card.remove(), MONSTER_DIE_MS);
            return;
        }
        card.remove();
    });

    // Novos monstros da onda (ainda nao no DOM).
    monsters.forEach((monster) => {
        const id = String(monster.public_id || '');
        if (!id || party.querySelector(`[data-campaign-monster-card="${id}"]`)) return;
        party.insertAdjacentHTML('beforeend', monsterCardMarkup(monster, multi));
        const card = party.querySelector(`[data-campaign-monster-card="${id}"]`);
        playMonsterEnter(card?.querySelector('[data-campaign-monster-wrap]'));
    });

    if (!aliveIds.size && !dying.length) {
        rebuildMonsterParty({ enter: false });
    }
}

function addLootWidget(grid, item, flags = {}) {
    if (!grid) return null;
    const el = widgetElement(item, flags);
    return lootKit.makeWidget(grid, el, {
        x: Number(item.grid_x || 0),
        y: Number(item.grid_y || 0),
        w: Math.max(1, Number(item.grid_w || 1)),
        h: Math.max(1, Number(item.grid_h || 1)),
    });
}

function moveLootWidget(fromGrid, toGrid, el) {
    return moveLootToFreeSlot(fromGrid, toGrid, el);
}

function deleteSelectedLootItem() {
    const selected = lootRoot?.querySelector('.campaign-loot-item.is-selected');
    if (!selected) return;
    const grid = selected.closest('[data-campaign-drop-grid]') ? dropGrid
        : selected.closest('[data-campaign-bag-grid]') ? bagGrid
            : null;
    if (!grid) return;

    const publicId = selected.getAttribute('data-public-id') || '';
    if (publicId) lootDiscardedPublicIds.add(publicId);

    try {
        grid.removeWidget(selected, true, false);
    } catch {
        selected.remove();
    }
    refreshBagOccupancy();
}

function widgetElement(item, { staging = false, carry = false } = {}) {
    const el = document.createElement('div');
    const w = Math.max(1, Number(item.grid_w || 1));
    const h = Math.max(1, Number(item.grid_h || 1));
    const rarity = String(item.rarity || 'common');
    const qty = Math.max(1, Number(item.quantity || 1));
    const code = String(item.definition_code || '');
    el.className = `grid-stack-item campaign-loot-item is-${rarity}${staging ? ' is-staging' : ''}${carry ? ' is-carry' : ''}`;
    el.setAttribute('gs-w', String(w));
    el.setAttribute('gs-h', String(h));
    el.setAttribute('gs-x', String(Number(item.grid_x || 0)));
    el.setAttribute('gs-y', String(Number(item.grid_y || 0)));
    if (item.staging_id) el.setAttribute('data-staging-id', String(item.staging_id));
    if (Array.isArray(item.staging_ids) && item.staging_ids.length) {
        el.setAttribute('data-staging-ids', item.staging_ids.join(','));
    }
    if (item.public_id) el.setAttribute('data-public-id', String(item.public_id));
    el.setAttribute('data-definition-code', code);
    el.setAttribute('data-quantity', String(qty));
    el.setAttribute('data-item-name', String(item.name || code || 'item'));
    el.setAttribute('data-base-w', String(w));
    el.setAttribute('data-base-h', String(h));
    el.setAttribute('data-rotated', item.rotated ? '1' : '0');
    el.innerHTML = `
        <div class="grid-stack-item-content" title="R/Q/W rotaciona · Del descarta · Alt+click move rapido">
            <span class="campaign-item-rarity-aura" aria-hidden="true"></span>
            <span class="campaign-item-rarity-runner" aria-hidden="true"></span>
            <div class="campaign-loot-item-inner inventory-item is-tiny is-compact rarity-${escapeHtml(rarity)}">
                <strong class="campaign-loot-name">${escapeHtml(item.name || code || 'item')}</strong>
                <span class="campaign-loot-qty">x${qty}</span>
            </div>
        </div>
    `;
    return el;
}

function collectTakenStagingIds() {
    return lootKit.collectPlacements(bagGrid, { stagingOnly: true })
        .flatMap((p) => p.staging_ids || []);
}

function collectTakenPlacements() {
    const raw = lootKit.collectPlacements(bagGrid, { stagingOnly: true })
        // Nunca re-grant em cima de item permanente da bag.
        .filter((p) => !p.public_id);
    return resolveBagPlacements(raw);
}

function collectAbandonedPublicIds() {
    const fromDrop = !dropGrid ? [] : (dropGrid.getGridItems?.() || [])
        .map((el) => el.getAttribute('data-public-id') || '')
        .filter(Boolean);
    return [...new Set([...fromDrop, ...lootDiscardedPublicIds])];
}

/**
 * Garante que placements de staging nao colidem entre si nem com itens
 * permanentes da bag (public_id sem staging). Reposiciona ou exclui.
 */
function resolveBagPlacements(rawPlacements) {
    if (!bagGrid) return Array.isArray(rawPlacements) ? rawPlacements : [];
    const occupied = new Set();

    // Celulas ja tomadas por carry permanente (fica na bag no commit).
    for (const el of (bagGrid.getGridItems?.() || [])) {
        const stagingIds = lootKit.readStagingIds(el);
        if (stagingIds.length > 0) continue;
        const publicId = el.getAttribute('data-public-id') || '';
        if (!publicId) continue;
        const node = el.gridstackNode || {};
        const x = Number(node.x || 0);
        const y = Number(node.y || 0);
        const w = Math.max(1, Number(node.w || 1));
        const h = Math.max(1, Number(node.h || 1));
        for (let yy = y; yy < y + h; yy += 1) {
            for (let xx = x; xx < x + w; xx += 1) occupied.add(`${xx},${yy}`);
        }
    }

    const cols = Math.max(1, Number(bagGrid.opts?.column || bagGrid.getColumn?.() || 1));
    const rows = Math.max(1, Number(bagGrid.opts?.maxRow || bagGrid.opts?.row || 1));
    const resolved = [];

    const rectFree = (x, y, w, h) => {
        if (x < 0 || y < 0 || x + w > cols || y + h > rows) return false;
        for (let yy = y; yy < y + h; yy += 1) {
            for (let xx = x; xx < x + w; xx += 1) {
                if (occupied.has(`${xx},${yy}`)) return false;
            }
        }
        return true;
    };

    const mark = (x, y, w, h) => {
        for (let yy = y; yy < y + h; yy += 1) {
            for (let xx = x; xx < x + w; xx += 1) occupied.add(`${xx},${yy}`);
        }
    };

    const findSpot = (w, h, preferX, preferY) => {
        if (rectFree(preferX, preferY, w, h)) return { x: preferX, y: preferY };
        for (let y = 0; y <= rows - h; y += 1) {
            for (let x = 0; x <= cols - w; x += 1) {
                if (rectFree(x, y, w, h)) return { x, y };
            }
        }
        return null;
    };

    for (const placement of (rawPlacements || [])) {
        const w = Math.max(1, Number(placement.grid_w || 1));
        const h = Math.max(1, Number(placement.grid_h || 1));
        const preferX = Number(placement.grid_x || 0);
        const preferY = Number(placement.grid_y || 0);
        const spot = findSpot(w, h, preferX, preferY);
        if (!spot) {
            // Sem espaco: devolve ao drop se ainda existir o widget.
            const el = (bagGrid.getGridItems?.() || []).find((node) => {
                const ids = lootKit.readStagingIds(node);
                const want = placement.staging_ids || [];
                return want.length > 0 && want.every((id) => ids.includes(id));
            });
            if (el && dropGrid) {
                moveLootToFreeSlot(bagGrid, dropGrid, el);
            }
            continue;
        }
        if (spot.x !== preferX || spot.y !== preferY) {
            const el = (bagGrid.getGridItems?.() || []).find((node) => {
                const ids = lootKit.readStagingIds(node);
                const want = placement.staging_ids || [];
                return want.length > 0 && want.every((id) => ids.includes(id));
            });
            if (el) {
                try { bagGrid.update(el, { x: spot.x, y: spot.y, w, h }); } catch { /* ignore */ }
            }
        }
        mark(spot.x, spot.y, w, h);
        resolved.push({
            ...placement,
            grid_x: spot.x,
            grid_y: spot.y,
            grid_w: w,
            grid_h: h,
        });
    }

    return resolved;
}

function packLootItems(items, cols) {
    const occupied = new Set();
    const placed = [];
    let maxRow = 0;

    const fits = (x, y, w, h) => {
        if (x < 0 || y < 0 || x + w > cols) return false;
        for (let yy = y; yy < y + h; yy += 1) {
            for (let xx = x; xx < x + w; xx += 1) {
                if (occupied.has(`${xx},${yy}`)) return false;
            }
        }
        return true;
    };

    const mark = (x, y, w, h) => {
        for (let yy = y; yy < y + h; yy += 1) {
            for (let xx = x; xx < x + w; xx += 1) {
                occupied.add(`${xx},${yy}`);
            }
        }
    };

    const sorted = [...(items || [])].sort((a, b) => {
        const aArea = Math.max(1, Number(a.grid_w || 1)) * Math.max(1, Number(a.grid_h || 1));
        const bArea = Math.max(1, Number(b.grid_w || 1)) * Math.max(1, Number(b.grid_h || 1));
        return bArea - aArea;
    });

    sorted.forEach((item) => {
        const w = Math.max(1, Number(item.grid_w || 1));
        const h = Math.max(1, Number(item.grid_h || 1));
        let done = false;
        for (let y = 0; y < 80 && !done; y += 1) {
            for (let x = 0; x <= cols - w; x += 1) {
                if (!fits(x, y, w, h)) continue;
                mark(x, y, w, h);
                placed.push({ ...item, grid_x: x, grid_y: y, grid_w: w, grid_h: h });
                maxRow = Math.max(maxRow, y + h);
                done = true;
                break;
            }
        }
        if (!done) {
            placed.push({ ...item, grid_x: 0, grid_y: maxRow, grid_w: w, grid_h: h });
            maxRow += h;
        }
    });

    return { items: placed, rows: Math.max(2, maxRow), cols };
}

const DROP_GRID_COLS = 12;
const DROP_GRID_MIN_ROWS = 3;
const DROP_GRID_MAX_ROWS = 8;
/** Bolsos 2 + maior mochila conhecida (medium 6). */
const MAX_EXPEDITION_COLS = 8;

function sizeDropGrid(staging) {
    const packed = packLootItems(staging || [], DROP_GRID_COLS);
    return {
        items: packed.items,
        cols: DROP_GRID_COLS,
        rows: Math.min(DROP_GRID_MAX_ROWS, Math.max(DROP_GRID_MIN_ROWS, packed.rows)),
    };
}

function applyGridShell(el, cols, rows) {
    lootKit.applyShell(el, cols, rows);
    const shell = el?.closest('.campaign-loot-grid-shell');
    if (shell) {
        shell.style.minHeight = `${Math.max(1, rows) * CELL_PX + 8}px`;
    }
}

function renderLockedBagColumns(host, activeCols, rows, maxCols = MAX_EXPEDITION_COLS) {
    if (!host) return;
    host.querySelectorAll('.campaign-loot-locked-strip').forEach((node) => node.remove());
    const lockedCols = Math.max(0, maxCols - activeCols);
    if (lockedCols <= 0) return;
    const strip = document.createElement('div');
    strip.className = 'campaign-loot-locked-strip';
    strip.style.width = `${lockedCols * CELL_PX}px`;
    strip.style.height = `${rows * CELL_PX}px`;
    strip.style.setProperty('--gs-cell', `${CELL_PX}px`);
    strip.style.gridTemplateColumns = `repeat(${lockedCols}, ${CELL_PX}px)`;
    strip.style.gridTemplateRows = `repeat(${rows}, ${CELL_PX}px)`;
    strip.title = 'Slots bloqueados — equipe uma mochila maior';
    for (let i = 0; i < lockedCols * rows; i += 1) {
        const cell = document.createElement('span');
        cell.className = 'campaign-loot-locked-cell';
        strip.appendChild(cell);
    }
    host.appendChild(strip);
}

function moveLootToFreeSlot(fromGrid, toGrid, el) {
    if (!fromGrid || !toGrid || !el) return false;
    const node = el.gridstackNode || {};
    const w = Math.max(1, Number(node.w || el.getAttribute('gs-w') || 1));
    const h = Math.max(1, Number(node.h || el.getAttribute('gs-h') || 1));
    const free = lootKit.findFreeCell(toGrid, w, h);
    if (!free) {
        showToast('Sem espaco livre no destino.', 'warning');
        return false;
    }
    fromGrid.removeWidget(el, false, false);
    lootKit.makeWidget(toGrid, el, { x: free.x, y: free.y, w, h });
    return true;
}

function quickMoveLootItem(el) {
    if (!el || !dropGrid || !bagGrid) return;
    const inDrop = Boolean(el.closest('[data-campaign-drop-grid]'));
    if (inDrop) {
        moveLootToFreeSlot(dropGrid, bagGrid, el);
    } else {
        moveLootToFreeSlot(bagGrid, dropGrid, el);
    }
    refreshBagOccupancy();
}

let refreshBagOccupancy = () => {};

function rotateSelectedLootItem() {
    const selected = lootRoot?.querySelector('.campaign-loot-item.is-selected');
    const active = lootKit.getActiveDragEl();
    const el = active || selected;
    if (!el) return;
    const grid = el.closest('[data-campaign-drop-grid]') ? dropGrid
        : el.closest('[data-campaign-bag-grid]') ? bagGrid
            : (selected?.closest('[data-campaign-drop-grid]') ? dropGrid : bagGrid);
    lootKit.rotateActiveOrSelected(grid, el);
}

function bindLootItemSelection(root) {
    root?.querySelectorAll('.campaign-loot-item').forEach((item) => {
        item.addEventListener('click', (event) => {
            event.stopPropagation();
            if (event.altKey) {
                event.preventDefault();
                quickMoveLootItem(item);
                return;
            }
            root.querySelectorAll('.campaign-loot-item.is-selected').forEach((node) => {
                if (node !== item) node.classList.remove('is-selected');
            });
            item.classList.add('is-selected');
        });
        item.addEventListener('pointerdown', () => {
            root.querySelectorAll('.campaign-loot-item.is-selected').forEach((node) => {
                if (node !== item) node.classList.remove('is-selected');
            });
            item.classList.add('is-selected');
        });
    });
}

function renderLootPanel() {
    if (!lootRoot) return;
    if (!lootState || scoreboard) {
        destroyLootGrids();
        lootRoot.hidden = true;
        lootRoot.innerHTML = '';
        return;
    }

    const staging = Array.isArray(lootState.staging_loot) ? lootState.staging_loot : [];
    const carry = lootState.expedition_carry || {};
    // Respeita a bag equipada (ex.: 6x4) ou bolsos 2x2 sem mochila.
    // grid_columns ja vem reduzido por fome (carry_locked_cols) no backend.
    const cols = Math.max(1, Number(carry.grid_columns || 2));
    const rows = Math.max(1, Number(carry.grid_rows || 2));
    const lockedCols = Math.max(0, Number(carry.hunger_locked_cols || 0));
    const fullCols = Math.max(cols, Number(carry.full_grid_columns || cols));
    const dropSize = sizeDropGrid(staging);
    const bagLabel = cols <= 2 && rows <= 2
        ? 'Bolsos'
        : (carry.name || 'Expedition Carry');
    const bagHint = lockedCols > 0
        ? `Fome bloqueou ${lockedCols} coluna(s): usando ${cols}x${rows} de ${fullCols}x${rows}`
        : (cols <= 2 && rows <= 2
            ? 'Espaco minimo nos bolsos'
            : `Bolsos 2x2 + mochila equipada = ${cols}x${rows}`);
    const vitalNotes = Array.isArray(carry.vital_notes) ? carry.vital_notes : [];
    const vitalLine = vitalNotes.length
        ? `<p class="campaign-loot-vitalwarn">${vitalNotes.map((n) => escapeHtml(n)).join(' · ')}</p>`
        : '';

    if (battleRoot) battleRoot.hidden = true;
    if (lobbyRoot) lobbyRoot.hidden = true;

    destroyLootGrids();
    lootDiscardedPublicIds = new Set();
    lootRoot.hidden = false;
    lootRoot.innerHTML = `
        <div class="campaign-loot-panel">
            <header class="campaign-loot-header">
                <div>
                    <p class="campaign-loot-kicker">Triagem de loot</p>
                    <strong>${escapeHtml(lootState.run?.node_label || 'Fase concluida')}</strong>
                    <p>Arraste Drops → Expedition. Sem merge aqui — organize no Main Inventory. <kbd>R</kbd> rotaciona · <kbd>Del</kbd> descarta · <kbd>Alt</kbd>+click move rapido.</p>
                    ${vitalLine}
                </div>
                <div class="campaign-loot-actions">
                    <button type="button" class="campaign-button is-primary" data-campaign-loot-commit>Levar e sair</button>
                </div>
            </header>
            <div class="campaign-loot-grids">
                <section class="campaign-loot-pane">
                    <h3>Drops da run <em>${staging.length} · ${DROP_GRID_COLS}x${dropSize.rows}</em></h3>
                    <div class="campaign-loot-grid-shell">
                        <div class="grid-stack campaign-loot-drop-grid" data-campaign-drop-grid></div>
                    </div>
                </section>
                <section class="campaign-loot-pane">
                    <h3>${escapeHtml(bagLabel)} <em data-campaign-bag-occupancy>${cols}x${rows} · ${Number(carry.occupied_cells || 0)}/${cols * rows}</em></h3>
                    <p class="campaign-loot-bag-hint">${escapeHtml(bagHint)}${MAX_EXPEDITION_COLS > fullCols ? ` · +${MAX_EXPEDITION_COLS - fullCols} cols com mochila maior` : ''}</p>
                    <div class="campaign-loot-grid-shell is-carry${lockedCols > 0 ? ' is-hunger-locked' : ''}" data-campaign-bag-shell>
                        <div class="grid-stack campaign-loot-bag-grid inventory-grid" data-campaign-bag-grid></div>
                    </div>
                </section>
            </div>
        </div>
    `;

    const dropEl = lootRoot.querySelector('[data-campaign-drop-grid]');
    const bagEl = lootRoot.querySelector('[data-campaign-bag-grid]');
    applyGridShell(dropEl, dropSize.cols, dropSize.rows);
    applyGridShell(bagEl, cols, rows);

    if (!dropEl || !bagEl || typeof window.GridStack?.init !== 'function') {
        dropEl.innerHTML = staging.map((item) => `
            <button type="button" class="campaign-loot-fallback" data-staging-id="${escapeHtml(item.staging_id || '')}">
                ${escapeHtml(item.name || item.definition_code)} x${Number(item.quantity || 1)}
            </button>
        `).join('') || '<p class="campaign-loot-empty">Nenhum item no pool.</p>';
        bagEl.innerHTML = (carry.items || []).map((item) => `
            <button type="button" class="campaign-loot-fallback is-carry" data-public-id="${escapeHtml(item.public_id || '')}">
                ${escapeHtml(item.name || item.definition_code)} x${Number(item.quantity || 1)}
            </button>
        `).join('') || '<p class="campaign-loot-empty">Bag vazia.</p>';

        const taken = new Set();
        const abandoned = new Set();
        dropEl.querySelectorAll('[data-staging-id]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-staging-id');
                if (!id) return;
                if (taken.has(id)) {
                    taken.delete(id);
                    btn.classList.remove('is-taken');
                } else {
                    taken.add(id);
                    btn.classList.add('is-taken');
                }
            });
        });
        bagEl.querySelectorAll('[data-public-id]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-public-id');
                if (!id) return;
                if (abandoned.has(id)) {
                    abandoned.delete(id);
                    btn.classList.remove('is-taken');
                } else {
                    abandoned.add(id);
                    btn.classList.add('is-taken');
                }
            });
        });
        lootRoot.querySelector('[data-campaign-loot-commit]')?.addEventListener('click', () => {
            commitLoot([...taken], [...abandoned]);
        });
        return;
    }

    dropGrid = lootKit.initFixedGrid(dropEl, {
        cols: DROP_GRID_COLS,
        rows: dropSize.rows,
        acceptWidgets: true,
    });

    bagGrid = lootKit.initFixedGrid(bagEl, {
        cols,
        rows,
        acceptWidgets: true,
    });

    if (!dropGrid || !bagGrid) {
        return;
    }

    dropSize.items.forEach((item) => {
        addLootWidget(dropGrid, item, { staging: true });
    });

    (carry.items || []).forEach((item) => {
        addLootWidget(bagGrid, item, { carry: true });
    });

    applyGridShell(dropEl, DROP_GRID_COLS, dropSize.rows);
    applyGridShell(bagEl, cols, rows);
    renderLockedBagColumns(lootRoot.querySelector('[data-campaign-bag-shell]'), cols, rows, MAX_EXPEDITION_COLS);
    bindLootItemSelection(lootRoot);
    mapFx.decorateEssence(lootRoot);

    lootFeedbackCleanups.push(
        lootKit.bindPlacementFeedback(dropGrid),
        lootKit.bindPlacementFeedback(bagGrid)
    );

    const occupancyEl = lootRoot.querySelector('[data-campaign-bag-occupancy]');
    refreshBagOccupancy = () => {
        if (!occupancyEl || !bagGrid) return;
        const occupied = (bagGrid.getGridItems?.() || []).reduce((sum, el) => {
            const node = el.gridstackNode || {};
            return sum + Math.max(1, Number(node.w || el.getAttribute('gs-w') || 1)) * Math.max(1, Number(node.h || el.getAttribute('gs-h') || 1));
        }, 0);
        occupancyEl.textContent = `${cols}x${rows} · ${occupied}/${cols * rows}`;
        lootKit.forceFloat(bagGrid);
        lootKit.forceFloat(dropGrid);
        lootKit.unlockAllNodes(bagGrid);
        lootKit.unlockAllNodes(dropGrid);
    };
    bagGrid.on('added removed change', refreshBagOccupancy);
    refreshBagOccupancy();

    lootRoot.querySelector('[data-campaign-loot-commit]')?.addEventListener('click', () => {
        commitLoot(collectTakenStagingIds(), collectAbandonedPublicIds(), collectTakenPlacements());
    });
}

function renderScoreboard() {
    if (!scoreRoot) return;
    if (!scoreboard) {
        scoreRoot.hidden = true;
        scoreRoot.innerHTML = '';
        return;
    }

    if (battleRoot) battleRoot.hidden = true;
    if (lootRoot) {
        destroyLootGrids();
        lootRoot.hidden = true;
        lootRoot.innerHTML = '';
    }
    if (lobbyRoot) lobbyRoot.hidden = true;

    const taken = Array.isArray(scoreboard.items_taken) ? scoreboard.items_taken : [];
    const left = Array.isArray(scoreboard.items_left) ? scoreboard.items_left : [];
    const failed = Array.isArray(scoreboard.failed) ? scoreboard.failed : [];

    scoreRoot.hidden = false;
    scoreRoot.innerHTML = `
        <div class="campaign-score-panel">
            <p class="campaign-loot-kicker">Placar</p>
            <strong>${escapeHtml(scoreboard.node_label || 'Fase')}</strong>
            <div class="campaign-score-stats">
                <div><span>Tempo</span><em>${escapeHtml(scoreboard.duration_label || '0:00')}</em></div>
                <div><span>Melhor</span><em>${escapeHtml(scoreboard.best_clear_label || '—')}${scoreboard.is_best ? ' ★' : ''}</em></div>
                <div><span>XP</span><em>+${Number(scoreboard.exploration_xp || 0)}</em></div>
                <div><span>Ouro</span><em>+${Number(scoreboard.gold || 0)}G</em></div>
            </div>
            ${scoreboard.exploration ? `<p class="campaign-score-xp">Exploracao Nv.${Number(scoreboard.exploration.level || 1)} · ${Number(scoreboard.exploration.xp || 0)}/${Number(scoreboard.exploration.xp_next || 0)} XP</p>` : ''}
            <div class="campaign-score-lists">
                <div>
                    <h4>Levados (${taken.length})</h4>
                    <ul>${taken.map((item) => `<li>${escapeHtml(item.name || item.definition_code)} x${Number(item.quantity || 1)}</li>`).join('') || '<li>Nenhum</li>'}</ul>
                </div>
                <div>
                    <h4>Deixados (${left.length})</h4>
                    <ul>${left.map((item) => `<li>${escapeHtml(item.name || item.definition_code)} x${Number(item.quantity || 1)}</li>`).join('') || '<li>Nenhum</li>'}</ul>
                </div>
            </div>
            ${failed.length ? `<div class="campaign-score-failed"><h4>Falhas ao guardar (${failed.length})</h4><ul>${failed.map((f) => `<li>${escapeHtml(f.message || 'Falha')}</li>`).join('')}</ul></div>` : ''}
            <button type="button" class="campaign-button is-primary" data-campaign-score-continue>Continuar</button>
        </div>
    `;

    mapFx.celebrate(scoreboard.is_best ? 'hard' : 'soft');
    mapFx.onLobbyShow(scoreRoot.querySelector('.campaign-score-panel'));

    scoreRoot.querySelector('[data-campaign-score-continue]')?.addEventListener('click', async () => {
        scoreboard = null;
        lootState = null;
        battleRun = null;
        hideOverlays();
        await loadWorld({ keepSelection: true });
        render();
    });
}

async function openLootTriage() {
    stopTickLoop();
    try {
        const response = await apiFetch('/api/campaign/stages/loot');
        lootState = response.data || null;
        const staging = Array.isArray(lootState?.staging_loot) ? lootState.staging_loot : [];
        if (!lootState?.run) {
            battleRun = null;
            lootState = null;
            await loadWorld({ keepSelection: true });
            render();
            return;
        }

        const combat = lootState.run?.combat || {};
        if (combat.loot_committed && combat.scoreboard) {
            scoreboard = combat.scoreboard;
            lootState = null;
            battleRun = null;
            renderScoreboard();
            render();
            return;
        }

        if (staging.length === 0) {
            await commitLoot([]);
            return;
        }
        battleRun = lootState.run;
        renderLootPanel();
        render();
    } catch (error) {
        showToast(errorMessage(error, 'Nao foi possivel abrir a triagem.'), 'warning');
        battleRun = null;
        await loadWorld({ keepSelection: true });
        render();
    }
}

async function commitLoot(takeStagingIds, abandonPublicIds = [], takePlacements = null) {
    if (lootCommitInFlight) return;
    lootCommitInFlight = true;
    try {
        const placements = resolveBagPlacements(
            Array.isArray(takePlacements) ? takePlacements : collectTakenPlacements()
        ).filter((p) => !p.public_id);
        const abandon = [...new Set([
            ...(abandonPublicIds || []),
            ...lootDiscardedPublicIds,
        ])];
        const response = await apiFetch('/api/campaign/stages/loot/commit', {
            method: 'POST',
            body: {
                take_staging_ids: takeStagingIds || placements.flatMap((p) => p.staging_ids || []),
                take_placements: placements,
                abandon_public_ids: abandon,
            },
        });
        scoreboard = response.data?.scoreboard || null;
        lootState = null;
        battleRun = null;
        destroyLootGrids();
        if (lootRoot) {
            lootRoot.hidden = true;
            lootRoot.innerHTML = '';
        }
        renderScoreboard();
        showToast('Loot confirmado', 'success');
        render();
    } catch (error) {
        showToast(errorMessage(error, 'Falha ao confirmar loot.'), 'warning');
    } finally {
        lootCommitInFlight = false;
    }
}

async function runTick() {
    if (!battleRun || battleRun.status !== 'active' || tickInFlight) return;
    tickInFlight = true;
    try {
        const response = await apiFetch('/api/campaign/stages/tick', { method: 'POST', body: {} });
        const payload = response.data || {};
        battleRun = payload.run || battleRun;
        if (Array.isArray(payload.potions)) {
            battlePotions = payload.potions;
            renderPotionBelt();
        }
        syncWaveTimerFromPayload(payload);

        showCombatFloaters(payload.events || []);
        pushFeed(payload.events || []);

        const killEvents = (payload.events || []).filter((e) => ['monster_kill', 'boss_kill'].includes(String(e?.type || '')));
        const dyingIds = killEvents.map((e) => String(e?.encounter_public_id || '')).filter(Boolean);
        const waveAdvance = (payload.events || []).some((e) => ['wave_advance', 'wave_spawn'].includes(String(e?.type || '')));
        const killed = dyingIds.length > 0;

        if (payload.wave_failed || battleRun?.status === 'failed') {
            stopTickLoop();
            showToast('Tempo da onda esgotado — voce perdeu.', 'warning');
            battleRun = null;
            battlePotions = [];
            hideOverlays();
            await loadWorld({ keepSelection: true });
            render();
            return;
        }

        if (payload.player_defeated) {
            showToast('Derrotado — onda reiniciada', 'warning');
            await sleep(BEAT_PAUSE_MS);
        }

        if (payload.stage_cleared || payload.awaiting_loot || battleRun?.status === 'awaiting_loot') {
            stopTickLoop();
            battleRoot?.querySelectorAll('[data-campaign-monster-wrap]').forEach((wrap) => wrap.classList.add('is-dying'));
            showToast('Fase concluida! Escolha o loot.', 'success');
            await sleep(killed ? MONSTER_DIE_MS : BEAT_PAUSE_MS);
            await openLootTriage();
            return;
        }

        if (battleRun?.status !== 'active') {
            stopTickLoop();
            battleRun = null;
            renderBattle();
            return;
        }

        if (killed) {
            updateBattleHud({ dyingIds });
            await sleep(MONSTER_DIE_MS + PLAYER_ACTION_PAUSE_MS);
        }
        if (waveAdvance || payload.player_defeated) {
            updateBattleHud({ forceRebuild: true });
            await sleep(MONSTER_ENTER_MS + MONSTER_ACTION_PAUSE_MS);
        } else if (!killed) {
            const monsterActed = (payload.events || []).some((e) => ['monster_hit', 'monster_crit'].includes(String(e?.type || '')));
            const playerActed = (payload.events || []).some((e) => ['player_hit', 'player_crit'].includes(String(e?.type || '')));
            updateBattleHud();
            if (monsterActed && playerActed) {
                await sleep(Math.max(PLAYER_ACTION_PAUSE_MS, MONSTER_ACTION_PAUSE_MS));
            } else if (monsterActed) {
                await sleep(MONSTER_ACTION_PAUSE_MS);
            } else {
                await sleep(PLAYER_ACTION_PAUSE_MS);
            }
        } else {
            updateBattleHud();
            await sleep(BEAT_PAUSE_MS);
        }
    } catch (error) {
        const msg = errorMessage(error, 'Combate pausado.');
        showToast(msg, 'warning');
        if (/energia|energy/i.test(msg)) {
            stopTickLoop();
        }
    } finally {
        tickInFlight = false;
    }
}

async function startBattle(nodeCode) {
    if (startInFlight) return;
    startInFlight = true;
    try {
        const response = await apiFetch('/api/campaign/stages/start', {
            method: 'POST',
            body: { node_code: nodeCode },
        });
        battleRun = response.data?.run || null;
        scoreboard = null;
        lootState = null;
        battlePotions = [];
        waveRemainingMs = Number(battleRun?.combat?.wave_limit_ms || 75000);
        waveLimitMs = waveRemainingMs;
        hideOverlays({ keepBattle: true });
        if (!battleRun) {
            showToast('Nao foi possivel entrar na fase.', 'warning');
            return;
        }
        if (lobbyRoot) lobbyRoot.hidden = true;
        renderBattle();
        startTickLoop();
        // Primeiro tick ja traz as pocoes; dispara um tick cedo para sincronizar HUD.
        runTick().catch(() => {});
        showToast(`Entrando em ${battleRun.node_label || 'fase'}`, 'success');
        render();
    } catch (error) {
        showToast(errorMessage(error, 'Nao foi possivel iniciar a fase.'), 'warning');
    } finally {
        startInFlight = false;
    }
}

async function leaveBattle() {
    stopTickLoop();
    try {
        await apiFetch('/api/campaign/stages/leave', { method: 'POST', body: {} });
    } catch {
        // ignore
    }
    battleRun = null;
    battlePotions = [];
    lootState = null;
    scoreboard = null;
    hideOverlays();
    await loadWorld({ keepSelection: true });
    render();
}

async function resumeActiveBattle() {
    try {
        const response = await apiFetch('/api/campaign/stages/active');
        const run = response.data?.run || null;
        if (run?.status === 'active') {
            battleRun = run;
            syncWaveTimerFromPayload({
                wave: {
                    remaining_ms: Math.max(0, Number(run.combat?.wave_limit_ms || 75000) - (Date.now() - Number(run.combat?.wave_started_at_ms || Date.now()))),
                    limit_ms: Number(run.combat?.wave_limit_ms || 75000),
                },
            });
            renderBattle();
            startTickLoop();
            runTick().catch(() => {});
            render();
            return;
        }
        if (run?.status === 'awaiting_loot') {
            battleRun = run;
            await openLootTriage();
        }
    } catch {
        // ignore
    }
}

function renderLobby(node) {
    if (!lobbyRoot) return;

    if (!node || battleRun || lootState || scoreboard) {
        lobbyRoot.hidden = true;
        lobbyRoot.innerHTML = '';
        lobbyRoot.classList.remove('is-expanded');
        closeDossier();
        return;
    }

    const lobby = node.lobby || {};
    const score = lobby.score || {};
    const isStage = node.type === 'stage';
    const isVillage = node.type === 'village';
    const hasDossier = isStage && (Array.isArray(lobby.threats) || lobby.story);
    const scene = node.scene_url
        ? `<div class="campaign-lobby-scene" style="background-image:url('${escapeHtml(node.scene_url)}')"></div>`
        : '';
    const vitalNotes = Array.isArray(lobby.vital_notes)
        ? lobby.vital_notes
        : (Array.isArray(world?.player?.vital_penalties?.notes) ? world.player.vital_penalties.notes : []);

    const chipBits = nodeSummaryChipBits(node);

    const scoreLine = (score.best_clear_label || Number(score.clear_count || 0) > 0)
        ? `<p class="campaign-lobby-scoreline">Melhor ${escapeHtml(score.best_clear_label || '—')} · ${Number(score.clear_count || 0)} clear(s)</p>`
        : '';

    const softWarn = isStage && lobby.soft_ready === false
        ? `<p class="campaign-lobby-softwarn">Seu ATK/DEF/poder esta abaixo do recomendado. Voce pode entrar, mas as ondas e chefes vao doer.</p>`
        : '';

    const vitalLine = vitalNotes.length
        ? `<p class="campaign-lobby-vitalwarn">${vitalNotes.map((n) => escapeHtml(n)).join(' · ')}</p>`
        : '';

    const detailsHtml = hasDossier ? lobbyDetailsMarkup(lobby) : '';
    const villageHtml = isVillage && lobby.cta_enabled ? villageHotspotsMarkup(lobby) : '';

    lobbyRoot.hidden = false;
    lobbyRoot.classList.toggle('is-expanded', Boolean(lobbyDetailsOpen && hasDossier));
    lobbyRoot.innerHTML = `
        ${scene}
        <div class="campaign-lobby-body">
            <p class="campaign-lobby-kicker">${escapeHtml(statusLabel(node.status))} · ${escapeHtml(typeLabel(node.type))}</p>
            <strong>${escapeHtml(lobby.title || node.label)}</strong>
            <p>${escapeHtml(lobby.body || '')}</p>
            ${chipBits.length ? `<div class="campaign-lobby-chips">${chipBits.join('')}</div>` : ''}
            ${scoreLine}
            ${softWarn}
            ${vitalLine}
            ${villageHtml}
            ${detailsHtml}
            <div class="campaign-lobby-actions">
                ${isVillage ? '' : `
                <button type="button" class="campaign-button is-primary" data-campaign-cta ${lobby.cta_enabled ? '' : 'disabled'}>
                    ${escapeHtml(lobby.cta || 'OK')}
                </button>`}
                ${hasDossier ? `
                    <div class="campaign-lobby-actions-row">
                        <button type="button" class="campaign-button is-ghost" data-campaign-lobby-expand>
                            ${lobbyDetailsOpen ? 'Ocultar detalhes' : 'Ver detalhes'}
                        </button>
                        <button type="button" class="campaign-button is-ghost" data-campaign-lobby-dossier>Dossier</button>
                    </div>
                ` : ''}
                <button type="button" class="campaign-lobby-close" data-campaign-lobby-close>Fechar</button>
            </div>
        </div>
    `;

    lobbyRoot.querySelector('[data-campaign-lobby-close]')?.addEventListener('click', () => {
        selectedNodeCode = null;
        lobbyDetailsOpen = false;
        closeDossier();
        render();
    });

    lobbyRoot.querySelector('[data-campaign-lobby-expand]')?.addEventListener('click', () => {
        lobbyDetailsOpen = !lobbyDetailsOpen;
        renderLobby(node);
    });

    lobbyRoot.querySelector('[data-campaign-lobby-dossier]')?.addEventListener('click', () => {
        openDossier(node);
    });

    lobbyRoot.querySelector('[data-campaign-cta]')?.addEventListener('click', () => {
        startNodeAction(node);
    });

    lobbyRoot.querySelectorAll('[data-campaign-hotspot]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const code = btn.getAttribute('data-campaign-hotspot');
            if (code) interactVillage(node.code, code);
        });
    });

    mapFx.onLobbyShow(lobbyRoot);
}

function villageHotspotsMarkup(lobby) {
    const spots = Array.isArray(lobby.hotspots) ? lobby.hotspots : [];
    if (!spots.length) {
        return '<p class="campaign-lobby-meta">Nenhum ponto de interesse cadastrado.</p>';
    }
    return `
        <div class="campaign-village-hotspots">
            <h4>Pontos de interesse</h4>
            <div class="campaign-village-hotspot-row">
                ${spots.map((h) => `
                    <button type="button" class="campaign-button is-ghost campaign-village-hotspot" data-campaign-hotspot="${escapeHtml(h.code || '')}">
                        ${escapeHtml(h.label || h.code || 'Local')}
                        ${h.requires_tool ? `<em title="Ferramenta">${escapeHtml(h.requires_tool)}</em>` : ''}
                    </button>
                `).join('')}
            </div>
            <p class="campaign-lobby-meta">MVP: lupa ainda nao e item obrigatorio — investigue o Bau escondido.</p>
        </div>
    `;
}

async function interactVillage(nodeCode, hotspotCode) {
    try {
        const response = await apiFetch('/api/campaign/village/interact', {
            method: 'POST',
            body: { node_code: nodeCode, hotspot_code: hotspotCode },
        });
        if (response.data?.world) {
            world = response.data.world;
        }
        showToast(response.message || response.data?.interaction?.message || 'Investigado.', 'success');
        const granted = response.data?.interaction?.flags_granted || [];
        if (granted.includes('torn_map')) {
            mapFx.discoveryFlash();
        }
        const node = (world?.nodes || []).find((n) => n.code === nodeCode) || null;
        if (node) renderLobby(node);
        else render();
        renderMap();
    } catch (error) {
        showToast(errorMessage(error, 'Nao foi possivel investigar.'), 'warning');
    }
}

function lobbyDetailsMarkup(lobby) {
    const reqs = Array.isArray(lobby.requirements) ? lobby.requirements : [];
    const threats = Array.isArray(lobby.threats) ? lobby.threats : [];
    const loot = Array.isArray(lobby.loot_preview) ? lobby.loot_preview : [];
    const mods = Array.isArray(lobby.modifiers) ? lobby.modifiers : [];
    const chips = lobby.summary_chips || {};
    const score = lobby.score || {};

    const reqList = reqs.length
        ? `<ul>${reqs.map((r) => {
            const met = r.met;
            const cls = met === true ? 'is-met' : (met === false ? 'is-unmet' : '');
            return `<li class="${cls}">${escapeHtml(r.label || '')}</li>`;
        }).join('')}</ul>`
        : '<p>Sem requisitos especiais.</p>';

    const threatRow = threats.length
        ? `<div class="campaign-lobby-mini-row">${threats.slice(0, 4).map((t) => {
            const known = Boolean(t.discovered);
            return `
            <span class="campaign-lobby-mini${known ? '' : ' is-unknown'}">
                ${t.art_url ? `<img src="${escapeHtml(t.art_url)}" alt="" class="${known ? '' : 'is-silhouette'}">` : ''}
                ${escapeHtml(known ? (t.name || t.code || '') : '???')}
            </span>`;
        }).join('')}</div>`
        : '<p>Pool nao informado.</p>';

    const lootRow = loot.length
        ? `<div class="campaign-lobby-mini-row">${loot.slice(0, 6).map((item) => {
            const known = Boolean(item.discovered);
            const rarity = String(item.rarity || 'common');
            return `
            <span class="campaign-lobby-mini${known ? '' : ' is-unknown'} rarity-${escapeHtml(rarity)}">
                ${escapeHtml(known ? (item.name || item.code || '') : '???')}
                <em class="campaign-lobby-rarity">${escapeHtml(rarity)}</em>
                ${item.special ? '<em class="campaign-lobby-badge">especial</em>' : ''}
            </span>`;
        }).join('')}</div>`
        : '<p>Loot comum da run.</p>';

    const modList = mods.length
        ? `<ul>${mods.map((m) => `<li><strong>${escapeHtml(m.kind || 'buff')}</strong> — ${escapeHtml(m.label || '')}${m.detail ? `: ${escapeHtml(m.detail)}` : ''}</li>`).join('')}</ul>`
        : '<p>Nenhum modificador ambiental.</p>';

    return `
        <div class="campaign-lobby-details">
            <section class="campaign-lobby-section">
                <h4>Historia</h4>
                <p>${escapeHtml(lobby.story || '')}</p>
            </section>
            <section class="campaign-lobby-section">
                <h4>Exigencias</h4>
                ${reqList}
            </section>
            <section class="campaign-lobby-section">
                <h4>Energia</h4>
                <p>Entrada ${Number(chips.energy_start ?? 5)} · ${Number(chips.energy_per_tick ?? 0.35)} por tick</p>
            </section>
            <section class="campaign-lobby-section">
                <h4>Ameacas</h4>
                ${threatRow}
            </section>
            <section class="campaign-lobby-section">
                <h4>Achados${lobby.has_special_drops ? ' <em class="campaign-lobby-badge">especiais</em>' : ''}</h4>
                ${lootRow}
            </section>
            <section class="campaign-lobby-section">
                <h4>Clima da fase</h4>
                ${modList}
            </section>
            <section class="campaign-lobby-section">
                <h4>Placar pessoal</h4>
                <p>Melhor ${escapeHtml(score.best_clear_label || '—')} · Clears ${Number(score.clear_count || 0)} · Onda max ${Number(score.highest_wave || 0)}</p>
            </section>
        </div>
    `;
}

function startNodeAction(node) {
    const lobby = node?.lobby || {};
    if (node.status === 'teaser') {
        showToast(lobby.body || 'Em breve.', 'warning');
        return;
    }
    if (node.type === 'village') {
        // Hotspots ja ficam no lobby; CTA so reforça o painel.
        if (!lobby.cta_enabled) {
            showToast(lobby.body || 'Vilarejo bloqueado.', 'warning');
            return;
        }
        showToast('Escolha um ponto de interesse abaixo.', 'info');
        return;
    }
    if (node.locked || !lobby.cta_enabled) {
        showToast(lobby.body || 'Ainda bloqueado.', 'warning');
        return;
    }
    if (lobby.soft_ready === false) {
        const ok = window.confirm(
            'Seu ataque, defesa ou poder esta abaixo do recomendado. Ondas com varios monstros e chefes podem te matar. Entrar mesmo assim?'
        );
        if (!ok) return;
    }
    closeDossier();
    startBattle(node.code);
}

function openDossier(node) {
    if (!dossierRoot || !node || node.type !== 'stage') return;
    dossierTab = 'overview';
    renderDossier(node);
}

function renderDossier(node) {
    if (!dossierRoot || !node) return;
    const lobby = node.lobby || {};
    const chips = lobby.summary_chips || {};
    const score = lobby.score || {};
    const threats = Array.isArray(lobby.threats) ? lobby.threats : [];
    const loot = Array.isArray(lobby.loot_preview) ? lobby.loot_preview : [];
    const mods = Array.isArray(lobby.modifiers) ? lobby.modifiers : [];
    const reqs = Array.isArray(lobby.requirements) ? lobby.requirements : [];

    const tabs = [
        ['overview', 'Visao geral'],
        ['monsters', 'Monstros'],
        ['loot', 'Itens'],
        ['score', 'Placar'],
    ];

    dossierRoot.hidden = false;
    dossierRoot.innerHTML = `
        <div class="campaign-dossier-backdrop" data-campaign-dossier-close></div>
        <div class="campaign-dossier-panel" role="dialog" aria-modal="true" aria-label="Dossier da fase">
            <header class="campaign-dossier-head">
                <div>
                    <strong>${escapeHtml(lobby.title || node.label)}</strong>
                    <p>${escapeHtml(statusLabel(node.status))} · Nv.${Number(chips.map_level || 1)} · Poder ${Number(chips.power || 0)}</p>
                </div>
                <button type="button" class="campaign-lobby-close" data-campaign-dossier-close>Fechar</button>
            </header>
            <nav class="campaign-dossier-tabs">
                ${tabs.map(([id, label]) => `
                    <button type="button" class="campaign-dossier-tab${dossierTab === id ? ' is-active' : ''}" data-dossier-tab="${id}">${label}</button>
                `).join('')}
            </nav>
            <div class="campaign-dossier-body">
                <div class="campaign-dossier-pane" data-dossier-pane="overview" ${dossierTab === 'overview' ? '' : 'hidden'}>
                    <div class="campaign-dossier-grid">
                        <p>${escapeHtml(lobby.story || lobby.body || '')}</p>
                        <div class="campaign-dossier-stat"><em>Energia</em><b>Entrada ${Number(chips.energy_start ?? 5)} · ${Number(chips.energy_per_tick ?? 0.35)}/tick</b></div>
                        <div class="campaign-dossier-stat"><em>Ondas</em><b>${Number(chips.waves || 0)} · chefes ${(chips.boss_waves || []).join(', ') || '—'}</b></div>
                        <div class="campaign-dossier-stat"><em>Exigencias</em><b>${reqs.map((r) => r.label).filter(Boolean).join(' · ') || 'Nenhuma'}</b></div>
                        <div class="campaign-dossier-stat"><em>Clima</em><b>${mods.length ? mods.map((m) => `${m.kind}: ${m.label}`).join(' · ') : 'Neutro'}</b></div>
                        ${mods.map((m) => `<p><strong>${escapeHtml(m.label || '')}</strong> — ${escapeHtml(m.detail || '')}</p>`).join('')}
                    </div>
                </div>
                <div class="campaign-dossier-pane" data-dossier-pane="monsters" ${dossierTab === 'monsters' ? '' : 'hidden'}>
                    <div class="campaign-dossier-grid is-cards">
                        ${threats.map((t) => {
                            const known = Boolean(t.discovered);
                            return `
                            <article class="campaign-dossier-card${known ? '' : ' is-unknown'}">
                                ${t.art_url ? `<img src="${escapeHtml(t.art_url)}" alt="" class="${known ? '' : 'is-silhouette'}">` : ''}
                                <strong>${escapeHtml(known ? (t.name || t.code || '') : '???')}</strong>
                                <span>${known ? `${escapeHtml(t.element || '—')}${t.resistance ? ` · resiste ${escapeHtml(t.resistance)}` : ''}` : 'Ainda nao encontrado'}</span>
                                <span>${known ? 'Pode aparecer como chefe nas ondas marcadas' : 'Derrote para revelar'}</span>
                            </article>`;
                        }).join('') || '<p>Nenhum monstro no pool.</p>'}
                    </div>
                </div>
                <div class="campaign-dossier-pane" data-dossier-pane="loot" ${dossierTab === 'loot' ? '' : 'hidden'}>
                    <div class="campaign-dossier-grid is-cards">
                        ${loot.map((item) => {
                            const known = Boolean(item.discovered);
                            const rarity = String(item.rarity || 'common');
                            return `
                            <article class="campaign-dossier-card${known ? '' : ' is-unknown'} rarity-${escapeHtml(rarity)}">
                                <strong>${escapeHtml(known ? (item.name || item.code || '') : '???')}${item.special ? ' <em class="campaign-lobby-badge">especial</em>' : ''}</strong>
                                <span class="campaign-dossier-rarity">${escapeHtml(rarity)}</span>
                                <span>${known ? 'Ja visto em drops' : 'Ainda nao encontrado — raridade revelada'}</span>
                            </article>`;
                        }).join('') || '<p>Sem preview de loot.</p>'}
                    </div>
                    ${lobby.has_special_drops ? '<p class="campaign-lobby-meta">Esta fase pode dropar itens especiais/artefatos.</p>' : '<p>Sem artefatos especiais listados.</p>'}
                </div>
                <div class="campaign-dossier-pane" data-dossier-pane="score" ${dossierTab === 'score' ? '' : 'hidden'}>
                    <div class="campaign-dossier-grid">
                        <div class="campaign-dossier-stat"><em>Melhor tempo</em><b>${escapeHtml(score.best_clear_label || '—')}</b></div>
                        <div class="campaign-dossier-stat"><em>Clears</em><b>${Number(score.clear_count || 0)}</b></div>
                        <div class="campaign-dossier-stat"><em>Onda maxima</em><b>${Number(score.highest_wave || 0)}</b></div>
                        <h4 class="campaign-lobby-section" style="margin:8px 0 0">Historico pessoal</h4>
                        ${(Array.isArray(score.history) && score.history.length)
                            ? `<ul class="campaign-dossier-history">${score.history.map((row) => `
                                <li>${escapeHtml(row.duration_label || '—')}${row.is_best ? ' ★' : ''} · ${Number(row.kills || 0)} kills · +${Number(row.gold || 0)}G · +${Number(row.exploration_xp || 0)} XP</li>
                            `).join('')}</ul>`
                            : '<p>Sem clears registrados ainda. Conclua a fase para comecar o historico.</p>'}
                        <p class="campaign-lobby-meta">Ranking global fica para uma proxima etapa.</p>
                    </div>
                </div>
            </div>
            <footer class="campaign-dossier-foot">
                <button type="button" class="campaign-button is-ghost" data-campaign-dossier-close>Fechar</button>
                <button type="button" class="campaign-button is-primary" data-campaign-dossier-cta ${lobby.cta_enabled ? '' : 'disabled'}>
                    ${escapeHtml(lobby.cta || 'OK')}
                </button>
            </footer>
        </div>
    `;

    dossierRoot.querySelectorAll('[data-campaign-dossier-close]').forEach((el) => {
        el.addEventListener('click', () => closeDossier());
    });
    dossierRoot.querySelectorAll('[data-dossier-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            dossierTab = btn.getAttribute('data-dossier-tab') || 'overview';
            renderDossier(node);
        });
    });
    dossierRoot.querySelector('[data-campaign-dossier-cta]')?.addEventListener('click', () => {
        startNodeAction(node);
    });
    mapFx.onDossierShow(dossierRoot);
}

function trailStateForEdge(fromNode, toNode) {
    const from = String(fromNode?.status || '');
    const to = String(toNode?.status || '');
    // Liberado / concluido: trilha viva. Bloqueado a frente: apagado (quase invisivel).
    if (from === 'cleared' && to === 'cleared') return 'cleared';
    if (from === 'cleared' && (to === 'available' || to === 'village')) return 'active';
    if (from === 'cleared' && (to === 'locked' || to === 'teaser')) return 'frontier';
    if (from === 'available' || to === 'available') return 'active';
    if (from === 'village' || to === 'village') {
        if (from === 'cleared' || to === 'cleared' || from === 'available' || to === 'available') return 'cleared';
    }
    return 'locked';
}

function quadPoint(t, x1, y1, cx, cy, x2, y2) {
    const u = 1 - t;
    return {
        x: u * u * x1 + 2 * u * t * cx + t * t * x2,
        y: u * u * y1 + 2 * u * t * cy + t * t * y2,
    };
}

function mapTrailsMarkup(worldData) {
    const path = Array.isArray(worldData?.path) ? worldData.path : [];
    const byCode = Object.fromEntries((worldData?.nodes || []).map((n) => [n.code, n]));
    const beds = [];
    const glows = [];
    const steps = [];
    for (let i = 0; i < path.length - 1; i += 1) {
        const a = byCode[path[i]];
        const b = byCode[path[i + 1]];
        if (!a || !b) continue;
        const x1 = Number(a.map_x);
        const y1 = Number(a.map_y);
        const x2 = Number(b.map_x);
        const y2 = Number(b.map_y);
        const mx = (x1 + x2) / 2;
        const my = (y1 + y2) / 2;
        const dx = x2 - x1;
        const dy = y2 - y1;
        const len = Math.max(0.001, Math.hypot(dx, dy));
        const bend = (i % 2 === 0 ? 1 : -1) * Math.min(8, len * 0.22);
        const cx = mx + (-dy / len) * bend;
        const cy = my + (dx / len) * bend;
        const state = trailStateForEdge(a, b);
        const d = `M ${x1.toFixed(2)} ${y1.toFixed(2)} Q ${cx.toFixed(2)} ${cy.toFixed(2)} ${x2.toFixed(2)} ${y2.toFixed(2)}`;

        beds.push(`<path class="is-bed is-${escapeHtml(state)}" d="${d}" />`);
        glows.push(`<path class="is-glow is-${escapeHtml(state)}" d="${d}" data-trail-state="${escapeHtml(state)}" />`);

        if (state === 'locked') continue;

        const stepCount = state === 'active' ? 7 : 5;
        for (let s = 1; s < stepCount; s += 1) {
            const t = s / stepCount;
            const p = quadPoint(t, x1, y1, cx, cy, x2, y2);
            const side = s % 2 === 0 ? 1 : -1;
            const ox = (-dy / len) * side * 0.55;
            const oy = (dx / len) * side * 0.55;
            steps.push(
                `<circle class="is-step is-${escapeHtml(state)}" cx="${(p.x + ox).toFixed(2)}" cy="${(p.y + oy).toFixed(2)}" r="${state === 'active' ? 0.55 : 0.42}" />`
            );
        }
    }
    if (!beds.length) return '';
    return `
        <svg class="campaign-map-trails" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <g class="campaign-trail-beds">${beds.join('')}</g>
            <g class="campaign-trail-glows">${glows.join('')}</g>
            <g class="campaign-trail-steps">${steps.join('')}</g>
        </svg>
    `;
}

function mapAtmosphereMarkup() {
    const leaves = Array.from({ length: 14 }, (_, i) => {
        const left = ((i * 17) % 97) + 1;
        const delay = ((i * 0.47) % 7).toFixed(2);
        const dur = (9 + (i % 6)).toFixed(1);
        const size = 4 + (i % 4);
        return `<span style="left:${left}%; animation-delay:-${delay}s; animation-duration:${dur}s; width:${size}px; height:${size + 3}px;"></span>`;
    }).join('');
    return `<div class="campaign-map-mist" aria-hidden="true"></div><div class="campaign-map-fx" aria-hidden="true">${leaves}</div>`;
}

function closeAlbum() {
    if (!albumRoot || albumRoot.hidden) return;
    const panel = albumRoot.querySelector('.campaign-album-panel');
    mapFx.popOut(panel || albumRoot).then(() => {
        albumRoot.hidden = true;
        albumRoot.innerHTML = '';
    });
}

function closeDossier() {
    if (!dossierRoot || dossierRoot.hidden) return;
    const panel = dossierRoot.querySelector('.campaign-dossier-panel');
    mapFx.popOut(panel || dossierRoot).then(() => {
        dossierRoot.hidden = true;
        dossierRoot.innerHTML = '';
    });
}

function renderAlbumChrome() {
    const album = world?.player?.album;
    if (!albumOpenBtn) return;
    if (!album || !Array.isArray(album.entries)) {
        albumOpenBtn.hidden = true;
        return;
    }
    albumOpenBtn.hidden = false;
    if (albumCountEl) {
        albumCountEl.textContent = `${Number(album.found || 0)}/${Number(album.total || 0)}`;
    }
}

function openAlbum() {
    if (!albumRoot || !world?.player?.album) return;
    const album = world.player.album;
    const entries = Array.isArray(album.entries) ? album.entries : [];
    albumRoot.hidden = false;
    albumRoot.innerHTML = `
        <div class="campaign-album-backdrop" data-campaign-album-close></div>
        <div class="campaign-album-panel" role="dialog" aria-modal="true" aria-label="Album de artefatos">
            <header>
                <div>
                    <strong>${escapeHtml(album.name || 'Album')}</strong>
                    <p>${Number(album.found || 0)}/${Number(album.total || 0)} encontrados · bestiary de almas vem depois</p>
                </div>
                <button type="button" class="campaign-lobby-close" data-campaign-album-close>Fechar</button>
            </header>
            <div class="campaign-album-grid">
                ${entries.map((entry) => {
                    const known = Boolean(entry.discovered);
                    const rarity = String(entry.rarity || 'rare');
                    return `
                        <article class="campaign-album-card${known ? '' : ' is-unknown'} rarity-${escapeHtml(rarity)}">
                            <strong>${escapeHtml(known ? (entry.name || entry.code) : '???')}</strong>
                            <span>${escapeHtml(rarity)}</span>
                            <em style="font-style:normal;font-size:0.72rem;color:#94a3b8">${known ? 'Coletado' : 'Ainda nao visto'}</em>
                        </article>`;
                }).join('') || '<p>Nenhum artefato cadastrado neste mundo.</p>'}
            </div>
        </div>
    `;
    albumRoot.querySelectorAll('[data-campaign-album-close]').forEach((el) => {
        el.addEventListener('click', () => closeAlbum());
    });
    mapFx.onAlbumOpen(albumRoot);
}

function renderMap() {
    if (!stage || !world) return;

    const pins = (world.nodes || []).map((node) => {
        const active = selectedNodeCode === node.code;
        return `
            <button type="button"
                class="campaign-pin is-${escapeHtml(node.status)}${active ? ' is-active' : ''} is-${escapeHtml(node.type)}"
                style="left:${Number(node.map_x)}%; top:${Number(node.map_y)}%;"
                data-campaign-node="${escapeHtml(node.code)}"
                aria-label="${escapeHtml(node.label)}"
                aria-pressed="${active ? 'true' : 'false'}">
                <img src="${escapeHtml(node.pin_url)}" alt="" aria-hidden="true">
                <span>${escapeHtml(node.label)}</span>
            </button>
        `;
    }).join('');

    stage.innerHTML = `
        <div class="campaign-map" style="background-image:url('${escapeHtml(world.background_url)}')">
            ${mapTrailsMarkup(world)}
            ${mapAtmosphereMarkup()}
            <div class="campaign-pins">${pins}</div>
        </div>
    `;

    const mapRoot = stage.querySelector('.campaign-map');
    mapFx.afterMapRender(mapRoot, world);
    map3d.afterMapRender(mapRoot, world);

    stage.querySelectorAll('[data-campaign-node]').forEach((button) => {
        const code = button.getAttribute('data-campaign-node');
        button.addEventListener('click', () => {
            if (battleRun || lootState || scoreboard) return;
            hidePinTooltip();
            mapFx.onPinSelect();
            if (code !== selectedNodeCode) {
                lobbyDetailsOpen = false;
                closeDossier();
            }
            selectedNodeCode = code;
            render();
        });
        button.addEventListener('mouseenter', () => {
            const node = (world?.nodes || []).find((n) => n.code === code);
            if (node) showPinTooltip(node, button);
        });
        button.addEventListener('mousemove', () => {
            if (!pinTooltip || pinTooltip.hidden) return;
            positionPinTooltip(button);
        });
        button.addEventListener('mouseleave', () => hidePinTooltip());
        button.addEventListener('focus', () => {
            const node = (world?.nodes || []).find((n) => n.code === code);
            if (node) showPinTooltip(node, button);
        });
        button.addEventListener('blur', () => hidePinTooltip());
    });
}

function render() {
    if (worldNameEl) {
        worldNameEl.textContent = world?.name || 'Mundo 1';
    }
    if (worldHintEl) {
        const weatherHint = mapFx.weather === 'rain'
            ? ' · chuva'
            : mapFx.weather === 'fog'
                ? ' · neblina'
                : mapFx.weather === 'storm'
                    ? ' · tempestade'
                    : mapFx.weather === 'night' || mapFx.weather === 'fireflies'
                        ? ' · noite'
                        : '';
        worldHintEl.textContent = scoreboard
            ? 'Placar da fase'
            : lootState
                ? 'Triagem de loot'
                : battleRun
                    ? 'Batalha em andamento'
                    : (selectedNodeCode ? `Detalhe do pin a direita${weatherHint}` : `Passe o mouse no pin · clique para detalhes${weatherHint}`);
    }
    renderMap();
    renderAlbumChrome();
    renderLobby(selectedNode());
    if (scoreboard) {
        renderScoreboard();
        return;
    }
    if (lootState) {
        if (lootRoot?.hidden || !lootRoot?.querySelector('.campaign-loot-panel')) {
            renderLootPanel();
        }
        return;
    }
    renderBattle();
}

async function loadWorld(options = {}) {
    if (!stage) return;
    const keepSelection = Boolean(options.keepSelection);
    if (!keepSelection) {
        stage.innerHTML = '<p class="campaign-loading">Carregando mapa...</p>';
    }
    try {
        const response = await apiFetch(`/api/campaign/worlds/${encodeURIComponent(WORLD_CODE)}`);
        world = response.data?.world || null;
        if (!world) {
            stage.innerHTML = '<p class="campaign-error">Campanha indisponivel. Rode migrate + seed 017.</p>';
            return;
        }
        render();
    } catch (error) {
        const message = errorMessage(error, 'Falha ao carregar a campanha.');
        stage.innerHTML = `<p class="campaign-error">${escapeHtml(message)}</p>`;
    }
}

function bindChrome() {
    albumOpenBtn?.addEventListener('click', () => openAlbum());

    document.addEventListener('keydown', (event) => {
        if (lootState && !scoreboard) {
            if (event.key === 'r' || event.key === 'R' || event.key === 'q' || event.key === 'Q' || event.key === 'w' || event.key === 'W') {
                event.preventDefault();
                event.stopPropagation();
                rotateSelectedLootItem();
                return;
            }
            if (event.key === 'Delete' || event.key === 'Backspace') {
                // Ignora durante drag e key-repeat (evita spam / efeitos estranhos).
                if (lootKit.getActiveDragEl() || event.repeat) return;
                event.preventDefault();
                deleteSelectedLootItem();
                return;
            }
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            if (albumRoot && !albumRoot.hidden) {
                closeAlbum();
                return;
            }
            if (page?.querySelector('[data-campaign-fx-lab]:not([hidden])')) {
                mapFx.closeFxLab();
                return;
            }
            if (dossierRoot && !dossierRoot.hidden) {
                closeDossier();
                return;
            }
            if (scoreboard) {
                scoreRoot?.querySelector('[data-campaign-score-continue]')?.click();
                return;
            }
            if (lootState) {
                commitLoot(collectTakenStagingIds(), collectAbandonedPublicIds(), collectTakenPlacements());
                return;
            }
            if (battleRun) {
                leaveBattle();
                return;
            }
            if (selectedNodeCode) {
                selectedNodeCode = null;
                lobbyDetailsOpen = false;
                render();
                return;
            }
            window.location.href = '/dashboard';
        }
    }, true);
}

if (page && stage) {
    mapFx.boot();
    bindChrome();
    // Atmosfera 3D ligada por padrao (mapa 2D continua por baixo).
    map3d.enable();
    loadWorld().then(() => resumeActiveBattle());
}
