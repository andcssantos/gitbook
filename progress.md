Original prompt: me apareceu isso. Acho que tem algo que se faz no js do gridstack para ele renderizar o html e não considerar como texto. Acho que no meu code tem algo assim. Não gerou erros, perfeito. Mas os itens ainda não se movimentam, não sei se ainda é um próximo passo. A outra IA adicionou outras tasks de inventário. Nosso trabalho é deixar o inventário pronto em todos os sentidos possíveis, funções, antes de continuar. Pode ser? O que acha?

Notes:
- GridStack 12 defaults to rendering widget content as textContent. The old prototype used GridStack.renderCB with innerHTML.
- Inventory should be completed as a backend-authoritative foundation before moving to crafting, marketplace, expeditions, or game loops.
- `allow_container_items` means whether container-items can be placed inside, not whether normal items can move. The frontend should not turn a grid static from that flag.
- Browser check passed after the fix: item HTML renders as real markup, no console errors, GridStack item is draggable, and a drag updated a widget from y=0 to y=2 with status "Sincronizado".

Completed inventory foundation:
- TASK-005 implemented: stack merge/split.
- TASK-006 implemented: container acceptance rules.
- TASK-007 implemented: auto-placement, dev grant endpoint, Ctrl+Click split, drag-over merge.
- TASK-008 implemented: item action definitions/rules, available-actions endpoint, execute DISCARD/INSPECT/OPEN, tests, report.
- TASK-013 implemented: context menu UI wired to item actions API, cross-container drag validation fix, report.
- TASK-009 implemented: physical container auto-link for container items, grant/starter integration, tests, report.
- TASK-010 implemented: inventory summary/container/item query API, linked metadata in snapshot, rotated on move route, tests, report.
- TASK-011 implemented: occupancy badges, physical bag navigation, durability bar, summary header, report.

Inventory UX fixes after TASK-007:
- GridStack `float: true` keeps items at the exact cell where dropped (SCUM-style free placement); `float: false` was compacting widgets upward.
- During drag the widget snaps to the pointer cell; drop uses `hoverX/hoverY` with pointer fallback on dragstop.
- Other inventory items are frozen during drag via restore-on-change.
- Drop/merge uses intended drag coordinates (`lastX/lastY`) and a frozen snapshot of other items, not GridStack's post-collision jump.
- Drop on physical backpack item auto-deposits into linked Small Backpack when space exists (blue preview).
- Merge only when exactly one compatible stack overlaps the drop footprint (works in both directions).
- Fixed drag ReferenceError (`size` before init) that froze items at top-left.
- Drop preserves preview coordinates; dragstop no longer overwrites hover cell with fallback 0,0.
- Rotation preview swaps footprint during drag; other items never move aside.
- Successful move/merge always reloads inventory from server to avoid visual desync.
- Main inventory acceptance fixed: backpacks and container items can move inside main inventory again.
- Nesting inside backpacks/chests/market containers remains blocked by `CONTAINER_BLOCK`.

Important migrations to run on existing DB:
- `2026_07_09_000005_fix_main_inventory_container_acceptance.php`
- `2026_07_09_000006_create_item_action_tables.php`

Frontend shortcuts in `public/views/app/dashboard/inventory/assets/script/main.js`:
- Ctrl+Click / Cmd+Click: split half quantity.
- Drag over compatible stack: merge.
- Right-click: item context menu (DISCARD, INSPECT, OPEN from server).
- Hold click + R: rotate while dragging (SCUM-style toggle for non-square items).
- Cross-container drop requires pointer inside target grid; dropping on backpack item in parent grid is blocked with guidance.

API highlights:
- `GET /api/inventory`
- `GET /api/inventory/summary`
- `GET /api/inventory/containers/{containerPublicId}`
- `GET /api/inventory/items/{itemPublicId}`
- `GET /api/items/{itemPublicId}/actions`
- `POST /api/items/{itemPublicId}/actions/{actionCode}` with optional `{ "confirm": true }`
- `POST /api/dev/inventory/grant-item` (dev/local only)

Validation:
- composer test: OK, 104 tests, 387 assertions
- composer analyse: OK

Next inventory candidates:
- Backpack equip/unequip flow.
- Partial inventory refresh in frontend.
- Icons/search/filter polish.
- Ghost placement preview overlay (SCUM-style cell highlight).

Do not start crafting, marketplace, expeditions, or game loops until inventory backend + core frontend affordances are complete.
