# Evolução dos Sistemas de Itens e Inventário — Evolvaxe

Documento vivo de decisão e implementação. Atualizar a cada fase concluída ou nova ideia validada.

**Última atualização:** 2026-07-10  
**Fase ativa:** Fase 4 — Economia (pendente)

---

## Visão Geral — Estado vs. Gaps

| Épico | Estado atual | Maturidade | Próximo passo |
|---|---|---|---|
| A — Progressão de Itens | Ranges, caps bless, bônus item, chaos proporcional | ~75% | Affix reroll no chaos (debate D2) |
| B — UI/UX de Itens | Tooltip rico, comparação inline, poder, estrelas | ~80% | Fase 3 polish |
| C — Inventário GridStack | Drag/drop, Expedition Carry 6×4, containers | ~85% | Fase 4 polish |
| D — Economia / mercado | Schema preparado, sem precificação | ~10% | Fase 4 |
| E — Investigação / desmanche | INSPECT → toast, composição no schema | ~5% | Fase 5 |
| F — Ideias extras | Catalogadas abaixo | — | Debate contínuo |

---

## Decisões Registradas

| # | Tema | Decisão | Data |
|---|---|---|---|
| D1 | Ranges de stats (Fase 1) | Fórmula por `quality_value` + raridade + categoria; caps por nível bless (+1…+25) | 2026-07-10 |
| D2 | Chaos reroll de affixes | **Fase 1:** affixes existentes mantidos; apenas base stats recalculados proporcionalmente | 2026-07-10 |
| D3 | Abertura de baús | Pendente — candidata: stack + split (A+C) | — |
| D4 | Inventário principal 12 colunas | **Fase 3:** migrado para 12×5 | 2026-07-10 |
| D5 | Materiais separados | Pendente — recomendado inventário por abas (Fase 5) | — |
| D6 | Moeda premium | Pendente — antes da Fase 4 | — |

---

## Decisões Pendentes para Debate (pós-Fase 2)

Estas decisões **não bloqueiam** a Fase 2. Registrar aqui para sessão dedicada de design.

### D2 — Chaos: reroll de affixes ao subir raridade?

| Opção | Prós | Contras |
|---|---|---|
| **A) Manter affixes** (atual Fase 1) | Previsível; não frustra builds | Itens “presos” em affixes fracos |
| **B) Reroll parcial** | Meio-termo; sobe raridade com novidade | Mais RNG |
| **C) Reroll total** | Máxima variância estilo PoE | Pode piorar item bom |

**Pergunta:** chaos em item raro+ deve rerolar affixes existentes ou só adicionar novos até o target da raridade?

---

### D3 — Abertura de baús/bags aninhados

| Opção | Prós | Contras |
|---|---|---|
| **A) Stack + breadcrumb** | Simples; um drawer | Estreito em bags 2×2 |
| **B) Modal flutuante** | Espaço; familiar | Dois contextos de drag |
| **C) Split view** | Melhor gestão visual | Mais complexo |
| **A+C híbrido** (recomendado no plano) | Breadcrumb + split ao abrir baú | Implementação média |

**Pergunta:** drag entre painel pai e filho no mesmo drawer é obrigatório no MVP?

---

### D4 — Inventário principal: migrar 8×5 → 12×N?

| Opção | Prós | Contras |
|---|---|---|
| **A) Migrar agora** | Alinha com Diablo/POE; Fase 3 mais limpa | Seed + testes + DB existentes |
| **B) Manter 8×5 até Fase 3** | Sem breaking change | Layout híbrido temporário |
| **C) 12 colunas só no frontend** | Visual correto sem migration | Backend ainda 8×5 |

**Pergunta:** jogadores existentes podem ter itens em x≥8 que precisam re-posicionar?

---

### D5 — Materiais: inventário separado ou slots no principal?

| Opção | Prós | Contras |
|---|---|---|
| **A) Abas automáticas (stash)** | Padrão PoE/D4; sem bagunça | Novo sistema + atalho `M` |
| **B) Slots no inventário** | Um só grid | Enche rápido; UX ruim |
| **C) Híbrido** | Materiais em stash; resto no grid | Dois sistemas para aprender |

**Recomendação documentada:** opção A na Fase 5.

---

### D6 — Moeda premium (marketplace P2P)

| Tópico | Opções a definir |
|---|---|
| Nome | Cristais / Essência / Platinum / outro |
| Aquisição | Drop raro, craft, login, evento, compra real |
| Taxa de listagem | % fixa vs. flat vs. escala por preço |
| Conversão | Premium ↔ ouro? One-way? |

---

### D7 — Expedition Carry: evoluções opcionais (Fase 3+)

- Peso/capacidade além de slots?
- Quick-access 4 slots de consumível no bolso?
- Durabilidade da mochila na expedição?
- Hotkeys 1–4 para consumíveis do bolso?

---

### D8 — Comparação avançada (Fase 2+ / polish)

- Build tags (“Melhor para DPS”) — regras heurísticas ou ML?
- Set impact ao desequipar (“quebra bônus 3/5”) — precisa meta de sets no tooltip
- Comparar dois itens do inventário (não só vs. equipado)

---

### D9 — Ranges de stats: tabela fixa vs. fórmula

| Opção | Estado |
|---|---|
| Fórmula contínua (`ItemStatRangeService`) | ✅ Fase 1 |
| Tabela `item_stat_ranges` por tipo/raridade | Pendente — overrides por item lendário |

**Pergunta:** quando introduzir tabela no DB — Fase 2 polish ou Fase 3?

---

## Fases de Implementação

### FASE 1 — Fundação de Stats ✅ concluída

**Épico A — pontos 1, 2, 14 (parcial)**

| Item | Status | Notas |
|---|---|---|
| `ItemStatRangeService` — ranges por raridade/tipo/quality | ✅ | Fórmulas + caps bless |
| Bless inclui strength/defense/vitality | ✅ | 8 stats elegíveis |
| Caps por nível bless (+1…+25) | ✅ | 0–20% / 20–60% / 60–100% do range |
| Bônus de sucesso por item/affix | ✅ | `ItemEnhancementBonusService` + affix `masterwork` |
| Preview com decomposição de taxa | ✅ | `success_rate_breakdown` na API |
| Chaos recalcula base stats + quality_value | ✅ | Preserva % dentro do range |
| Comparação equipado vs hover (evolução) | ✅ | Delta verde/vermelho no tooltip |
| Poder por item + poder total | ✅ | `ItemPowerService` + painel |

**Migration:** `php bin/gb migrate` → `2026_07_10_000017_item_stat_range_foundation.php`

---

### FASE 2 — UI Core ✅ concluída

**Épico B — pontos 3, 4, 5, 11, 14**

| Item | Status | Notas |
|---|---|---|
| Tooltip condicional por `category_code` | ✅ | Hero 64px, seções por tipo, bloco de set |
| `category_code` na API de inventário | ✅ | `InventoryStateService` |
| Modais no design system (sem `confirm`) | ✅ | `confirmInventoryAction` + tema dark |
| SVG set glow por completude | ✅ | CSS `is-set-glow-1/2/3` |
| Ícones de tipo nos grid cells | ✅ | Badge canto do item |
| Comparar Ctrl+click (equipáveis) | ✅ | Painel flutuante lado a lado |
| Build tags / trade-offs | ⏳ | Pós-debate D8 |

---

### FASE 3 — Inventário v2 ✅ concluída

**Épico C — pontos 6–10, 12, 13**

| Item | Status | Notas |
|---|---|---|
| Grid 12 colunas responsivo | ✅ | Drawer direito 12×N, sem scroll horizontal |
| Drawers E/I + Esc/Tab | ✅ | Esq: equipamento + expedição · Dir: inventário + baús |
| Nested containers (baú → bag, max 2 níveis) | ✅ | `ContainerNestingService` + baú aceita containers |
| Rename inline de baús/bags | ✅ | Duplo clique no título + `PATCH /containers/{id}/rename` |
| Badges acceptance + borda por tipo | ✅ | Ícones + tooltip + `tone` no header |
| Auto-organize (5 modos) | ✅ | tipo/raridade/tamanho/nome/compactar |
| Split view ao abrir baú | ✅ | Main + chest lado a lado no drawer direito |
| Breadcrumb `parent_chain` | ✅ | Snapshot API |
| Expedition: peso/quick-access | ⏳ | Debate D7 — hotkeys 1-4 apenas |

**Migration:** `php bin/gb migrate` → `2026_07_10_000018_inventory_v2_foundation.php`

---

### FASE 4 — Economia (pendente)

**Épico D — pontos 15, 16**

- Engine de precificação dinâmica
- Venda NPC (ouro) + Marketplace P2P (moeda premium)
- Preços reais no tooltip

---

### FASE 5 — Lifecycle (pendente)

**Épico E — pontos 17, 18**

- Tela de investigação completa
- Desmanche + yield
- Inventário de materiais por abas

---

## ÉPICO A — Progressão de Itens (detalhe)

### 1) Stats base + bless + ranges

**Implementado (Fase 1):**
```
Stat efetivo = min(valor_proposto, cap_por_nivel_bless)
cap = min_range + (max_range - min_range) × consumo_por_nivel(+N)

consumo_por_nivel:
  +1…+5   → 0–20% do range
  +6…+15  → 20–60%
  +16…+25 → 60–100%
```

**Pendente pós-Fase 1:**
- Tabela `item_stat_ranges` no DB (override por tipo específico)
- Diminishing returns mais agressivos no tier +16…+25

### 2) Taxa de sucesso

**Implementado (Fase 1):**
```
Taxa_final = taxa_joia × decay(nível²) × (1 + bônus_item%)
Preview expõe: base_rate, decay_multiplier, after_decay, item_bonus_percent, final_rate
```

**Pendente:** set bonus, buff temporário, affix Masterwork craftável

### 14) Comparação equipado vs desequipado

**Hoje:** poder, stats base, affixes com delta colorido.

**Evolução (Fase 2+):** build tags, set impact, painel lado a lado.

---

## ÉPICO B — UI/UX (detalhe)

Ver plano original — tooltip reorganizado, modais, SVG glow, ícones de tipo.

**Adições recentes (pré-Fase 2):**
- Estrelas de upgrade nos slots equipados (1–5)
- Painel de poder: Ataque / Armadura / Vida / Total
- Tooltips com linha pontilhada e faixas `[min - max]`

---

## ÉPICO C — Inventário e Containers (detalhe)

Arquitetura drawer E/I, hierarquia de containers, grid 12 colunas — ver plano original.

**Correção aplicada (2026-07-10):** Expedition Carry sincroniza mochila equipada no load (6×4).

---

## ÉPICO D — Economia (detalhe)

Fórmula de preço dinâmico, NPC vs P2P — ver plano original. Sem implementação.

---

## ÉPICO E — Investigação e Desmanche (detalhe)

Tela de investigação, inventário de materiais por abas — ver plano original.

---

## ÉPICO F — Ideias Extras (catálogo)

### Gameplay e Progressão
| ID | Ideia |
|---|---|
| F1 | Item locking |
| F2 | Favoritos / Wishlist de affixes |
| F3 | Item history log |
| F4 | Transmog / Skin |
| F5 | Sets evolutivos por raridade média |
| F6 | Curse/blessing aleatório |
| F7 | Reforge (reroll 1 affix) |
| F8 | Awakening pós-+25 |

### Inventário e UX
| ID | Ideia |
|---|---|
| F9 | Filtro visual no grid |
| F10 | Busca por nome/affix |
| F11 | Multi-select + bulk actions |
| F12 | Container templates |
| F13 | Quick-move por categoria |
| F14 | Ghost preview em slot vazio |
| F15 | Expedition loadout salvo |

### Economia
| ID | Ideia |
|---|---|
| F16 | Auction house |
| F17 | Price alerts |
| F18 | Market analytics |
| F19 | Bulk sell NPC |
| F20 | Trade window P2P |

### Crafting (futuro)
| ID | Ideia |
|---|---|
| F21 | Forja |
| F22 | Alquimia |
| F23 | Salvage bonus affix |
| F24 | Crafting quality por skill |
| F25 | Blueprint discovery |

### Novas (2026-07-10)
| ID | Ideia |
|---|---|
| F26 | **Bless preview visual** — barra de chance com segmentos coloridos (base/decay/bônus) |
| F27 | **Range bar no tooltip** — barra mostrando onde o roll atual está no range |
| F28 | **Cap warn** — aviso quando stat está a 95%+ do cap do nível bless |

---

## Changelog do Documento

| Data | Alteração |
|---|---|
| 2026-07-10 | Documento criado a partir do plano organizado; Fase 1 iniciada |
| 2026-07-10 | Decisões D1/D2 registradas; itens F26–F28 adicionados |
| 2026-07-10 | **Fase 1 concluída** — ranges, caps bless, bônus masterwork, chaos proporcional, 148 testes OK |
| 2026-07-10 | **Fase 2 concluída** — tooltip por tipo, modais dark, set glow, badges, compare Ctrl+click; decisões D2–D9 documentadas para debate |
| 2026-07-10 | **Fase 3 concluída** — grid 12×5, nesting max 2, split baú, organize, rename, atalhos E/I/1-4; 150 testes OK |
