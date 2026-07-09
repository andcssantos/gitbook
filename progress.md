Original prompt: me apareceu isso. Acho que tem algo que se faz no js do gridstack para ele renderizar o html e não considerar como texto. Acho que no meu code tem algo assim. Não gerou erros, perfeito. Mas os itens ainda não se movimentam, não sei se ainda é um próximo passo. A outra IA adicionou outras tasks de inventário. Nosso trabalho é deixar o inventário pronto em todos os sentidos possíveis, funções, antes de continuar. Pode ser? O que acha?

Notes:
- GridStack 12 defaults to rendering widget content as textContent. The old prototype used GridStack.renderCB with innerHTML.
- Inventory should be completed as a backend-authoritative foundation before moving to crafting, marketplace, expeditions, or game loops.
- `allow_container_items` means whether container-items can be placed inside, not whether normal items can move. The frontend should not turn a grid static from that flag.
- Browser check passed after the fix: item HTML renders as real markup, no console errors, GridStack item is draggable, and a drag updated a widget from y=0 to y=2 with status "Sincronizado".

Next inventory foundation candidates:
- Implement TASK-005 stack merge/split before crafting or loot.
- Implement TASK-007 auto-placement/free-space scan before expedition rewards.
- Add frontend affordances for rejected moves, locked containers, split stack modal, merge hints, and item action menus after backend services exist.
- TASK-006 implemented: container_acceptance_rules table, seeded initial rules, ContainerAcceptanceService, validator integration, tests, and report.
- TASK-005 implemented: stack merge/split DTOs, services, compatibility checks, quality/composition calculators, endpoints, route tests, service tests, and report.
