let arenaDeps = null;
let arenaState = null;
let arenaLoading = false;
let arenaMoveInFlight = false;
let arenaFocusInFlight = false;
let arenaPotionInFlight = false;
let arenaPoiInFlight = false;
let arenaWanderTimer = null;
let arenaCombatTickTimer = null;
let arenaCombatTickInFlight = false;
let arenaCombatSyncPaused = false;
let arenaCombatFailStreak = 0;
let arenaWanderOffsets = new Map();
let arenaRecentEvents = [];
let arenaSelectedEncounterId = null;
let arenaCameraByBiome = new Map();
let arenaHoldInteraction = null;
let arenaPendingAttackIds = new Set();
let arenaPendingPickupIds = new Set();
let arenaRefreshTimer = 0;

const ARENA_COMBAT_TICK_MS = 450;
const ARENA_MAX_PICKUPS_IN_FLIGHT = 3;
const arenaToastCooldown = new Map();

function arenaToast(message, tone = 'info', cooldownMs = 2200) {
    const key = `${tone}:${String(message || '')}`;
    const now = Date.now();
    const last = arenaToastCooldown.get(key) || 0;
    if (now - last < cooldownMs) return;
    arenaToastCooldown.set(key, now);
    arenaDeps?.toast?.(message, tone);
}

function localizeArenaError(error, fallback = 'Algo deu errado na arena.') {
    const raw = String(error?.message || error?.payload?.message || fallback);
    const reasonCode = String(error?.payload?.errors?.reason_code || error?.reason_code || '');
    if (/out of pickup range|fora do alcance.*loot|loot fora/i.test(raw)) {
        return 'Loot fora do alcance. Aproxime o pontinho azul do item e clique.';
    }
    if (/not enough energy|energia insuficiente/i.test(raw)) {
        return 'Sem energia. Pare de lutar um pouco ou descanse depois da expedicao.';
    }
    if (/definition was not found|definicao nao encontrada/i.test(raw)) {
        return 'Esse drop esta com item invalido. Ignore e colete outro.';
    }
    if (/full|cheio|capacity|capacidade|carry/i.test(raw)) {
        return 'Carry cheio. Abra o Expedition Carry e liberte espaco.';
    }
    if (/ground loot not found|ja foi coletado/i.test(raw)) {
        return 'Esse loot ja foi coletado.';
    }
    if (/out of attack range|fora do alcance/i.test(raw)) {
        return 'Monstro fora do alcance. Clique no chao para se aproximar.';
    }
    if (/tool|ferramenta|missing_tool|TOOL/i.test(`${raw} ${reasonCode}`)) {
        return 'Ferramenta ausente ou inadequada para esta acao.';
    }
    if (/expired|expir|finished|claim/i.test(`${raw} ${reasonCode}`)) {
        return 'Expedicao encerrada. Reivindique a recompensa no painel.';
    }
    return raw || fallback;
}

const ARENA_MONSTER_ART = {
    treant: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__81_.PNG',
    brute: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__68_.PNG',
    crab: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__77_.PNG',
    lurker: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__83_.PNG',
    bat: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__72_.PNG',
    golem: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__75_.PNG',
    specter: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__79_.PNG',
    toad: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__70_.PNG',
    wisp: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__74_.PNG',
    mob: '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__67_.PNG',
};

const ARENA_MONSTER_FRAME = {
    common: '/assets/game/ui_icons/Frames/SquareBorder__9_.PNG',
    elite: '/assets/game/ui_icons/Frames/SquareBorder__10_.PNG',
    rare: '/assets/game/ui_icons/Frames/SquareBorder__11_.PNG',
};

const ARENA_CURSOR = {
    default: 'default',
    drag: 'grab',
    attack: 'crosshair',
    loot: 'pointer',
    poi: 'help',
};

const ARENA_POI_ART = {
    resource: '/assets/game/ui_icons/MapPins/MapPin__6_.PNG',
    cave: '/assets/game/buildings/Wonders/Pit__4_.PNG',
    cabin: '/assets/game/buildings/Buildings/Building__16_.PNG',
    shrine: '/assets/game/buildings/Church_Temples/PrayerSite__3_.PNG',
    chest: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG',
    ruin: '/assets/game/buildings/Wonders/Wonder__7_.PNG',
    unknown: '/assets/game/ui_icons/MapPins/MapPin__29_.PNG',
};

const ARENA_ICON_KEY_ART = {
    flora_patch: '/assets/game/ui_icons/MapPins/MapPin__4_.PNG',
    wood_stump: '/assets/game/ui_icons/MapPins/MapPin__6_.PNG',
    stone_rock: '/assets/game/ui_icons/MapPins/MapPin__14_.PNG',
    briar_wall: '/assets/game/ui_icons/MapPins/MapPin__11_.PNG',
    wood_crate: '/assets/game/ui_icons/MapPins/MapPin__18_.PNG',
};

const ARENA_LOOT_ART = {
    wood: '/assets/game/items/wood.png',
    stone: '/assets/game/items/stone.png',
    herb: '/assets/game/items/showcase_health_potion.png',
    gold_coin: '/assets/game/items/showcase_gold_pouch.png',
};

const ARENA_HOLD_FRAME = '/assets/game/ui_icons/Frames/CircleBorder__11_.PNG';

function cameraStateForBiome(biomeCode) {
    const key = String(biomeCode || 'default');
    if (!arenaCameraByBiome.has(key)) {
        arenaCameraByBiome.set(key, { x: 0, y: 0 });
    }
    return arenaCameraByBiome.get(key);
}

export function configureExplorationArena(deps) {
    arenaDeps = deps;
}

export function getExplorationArenaState() {
    return arenaState;
}

export function applyExplorationArenaPayload(payload, options = {}) {
    if (!payload || typeof payload !== 'object') return;
    const merge = options.merge !== false && arenaState && payload.mode === 'lite';
    if (merge) {
        arenaState = {
            ...arenaState,
            ...payload,
            points_of_interest: payload.points_of_interest ?? arenaState.points_of_interest,
            hidden_points: payload.hidden_points ?? arenaState.hidden_points,
            exploration: payload.exploration ?? arenaState.exploration,
            modifiers: payload.modifiers ?? arenaState.modifiers,
            wallets: payload.wallets ?? arenaState.wallets,
            expedition_carry: payload.expedition_carry
                ? {
                    ...(arenaState.expedition_carry || {}),
                    ...payload.expedition_carry,
                    items: Array.isArray(payload.expedition_carry.items)
                        ? payload.expedition_carry.items
                        : (arenaState.expedition_carry?.items ?? []),
                }
                : arenaState.expedition_carry,
            player_hud: payload.player_hud
                ? {
                    ...(arenaState.player_hud || {}),
                    ...payload.player_hud,
                    vitals: {
                        ...((arenaState.player_hud || {}).vitals || {}),
                        ...((payload.player_hud || {}).vitals || {}),
                    },
                }
                : arenaState.player_hud,
            potion_belt: payload.potion_belt ?? arenaState.potion_belt,
            active_buffs: payload.active_buffs ?? arenaState.active_buffs,
        };
    } else {
        arenaState = payload;
    }
    if (payload.expedition) {
        arenaDeps?.setExplorationExpedition?.(payload.expedition);
    }
    if (payload.position) {
        arenaDeps?.setExplorationPosition?.(payload.position);
    }
    if (payload.exploration?.objects) {
        arenaDeps?.setExplorationObjects?.(payload.exploration.objects);
    }
    if (payload.exploration?.map) {
        arenaDeps?.setExplorationMap?.(payload.exploration.map);
    }
    if (payload.modifiers) {
        arenaDeps?.setExplorationModifiers?.(payload.modifiers);
    }
    const focusId = payload.combat_state?.focus_encounter_public_id || null;
    if (focusId) {
        arenaSelectedEncounterId = focusId;
    }
}

function applyOptimisticPlayerMove(root, coords) {
    if (!coords) return;
    const mapX = Number(coords.map_x ?? 0);
    const mapY = Number(coords.map_y ?? 0);
    if (arenaState) {
        arenaState = {
            ...arenaState,
            position: {
                ...(arenaState.position || {}),
                map_x: mapX,
                map_y: mapY,
            },
        };
    }
    arenaDeps?.setExplorationPosition?.({ map_x: mapX, map_y: mapY });
    const anchor = root?.querySelector?.('[data-arena-player-anchor]');
    if (!anchor) return;
    const mapWidth = Number(arenaState?.arena?.map_width || 6);
    const mapHeight = Number(arenaState?.arena?.map_height || 4);
    const combatState = arenaState?.combat_state || {};
    const engage = Math.max(0.4, Number(combatState.engage_radius || 2));
    const loot = Math.max(1, Number(combatState.loot_pickup_radius || 1));
    const engagePct = Math.max(8, Math.min(70, (engage / Math.max(1, mapWidth)) * 100));
    const lootPct = Math.max(8, Math.min(60, (loot / Math.max(1, mapWidth)) * 100));
    anchor.setAttribute('style', entityPositionStyle(mapX, mapY, mapWidth, mapHeight));
    const engageRing = anchor.querySelector('.inventory-expedition-arena-radius.is-engage');
    if (engageRing) engageRing.style.setProperty('--radius-pct', `${engagePct}%`);
    const lootRing = anchor.querySelector('.inventory-expedition-arena-radius.is-loot');
    if (lootRing) lootRing.style.setProperty('--radius-pct', `${lootPct}%`);
}

function scheduleArenaRefresh(delay = 180) {
    if (arenaRefreshTimer) {
        window.clearTimeout(arenaRefreshTimer);
    }
    arenaRefreshTimer = window.setTimeout(() => {
        arenaRefreshTimer = 0;
        if (arenaDeps?.softRefreshArenaStage) {
            arenaDeps.softRefreshArenaStage();
            return;
        }
        arenaDeps?.renderExplorationPanel?.();
    }, delay);
}

export async function loadExplorationArena(biomeCode) {
    if (!arenaDeps?.apiFetch || arenaLoading) return arenaState;
    arenaLoading = true;

    try {
        const response = await arenaDeps.apiFetch(`/api/expeditions/arena?biome_code=${encodeURIComponent(biomeCode)}`);
        applyExplorationArenaPayload(response.data || null);
        return arenaState;
    } catch (error) {
        arenaDeps?.handleError?.(error, 'Nao foi possivel carregar a arena.');
        return arenaState;
    } finally {
        arenaLoading = false;
    }
}

function pct(value, max) {
    const safeMax = Math.max(0.0001, Number(max || 1));
    return Math.max(0, Math.min(100, (Number(value || 0) / safeMax) * 100));
}

function hpBar(current, max) {
    const width = pct(current, max);
    return `<div class="inventory-expedition-arena-hpbar" aria-hidden="true"><span style="width:${width}%"></span></div>`;
}

function shortHp(current, max) {
    return `${Number(current || 0)}/${Number(max || 0)}`;
}

function formatTimeRemaining(expedition) {
    if (!expedition?.active || !expedition?.ends_at) return 'Expedicao inativa';

    const endsAt = new Date(expedition.ends_at);
    const diffMs = endsAt.getTime() - Date.now();
    if (!Number.isFinite(diffMs) || diffMs <= 0) return 'Encerrando';

    const totalSeconds = Math.max(0, Math.floor(diffMs / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

function renderArenaPotionButtons(escapeHtml) {
    const belt = Array.isArray(arenaState?.potion_belt) ? arenaState.potion_belt : [];
    const activeBuffs = Array.isArray(arenaState?.active_buffs) ? arenaState.active_buffs : [];
    const buffHint = activeBuffs.length
        ? activeBuffs.map((buff) => buff.label || buff.code).filter(Boolean).join(', ')
        : '';

    if (!belt.length) {
        return `
            <button type="button" class="inventory-expedition-arena-tool is-missing" data-arena-open-equipment title="Equipe um tonico no cinto">
                <span>P</span>
                <strong>Pocao</strong>
            </button>
            ${buffHint ? `<small class="inventory-expedition-arena-buff-hint" title="${escapeHtml(buffHint)}">Buff ativo</small>` : ''}
        `;
    }

    return belt.slice(0, 2).map((potion, index) => `
        <button type="button"
            class="inventory-expedition-arena-tool is-potion"
            data-arena-use-potion="${escapeHtml(potion.slot_code || '')}"
            title="${escapeHtml(potion.name || 'Pocao')}${buffHint ? ` · Buff: ${buffHint}` : ''}"
            style="--tool-accent:#c084fc">
            <span>P${index + 1}</span>
            <strong>${escapeHtml((potion.name || 'Pocao').slice(0, 8))}</strong>
        </button>
    `).join('');
}

function biomeTitle(biomeCode) {
    const map = {
        bosque_inicial: 'Bosque Inicial',
        costa_salobra: 'Costa Salobra',
        gruta_ecoante: 'Gruta Ecoante',
        ruinas_afundadas: 'Ruinas Afundadas',
        pantano_venenoso: 'Pantano Venenoso',
        vale_dos_reis: 'Vale dos Reis',
    };

    return map[String(biomeCode || '')] || arenaState?.biome?.name || 'Expedicao';
}

function entityPositionStyle(x, y, mapWidth, mapHeight) {
    return `--entity-x:${Number(x || 0)}; --entity-y:${Number(y || 0)}; --map-width:${mapWidth}; --map-height:${mapHeight};`;
}

function renderArenaRadiusRings(mapWidth, mapHeight, escapeHtml) {
    const position = arenaState?.position || {};
    const combatState = arenaState?.combat_state || {};
    const x = Number(position.map_x ?? 0);
    const y = Number(position.map_y ?? 0);
    const engage = Math.max(0.4, Number(combatState.engage_radius || 2));
    const loot = Math.max(1, Number(combatState.loot_pickup_radius || 1));
    const engagePct = Math.max(8, Math.min(70, (engage / Math.max(1, Number(mapWidth) || 6)) * 100));
    const lootPct = Math.max(8, Math.min(60, (loot / Math.max(1, Number(mapWidth) || 6)) * 100));

    return `
        <div class="inventory-expedition-arena-player-anchor"
            style="${entityPositionStyle(x, y, mapWidth, mapHeight)}"
            data-arena-player-anchor
            aria-hidden="true">
            <span class="inventory-expedition-arena-radius is-engage" style="--radius-pct:${engagePct}%" title="Alcance de ataque"></span>
            <span class="inventory-expedition-arena-radius is-loot" style="--radius-pct:${lootPct}%" title="Alcance de coleta"></span>
            <span class="inventory-expedition-arena-player-dot" title="${escapeHtml('Voce')}"></span>
        </div>
    `;
}

function spriteClass(spriteKey, tier) {
    const tierNumber = Number(tier || 1);
    const tierClass = tierNumber >= 3 ? ' is-rare' : (tierNumber > 1 ? ' is-elite' : '');
    return `inventory-expedition-arena-sprite is-${spriteKey || 'mob'}${tierClass}`;
}

function monsterArt(spriteKey) {
    return ARENA_MONSTER_ART[String(spriteKey || 'mob')] || ARENA_MONSTER_ART.mob;
}

function monsterFrame(tier) {
    const tierNumber = Number(tier || 1);
    if (tierNumber >= 3) return ARENA_MONSTER_FRAME.rare;
    if (tierNumber > 1) return ARENA_MONSTER_FRAME.elite;
    return ARENA_MONSTER_FRAME.common;
}

function monsterInitials(name) {
    const words = String(name || 'Mob').trim().split(/\s+/).filter(Boolean);
    return (words[0]?.[0] || 'M') + (words[1]?.[0] || '');
}

function poiArt(point) {
    const iconKey = String(point?.icon_key || '').toLowerCase();
    if (iconKey && ARENA_ICON_KEY_ART[iconKey]) return ARENA_ICON_KEY_ART[iconKey];
    const visualType = String(point?.visual_type || '').toLowerCase();
    if (visualType && ARENA_POI_ART[visualType]) return ARENA_POI_ART[visualType];
    const code = String(point?.definition_code || '').toLowerCase();
    const kind = String(point?.kind || '').toLowerCase();
    const blob = `${kind} ${code}`;
    if (blob.includes('cave') || blob.includes('pit') || blob.includes('mine')) return ARENA_POI_ART.cave;
    if (blob.includes('cabin') || blob.includes('hut') || blob.includes('house')) return ARENA_POI_ART.cabin;
    if (blob.includes('altar') || blob.includes('shrine') || blob.includes('temple')) return ARENA_POI_ART.shrine;
    if (blob.includes('chest') || blob.includes('cache')) return ARENA_POI_ART.chest;
    if (blob.includes('ruin') || blob.includes('tower') || blob.includes('portal')) return ARENA_POI_ART.ruin;
    if (blob.includes('resource') || blob.includes('tree') || blob.includes('stone') || blob.includes('ore')) return ARENA_POI_ART.resource;
    return ARENA_POI_ART.resource;
}

function lootArt(itemCode) {
    const code = String(itemCode || '').toLowerCase();
    return ARENA_LOOT_ART[code] || '/assets/game/items/stone.png';
}

function hiddenPointArt(point) {
    if (String(point?.visual_type || '').toLowerCase() === 'chest') {
        return ARENA_POI_ART.chest;
    }
    return ARENA_POI_ART.unknown;
}

function poiCategoryLabel(point) {
    const visualType = String(point?.visual_type || '').toLowerCase();
    if (visualType === 'cave') return 'Entrada de caverna';
    if (visualType === 'cabin') return 'Estrutura abandonada';
    if (visualType === 'shrine') return 'Local sagrado';
    if (visualType === 'chest') return 'Cache ou bau';
    if (visualType === 'ruin') return 'Ruina ou ponto especial';
    return point?.is_structure ? 'Estrutura exploravel' : 'Recurso do mapa';
}

function poiDiscoveryHint(point) {
    if (point?.is_structure) {
        return point?.position_label
            ? `Local: ${point.position_label}`
            : 'Estrutura marcada no terreno';
    }
    return point?.position_label
        ? `Area: ${point.position_label}`
        : 'Ponto detectado no mapa';
}

function actionDisplayLabel(action) {
    if (action && typeof action === 'object' && typeof action.action_label === 'string' && action.action_label.trim() !== '') {
        return action.action_label;
    }
    const code = typeof action === 'object' ? action.action_code : action;
    return arenaDeps?.explorationActionLabel?.(code) || String(code || 'Acao');
}

function bestPoiAction(point) {
    const actions = Array.isArray(point?.available_actions) ? point.available_actions.filter((entry) => entry?.available) : [];
    if (!actions.length) return null;

    const score = (action) => {
        const code = String(action?.action_code || '');
        if (code === 'analyze_magnifier') return 100;
        if (code === 'pick_lock') return 80;
        if (code === 'clear_shears') return 70;
        if (code === 'dig_shovel') return 65;
        if (code === 'mine_pickaxe') return 60;
        if (code === 'chop_hatchet') return 55;
        if (code === 'harvest_shears') return 50;
        if (code === 'force_open') return 10;
        return 40;
    };

    return [...actions].sort((left, right) => score(right) - score(left))[0] || null;
}

function canUsePoiAction(action) {
    if (!action) return { ok: false, message: 'Nenhuma acao disponivel.' };
    const code = String(action.action_code || '');
    if (code === 'analyze_magnifier') {
        const tool = arenaDeps?.listOwnedToolsByType?.('magnifier')?.[0] || null;
        return tool ? { ok: true, tool } : { ok: false, message: 'Voce precisa de uma lupa para investigar.' };
    }

    const toolType = String(action.required_tool_type || '');
    const tool = arenaDeps?.listOwnedToolsByType?.(toolType)?.[0] || null;
    return tool ? { ok: true, tool } : { ok: false, message: `Ferramenta necessaria: ${toolType || code}.` };
}

function tierBadge(tier, tierLabel) {
    const tierNumber = Number(tier || 1);
    if (tierNumber <= 1) return '';
    const label = tierLabel || (tierNumber >= 3 ? 'Raro' : 'Elite');
    const modifier = tierNumber >= 3 ? 'is-rare' : 'is-elite';
    return `<span class="inventory-expedition-arena-tier ${modifier}">${label}</span>`;
}

function eventTone(entry) {
    const type = String(entry?.type || '');
    if (type.startsWith('reward_')) return 'success';
    if (type.includes('crit')) return 'crit';
    if (type.includes('kill')) return 'success';
    if (type.includes('dodge') || type.includes('reflect')) return 'info';
    if (entry?.target === 'player') return 'danger';
    if (entry?.target === 'monster') return 'attack';
    return 'neutral';
}

function eventLabel(entry) {
    const damage = Number(entry?.damage || 0);
    const type = String(entry?.type || '');
    if (type === 'player_dodge') return 'Esquivou';
    if (type === 'monster_dodge') return 'Escapou';
    if (type === 'player_reflect') return `Refletiu ${damage}`;
    if (type === 'monster_kill') return 'Abate!';
    if (type === 'player_defeat') return 'Derrota';
    if (type === 'reward_gold') return `+${damage}G`;
    if (type === 'reward_xp') return `+${damage} XP`;
    if (damage > 0 && type.includes('crit')) return `${damage} Critico`;
    if (damage > 0) return String(damage);
    return String(entry?.message || 'Acao');
}

function walletBalance(wallets, code) {
    return Number((wallets || []).find((entry) => String(entry?.currency_code || entry?.code || '') === code)?.balance || 0);
}

function quickTool(type, label, accent) {
    const tool = arenaDeps?.listOwnedToolsByType?.(type)?.[0] || null;
    return {
        type,
        label,
        accent,
        equipped: Boolean(tool?.public_id),
        quantity: Number(tool?.quantity || 0),
    };
}

function updateCombatFeed(events) {
    if (!Array.isArray(events) || !events.length) return;
    const mapped = events.map((entry) => ({
        kind: eventTone(entry),
        label: entry.message || eventLabel(entry),
    }));
    arenaRecentEvents = [...mapped.reverse(), ...arenaRecentEvents].slice(0, 6);
}

function cloneArenaStateSnapshot() {
    return {
        arena: arenaState?.arena ? { ...arenaState.arena } : null,
        vitals: arenaState?.vitals ? { ...arenaState.vitals } : null,
        encounters: Array.isArray(arenaState?.encounters) ? arenaState.encounters.map((entry) => ({ ...entry })) : [],
        ground_loot: Array.isArray(arenaState?.ground_loot) ? arenaState.ground_loot.map((entry) => ({ ...entry })) : [],
        points_of_interest: Array.isArray(arenaState?.points_of_interest) ? arenaState.points_of_interest.map((entry) => ({ ...entry })) : [],
        hidden_points: Array.isArray(arenaState?.hidden_points) ? arenaState.hidden_points.map((entry) => ({ ...entry })) : [],
        modifiers: arenaState?.modifiers ? { ...arenaState.modifiers } : null,
        player_hud: arenaState?.player_hud ? { ...arenaState.player_hud } : null,
        wallets: Array.isArray(arenaState?.wallets) ? arenaState.wallets.map((entry) => ({ ...entry })) : [],
        expedition_carry: arenaState?.expedition_carry ? {
            ...arenaState.expedition_carry,
            items: Array.isArray(arenaState?.expedition_carry?.items) ? arenaState.expedition_carry.items.map((entry) => ({ ...entry })) : [],
        } : null,
    };
}

function restoreArenaStateSnapshot(snapshot) {
    if (!snapshot) return;
    arenaState = {
        ...arenaState,
        ...snapshot,
    };
}

function optimisticDamageForEncounter(encounter) {
    const currentHp = Number(encounter?.current_hp || 0);
    const maxHp = Math.max(1, Number(encounter?.max_hp || 1));
    return Math.max(6, Math.min(currentHp, Math.round(maxHp * 0.14)));
}

function applyOptimisticAttack(root, encounterPublicId) {
    const encounter = Array.isArray(arenaState?.encounters)
        ? arenaState.encounters.find((entry) => entry.public_id === encounterPublicId)
        : null;
    if (!encounter) return null;

    const optimisticDamage = optimisticDamageForEncounter(encounter);
    encounter.current_hp = Math.max(0, Number(encounter.current_hp || 0) - optimisticDamage);

    const optimisticEvents = [{
        type: 'player_hit',
        message: 'Voce atacou o monstro.',
        damage: optimisticDamage,
        target: 'monster',
    }];

    updateCombatFeed(optimisticEvents);
    syncArenaFeed(root);
    showCombatFloaters(root, optimisticEvents, { encounterId: encounterPublicId });
    applyCombatStateToDom(root, {
        combat: {
            encounter,
            vitals: arenaState?.vitals || null,
            killed: Number(encounter.current_hp || 0) <= 0,
        },
    }, encounterPublicId);

    return optimisticDamage;
}

function applyOptimisticPickup(root, lootPublicId) {
    const lootList = Array.isArray(arenaState?.ground_loot) ? arenaState.ground_loot : [];
    const index = lootList.findIndex((entry) => entry.public_id === lootPublicId);
    if (index < 0) return null;

    const [loot] = lootList.splice(index, 1);
    const node = root.querySelector(`[data-arena-pickup="${lootPublicId}"]`);
    if (node) {
        node.style.opacity = '0';
        window.setTimeout(() => node.remove(), 120);
    }
    return loot || null;
}

function createArenaNode(markup) {
    const template = document.createElement('template');
    template.innerHTML = String(markup || '').trim();
    return template.content.firstElementChild;
}

function lootNodeMarkup(loot) {
    const mapWidth = Math.max(1, Number(arenaState?.arena?.map_width || 6));
    const mapHeight = Math.max(1, Number(arenaState?.arena?.map_height || 4));
    const escapeHtml = arenaDeps?.escapeHtml || ((value) => String(value ?? ''));

    return `
        <button type="button"
            class="inventory-expedition-arena-entity is-loot"
            style="${entityPositionStyle(loot.map_x, loot.map_y, mapWidth, mapHeight)}"
            data-arena-pickup="${escapeHtml(loot.public_id)}"
            data-arena-entity-id="${escapeHtml(loot.public_id)}"
            data-arena-entity-type="loot"
            aria-label="Coletar ${escapeHtml(loot.item_definition_code)}">
            <span class="inventory-expedition-arena-loot-chip">
                <img src="${escapeHtml(lootArt(loot.item_definition_code))}" alt="" aria-hidden="true">
            </span>
            <span class="inventory-expedition-arena-entity-label">${escapeHtml(loot.quantity)}x ${escapeHtml(loot.item_definition_code)}</span>
        </button>
    `;
}

function syncArenaWalletBalance(walletCode, balance) {
    if (!Array.isArray(arenaState?.wallets)) {
        arenaState.wallets = [];
    }
    const code = String(walletCode || '').toLowerCase();
    const nextBalance = Number(balance || 0);
    const wallet = arenaState.wallets.find((entry) => String(entry?.code || '').toLowerCase() === code);
    if (wallet) {
        wallet.balance = nextBalance;
        return;
    }
    arenaState.wallets.push({ code, balance: nextBalance });
}

function syncArenaCarryFromPickup(pickup) {
    if (!pickup?.placed_in_expedition_carry) return;
    if (!arenaState?.expedition_carry) {
        arenaState.expedition_carry = { capacity_cells: 24, occupied_cells: 0, items: [] };
    }
    if (!Array.isArray(arenaState.expedition_carry.items)) {
        arenaState.expedition_carry.items = [];
    }

    const definitionCode = String(pickup.item_definition_code || 'item');
    const quantity = Math.max(1, Number(pickup.quantity || 1));
    const existing = arenaState.expedition_carry.items.find((entry) => String(entry?.definition_code || '') === definitionCode);
    if (existing) {
        existing.quantity = Math.max(1, Number(existing.quantity || 0) + quantity);
    } else {
        arenaState.expedition_carry.items.unshift({
            definition_code: definitionCode,
            quantity,
            name: definitionCode,
        });
        arenaState.expedition_carry.occupied_cells = Math.max(0, Number(arenaState.expedition_carry.occupied_cells || 0) + 1);
    }
}

function syncArenaHudFromState(root) {
    if (!root) return;

    const vitals = arenaState?.vitals || null;
    if (vitals) {
        const hudBar = root.querySelector('.inventory-expedition-arena-hud-vitals .inventory-expedition-arena-hpbar span');
        const label = root.querySelector('.inventory-expedition-arena-hud-vitals strong');
        const vitalPercent = Math.max(0, Math.min(100, (Number(vitals.current_hp || 0) / Math.max(1, Number(vitals.max_hp || 1))) * 100));
        if (hudBar) hudBar.style.width = `${vitalPercent}%`;
        if (label) label.textContent = `${Number(vitals.current_hp || 0)}/${Number(vitals.max_hp || 0)} HP`;
    }

    const expeditionCarry = arenaState?.expedition_carry || null;
    if (expeditionCarry) {
        const capacity = Math.max(1, Number(expeditionCarry.capacity_cells || 1));
        const occupied = Math.max(0, Number(expeditionCarry.occupied_cells || 0));
        const carryBar = root.querySelector('.inventory-expedition-arena-hud-vitals.is-carry .inventory-expedition-arena-hpbar span');
        const carryLabel = root.querySelector('.inventory-expedition-arena-hud-vitals.is-carry strong');
        const carryPreview = root.querySelector('.inventory-expedition-arena-carry-items');
        if (carryBar) {
            carryBar.style.width = `${Math.round((occupied / capacity) * 100)}%`;
        }
        if (carryLabel) {
            const stacks = Array.isArray(expeditionCarry.items) ? expeditionCarry.items.length : occupied;
            carryLabel.textContent = `${occupied}/${capacity} espacos · ${stacks} pilha(s)`;
        }
        if (carryPreview && Array.isArray(expeditionCarry.items)) {
            carryPreview.innerHTML = expeditionCarry.items.length
                ? expeditionCarry.items.slice(0, 6).map((item) => `
                    <span class="inventory-expedition-arena-carry-chip" title="${String(item.name || item.definition_code || 'Item')}">
                        ${Number(item.quantity || 1)}x ${String(item.definition_code || 'item')}
                    </span>
                `).join('')
                : '<span class="inventory-expedition-arena-carry-chip is-empty">Carry vazio</span>';
        }
    }

    const wallets = Array.isArray(arenaState?.wallets) ? arenaState.wallets : [];
    const goldBalance = walletBalance(wallets, 'gold');
    const goldLabel = root.querySelector('.inventory-expedition-arena-feed strong');
    if (goldLabel) {
        goldLabel.textContent = `${goldBalance.toLocaleString('pt-BR')} G`;
    }

    const combatState = arenaState?.combat_state || null;
    const waveNode = root.querySelector('[data-arena-wave-progress]');
    if (waveNode && combatState) {
        const kills = Number(combatState.kills_toward_boss || 0);
        const need = Math.max(1, Number(combatState.kills_to_boss || 10));
        const bossActive = Boolean(combatState.boss_active);
        const focusId = combatState.focus_encounter_public_id || arenaSelectedEncounterId;
        const focusEncounter = (arenaState?.encounters || []).find((entry) => entry.public_id === focusId);
        const focusLabel = focusEncounter
            ? `${focusEncounter.is_boss ? 'Chefe' : 'Alvo'}: ${focusEncounter.name}`
            : 'Auto-ataque no alcance';
        const title = waveNode.querySelector('strong');
        const note = waveNode.querySelector('span');
        if (title) title.textContent = bossActive ? 'Chefe ativo' : `Rumo ao chefe ${kills}/${need}`;
        if (note) note.textContent = focusLabel;
        waveNode.classList.toggle('is-boss', bossActive);
        const bar = root.querySelector('[data-arena-wave-bar] span');
        if (bar) bar.style.width = `${Math.max(0, Math.min(100, Math.round((kills / need) * 100)))}%`;
    }

    root.querySelectorAll('[data-arena-attack]').forEach((node) => {
        const id = node.getAttribute('data-arena-attack');
        const selected = Boolean(id && id === arenaSelectedEncounterId);
        node.classList.toggle('is-selected', selected);
        node.classList.toggle('is-engaging', selected);
    });
}

function syncArenaFeed(root) {
    const list = root?.querySelector('.inventory-expedition-arena-feed ul');
    if (!list) return;

    list.innerHTML = arenaRecentEvents.length
        ? arenaRecentEvents.slice(0, 5).map((entry) => `
            <li class="inventory-expedition-arena-feed-item is-${String(entry.kind || 'neutral')}">
                <span>${String(entry.label || '')}</span>
            </li>
        `).join('')
        : '<li class="inventory-expedition-arena-feed-item is-neutral"><span>Clique em um monstro para focar. O combate e automatico no alcance.</span></li>';
}

function updateArenaEntityPosition(node, x, y) {
    if (!node) return;
    const mapWidth = Math.max(1, Number(arenaState?.arena?.map_width || 6));
    const mapHeight = Math.max(1, Number(arenaState?.arena?.map_height || 4));
    node.setAttribute('style', entityPositionStyle(x, y, mapWidth, mapHeight));
}

function applyServerEncounterState(root, encounter) {
    if (!root || !encounter?.public_id) return;
    if (!Array.isArray(arenaState?.encounters)) {
        arenaState.encounters = [];
    }

    const index = arenaState.encounters.findIndex((entry) => entry.public_id === encounter.public_id);
    if (index >= 0) {
        arenaState.encounters[index] = { ...arenaState.encounters[index], ...encounter };
    } else {
        arenaState.encounters.push({ ...encounter });
    }

    const entity = root.querySelector(`[data-arena-attack="${encounter.public_id}"]`);
    if (entity) {
        updateArenaEntityPosition(entity, Number(encounter.map_x || 0), Number(encounter.map_y || 0));
    }
}

function removeEncounterFromArenaState(encounterPublicId) {
    if (!Array.isArray(arenaState?.encounters)) return;
    arenaState.encounters = arenaState.encounters.filter((entry) => entry.public_id !== encounterPublicId);
}

function appendGroundLootToArena(root, lootPayload) {
    const host = root?.querySelector('[data-arena-entities]');
    if (!host) return;

    const lootItems = Array.isArray(lootPayload)
        ? lootPayload.filter(Boolean)
        : (lootPayload ? [lootPayload] : []);

    if (!Array.isArray(arenaState?.ground_loot)) {
        arenaState.ground_loot = [];
    }

    lootItems.forEach((loot) => {
        if (!loot?.public_id) return;
        const exists = arenaState.ground_loot.some((entry) => entry.public_id === loot.public_id);
        if (!exists) {
            arenaState.ground_loot.push({ ...loot });
        }
        if (!root.querySelector(`[data-arena-pickup="${loot.public_id}"]`)) {
            const node = createArenaNode(lootNodeMarkup(loot));
            if (node) {
                node.classList.add('is-pending');
                host.appendChild(node);
                window.setTimeout(() => node.classList.remove('is-pending'), 260);
            }
        }
    });
}

function queueArenaBackgroundSync(options = {}) {
    const biomeCode = arenaDeps?.getExplorationBiomeCode?.() || '';
    const skipInventory = options.skipInventory === true;
    window.setTimeout(() => {
        const tasks = [];
        tasks.push(
            loadExplorationArena(biomeCode).then(() => {
                arenaDeps?.softRefreshArenaStage?.();
            })
        );
        if (!skipInventory) {
            tasks.push(arenaDeps?.loadInventory?.({ skipExplorationRender: true }));
        }
        Promise.allSettled(tasks).catch(() => {});
    }, Number(options.delay || 0));
}

function queueArenaPoiSync() {
    const biomeCode = arenaDeps?.getExplorationBiomeCode?.() || '';
    window.setTimeout(() => {
        Promise.allSettled([
            arenaDeps?.loadExplorationObjects?.({ silent: true }),
            loadExplorationArena(biomeCode),
            arenaDeps?.loadInventory?.({ skipExplorationRender: true }),
        ]).then(() => {
            arenaDeps?.softRefreshArenaStage?.();
        }).catch(() => {});
    }, 0);
}

function queueArenaMoveSync(delay = 120) {
    const biomeCode = arenaDeps?.getExplorationBiomeCode?.() || '';
    window.setTimeout(() => {
        Promise.allSettled([
            loadExplorationArena(biomeCode),
            arenaDeps?.loadExplorationObjects?.({ silent: true }),
        ]).then(() => {
            arenaDeps?.softRefreshArenaStage?.();
        }).catch(() => {});
    }, Number(delay || 0));
}

function entityAnchor(root, target, encounterId) {
    if (target === 'player') {
        return root.querySelector('.inventory-expedition-arena-hud-vitals')
            || root.querySelector('.inventory-expedition-arena-hud-main')
            || root.querySelector('[data-arena-viewport]');
    }

    if (encounterId) {
        return root.querySelector(`[data-arena-entity-id="${encounterId}"]`);
    }

    return root.querySelector('[data-arena-attack]');
}

export function renderExplorationArenaSection({
    biomeCode,
    expedition,
    fallbackMap,
    fallbackPosition,
}) {
    const arena = arenaState?.arena || {};
    const mapWidth = Math.max(1, Number(arena.map_width || fallbackMap?.width || 6));
    const mapHeight = Math.max(1, Number(arena.map_height || fallbackMap?.height || 4));
    const vitals = arenaState?.vitals;
    const encounters = Array.isArray(arenaState?.encounters) ? arenaState.encounters : [];
    const groundLoot = Array.isArray(arenaState?.ground_loot) ? arenaState.ground_loot : [];
    const points = Array.isArray(arenaState?.points_of_interest) ? arenaState.points_of_interest : [];
    const hiddenPoints = Array.isArray(arenaState?.hidden_points) ? arenaState.hidden_points : [];
    const playerHud = arenaState?.player_hud || arenaDeps?.getPlayerHud?.() || null;
    const wallets = Array.isArray(arenaState?.wallets) ? arenaState.wallets : (arenaDeps?.getWallets?.() || []);
    const expeditionCarry = arenaState?.expedition_carry || null;
    const expeditionReady = Boolean(expedition?.active);
    const backgroundUrl = arena.background_url || '/assets/expedition/bosque/background.svg';
    const escapeHtml = arenaDeps?.escapeHtml || ((value) => String(value ?? ''));
    const timerLabel = formatTimeRemaining(expedition);
    const hpPercent = vitals ? pct(vitals.current_hp, vitals.max_hp) : 0;
    const lowHealth = hpPercent > 0 && hpPercent <= 30;
    const camera = cameraStateForBiome(biomeCode);

    const encounterMarkup = encounters.map((encounter) => {
        const offset = arenaWanderOffsets.get(encounter.public_id) || { x: 0, y: 0 };
        const selectedClass = arenaSelectedEncounterId === encounter.public_id ? ' is-selected is-engaging' : '';
        const bossClass = encounter.is_boss ? ' is-boss' : '';
        return `
            <button type="button"
                class="inventory-expedition-arena-entity is-monster ${spriteClass(encounter.sprite_key, encounter.tier)}${selectedClass}${bossClass}"
                style="${entityPositionStyle(Number(encounter.map_x || 0) + offset.x, Number(encounter.map_y || 0) + offset.y, mapWidth, mapHeight)}"
                data-arena-attack="${escapeHtml(encounter.public_id)}"
                data-arena-entity-id="${escapeHtml(encounter.public_id)}"
                data-arena-entity-type="monster"
                ${expeditionReady ? '' : 'disabled'}
                aria-label="Focar ${escapeHtml(encounter.name || 'monstro')}">
                <span class="inventory-expedition-arena-monster-card">
                    <span class="inventory-expedition-arena-monster-frame-wrap">
                        <span class="inventory-expedition-arena-monster-frame-art" style="background-image:url('${escapeHtml(monsterFrame(encounter.tier))}')"></span>
                        <span class="inventory-expedition-arena-monster-frame" style="background-image:url('${escapeHtml(monsterArt(encounter.sprite_key))}')">
                            <span class="inventory-expedition-arena-monster-glyph">${escapeHtml(monsterInitials(encounter.name || 'Monstro'))}</span>
                        </span>
                    </span>
                    <span class="inventory-expedition-arena-monster-panel">
                        <span class="inventory-expedition-arena-monster-nameplate">
                            <span class="inventory-expedition-arena-entity-label">${escapeHtml(encounter.name || 'Monstro')}</span>
                            <span class="inventory-expedition-arena-monster-subline">
                                <span class="inventory-expedition-arena-level">Nv ${Math.max(1, Number(encounter.tier || 1) * 10)}</span>
                                ${tierBadge(encounter.tier, encounter.is_boss ? 'Chefe' : encounter.tier_label)}
                            </span>
                        </span>
                        <span class="inventory-expedition-arena-monster-life">
                            ${hpBar(encounter.current_hp, encounter.max_hp)}
                            <span class="inventory-expedition-arena-monster-life-text">${escapeHtml(shortHp(encounter.current_hp, encounter.max_hp))}</span>
                        </span>
                    </span>
                </span>
            </button>
        `;
    }).join('');

    const lootMarkup = groundLoot.map((loot) => `
        <button type="button"
            class="inventory-expedition-arena-entity is-loot"
            style="${entityPositionStyle(loot.map_x, loot.map_y, mapWidth, mapHeight)}"
            data-arena-pickup="${escapeHtml(loot.public_id)}"
            data-arena-entity-id="${escapeHtml(loot.public_id)}"
            data-arena-entity-type="loot"
            ${expeditionReady ? '' : 'disabled'}
            aria-label="Coletar ${escapeHtml(loot.item_definition_code)}">
            <span class="inventory-expedition-arena-loot-chip">
                <img src="${escapeHtml(lootArt(loot.item_definition_code))}" alt="" aria-hidden="true">
            </span>
            <span class="inventory-expedition-arena-entity-label">${escapeHtml(loot.quantity)}x ${escapeHtml(loot.item_definition_code)}</span>
        </button>
    `).join('');

    const modifiers = arenaState?.modifiers || {};
    const combatBonuses = modifiers.combat_bonuses || {};
    const bonusParts = [];
    if (Number(combatBonuses.damage_bonus || 0) > 0) bonusParts.push(`Dano +${Math.round(Number(combatBonuses.damage_bonus) * 100)}%`);
    if (Number(combatBonuses.dodge_bonus || 0) > 0) bonusParts.push(`Esquiva +${Math.round(Number(combatBonuses.dodge_bonus) * 100)}%`);
    const bonusLabel = bonusParts.length ? ` · ${bonusParts.join(' · ')}` : '';

    const poiMarkup = points.map((point) => {
        const poiClass = point.discovered
            ? `is-poi${point.is_structure ? ' is-structure' : ''}${point.can_interact ? ' is-interactive' : ''}`
            : (point.in_range ? 'is-poi is-nearby' : 'is-poi is-fog');
        const label = point.discovered ? (point.name || 'Ponto') : (point.in_range ? '???' : '');
        return `
            <button type="button"
                class="inventory-expedition-arena-entity ${poiClass}"
                style="${entityPositionStyle(point.map_x, point.map_y, mapWidth, mapHeight)}"
                data-arena-poi="${escapeHtml(point.public_id)}"
                data-arena-entity-id="${escapeHtml(point.public_id)}"
                data-arena-entity-type="poi"
                ${!expeditionReady && point.discovered ? 'disabled' : ''}
                aria-label="${escapeHtml(point.discovered ? (point.name || 'Ponto de interesse') : 'Ponto nao identificado')}">
                <span class="inventory-expedition-arena-poi-marker">
                    <span class="inventory-expedition-arena-poi-pulse"></span>
                    <span class="inventory-expedition-arena-poi-question">?</span>
                </span>
                ${point.discovered ? `<span class="inventory-expedition-arena-entity-label">${escapeHtml(label)}</span>` : ''}
            </button>
        `;
    }).join('');

    const hiddenMarkup = hiddenPoints.map((point) => `
        <button type="button"
            class="inventory-expedition-arena-entity is-hidden-point"
            style="${entityPositionStyle(point.map_x, point.map_y, mapWidth, mapHeight)}"
            data-arena-hidden-point="${escapeHtml(point.public_id)}"
            data-arena-entity-id="${escapeHtml(point.public_id)}"
            data-arena-entity-type="hidden"
            ${!expeditionReady ? 'disabled' : ''}
            aria-label="Sinal de algo oculto">
            <span class="inventory-expedition-arena-secret-glimmer">
                <span class="inventory-expedition-arena-secret-ring"></span>
                <img src="${escapeHtml(hiddenPointArt(point))}" alt="" aria-hidden="true">
            </span>
            <span class="inventory-expedition-arena-entity-label">Vestigio estranho</span>
        </button>
    `).join('');

    const holdMarkup = arenaHoldInteraction
        ? `
            <div class="inventory-expedition-arena-hold-indicator"
                style="${entityPositionStyle(arenaHoldInteraction.mapX, arenaHoldInteraction.mapY, mapWidth, mapHeight)} --hold-progress:${Math.max(0, Math.min(100, Number(arenaHoldInteraction.progress || 0)))}; --hold-accent:${escapeHtml(arenaHoldInteraction.accent || '#fbbf24')};">
                <span class="inventory-expedition-arena-hold-ring"></span>
                <span class="inventory-expedition-arena-hold-frame" style="background-image:url('${escapeHtml(ARENA_HOLD_FRAME)}')"></span>
                <span class="inventory-expedition-arena-hold-core"></span>
                <span class="inventory-expedition-arena-hold-label">${escapeHtml(arenaHoldInteraction.label || 'Explorando')}</span>
            </div>
        `
        : '';

    const vitalsLabel = vitals
        ? `${Number(vitals.current_hp || 0)}/${Number(vitals.max_hp || 0)} HP`
        : 'Sem HP ativo';
    const playerLevel = Number(playerHud?.player?.level || 1);
    const playerName = playerHud?.player?.name || 'Jogador';
    const playerPower = Number(playerHud?.power?.total || 0);
    const playerXp = Number(playerHud?.player?.experience || 0);
    const goldBalance = walletBalance(wallets, 'gold');
    const carryCapacity = Math.max(1, Number(expeditionCarry?.capacity_cells || 1));
    const carryOccupied = Math.max(0, Number(expeditionCarry?.occupied_cells || 0));
    const carryPercent = Math.max(0, Math.min(100, Math.round((carryOccupied / carryCapacity) * 100)));
    const carryItems = Array.isArray(expeditionCarry?.items) ? expeditionCarry.items : [];
    const quickTools = [
        quickTool('hatchet', 'Machado', '#f97316'),
        quickTool('pickaxe', 'Picareta', '#38bdf8'),
        quickTool('shears', 'Tesoura', '#4ade80'),
        quickTool('magnifier', 'Lupa', '#facc15'),
    ];

    const combatState = arenaState?.combat_state || {};
    const killsToward = Number(combatState.kills_toward_boss || 0);
    const killsToBoss = Math.max(1, Number(combatState.kills_to_boss || 10));
    const bossActive = Boolean(combatState.boss_active);
    const wavePercent = Math.max(0, Math.min(100, Math.round((killsToward / killsToBoss) * 100)));
    const focusEncounter = encounters.find((entry) => entry.public_id === (combatState.focus_encounter_public_id || arenaSelectedEncounterId));
    const focusLabel = focusEncounter
        ? `${focusEncounter.is_boss ? 'Chefe' : 'Alvo'}: ${focusEncounter.name}`
        : 'Auto-ataque no alcance';

    const combatFeedMarkup = arenaRecentEvents.length
        ? arenaRecentEvents.slice(0, 5).map((entry) => `
            <li class="inventory-expedition-arena-feed-item is-${escapeHtml(entry.kind || 'neutral')}">
                <span>${escapeHtml(entry.label || '')}</span>
            </li>
        `).join('')
        : '<li class="inventory-expedition-arena-feed-item is-neutral"><span>Clique em um monstro para focar. O combate e automatico no alcance.</span></li>';

    return `
        <section class="inventory-expedition-arena-wrap">
            <div class="inventory-expedition-arena-scene ${lowHealth ? 'is-low-health' : ''}">
                <div class="inventory-expedition-arena ${expeditionReady ? 'is-ready' : 'is-locked'}"
                    data-arena-biome="${escapeHtml(biomeCode)}"
                    data-arena-background="${escapeHtml(backgroundUrl)}"
                    style="--map-width:${mapWidth}; --map-height:${mapHeight}; --arena-cursor-default:${escapeHtml(ARENA_CURSOR.default)}; --arena-cursor-drag:${escapeHtml(ARENA_CURSOR.drag)}; --arena-cursor-attack:${escapeHtml(ARENA_CURSOR.attack)}; --arena-cursor-loot:${escapeHtml(ARENA_CURSOR.loot)}; --arena-cursor-poi:${escapeHtml(ARENA_CURSOR.poi)};">
                    <div class="inventory-expedition-arena-hud">
                        <div class="inventory-expedition-arena-hud-main">
                            <span class="inventory-expedition-arena-kicker">Expedicao ativa</span>
                            <strong>${escapeHtml(biomeTitle(biomeCode))}</strong>
                            <span class="inventory-expedition-arena-hud-note">Chao = andar · Monstro = atacar · Item = coletar · ? = investigar (clique)</span>
                            <span class="inventory-expedition-arena-hud-note">${escapeHtml(playerName)} · Nv ${playerLevel}${playerPower > 0 ? ` · Poder ${playerPower}` : ''}${playerXp > 0 ? ` · XP ${playerXp}` : ''}</span>
                            <span class="inventory-expedition-arena-hud-note">${escapeHtml(timerLabel)}${escapeHtml(bonusLabel)}</span>
                            <div class="inventory-expedition-arena-wave ${bossActive ? 'is-boss' : ''}" data-arena-wave-progress>
                                <strong>${bossActive ? 'Chefe ativo' : `Rumo ao chefe ${killsToward}/${killsToBoss}`}</strong>
                                <span>${escapeHtml(focusLabel)}</span>
                                <div class="inventory-expedition-arena-hpbar" data-arena-wave-bar><span style="width:${wavePercent}%"></span></div>
                            </div>
                        </div>
                        <div class="inventory-expedition-arena-hud-vitals">
                            <span class="inventory-expedition-arena-vital-label">Vida</span>
                            <strong>${escapeHtml(vitalsLabel)}</strong>
                            ${vitals ? hpBar(vitals.current_hp, vitals.max_hp) : ''}
                        </div>
                        <div class="inventory-expedition-arena-hud-vitals is-carry">
                            <span class="inventory-expedition-arena-vital-label">Carry (espacos)</span>
                            <strong>${carryOccupied}/${carryCapacity} espacos</strong>
                            <div class="inventory-expedition-arena-hpbar"><span style="width:${carryPercent}%"></span></div>
                            <button type="button" class="inventory-button" data-arena-open-carry>Abrir carry</button>
                        </div>
                    </div>
                    <div class="inventory-expedition-arena-viewport" data-arena-viewport>
                        <div class="inventory-expedition-arena-camera" data-arena-camera style="--camera-x:${camera.x}px; --camera-y:${camera.y}px;">
                            <div class="inventory-expedition-arena-floor" data-arena-floor role="presentation" style="background-image:url('${escapeHtml(backgroundUrl)}')"></div>
                            <div class="inventory-expedition-arena-vignette" aria-hidden="true"></div>
                            <div class="inventory-expedition-arena-entities" data-arena-entities>
                                ${renderArenaRadiusRings(mapWidth, mapHeight, escapeHtml)}
                                ${holdMarkup}
                                ${hiddenMarkup}
                                ${poiMarkup}
                                ${lootMarkup}
                                ${encounterMarkup}
                            </div>
                        </div>
                    </div>
                    ${expeditionReady ? '' : '<div class="inventory-expedition-arena-overlay">Inicie a expedicao para entrar no mapa, mover-se e lutar.</div>'}
                </div>
                <div class="inventory-expedition-arena-footer is-hud-bar">
                    <div class="inventory-expedition-arena-carry-preview">
                        <strong>Loot recente</strong>
                        <div class="inventory-expedition-arena-carry-items">
                            ${carryItems.length ? carryItems.map((item) => `
                                <span class="inventory-expedition-arena-carry-chip" title="${escapeHtml(item.name || item.definition_code || 'Item')}">
                                    ${escapeHtml(item.quantity)}x ${escapeHtml(item.definition_code || 'item')}
                                </span>
                            `).join('') : '<span class="inventory-expedition-arena-carry-chip is-empty">Carry vazio</span>'}
                        </div>
                    </div>
                    <div class="inventory-expedition-arena-skillbar">
                        ${quickTools.map((tool, index) => `
                            <button type="button" class="inventory-expedition-arena-tool ${tool.equipped ? 'is-equipped' : 'is-missing'}" data-arena-open-equipment style="--tool-accent:${escapeHtml(tool.accent)}">
                                <span>${index + 1}</span>
                                <strong>${escapeHtml(tool.label)}</strong>
                            </button>
                        `).join('')}
                        ${renderArenaPotionButtons(escapeHtml)}
                        <button type="button" class="inventory-expedition-arena-tool is-utility" data-arena-open-stats>
                            <span>C</span>
                            <strong>Status</strong>
                        </button>
                    </div>
                    <div class="inventory-expedition-arena-feed is-compact">
                        <strong>${goldBalance.toLocaleString('pt-BR')} G</strong>
                        <ul>${combatFeedMarkup}</ul>
                    </div>
                </div>
            </div>
        </section>
    `;
}

function showPoiActionModal(point) {
    const openModal = arenaDeps?.openModal;
    const escapeHtml = arenaDeps?.escapeHtml || ((value) => String(value ?? ''));
    if (!openModal || !point) return;

    const actions = Array.isArray(point.available_actions) ? point.available_actions : [];
    const actionButtons = actions
        .filter((action) => action.available)
        .map((action) => {
            const code = action.action_code;
            const toolType = action.required_tool_type || '';
            const label = actionDisplayLabel(action);
            const risk = action.risk;
            const riskHint = risk?.label ? ` · ${risk.label}` : '';
            const isAnalyze = code === 'analyze_magnifier';
            const tool = isAnalyze
                ? arenaDeps?.listOwnedToolsByType?.('magnifier')?.[0] || null
                : arenaDeps?.listOwnedToolsByType?.(toolType)?.[0] || null;
            return `
                <div class="inventory-expedition-poi-action-row">
                    <button type="button"
                        class="inventory-button ${isAnalyze ? '' : 'is-primary'} inventory-exploration-action ${risk?.is_force_open ? 'is-danger' : ''}"
                        data-arena-poi-action="${escapeHtml(code)}"
                        data-arena-poi-id="${escapeHtml(point.public_id)}"
                        ${tool ? '' : 'disabled'}>
                        ${escapeHtml(label)}${riskHint ? `<span class="inventory-exploration-risk">${escapeHtml(riskHint)}</span>` : ''}
                    </button>
                    ${action.detail_text ? `<p class="inventory-exploration-muted">${escapeHtml(action.detail_text)}</p>` : ''}
                </div>
            `;
        }).join('');

    const content = document.createElement('div');
    content.className = 'inventory-expedition-poi-modal';
    content.innerHTML = `
        <h3>${escapeHtml(point.name || 'Ponto de interesse')}</h3>
        <p class="inventory-exploration-muted">${escapeHtml(poiCategoryLabel(point))}</p>
        <p class="inventory-exploration-muted">${escapeHtml(poiDiscoveryHint(point))}</p>
        <p class="inventory-exploration-muted">${escapeHtml(point.flavor || 'Interaja com ferramentas para coletar recursos.')}</p>
        <p class="inventory-exploration-muted">Distancia ${Number(point.distance || 0).toFixed(1)} · raio ${Number(point.discovery_radius || 0).toFixed(1)}</p>
        <div class="inventory-expedition-poi-actions">
            ${actionButtons || '<span class="inventory-exploration-muted">Nenhuma acao disponivel agora.</span>'}
        </div>
        <button type="button" class="inventory-button" data-arena-poi-close>Fechar</button>
    `;

    const { close, element } = openModal(content, { closeOnBackdrop: true });
    element?.querySelector('[data-arena-poi-close]')?.addEventListener('click', () => close());
    element?.querySelectorAll('[data-arena-poi-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const actionCode = button.getAttribute('data-arena-poi-action');
            const objectPublicId = button.getAttribute('data-arena-poi-id');
            if (!actionCode || !objectPublicId || button.disabled) return;
            close();
            try {
                if (actionCode === 'analyze_magnifier') {
                    await arenaDeps?.executeExplorationAnalyze?.(objectPublicId);
                } else {
                    await arenaDeps?.executeExplorationAction?.(objectPublicId, actionCode);
                }
                await arenaDeps?.loadExplorationObjects?.();
            } catch (error) {
                arenaToast(localizeArenaError(error, 'Nao foi possivel interagir com o ponto.'), 'warning', 2600);
            }
        });
    });
}

function flashArenaTrap(root) {
    const floor = root.querySelector('[data-arena-floor]');
    if (!floor) return;
    floor.classList.add('is-trap-hit');
    window.setTimeout(() => floor.classList.remove('is-trap-hit'), 650);
}

function coordsFromArenaClick(event, floorNode) {
    const rect = floorNode.getBoundingClientRect();
    const mapWidth = Number(floorNode.closest('[data-arena-biome]')?.style.getPropertyValue('--map-width') || 6);
    const mapHeight = Number(floorNode.closest('[data-arena-biome]')?.style.getPropertyValue('--map-height') || 4);
    const x = ((event.clientX - rect.left) / rect.width) * mapWidth;
    const y = ((event.clientY - rect.top) / rect.height) * mapHeight;

    return {
        map_x: Math.max(0, Math.min(mapWidth, Number(x.toFixed(2)))),
        map_y: Math.max(0, Math.min(mapHeight, Number(y.toFixed(2)))),
    };
}

function clampArenaCamera(root, biomeCode, nextX, nextY) {
    const viewport = root.querySelector('[data-arena-viewport]');
    const cameraNode = root.querySelector('[data-arena-camera]');
    if (!viewport || !cameraNode) {
        return { x: nextX, y: nextY };
    }

    const extraX = Math.max(0, (cameraNode.offsetWidth - viewport.clientWidth) / 2);
    const extraY = Math.max(0, (cameraNode.offsetHeight - viewport.clientHeight) / 2);
    const clamped = {
        x: Math.max(-extraX, Math.min(extraX, nextX)),
        y: Math.max(-extraY, Math.min(extraY, nextY)),
    };
    arenaCameraByBiome.set(String(biomeCode || 'default'), clamped);
    return clamped;
}

function applyArenaCamera(root, biomeCode, nextX, nextY) {
    const cameraNode = root.querySelector('[data-arena-camera]');
    if (!cameraNode) return;
    const clamped = clampArenaCamera(root, biomeCode, nextX, nextY);
    cameraNode.style.setProperty('--camera-x', `${clamped.x}px`);
    cameraNode.style.setProperty('--camera-y', `${clamped.y}px`);
}

function bindArenaCameraDrag(root) {
    const viewport = root.querySelector('[data-arena-viewport]');
    const biomeCode = root.querySelector('[data-arena-biome]')?.getAttribute('data-arena-biome') || 'default';
    if (!viewport) return;
    if (viewport.dataset.dragBound === '1') return;
    viewport.dataset.dragBound = '1';

    let dragState = null;
    let suppressClick = false;

    const endDrag = () => {
        if (!dragState) return;
        viewport.classList.remove('is-dragging');
        suppressClick = dragState.moved;
        if (suppressClick) {
            window.setTimeout(() => {
                suppressClick = false;
            }, 180);
        }
        dragState = null;
    };

    viewport.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        if (event.target instanceof Element && event.target.closest('[data-arena-attack], [data-arena-poi], [data-arena-pickup], [data-arena-hidden-point]')) {
            return;
        }
        const current = cameraStateForBiome(biomeCode);
        dragState = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            baseX: current.x,
            baseY: current.y,
            moved: false,
        };
        viewport.classList.add('is-dragging');
        viewport.setPointerCapture?.(event.pointerId);
    });

    viewport.addEventListener('pointermove', (event) => {
        if (!dragState || dragState.pointerId !== event.pointerId) return;
        const dx = event.clientX - dragState.startX;
        const dy = event.clientY - dragState.startY;
        if (Math.abs(dx) > 6 || Math.abs(dy) > 6) {
            dragState.moved = true;
        }
        applyArenaCamera(root, biomeCode, dragState.baseX + dx, dragState.baseY + dy);
    });

    viewport.addEventListener('pointerup', endDrag);
    viewport.addEventListener('pointercancel', endDrag);
    viewport.addEventListener('lostpointercapture', endDrag);
    viewport.addEventListener('click', (event) => {
        if (dragState?.moved || suppressClick) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
}

function showCombatFloaters(root, events, options = {}) {
    if (!Array.isArray(events) || !events.length) return;
    const host = root.querySelector('[data-arena-entities]');
    if (!host) return;
    const encounterId = options.encounterId || null;

    events.forEach((entry, index) => {
        const damage = Number(entry.damage || 0);
        const type = String(entry.type || '');
        const anchor = entityAnchor(root, entry.target, encounterId);
        const hostRect = host.getBoundingClientRect();
        const anchorRect = anchor?.getBoundingClientRect?.();
        const left = anchorRect ? (((anchorRect.left - hostRect.left) + (anchorRect.width / 2)) / hostRect.width) * 100 : 50;
        const top = anchorRect ? (((anchorRect.top - hostRect.top) + (anchorRect.height / 2)) / hostRect.height) * 100 : 42;

        if (damage > 0 || type === 'player_dodge' || type === 'monster_dodge' || type === 'player_reflect' || type === 'monster_kill') {
            const node = document.createElement('span');
            node.className = `inventory-expedition-arena-float is-${entry.target || 'monster'}${type.includes('crit') ? ' is-crit' : ''}`;
            node.textContent = eventLabel(entry);
            node.style.left = `${Math.max(8, Math.min(92, left + (index * 2.5)))}%`;
            node.style.top = `${Math.max(8, Math.min(90, top - 4 + (index * 1.5)))}%`;
            host.appendChild(node);
            window.setTimeout(() => node.remove(), 900);
        }

        if (damage > 0 && (entry.target === 'player' || entry.target === 'monster')) {
            spawnBloodParticles(host, entry.target, type.includes('crit'), left, top);
        }

        if (type === 'monster_kill') {
            const flash = document.createElement('span');
            flash.className = 'inventory-expedition-arena-kill-flash';
            host.appendChild(flash);
            window.setTimeout(() => flash.remove(), 500);
        }

        if (anchor) {
            anchor.classList.add('is-impact');
            window.setTimeout(() => anchor.classList.remove('is-impact'), 380);
        }
    });
}

function applyCombatStateToDom(root, payload, encounterPublicId) {
    const encounter = payload?.combat?.encounter || null;
    const vitals = payload?.combat?.vitals || null;
    const killed = Boolean(payload?.combat?.killed);
    const entity = encounterPublicId ? root.querySelector(`[data-arena-attack="${encounterPublicId}"]`) : null;

    if (vitals) {
        arenaState.vitals = { ...vitals };
    }
    syncArenaHudFromState(root);

    if (entity && encounter) {
        const hp = entity.querySelector('.inventory-expedition-arena-hpbar span');
        const label = entity.querySelector('.inventory-expedition-arena-entity-label');
        const hpText = entity.querySelector('.inventory-expedition-arena-monster-life-text');
        const pct = Math.max(0, Math.min(100, (Number(encounter.current_hp || 0) / Math.max(1, Number(encounter.max_hp || 1))) * 100));
        if (hp) hp.style.width = `${pct}%`;
        if (label && encounter.name) label.textContent = String(encounter.name);
        if (hpText) hpText.textContent = shortHp(encounter.current_hp, encounter.max_hp);
        updateArenaEntityPosition(entity, Number(encounter.map_x || 0), Number(encounter.map_y || 0));
    }

    if (entity && killed) {
        entity.style.opacity = '0';
        window.setTimeout(() => entity.remove(), 220);
    }
}

function spawnBloodParticles(host, target, isCrit, left, top) {
    const count = isCrit ? 6 : 3;
    for (let i = 0; i < count; i += 1) {
        const particle = document.createElement('span');
        particle.className = `inventory-expedition-arena-blood is-${target}`;
        particle.style.left = `${Math.max(6, Math.min(94, left + ((Math.random() - 0.5) * 7)))}%`;
        particle.style.top = `${Math.max(6, Math.min(92, top + ((Math.random() - 0.5) * 5)))}%`;
        particle.style.setProperty('--blood-x', `${(Math.random() - 0.5) * 36}px`);
        particle.style.setProperty('--blood-y', `${-8 - (Math.random() * 24)}px`);
        host.appendChild(particle);
        window.setTimeout(() => particle.remove(), 700);
    }
}

function showPlayerDefeatModal(failure) {
    const openModal = arenaDeps?.openModal;
    if (!openModal) {
        arenaDeps?.toast?.(failure?.message || 'Voce foi derrotado na arena.', 'error');
        return;
    }

    const escapeHtml = arenaDeps?.escapeHtml || ((value) => String(value ?? ''));
    const content = document.createElement('div');
    content.className = 'inventory-expedition-defeat-modal';
    content.innerHTML = `
        <h3>Derrota na arena</h3>
        <p>${escapeHtml(failure?.message || 'Sua expedicao foi encerrada. O loot no chao permanece perdido ate iniciar outra corrida.')}</p>
        <p class="inventory-exploration-muted">Inicie uma nova expedicao para voltar ao bioma com vida cheia.</p>
        <button type="button" class="inventory-button is-primary" data-arena-defeat-close>Entendi</button>
    `;

    const { close, element } = openModal(content, { closeOnBackdrop: true });
    element?.querySelector('[data-arena-defeat-close]')?.addEventListener('click', () => close());
}

function cancelArenaHold(render = false) {
    if (arenaHoldInteraction?.rafId) {
        window.cancelAnimationFrame(arenaHoldInteraction.rafId);
    }
    document.querySelector('[data-arena-hold-indicator]')?.remove();
    arenaHoldInteraction = null;
    if (render) {
        if (arenaDeps?.softRefreshArenaStage) {
            arenaDeps.softRefreshArenaStage();
        } else {
            arenaDeps?.renderExplorationPanel?.();
        }
    }
}

function syncArenaHoldIndicator(root) {
    const host = root?.querySelector?.('[data-arena-entities]');
    if (!host) return;

    const current = host.querySelector('[data-arena-hold-indicator]');
    if (!arenaHoldInteraction) {
        current?.remove();
        return;
    }

    const progress = Math.max(0, Math.min(100, Number(arenaHoldInteraction.progress || 0)));
    const style = `${entityPositionStyle(arenaHoldInteraction.mapX, arenaHoldInteraction.mapY, Number(arenaState?.arena?.map_width || 6), Number(arenaState?.arena?.map_height || 4))} --hold-progress:${progress}; --hold-accent:${String(arenaHoldInteraction.accent || '#fbbf24')};`;

    if (current) {
        current.setAttribute('style', style);
        const label = current.querySelector('.inventory-expedition-arena-hold-label');
        if (label) {
            label.textContent = String(arenaHoldInteraction.label || 'Explorando');
        }
        return;
    }

    const node = document.createElement('div');
    node.dataset.arenaHoldIndicator = '1';
    node.className = 'inventory-expedition-arena-hold-indicator';
    node.setAttribute('style', style);
    node.innerHTML = `
        <span class="inventory-expedition-arena-hold-ring"></span>
        <span class="inventory-expedition-arena-hold-frame" style="background-image:url('${ARENA_HOLD_FRAME}')"></span>
        <span class="inventory-expedition-arena-hold-core"></span>
        <span class="inventory-expedition-arena-hold-label">${String(arenaHoldInteraction.label || 'Explorando')}</span>
    `;
    host.appendChild(node);
}

function startArenaPoiHold(root, point) {
    if (!point || arenaPoiInFlight) return;
    const action = bestPoiAction(point);
    const readiness = canUsePoiAction(action);
    if (!readiness.ok) {
        arenaDeps?.toast?.(readiness.message || 'Acao indisponivel.', 'error');
        return;
    }

    const accent = point.is_structure ? '#38bdf8' : (String(point.visual_type || '').toLowerCase() === 'chest' ? '#fbbf24' : '#4ade80');
    const durationMs = point.is_structure ? 1050 : 850;
    const startedAt = performance.now();
    arenaHoldInteraction = {
        pointId: point.public_id,
        actionCode: action.action_code,
        label: actionDisplayLabel(action),
        mapX: Number(point.map_x || 0),
        mapY: Number(point.map_y || 0),
        progress: 0,
        accent,
        active: true,
        completed: false,
        rafId: 0,
    };
    syncArenaHoldIndicator(root);

    const tick = (now) => {
        if (!arenaHoldInteraction || arenaHoldInteraction.pointId !== point.public_id || !arenaHoldInteraction.active) {
            return;
        }
        const progress = Math.max(0, Math.min(100, ((now - startedAt) / durationMs) * 100));
        arenaHoldInteraction.progress = progress;
        syncArenaHoldIndicator(root);
        if (progress >= 100) {
            arenaHoldInteraction.completed = true;
            executeHeldPoiAction(point, action).finally(() => {
                cancelArenaHold(true);
            });
            return;
        }
        arenaHoldInteraction.rafId = window.requestAnimationFrame(tick);
    };

    arenaHoldInteraction.rafId = window.requestAnimationFrame(tick);
}

async function executeHeldPoiAction(point, action) {
    if (!point || !action) return;
    if (arenaPoiInFlight) return;
    arenaPoiInFlight = true;

    try {
        if (action.action_code === 'analyze_magnifier') {
            await arenaDeps?.executeExplorationAnalyze?.(point.public_id, { silent: true });
        } else {
            await arenaDeps?.executeExplorationAction?.(point.public_id, action.action_code, { silent: true });
        }
        queueArenaPoiSync();
    } finally {
        arenaPoiInFlight = false;
    }
}

export function bindExplorationArenaInteractions(root) {
    if (!root || !arenaDeps) return;

    stopArenaWander();
    bindArenaCameraDrag(root);
    const arenaNode = root.querySelector('[data-arena-biome]');
    if (!arenaNode) return;
    if (arenaNode.dataset.boundInteractions === '1') {
        startArenaWander(root);
        if (arenaDeps.getExplorationExpedition?.()?.active) {
            startArenaCombatLoop(root);
        }
        return;
    }
    arenaNode.dataset.boundInteractions = '1';

    const releaseHold = () => {
        if (arenaHoldInteraction && !arenaHoldInteraction.completed) {
            cancelArenaHold(true);
        }
    };

    arenaNode.addEventListener('pointerup', releaseHold);
    arenaNode.addEventListener('pointercancel', releaseHold);
    arenaNode.addEventListener('pointerleave', releaseHold);

    arenaNode.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const openCarryButton = target?.closest('[data-arena-open-carry]');
        const openEquipmentButton = target?.closest('[data-arena-open-equipment]');
        const openStatsButton = target?.closest('[data-arena-open-stats]');
        const attackButton = target?.closest('[data-arena-attack]');
        const poiButton = target?.closest('[data-arena-poi]');
        const hiddenPointButton = target?.closest('[data-arena-hidden-point]');
        const pickupButton = target?.closest('[data-arena-pickup]');
        const floor = root.querySelector('[data-arena-floor]');
        const viewport = root.querySelector('[data-arena-viewport]');

        if (openCarryButton) {
            event.preventDefault();
            event.stopPropagation();
            arenaDeps.openExpeditionCarryPanel?.();
            return;
        }

        if (openEquipmentButton) {
            event.preventDefault();
            event.stopPropagation();
            arenaDeps.openEquipmentPanel?.();
            return;
        }

        if (openStatsButton) {
            event.preventDefault();
            event.stopPropagation();
            arenaDeps.openStatsPanel?.();
            return;
        }

        const usePotionButton = target?.closest('[data-arena-use-potion]');
        if (usePotionButton) {
            event.preventDefault();
            event.stopPropagation();
            if (arenaPotionInFlight) return;
            if (!arenaDeps.getExplorationExpedition?.()?.active) {
                arenaDeps.toast?.('Inicie a expedicao para usar pocoes.', 'error');
                return;
            }
            const slotCode = usePotionButton.getAttribute('data-arena-use-potion') || '';
            arenaPotionInFlight = true;
            try {
                const response = await arenaDeps.apiFetch?.('/api/expeditions/arena/potions/use', {
                    method: 'POST',
                    body: { slot_code: slotCode },
                });
                const payload = response?.data?.arena || response?.arena || null;
                if (payload) {
                    applyExplorationArenaPayload(payload);
                    arenaDeps.softRefreshArenaStage?.();
                    arenaDeps.renderExplorationPanel?.();
                } else {
                    await loadExplorationArena(arenaNode.getAttribute('data-arena-biome') || 'bosque_inicial');
                    arenaDeps.renderExplorationPanel?.();
                }
                const buffLabel = response?.data?.use_potion?.buff?.label || 'Buff aplicado';
                arenaDeps.toast?.(buffLabel, 'success');
            } catch (error) {
                arenaDeps.toast?.(error?.message || 'Nao foi possivel usar a pocao.', 'error');
            } finally {
                arenaPotionInFlight = false;
            }
            return;
        }

        if (attackButton) {
            event.stopPropagation();
            if (arenaFocusInFlight) return;
            const encounterPublicId = attackButton.getAttribute('data-arena-attack');
            if (!encounterPublicId) return;

            arenaSelectedEncounterId = encounterPublicId;
            syncArenaHudFromState(root);
            attackButton.classList.add('is-selected', 'is-engaging', 'is-impact');
            arenaFocusInFlight = true;

            try {
                // Focus + ataque imediato em paralelo para feedback de clique.
                const [focusResponse, attackResponse] = await Promise.all([
                    arenaDeps.apiFetch('/api/expeditions/arena/focus', {
                        method: 'POST',
                        body: { encounter_public_id: encounterPublicId },
                    }),
                    arenaDeps.apiFetch('/api/expeditions/arena/attack', {
                        method: 'POST',
                        body: { encounter_public_id: encounterPublicId },
                    }).catch((error) => ({ __error: error })),
                ]);

                applyExplorationArenaPayload(focusResponse?.data?.arena || null);
                if (attackResponse?.__error) {
                    // Fora de alcance / sem energia: ainda mantem o foco idle.
                    const message = String(attackResponse.__error?.message || '');
                    if (!/out of attack range/i.test(message)) {
                        arenaToast(localizeArenaError(attackResponse.__error, 'Nao foi possivel atacar.'), 'warning', 2200);
                        arenaDeps.setStatus?.(localizeArenaError(attackResponse.__error, 'Falha no ataque'));
                    }
                } else {
                    const payload = attackResponse?.data || {};
                    applyExplorationArenaPayload(payload.arena || null);
                    const combat = payload.combat || {};
                    if (Array.isArray(combat.events) && combat.events.length) {
                        updateCombatFeed(combat.events);
                        syncArenaFeed(root);
                        showCombatFloaters(root, combat.events, { encounterId: encounterPublicId });
                    }
                    if (combat.encounter) {
                        applyServerEncounterState(root, combat.encounter);
                    }
                    applyCombatStateToDom(root, payload, encounterPublicId);
                    if (combat.ground_loot) {
                        appendGroundLootToArena(root, combat.ground_loot);
                    }
                    const killEvent = (combat.events || []).find((entry) => ['monster_kill', 'boss_kill'].includes(String(entry?.type || '')));
                    if (killEvent) {
                        removeEncounterFromArenaState(encounterPublicId);
                        arenaDeps.toast?.(killEvent.type === 'boss_kill' ? 'Chefe derrotado!' : 'Monstro derrotado!', 'success');
                    }
                }
                syncArenaHudFromState(root);
                startArenaCombatLoop(root);
            } catch (error) {
                arenaToast(localizeArenaError(error, 'Nao foi possivel focar o monstro.'), 'warning', 2200);
            } finally {
                attackButton.classList.remove('is-impact');
                arenaFocusInFlight = false;
            }
            return;
        }

        if (poiButton) {
            event.stopPropagation();
            event.preventDefault();
            if (arenaPoiInFlight) return;
            const publicId = poiButton.getAttribute('data-arena-poi');
            const point = (arenaState?.points_of_interest || []).find((entry) => entry.public_id === publicId);
            if (!point) return;
            if (!point.discovered) {
                arenaToast('Aproxime o pontinho azul deste ? para revelar o ponto.', 'info');
                return;
            }
            if (!arenaDeps.getExplorationExpedition?.()?.active) {
                arenaToast('Inicie a expedicao para interagir.', 'error');
                return;
            }
            if (!point.in_range && Number(point.distance || 99) > Number(point.discovery_radius || 1.5) + 0.35) {
                arenaToast('Chegue mais perto do ponto para investigar.', 'warning');
                return;
            }
            showPoiActionModal(point);
            return;
        }

        if (hiddenPointButton) {
            event.stopPropagation();
            const publicId = hiddenPointButton.getAttribute('data-arena-hidden-point');
            const point = (arenaState?.hidden_points || []).find((entry) => entry.public_id === publicId);
            if (!point) return;
            arenaRecentEvents = [{
                kind: 'info',
                label: `Algo oculto foi percebido perto de ${point.position_label || 'uma area suspeita'}.`,
            }, ...arenaRecentEvents].slice(0, 6);
            syncArenaFeed(root);
            arenaDeps.toast?.('Voce sentiu um vestigio estranho. Analise a area e continue explorando.', 'info');
            if (arenaDeps?.softRefreshArenaStage) {
                arenaDeps.softRefreshArenaStage();
            } else {
                arenaDeps.renderExplorationPanel?.();
            }
            return;
        }

        if (pickupButton) {
            event.stopPropagation();
            const lootPublicId = pickupButton.getAttribute('data-arena-pickup');
            if (!lootPublicId) return;
            if (arenaPendingPickupIds.has(lootPublicId)) return;
            if (arenaPendingPickupIds.size >= ARENA_MAX_PICKUPS_IN_FLIGHT) {
                arenaToast('Aguarde um instante para coletar mais loot.', 'info', 1600);
                return;
            }

            const stateSnapshot = cloneArenaStateSnapshot();
            arenaPendingPickupIds.add(lootPublicId);
            pickupButton.classList.add('is-pending');
            pickupButton.setAttribute('aria-busy', 'true');
            const optimisticLoot = applyOptimisticPickup(root, lootPublicId);
            const pickupTimer = arenaDeps.trackUx?.('pickup', { loot_public_id: lootPublicId });

            try {
                const response = await arenaDeps.apiFetch('/api/expeditions/arena/pickup', {
                    method: 'POST',
                    body: { loot_public_id: lootPublicId },
                });
                const payload = response.data || {};
                applyExplorationArenaPayload(payload.arena || null);
                const loot = payload.pickup || {};
                const carrySuffix = loot.placed_in_expedition_carry ? ' (Expedition Carry)' : '';
                const walletSuffix = loot.wallet_balance != null ? ` · Ouro total ${Number(loot.wallet_balance || 0).toLocaleString('pt-BR')}` : '';
                if (loot.wallet_balance != null) {
                    syncArenaWalletBalance('gold', loot.wallet_balance);
                }
                syncArenaCarryFromPickup(loot);
                arenaRecentEvents = [{
                    kind: 'success',
                    label: `Loot recolhido: ${loot.quantity || 1}x ${loot.item_definition_code || 'item'}`,
                }, ...arenaRecentEvents].slice(0, 6);
                syncArenaFeed(root);
                syncArenaHudFromState(root);
                arenaToast(`Coletado: ${loot.quantity || 1}x ${loot.item_definition_code || 'item'}${carrySuffix}${walletSuffix}`, 'success', 900);
                pickupTimer?.end({ ok: true });
                // Sync leve: arena/HUD apenas — sem inventário completo.
                queueArenaBackgroundSync({ delay: 0, skipInventory: true });
            } catch (error) {
                arenaToast(localizeArenaError(error, 'Nao foi possivel coletar o loot.'), 'warning', 1800);
                restoreArenaStateSnapshot(stateSnapshot);
                if (optimisticLoot && arenaDeps?.softRefreshArenaStage) {
                    arenaDeps.softRefreshArenaStage();
                }
                pickupTimer?.end({ ok: false });
            } finally {
                pickupButton.classList.remove('is-pending');
                pickupButton.removeAttribute('aria-busy');
                arenaPendingPickupIds.delete(lootPublicId);
            }
            return;
        }

        if (!floor || !viewport) return;
        if (target && target.closest('[data-arena-entities]')) return;
        if (arenaMoveInFlight || !arenaDeps.getExplorationExpedition?.()?.active) return;
        const biomeCode = arenaNode.getAttribute('data-arena-biome') || arenaDeps.getExplorationBiomeCode?.();
        if (!biomeCode) return;

        const coords = coordsFromArenaClick(event, floor);
        const previousPosition = arenaState?.position ? { ...arenaState.position } : null;
        applyOptimisticPlayerMove(root, coords);
        arenaMoveInFlight = true;

        try {
            const response = await arenaDeps.apiFetch('/api/expeditions/arena/move', {
                method: 'POST',
                body: {
                    biome_code: biomeCode,
                    map_x: coords.map_x,
                    map_y: coords.map_y,
                },
            });
            const payload = response.data || {};
            applyExplorationArenaPayload(payload.arena || payload);
            syncArenaHudFromState(root);
            const hazard = payload.move?.hazard;
            if (hazard?.triggered) {
                updateCombatFeed(hazard.events || []);
                syncArenaFeed(root);
                flashArenaTrap(root);
                showCombatFloaters(root, hazard.events || []);
                arenaDeps.toast?.(hazard.events?.[0]?.message || 'Armadilha disparou!', 'warning');
                if (hazard.player_defeated) {
                    showPlayerDefeatModal(hazard.expedition_failed || null);
                    stopArenaWander();
                }
                queueArenaMoveSync(120);
            }
        } catch (error) {
            if (previousPosition) {
                applyOptimisticPlayerMove(root, previousPosition);
            }
            arenaDeps.handleError?.(error, 'Nao foi possivel mover na arena.');
        } finally {
            arenaMoveInFlight = false;
        }
    });

    startArenaWander(root);
    if (arenaDeps.getExplorationExpedition?.()?.active) {
        startArenaCombatLoop(root);
    }
}

export function stopArenaWander() {
    if (arenaWanderTimer) {
        window.clearInterval(arenaWanderTimer);
        arenaWanderTimer = null;
    }
    stopArenaCombatLoop();
}

function setArenaSyncStatus(root, state, message) {
    const nodes = [
        root?.querySelector?.('[data-arena-sync-status]'),
        document.querySelector('[data-exploration-arena-status] [data-arena-sync-status]'),
    ].filter(Boolean);
    nodes.forEach((node) => {
        node.textContent = message;
        node.classList.toggle('is-paused', state === 'paused');
    });
}

function startArenaCombatLoop(root) {
    if (!root || !arenaDeps) return;
    if (arenaCombatTickTimer) return;
    arenaCombatSyncPaused = false;
    arenaCombatFailStreak = 0;
    setArenaSyncStatus(root, 'ok', 'Combate sincronizando');
    arenaCombatTickTimer = window.setInterval(() => {
        runArenaCombatTick(root).catch((error) => {
            // Falhas ja tratadas em runArenaCombatTick; evita UnhandledRejection.
            if (error && arenaCombatFailStreak === 0) {
                console.debug('[arena] tick rejected', error);
            }
        });
    }, ARENA_COMBAT_TICK_MS);
}

export function resumeArenaCombatLoop(root) {
    if (!root || !arenaDeps) return;
    arenaCombatSyncPaused = false;
    arenaCombatFailStreak = 0;
    stopArenaCombatLoop();
    setArenaSyncStatus(root, 'ok', 'Combate sincronizando');
    startArenaCombatLoop(root);
    runArenaCombatTick(root, { force: true }).catch(() => {});
}

function stopArenaCombatLoop() {
    if (arenaCombatTickTimer) {
        window.clearInterval(arenaCombatTickTimer);
        arenaCombatTickTimer = null;
    }
    arenaCombatTickInFlight = false;
}

async function runArenaCombatTick(root, options = {}) {
    if (!root || !arenaDeps) return;
    if (arenaCombatTickInFlight) return;
    if (!arenaDeps.getExplorationExpedition?.()?.active) {
        stopArenaCombatLoop();
        return;
    }

    arenaCombatTickInFlight = true;
    try {
        const response = await arenaDeps.apiFetch('/api/expeditions/arena/tick', {
            method: 'POST',
            body: {},
        });
        const payload = response.data || {};
        const combat = payload.combat || {};
        const focusId = combat.combat_state?.focus_encounter_public_id
            || arenaSelectedEncounterId
            || null;

        if (arenaCombatSyncPaused || arenaCombatFailStreak > 0) {
            arenaCombatSyncPaused = false;
            arenaCombatFailStreak = 0;
            setArenaSyncStatus(root, 'ok', 'Combate sincronizando');
        }

        applyExplorationArenaPayload(payload.arena || null);
        if (Array.isArray(combat.events) && combat.events.length) {
            const meaningful = combat.events.filter((entry) => !['idle_waiting'].includes(String(entry?.type || '')));
            if (meaningful.length) {
                updateCombatFeed(meaningful);
                syncArenaFeed(root);
                showCombatFloaters(root, meaningful.filter((entry) => !['player_hit', 'player_crit', 'out_of_range'].includes(String(entry?.type || ''))), {
                    encounterId: focusId,
                });
            }
        }

        if (combat.encounter) {
            applyServerEncounterState(root, combat.encounter);
        }
        applyCombatStateToDom(root, payload, focusId);

        const killEvent = (combat.events || []).find((entry) => ['monster_kill', 'boss_kill'].includes(String(entry?.type || '')));
        if (killEvent && focusId) {
            removeEncounterFromArenaState(focusId);
        }

        const energyDepleted = (combat.events || []).find((entry) => entry.type === 'energy_depleted');
        if (energyDepleted?.message) {
            arenaToast(energyDepleted.message, 'warning', 5000);
        }

        const bossSpawn = (combat.events || []).find((entry) => entry.type === 'boss_spawn');
        const heal = Number(combat.rewards?.heal || 0);
        const rewardGold = Number(combat.rewards?.gold || 0);
        const rewardXp = Number(combat.rewards?.exploration_xp || 0);
        if (combat.rewards?.wallet_balance != null) {
            syncArenaWalletBalance('gold', combat.rewards.wallet_balance);
        }
        if (combat.ground_loot) {
            appendGroundLootToArena(root, combat.ground_loot);
        }
        const autoPickups = Array.isArray(combat.auto_pickups) ? combat.auto_pickups : [];
        if (autoPickups.length > 0) {
            autoPickups.forEach((pickup) => {
                const lootId = pickup?.loot_public_id;
                if (lootId) {
                    const node = root.querySelector(`[data-arena-pickup="${lootId}"]`);
                    node?.remove();
                    if (Array.isArray(arenaState?.ground_loot)) {
                        arenaState.ground_loot = arenaState.ground_loot.filter((entry) => entry.public_id !== lootId);
                    }
                }
                syncArenaCarryFromPickup(pickup);
                if (pickup?.wallet_balance != null) {
                    syncArenaWalletBalance('gold', pickup.wallet_balance);
                }
            });
        }

        const summaryBits = [];
        if (killEvent) summaryBits.push(killEvent.type === 'boss_kill' ? 'Chefe derrotado' : 'Monstro derrotado');
        if (rewardGold > 0) summaryBits.push(`+${rewardGold}G`);
        if (rewardXp > 0) summaryBits.push(`+${rewardXp} XP`);
        if (heal > 0) summaryBits.push(`+${heal} HP`);
        if (autoPickups.length > 0) summaryBits.push(`${autoPickups.length} loot coletado`);
        if (bossSpawn?.message) summaryBits.push(bossSpawn.message);
        if (combat.rewards?.player_level_up) summaryBits.push('Level up!');
        if (summaryBits.length) {
            arenaToast(summaryBits.join(' · '), 'success', 1400);
        }

        if (combat.player_defeated) {
            showPlayerDefeatModal(combat.expedition_failed || null);
            stopArenaWander();
        }

        syncArenaHudFromState(root);

        if (
            killEvent
            || bossSpawn
            || combat.player_defeated
            || Number(combat.monsters_respawned || 0) > 0
            || combat.ground_loot
            || rewardGold > 0
            || rewardXp > 0
        ) {
            queueArenaBackgroundSync({ delay: killEvent || bossSpawn ? 120 : 0, skipInventory: true });
        }
    } catch (error) {
        arenaCombatFailStreak += 1;
        const message = String(error?.message || error?.payload?.message || '');
        const reasonCode = String(error?.payload?.errors?.reason_code || error?.reason_code || '');
        const playerVisible = /energy|energia|tool|ferramenta|full|cheio|carry|range|alcance|defeated|derrot|expired|expir|finished|claim/i.test(`${message} ${reasonCode}`)
            || options.force
            || arenaCombatFailStreak >= 3;

        if (playerVisible) {
            arenaCombatSyncPaused = true;
            setArenaSyncStatus(root, 'paused', 'Sincronizacao pausada — use Atualizar');
            if (arenaCombatFailStreak === 1 || arenaCombatFailStreak === 3 || options.force) {
                arenaToast(localizeArenaError(error, 'Combate pausado. Toque em Atualizar.'), 'warning', 2800);
                arenaDeps.setStatus?.('Arena: sincronizacao pausada');
            }
            if (arenaCombatFailStreak >= 5) {
                stopArenaCombatLoop();
            }
        }

        if (options.force) {
            arenaDeps.handleError?.(error, 'Falha no combate automatico.');
        }
    } finally {
        arenaCombatTickInFlight = false;
    }
}

function startArenaWander(root) {
    const encounters = Array.isArray(arenaState?.encounters) ? arenaState.encounters : [];
    if (!encounters.length) return;

    arenaWanderOffsets = new Map(encounters.map((entry) => [entry.public_id, { x: 0, y: 0, vx: 0, vy: 0 }]));

    arenaWanderTimer = window.setInterval(() => {
        const host = root.querySelector('[data-arena-entities]');
        if (!host) return;

        encounters.forEach((encounter) => {
            const current = arenaWanderOffsets.get(encounter.public_id) || { x: 0, y: 0, vx: 0, vy: 0 };
            const driftX = (current.vx * 0.72) + ((Math.random() - 0.5) * 0.065);
            const driftY = (current.vy * 0.72) + ((Math.random() - 0.5) * 0.055);
            const next = {
                x: Math.max(-0.34, Math.min(0.34, current.x + driftX)),
                y: Math.max(-0.28, Math.min(0.28, current.y + driftY)),
                vx: driftX,
                vy: driftY,
            };
            arenaWanderOffsets.set(encounter.public_id, next);
            const node = host.querySelector(`[data-arena-attack="${encounter.public_id}"]`);
            if (!node) return;
            node.style.setProperty('--entity-x', String(Number(encounter.map_x || 0) + next.x));
            node.style.setProperty('--entity-y', String(Number(encounter.map_y || 0) + next.y));
        });
    }, 480);
}
