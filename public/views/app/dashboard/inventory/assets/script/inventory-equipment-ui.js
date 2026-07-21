/**
 * Equipment / paperdoll UI: slot math, render, equip/unequip.
 */

export const EQUIPMENT_SLOT_ICON_FILES = {
    weapon: 'main_weapon.png',
    offhand: 'offhand.png',
    weapon_offhand: 'offhand.png',
    shield: 'offhand.png',
    quiver: 'offhand.png',
    helmet: 'healme.png',
    chest: 'peitoral.png',
    pants: 'pants.png',
    boots: 'boots.png',
    gloves: 'gloves.png',
    ring: 'ring_1.png',
    ring_2: 'ring_2.png',
    amulet: 'pendante.png',
    earring: 'neacles.png',
    belt: 'belt.png',
    wings: 'wings.png',
    pet: 'pet.png',
    backpack: 'bagpack.png',
};

let equipmentDeps = null;

export function configureInventoryEquipmentUi(deps = {}) {
    equipmentDeps = deps;
}

function d() {
    return equipmentDeps || {};
}

export function visualSlotCode(slotCode) {
    return ['weapon_offhand', 'shield', 'quiver', 'offhand'].includes(slotCode) ? 'offhand' : slotCode;
}

export function equipmentSlotCodesForItem(item) {
    const code = String(item?.definition?.equip_slot_code || '');
    if (!code) return [];
    if (code === 'ring') return ['ring', 'ring_2'];
    if (code === 'potion' || code === 'consumable') return ['potion_1', 'potion_2', 'potion_3', 'potion_4'];
    if (['weapon_offhand', 'shield', 'quiver', 'offhand'].includes(code)) {
        return ['weapon_offhand', 'shield', 'quiver', 'offhand'];
    }
    return [code];
}

export function equipmentSlotMatchesItem(slotEl, item) {
    if (!(slotEl instanceof HTMLElement) || !item) return false;
    const codes = equipmentSlotCodesForItem(item);
    if (!codes.length) return false;
    const slotCode = String(slotEl.dataset.equipmentSlot || '');
    const visual = String(slotEl.dataset.visualSlot || '');
    return codes.includes(slotCode)
        || codes.includes(visual)
        || codes.some((code) => visualSlotCode(code) === visual);
}

export function findEquipmentSlotUnderPointer(clientX, clientY) {
    const equipmentRoot = d().getEquipmentRoot?.();
    const isPointerInsideElement = d().isPointerInsideElement;
    if (!equipmentRoot || clientX == null || clientY == null) return null;
    if (typeof isPointerInsideElement !== 'function') return null;

    const slots = equipmentRoot.querySelectorAll('.inventory-equipment-slot');
    for (const slot of slots) {
        if (!(slot instanceof HTMLElement)) continue;
        if (slot.dataset.ghostOccupied === '1') continue;
        if (isPointerInsideElement(slot, clientX, clientY)) {
            return slot;
        }
    }
    return null;
}

export function resolvePaperdollEquipTargetSlot(slotEl, item) {
    if (!(slotEl instanceof HTMLElement) || !item) return null;
    if (slotEl.dataset.ghostOccupied === '1') return null;
    if (!equipmentSlotMatchesItem(slotEl, item)) return null;

    const slotCode = String(slotEl.dataset.equipmentSlot || '');
    const visual = String(slotEl.dataset.visualSlot || '');
    const itemCodes = equipmentSlotCodesForItem(item);
    if (!itemCodes.length) return null;

    if (itemCodes.includes(slotCode) && slotCode !== 'offhand') {
        return slotCode;
    }

    if (visual === 'offhand' || slotCode === 'offhand') {
        const primary = String(item?.definition?.equip_slot_code || '');
        if (['weapon_offhand', 'shield', 'quiver', 'offhand'].includes(primary)) {
            return primary === 'offhand' ? 'weapon_offhand' : primary;
        }
        const offhandCode = itemCodes.find((code) => ['weapon_offhand', 'shield', 'quiver'].includes(code));
        if (offhandCode) return offhandCode;
    }

    if (itemCodes.includes(slotCode)) return slotCode;
    return itemCodes[0] || null;
}

export function clearEquipmentDragHighlights() {
    const equipmentRoot = d().getEquipmentRoot?.();
    equipmentRoot?.querySelectorAll('.inventory-equipment-slot.is-drag-compatible, .inventory-equipment-slot.is-drag-hover')
        .forEach((slot) => {
            slot.classList.remove('is-drag-compatible', 'is-drag-hover');
        });
    document.querySelectorAll('.inventory-container.is-drag-source, .inventory-container.is-drag-target')
        .forEach((section) => {
            section.classList.remove('is-drag-source', 'is-drag-target');
        });
    document.documentElement.classList.remove('is-inventory-dragging');
}

export function equipmentSlotIconUrl(slotCode) {
    const file = EQUIPMENT_SLOT_ICON_FILES[visualSlotCode(slotCode)]
        || EQUIPMENT_SLOT_ICON_FILES[slotCode];
    if (!file) return null;
    return `/assets/game/icons/${file}`;
}

function bumpEquipmentSync(delta) {
    const deps = d();
    const next = Math.max(0, Number(deps.getEquipmentSyncInFlight?.() || 0) + delta);
    deps.setEquipmentSyncInFlight?.(next);
}

export function hideItemWidgetPendingEquip(itemPublicId) {
    const safeId = String(itemPublicId || '').replace(/"/g, '\\"');
    if (!safeId) return;
    document.querySelectorAll(`.grid-stack-item[gs-id="${safeId}"]`).forEach((element) => {
        element.classList.add('is-equip-pending-remove');
        element.style.visibility = 'hidden';
        element.style.pointerEvents = 'none';
        element.style.opacity = '0';
    });
}

export function applyOptimisticEquipToSlot(item, targetSlotCode) {
    const deps = d();
    if (!item?.public_id || !targetSlotCode) return;

    const equippedItem = {
        ...item,
        equipped: true,
        placement: null,
    };

    const offhandFamily = ['weapon_offhand', 'shield', 'quiver', 'offhand'];
    const isOffhandTarget = offhandFamily.includes(targetSlotCode);
    const currentEquipment = deps.getCurrentEquipment?.() || [];

    const next = currentEquipment.map((slot) => {
        const code = String(slot.code || '');
        if (code === targetSlotCode) {
            return { ...slot, item: equippedItem };
        }
        if (isOffhandTarget && offhandFamily.includes(code)) {
            return { ...slot, item: null };
        }
        if (slot.item?.public_id === item.public_id) {
            return { ...slot, item: null };
        }
        return { ...slot, item: slot.item || null };
    });

    deps.setCurrentEquipment?.(next);
    if (targetSlotCode === 'backpack') {
        deps.setEquippedBackpackPublicId?.(item.public_id);
    }

    deps.renderDockHotbar?.(next);
    if (deps.isLeftDrawerOpen?.()) {
        renderEquipment(
            next,
            deps.getLastCharacterStats?.() || [],
            deps.getCurrentEquipmentLinks?.() || [],
            deps.getCurrentSetBonuses?.() || []
        );
    }
}

export async function refreshEquipmentOnly() {
    const deps = d();
    try {
        const response = await deps.apiFetch?.('/api/inventory');
        const equipment = response?.data?.equipment || [];
        const equipmentLinks = response?.data?.equipment_links || [];
        const activeSetBonuses = response?.data?.active_set_bonuses || [];
        const characterStats = response?.data?.character_stats || [];

        deps.setCurrentEquipment?.(equipment);
        deps.setCurrentEquipmentLinks?.(equipmentLinks);
        deps.setCurrentSetBonuses?.(activeSetBonuses);
        deps.setPlayerPower?.(response?.data?.player_power ?? deps.getPlayerPower?.());
        deps.setEquippedBackpackPublicId?.(
            equipment.find((slot) => slot.code === 'backpack' && slot.item)?.item?.public_id || null
        );
        deps.renderDockHotbar?.(equipment);
        if (deps.isLeftDrawerOpen?.()) {
            renderEquipment(equipment, characterStats, equipmentLinks, activeSetBonuses);
        }
        if (response?.data?.player_hud) {
            deps.renderPlayerHud?.(response.data.player_hud);
        }
        deps.setLastCharacterStats?.(characterStats);
        deps.renderCharacterStats?.(
            characterStats,
            activeSetBonuses,
            deps.getPlayerPower?.(),
            deps.getStatsDrawerPanel?.()
        );
        return true;
    } catch {
        return false;
    }
}

export function updatePaperdollUnequipGhost(item, clientX, clientY) {
    const deps = d();
    deps.clearAllGhostPreviews?.();
    const target = deps.resolveUnequipDropTarget?.(item, clientX, clientY);
    if (!target) return;
    deps.renderGhostPreview?.(
        target.containerPublicId,
        target.grid_x,
        target.grid_y,
        target.grid_w,
        target.grid_h,
        'valid'
    );
}

export function onPaperdollUnequipDragOver(event) {
    const drag = d().getPaperdollDragState?.();
    if (!drag?.item) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    if (event.clientX == null || event.clientY == null) return;
    updatePaperdollUnequipGhost(drag.item, event.clientX, event.clientY);
}

export async function equipItemToEquipmentSlot(item, targetSlotCode, options = {}) {
    const deps = d();
    if (!item?.public_id || !targetSlotCode) return false;
    if (!options.alreadyOptimistic && (!deps.isEquippableItem?.(item) || item.equipped)) {
        deps.toast?.('Este item nao pode ser equipado.', 'info', 2600);
        deps.playInventoryFeedback?.('invalid');
        return false;
    }

    const alreadyOptimistic = Boolean(options.alreadyOptimistic);
    let sourceContainerPublicId = options.sourceContainerPublicId || null;

    if (!alreadyOptimistic) {
        hideItemWidgetPendingEquip(item.public_id);
        applyOptimisticEquipToSlot(item, targetSlotCode);
        deps.toast?.('Item equipado.', 'success', 2200);
        deps.playInventoryFeedback?.('valid');
        deps.clearDragMirror?.();
    }

    await new Promise((resolve) => {
        window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
    });
    sourceContainerPublicId = deps.consumeItemLocally?.(item.public_id) || sourceContainerPublicId;

    bumpEquipmentSync(1);
    try {
        deps.setStatus?.('Equipando item...');
        await deps.apiFetch?.(`/api/items/${encodeURIComponent(item.public_id)}/actions/EQUIP`, {
            method: 'POST',
            body: { target_slot: targetSlotCode },
        });
        deps.containerDetailCache?.invalidate?.();
        deps.invalidateItemActionsCache?.(item.public_id);
        // Atualiza links/bônus imediatamente; a fila idle podia atrasar minutos.
        await refreshEquipmentOnly().catch(() => null);
        deps.scheduleIdleUiSync?.(async () => {
            if (sourceContainerPublicId) {
                await deps.resyncContainerPanel?.(sourceContainerPublicId).catch(() => null);
            }
        });
        deps.setStatus?.('Sincronizado');
        return true;
    } catch (error) {
        deps.playInventoryFeedback?.('invalid');
        deps.handleError?.(error, 'Nao foi possivel equipar este item.');
        deps.containerDetailCache?.invalidate?.();
        deps.scheduleIdleUiSync?.(async () => {
            await refreshEquipmentOnly().catch(() => null);
            await deps.reloadContainerPanelsOnly?.().catch(() => null);
        });
        return false;
    } finally {
        bumpEquipmentSync(-1);
    }
}

export async function unequipPaperdollItem(item, options = {}) {
    const deps = d();
    if (!item?.public_id) return false;

    const previousEquipment = (deps.getCurrentEquipment?.() || []).map((slot) => ({
        ...slot,
        item: slot.item || null,
    }));
    const nextEquipment = previousEquipment.map((slot) => (
        slot.item?.public_id === item.public_id
            ? { ...slot, item: null }
            : slot
    ));
    deps.setCurrentEquipment?.(nextEquipment);
    if (deps.getEquippedBackpackPublicId?.() === item.public_id) {
        deps.setEquippedBackpackPublicId?.(null);
    }
    deps.renderDockHotbar?.(nextEquipment);
    if (deps.isLeftDrawerOpen?.()) {
        renderEquipment(
            nextEquipment,
            deps.getLastCharacterStats?.() || [],
            deps.getCurrentEquipmentLinks?.() || [],
            deps.getCurrentSetBonuses?.() || []
        );
    }
    deps.toast?.('Item desequipado.', 'success', 2200);
    deps.playInventoryFeedback?.('valid');

    const preferredDrop = deps.resolveUnequipDropTarget?.(
        item,
        options.clientX,
        options.clientY,
        options.containerPublicId || null
    );

    bumpEquipmentSync(1);
    try {
        deps.setStatus?.('Desequipando item...');
        const response = await deps.apiFetch?.(
            `/api/items/${encodeURIComponent(item.public_id)}/actions/UNEQUIP`,
            { method: 'POST', body: {} }
        );
        const payload = response?.data || response || {};
        const placement = payload.placement || {};
        const autoContainerId = placement.container_public_id || null;
        const autoX = Number(placement.grid_x ?? 0);
        const autoY = Number(placement.grid_y ?? 0);
        const autoW = Math.max(1, Number(placement.grid_w || item.definition?.grid_w || 1));
        const autoH = Math.max(1, Number(placement.grid_h || item.definition?.grid_h || 1));
        const autoVersion = Number(placement.placement_version || 1);
        const autoRotated = Boolean(placement.rotated);
        const serverItem = payload.item || item;

        deps.containerDetailCache?.invalidate?.();
        deps.invalidateItemActionsCache?.(item.public_id);

        if (preferredDrop) {
            const needsMove = !autoContainerId
                || autoContainerId !== preferredDrop.containerPublicId
                || autoX !== preferredDrop.grid_x
                || autoY !== preferredDrop.grid_y
                || autoRotated !== preferredDrop.rotated;

            deps.placeItemInContainerLocally?.(
                serverItem,
                preferredDrop.containerPublicId,
                preferredDrop.grid_x,
                preferredDrop.grid_y,
                {
                    rotated: preferredDrop.rotated,
                    placement_version: autoVersion,
                    grid_w: preferredDrop.grid_w || autoW,
                    grid_h: preferredDrop.grid_h || autoH,
                }
            );

            if (needsMove && autoContainerId) {
                try {
                    const moveResponse = await deps.apiFetch?.('/api/inventory/move', {
                        method: 'POST',
                        body: {
                            item_public_id: item.public_id,
                            source_container_public_id: autoContainerId,
                            target_container_public_id: preferredDrop.containerPublicId,
                            grid_x: preferredDrop.grid_x,
                            grid_y: preferredDrop.grid_y,
                            rotated: preferredDrop.rotated,
                            expected_placement_version: autoVersion,
                        },
                    });
                    const confirmedVersion = Number(
                        moveResponse?.data?.placement_version
                        || moveResponse?.data?.item?.placement?.placement_version
                        || autoVersion + 1
                    );
                    deps.placeItemInContainerLocally?.(
                        serverItem,
                        preferredDrop.containerPublicId,
                        preferredDrop.grid_x,
                        preferredDrop.grid_y,
                        {
                            rotated: preferredDrop.rotated,
                            placement_version: confirmedVersion,
                            grid_w: preferredDrop.grid_w || autoW,
                            grid_h: preferredDrop.grid_h || autoH,
                        }
                    );
                } catch (moveError) {
                    console.warn('[inventory-unequip-move]', moveError);
                    if (autoContainerId) {
                        deps.placeItemInContainerLocally?.(serverItem, autoContainerId, autoX, autoY, {
                            rotated: autoRotated,
                            placement_version: autoVersion,
                            grid_w: autoW,
                            grid_h: autoH,
                        });
                    }
                }
            }
        } else if (autoContainerId) {
            deps.placeItemInContainerLocally?.(serverItem, autoContainerId, autoX, autoY, {
                rotated: autoRotated,
                placement_version: autoVersion,
                grid_w: autoW,
                grid_h: autoH,
            });
        }

        await refreshEquipmentOnly().catch(() => null);
        const targetContainerId = preferredDrop?.containerPublicId || autoContainerId;
        if (targetContainerId) {
            await deps.resyncContainerPanel?.(targetContainerId).catch(() => null);
        }
        deps.setStatus?.('Sincronizado');
        return true;
    } catch (error) {
        deps.playInventoryFeedback?.('invalid');
        deps.handleError?.(error, 'Nao foi possivel desequipar este item.');
        deps.setCurrentEquipment?.(previousEquipment);
        deps.renderDockHotbar?.(previousEquipment);
        if (deps.isLeftDrawerOpen?.()) {
            renderEquipment(
                previousEquipment,
                deps.getLastCharacterStats?.() || [],
                deps.getCurrentEquipmentLinks?.() || [],
                deps.getCurrentSetBonuses?.() || []
            );
        }
        deps.consumeItemLocally?.(item.public_id);
        deps.containerDetailCache?.invalidate?.();
        deps.scheduleIdleUiSync?.(async () => {
            await refreshEquipmentOnly().catch(() => null);
            await deps.reloadContainerPanelsOnly?.().catch(() => null);
        });
        return false;
    } finally {
        bumpEquipmentSync(-1);
    }
}

export function isTwoHandedWeapon(item) {
    const hands = Number(item?.definition?.base_config?.hands || 0);
    return hands >= 2;
}

export function equipmentSlotLabel(slot) {
    const code = visualSlotCode(slot?.code);
    const labels = {
        weapon: 'Arma',
        offhand: 'Secundaria',
        helmet: 'Elmo',
        chest: 'Armadura',
        pants: 'Calca',
        boots: 'Botas',
        gloves: 'Luvas',
        belt: 'Cinto',
        ring: 'Anel',
        ring_2: 'Anel',
        amulet: 'Colar',
        wings: 'Asa',
        backpack: 'Mochila',
        pet: 'Pet',
        potion_1: 'Pocao',
        potion_2: 'Pocao',
        potion_3: 'Pocao',
        potion_4: 'Pocao',
        earring: 'Brinco',
    };
    return labels[code] || slot?.name || code;
}

function renderEquipmentSlotIconHtml(slotCode) {
    const deps = d();
    const iconUrl = equipmentSlotIconUrl(slotCode);
    if (!iconUrl) return '';
    const escapeHtml = deps.escapeHtml || ((value) => String(value ?? ''));
    return `
        <span class="inventory-equipment-slot-icon" aria-hidden="true">
            <img src="${escapeHtml(iconUrl)}" alt="" loading="lazy">
        </span>
    `;
}

export function equipmentSlotNode(slot, options = {}) {
    const deps = d();
    const escapeHtml = deps.escapeHtml || ((value) => String(value ?? ''));
    const ghostItem = options.ghostItem || null;
    const showLabel = Boolean(options.showLabel);
    const titleLabel = options.titleLabel || '';
    const displayItem = slot.item || ghostItem;
    const isGhostOccupied = Boolean(!slot.item && ghostItem);
    const node = document.createElement('article');
    const visualCode = ['weapon_offhand', 'shield', 'quiver'].includes(slot.code) ? 'offhand' : slot.code;
    const rarityClass = displayItem ? ` rarity-${deps.rarityKey?.(displayItem) || 'common'}` : '';
    node.className = `inventory-equipment-slot is-${escapeHtml(visualCode)}${displayItem ? ' has-item' : ''}${isGhostOccupied ? ' is-ghost-occupied' : ''}${rarityClass}`;
    node.dataset.equipmentSlot = slot.code;
    node.dataset.visualSlot = visualCode;
    if (titleLabel) {
        node.title = titleLabel;
    }
    const labelHtml = showLabel
        ? `<span class="inventory-equipment-slot-name">${escapeHtml(equipmentSlotLabel(slot))}</span>`
        : '';
    if (isGhostOccupied) {
        node.dataset.ghostOccupied = '1';
        node.title = 'Ocupado por arma de duas maos';
    }

    if (!displayItem) {
        node.innerHTML = `
            ${labelHtml}
            ${renderEquipmentSlotIconHtml(slot.code)}
            <span class="inventory-equipment-empty" aria-hidden="true"></span>
        `;
        return node;
    }

    node.innerHTML = `
        ${labelHtml}
        ${renderEquipmentSlotIconHtml(slot.code)}
        <div class="inventory-equipment-item-shell${isGhostOccupied ? ' is-ghost-shell' : ''}">${deps.renderItem?.(displayItem, { ghost: isGhostOccupied, hideTypeBadge: true }) || ''}</div>
    `;

    const itemNode = node.querySelector('.inventory-item');
    if (!isGhostOccupied) {
        if (itemNode && slot.item) {
            itemNode.setAttribute('draggable', 'true');
            itemNode.querySelectorAll('img').forEach((img) => {
                img.setAttribute('draggable', 'false');
            });
            itemNode.addEventListener('dragstart', (event) => {
                if (event.button != null && event.button !== 0) {
                    event.preventDefault();
                    return;
                }
                deps.setPaperdollDragState?.({
                    itemPublicId: slot.item.public_id,
                    slotCode: slot.code,
                    item: slot.item,
                });
                deps.hideAllItemTooltips?.();
                deps.closeContextMenu?.();
                node.classList.add('is-paperdoll-dragging');
                event.dataTransfer?.setData('text/evolvaxe-paperdoll', String(slot.item.public_id));
                event.dataTransfer.effectAllowed = 'move';
                document.documentElement.classList.add('is-inventory-dragging');
                document.querySelectorAll('.inventory-container').forEach((section) => {
                    section.classList.add('is-drag-target');
                });
                document.addEventListener('dragover', onPaperdollUnequipDragOver, true);
            });
            itemNode.addEventListener('dragend', async (event) => {
                document.removeEventListener('dragover', onPaperdollUnequipDragOver, true);
                const drag = deps.getPaperdollDragState?.();
                deps.setPaperdollDragState?.(null);
                node.classList.remove('is-paperdoll-dragging');
                document.documentElement.classList.remove('is-inventory-dragging');
                document.querySelectorAll('.inventory-container.is-drag-target')
                    .forEach((section) => section.classList.remove('is-drag-target'));
                deps.clearAllGhostPreviews?.();
                deps.pumpIdleUiSync?.();

                if (!drag?.itemPublicId) return;

                const overEquipment = findEquipmentSlotUnderPointer(event.clientX, event.clientY);
                if (overEquipment && String(overEquipment.dataset.equipmentSlot || '') === String(drag.slotCode)) {
                    return;
                }

                if (deps.findHotbarSlotUnderPointer?.(event.clientX, event.clientY)) return;

                const overGrid = deps.findGridUnderPointer?.(event.clientX, event.clientY);
                if (!overGrid) return;

                await unequipPaperdollItem(drag.item, {
                    clientX: event.clientX,
                    clientY: event.clientY,
                    containerPublicId: overGrid.containerPublicId,
                });
            });
        }
        itemNode?.addEventListener('contextmenu', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            await deps.openContextMenu?.(event, slot.item);
        });
        itemNode?.addEventListener('click', (event) => {
            if (!event.ctrlKey && !event.metaKey) return;
            event.preventDefault();
            event.stopPropagation();
            if (slot.item) deps.openComparePanel?.({ ...slot.item, equipped: true });
        });
        if (slot.code === 'backpack') {
            let bagToggleLockUntil = 0;
            node.addEventListener('click', (event) => {
                if (event.target.closest('.tippy-box')) return;
                if (deps.getActiveDrag?.() || deps.getPaperdollDragState?.()) return;
                const now = Date.now();
                if (now < bagToggleLockUntil) return;
                bagToggleLockUntil = now + 320;
                event.preventDefault();
                event.stopPropagation();
                deps.toggleExpeditionBag?.();
            });
        } else {
            itemNode?.addEventListener('dblclick', async (event) => {
                event.preventDefault();
                event.stopPropagation();
                await deps.openLinkedContainerForItem?.(slot.item);
            });
        }
    } else {
        itemNode?.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
    }

    if (itemNode && !isGhostOccupied) {
        deps.attachItemTooltip?.(itemNode, slot.item);
    }

    return node;
}

export function renderEquipmentSlots(equipment = []) {
    const deps = d();
    const equipmentRoot = deps.getEquipmentRoot?.();
    const stage = equipmentRoot?.querySelector?.('[data-equipment-stage]');
    if (!stage) return;

    const byCode = new Map(equipment.map((slot) => [slot.code, slot]));
    const isDrawerPaperdoll = Boolean(equipmentRoot.querySelector('.inventory-equipment-drawer-wrap'));
    const weaponSlot = byCode.get('weapon');
    const twoHandedWeapon = weaponSlot?.item && isTwoHandedWeapon(weaponSlot.item) ? weaponSlot.item : null;
    const occupiedOffhand = ['weapon_offhand', 'shield', 'quiver']
        .map((code) => byCode.get(code))
        .find((slot) => slot?.item) || null;
    const offhand = occupiedOffhand || { code: 'offhand', name: 'Offhand', item: null };
    const offhandGhost = !occupiedOffhand && twoHandedWeapon ? twoHandedWeapon : null;

    const visualSlots = isDrawerPaperdoll
        ? [
            { slot: byCode.get('pet') },
            { slot: byCode.get('helmet') },
            { slot: byCode.get('wings') },
            { slot: byCode.get('weapon') },
            { slot: byCode.get('chest') },
            { slot: offhand, ghostItem: offhandGhost },
            { slot: byCode.get('gloves') },
            { slot: byCode.get('pants') },
            { slot: byCode.get('boots') },
            { slot: byCode.get('belt') },
            { slot: byCode.get('amulet') },
            { slot: byCode.get('earring') },
            { slot: byCode.get('ring') },
            { slot: byCode.get('ring_2') },
            { slot: byCode.get('backpack') },
            { slot: byCode.get('potion_1') },
            { slot: byCode.get('potion_2') },
            { slot: byCode.get('potion_3') },
            { slot: byCode.get('potion_4') },
        ].filter((entry) => entry.slot)
        : [
            { slot: byCode.get('pet') },
            { slot: byCode.get('helmet') },
            { slot: byCode.get('wings') },
            { slot: byCode.get('weapon') },
            { slot: byCode.get('chest') },
            { slot: offhand, ghostItem: offhandGhost },
            { slot: byCode.get('gloves') },
            { slot: byCode.get('pants') },
            { slot: byCode.get('boots') },
            { slot: byCode.get('amulet') },
            { slot: byCode.get('earring') },
            { slot: byCode.get('ring') },
            { slot: byCode.get('ring_2') },
            { slot: byCode.get('backpack') },
        ].filter((entry) => entry.slot);

    for (const entry of visualSlots) {
        stage.appendChild(equipmentSlotNode(entry.slot, {
            ghostItem: entry.ghostItem || null,
            showLabel: false,
        }));
    }
}

export function renderEquipment(equipment = [], stats = [], links = [], setBonuses = []) {
    const deps = d();
    const equipmentRoot = deps.getEquipmentRoot?.();
    if (!equipmentRoot) return;

    deps.setLastCharacterStats?.(stats);
    equipmentRoot.replaceChildren();
    deps.renderDockHotbar?.(equipment);
    equipmentRoot.classList.remove('is-collapsed');
    equipmentRoot.innerHTML = `
        <div class="inventory-equipment-drawer-wrap">
            <div class="inventory-equipment-scaler">
                <div class="inventory-character-layout">
                    <div class="inventory-paperdoll">
                        <div class="inventory-character-figure" aria-hidden="true">
                            <div class="inventory-character-glow"></div>
                            <div class="inventory-character-head"></div>
                            <div class="inventory-character-body"></div>
                            <div class="inventory-character-legs"></div>
                        </div>
                        <svg class="inventory-set-beams" data-equipment-beams aria-hidden="true"></svg>
                        <div class="inventory-equipment-stage" data-equipment-stage></div>
                    </div>
                </div>
            </div>
            <section class="inventory-arpg-attributes" data-equipment-attributes aria-label="Atributos do personagem"></section>
        </div>
    `;

    if (deps.isStatsDrawerOpen?.()) {
        deps.renderCharacterStats?.(
            stats,
            setBonuses,
            deps.getPlayerPower?.(),
            deps.getStatsDrawerPanel?.()
        );
    }
    deps.renderEquipmentAttributes?.(deps.getLastPlayerHud?.());
    renderEquipmentSlots(equipment);
    window.requestAnimationFrame(() => {
        renderEquipmentLinks(links, setBonuses);
        window.requestAnimationFrame(() => renderEquipmentLinks(links, setBonuses));
    });
}

export function setGlowLevel(setCode, setBonuses = []) {
    const bonus = (setBonuses || []).find((entry) => entry.set_code === setCode);
    const pieces = Number(bonus?.equipped_pieces || 0);
    if (pieces >= 5) return 3;
    if (pieces >= 3) return 2;
    if (pieces >= 2) return 1;
    return 0;
}

export function equipmentSlotElement(slotCode) {
    const equipmentRoot = d().getEquipmentRoot?.();
    if (!equipmentRoot) return null;
    const escaped = String(slotCode).replace(/"/g, '\\"');
    const visual = visualSlotCode(slotCode).replace(/"/g, '\\"');

    return equipmentRoot.querySelector(`[data-equipment-slot="${escaped}"]`)
        || equipmentRoot.querySelector(`[data-visual-slot="${visual}"]`);
}

export function equipmentSlotCenterInPaperdoll(element, paperdoll) {
    if (!element || !paperdoll || !paperdoll.contains(element)) {
        return null;
    }

    const elementRect = element.getBoundingClientRect();
    const paperdollRect = paperdoll.getBoundingClientRect();
    if (!paperdollRect.width || !paperdollRect.height) {
        return null;
    }

    const scaleX = paperdoll.offsetWidth / paperdollRect.width;
    const scaleY = paperdoll.offsetHeight / paperdollRect.height;

    return {
        x: ((elementRect.left - paperdollRect.left) + (elementRect.width / 2)) * scaleX,
        y: ((elementRect.top - paperdollRect.top) + (elementRect.height / 2)) * scaleY,
    };
}

function rarityRgbFromSlot(element) {
    if (!element) return '170, 178, 190';
    if (element.classList.contains('rarity-unique') || element.classList.contains('rarity-divine')) return '244, 143, 177';
    if (element.classList.contains('rarity-legendary')) return '255, 176, 56';
    if (element.classList.contains('rarity-epic')) return '181, 106, 255';
    if (element.classList.contains('rarity-rare')) return '241, 205, 91';
    if (element.classList.contains('rarity-magic')) return '88, 170, 255';
    if (element.classList.contains('rarity-uncommon')) return '96, 196, 112';
    return '170, 178, 190';
}

function jaggedBoltPath(x1, y1, x2, y2, seed = 1) {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = Math.hypot(dx, dy) || 1;
    const nx = -dy / len;
    const ny = dx / len;
    const segments = Math.max(5, Math.min(9, Math.round(len / 28)));
    let d = `M ${x1.toFixed(1)} ${y1.toFixed(1)}`;
    for (let i = 1; i < segments; i += 1) {
        const t = i / segments;
        const wave = Math.sin((i * 2.15) + seed) * 0.55 + ((i + seed) % 2 === 0 ? 1 : -1) * 0.4;
        const wobble = wave * Math.min(16, len * 0.14);
        const x = x1 + dx * t + nx * wobble;
        const y = y1 + dy * t + ny * wobble;
        d += ` L ${x.toFixed(1)} ${y.toFixed(1)}`;
    }
    d += ` L ${x2.toFixed(1)} ${y2.toFixed(1)}`;
    return d;
}

function boltBranchPath(x1, y1, x2, y2, seed = 1, direction = 1) {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = Math.hypot(dx, dy) || 1;
    const nx = -dy / len;
    const ny = dx / len;
    const startT = 0.28 + ((seed * 0.13) % 0.3);
    const startX = x1 + dx * startT;
    const startY = y1 + dy * startT;
    const branchLen = Math.min(24, Math.max(10, len * 0.18));
    const endX = startX + dx / len * branchLen * 0.55 + nx * branchLen * direction;
    const endY = startY + dy / len * branchLen * 0.55 + ny * branchLen * direction;
    const midX = (startX + endX) / 2 - nx * direction * 4;
    const midY = (startY + endY) / 2 - ny * direction * 4;
    return `M ${startX.toFixed(1)} ${startY.toFixed(1)} L ${midX.toFixed(1)} ${midY.toFixed(1)} L ${endX.toFixed(1)} ${endY.toFixed(1)}`;
}

function ensureRarityFx(shell, rgb) {
    if (!shell) return;
    shell.style.setProperty('--item-rarity-rgb', rgb);
    shell.style.setProperty('--essence', rgb);

    if (!shell.querySelector(':scope > .inventory-rarity-aura')) {
        const aura = document.createElement('span');
        aura.className = 'inventory-rarity-aura';
        aura.setAttribute('aria-hidden', 'true');
        shell.appendChild(aura);
    }

    if (!shell.querySelector(':scope > .inventory-rarity-runner')) {
        const runner = document.createElement('span');
        runner.className = 'inventory-rarity-runner';
        runner.setAttribute('aria-hidden', 'true');
        shell.appendChild(runner);
    }

    shell.querySelector(':scope > .inventory-rarity-motes')?.remove();
}

export function renderEquipmentLinks(links = [], setBonuses = []) {
    const equipmentRoot = d().getEquipmentRoot?.();
    if (!equipmentRoot) return;

    const paperdoll = equipmentRoot.querySelector('.inventory-paperdoll');
    const beamLayer = equipmentRoot.querySelector('[data-equipment-beams]');
    if (!paperdoll || !beamLayer) return;

    beamLayer.replaceChildren();
    const dollW = paperdoll.offsetWidth || 1;
    const dollH = paperdoll.offsetHeight || 1;
    beamLayer.setAttribute('viewBox', `0 0 ${dollW} ${dollH}`);
    beamLayer.setAttribute('preserveAspectRatio', 'none');

    equipmentRoot.querySelectorAll('.inventory-equipment-slot.is-set-glow-1, .inventory-equipment-slot.is-set-glow-2, .inventory-equipment-slot.is-set-glow-3')
        .forEach((node) => {
            node.classList.remove('is-set-glow-1', 'is-set-glow-2', 'is-set-glow-3');
            node.style.removeProperty('--set-aura-color');
            node.querySelectorAll('.is-set-neon').forEach((child) => child.classList.remove('is-set-neon'));
            node.querySelectorAll('.inventory-set-essence, .inventory-set-motes').forEach((child) => child.remove());
        });

    // Aura + partículas na cor da raridade em todo item equipado.
    equipmentRoot.querySelectorAll('.inventory-equipment-slot.has-item').forEach((slot) => {
        const shell = slot.querySelector('.inventory-equipment-item-shell') || slot;
        ensureRarityFx(shell, rarityRgbFromSlot(slot));
    });

    let boltIndex = 0;
    for (const link of links) {
        const slots = Array.isArray(link.slots) ? link.slots : [];
        if (slots.length < 2) continue;

        const glowLevel = setGlowLevel(link.set_code, setBonuses);
        if (glowLevel <= 0) continue;
        const glowClass = `is-set-glow-${glowLevel}`;

        for (const slot of slots) {
            const element = equipmentSlotElement(slot.slot_code);
            if (!element) continue;
            element.classList.add(glowClass);
            const rgb = rarityRgbFromSlot(element);
            element.style.setProperty('--set-aura-color', `rgb(${rgb})`);
            element.style.setProperty('--essence', rgb);
            const shell = element.querySelector('.inventory-equipment-item-shell') || element;
            ensureRarityFx(shell, rgb);
            if (!shell.querySelector(':scope > .inventory-set-essence')) {
                const essence = document.createElement('span');
                essence.className = 'inventory-set-essence';
                essence.setAttribute('aria-hidden', 'true');
                shell.appendChild(essence);
            }
        }

        const points = slots
            .map((slot) => equipmentSlotElement(slot.slot_code))
            .filter(Boolean)
            .map((element) => ({
                el: element,
                pt: equipmentSlotCenterInPaperdoll(element, paperdoll),
            }))
            .filter((entry) => entry.pt);

        if (points.length < 2) continue;

        for (let index = 0; index < points.length - 1; index += 1) {
            const a = points[index];
            const b = points[index + 1];
            const dist = Math.hypot(b.pt.x - a.pt.x, b.pt.y - a.pt.y);
            if (dist < 4) continue;

            boltIndex += 1;
            const seed = boltIndex * 3.1 + glowLevel;
            const pathD = jaggedBoltPath(a.pt.x, a.pt.y, b.pt.x, b.pt.y, seed);
            const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            group.setAttribute('class', `inventory-set-bolt ${glowClass}`);
            // Conectores representam o conjunto: sempre verdes, independente da raridade.
            group.style.setProperty('--essence', '57, 255, 138');

            const halo = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            halo.setAttribute('d', pathD);
            halo.setAttribute('class', 'inventory-set-bolt-halo');
            group.appendChild(halo);

            const core = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            core.setAttribute('d', pathD);
            core.setAttribute('class', 'inventory-set-bolt-core');
            group.appendChild(core);

            const spark = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            spark.setAttribute('d', pathD);
            spark.setAttribute('class', 'inventory-set-bolt-spark');
            group.appendChild(spark);

            // Pequenas ramificações fazem o elo parecer eletricidade viva, não uma linha.
            [-1, 1].forEach((direction, branchIndex) => {
                const branch = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                branch.setAttribute(
                    'd',
                    boltBranchPath(a.pt.x, a.pt.y, b.pt.x, b.pt.y, seed + branchIndex, direction)
                );
                branch.setAttribute('class', 'inventory-set-bolt-branch');
                branch.style.animationDelay = `${(boltIndex * 0.17 + branchIndex * 0.29).toFixed(2)}s`;
                group.appendChild(branch);
            });

            // Orbs de luz viajando no raio
            [0, 0.52, 1.04].forEach((delay, orbI) => {
                const orb = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                orb.setAttribute('r', orbI === 0 ? '3.4' : '2.1');
                orb.setAttribute('class', 'inventory-set-bolt-orb');
                const motion = document.createElementNS('http://www.w3.org/2000/svg', 'animateMotion');
                motion.setAttribute('dur', glowLevel >= 3 ? '1.45s' : '2.15s');
                motion.setAttribute('repeatCount', 'indefinite');
                motion.setAttribute('path', pathD);
                motion.setAttribute('begin', `${delay + boltIndex * 0.11}s`);
                orb.appendChild(motion);
                group.appendChild(orb);
            });

            beamLayer.appendChild(group);
        }
    }
}
