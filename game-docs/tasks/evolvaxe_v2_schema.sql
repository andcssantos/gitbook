-- Evolvaxe V2 Schema
-- Modelo para jogo web session-based procedural craft economy RPG
-- Compatível com MySQL 8+ / MariaDB 10.4+ com InnoDB.
-- Nomes em inglês; comentários em português.

SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS evolvaxe_v2;
CREATE DATABASE evolvaxe_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE evolvaxe_v2;

-- =========================================================
-- 1) AUTH / PLAYERS
-- =========================================================

CREATE TABLE accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificador interno da conta.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público para APIs e frontend.',
  display_name VARCHAR(80) NOT NULL COMMENT 'Nome público da conta.',
  email VARCHAR(160) NOT NULL COMMENT 'E-mail único da conta.',
  password_hash VARCHAR(255) NOT NULL COMMENT 'Hash Argon2i/Argon2id da senha; nunca armazenar senha reversível.',
  status ENUM('pending','active','suspended','deleted') NOT NULL DEFAULT 'active' COMMENT 'Status operacional da conta.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data da última atualização.',
  deleted_at DATETIME NULL COMMENT 'Soft delete lógico.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_public_id (public_id),
  UNIQUE KEY uq_accounts_email (email),
  KEY idx_accounts_status (status)
) ENGINE=InnoDB COMMENT='Contas de acesso dos usuários.';

CREATE TABLE players (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificador interno do personagem/jogador.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público do jogador.',
  account_id BIGINT UNSIGNED NOT NULL COMMENT 'Conta proprietária do jogador.',
  name VARCHAR(40) NOT NULL COMMENT 'Nome único do personagem.',
  avatar_key VARCHAR(80) NULL COMMENT 'Chave do avatar/sprite usado no frontend.',
  level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível geral do jogador.',
  experience BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Experiência geral acumulada.',
  base_expedition_seconds INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Tempo base das expedições, em segundos.',
  status ENUM('active','disabled','deleted') NOT NULL DEFAULT 'active' COMMENT 'Status do jogador.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data da última atualização.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_players_public_id (public_id),
  UNIQUE KEY uq_players_name (name),
  KEY idx_players_account (account_id),
  CONSTRAINT fk_players_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Personagens jogáveis. Cada player possui mundos e inventários próprios.';

CREATE TABLE player_stats (
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador dono dos atributos.',
  strength INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Força: dano físico e requisitos.',
  agility INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Agilidade: velocidade/ataque/esquiva.',
  vitality INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Vitalidade: vida máxima e resistência.',
  intelligence INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Inteligência: crafting, bônus mágicos e análise.',
  luck INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Sorte: pequenas influências em descoberta/loot.',
  current_hp INT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Vida atual.',
  max_hp INT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Vida máxima.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data da última atualização.',
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_stats_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Atributos básicos do jogador.';

-- =========================================================
-- 2) WORLD MAPS / POINTS OF INTEREST / EXPEDITIONS
-- =========================================================

CREATE TABLE world_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Template de mundo estático exibido ao jogador.',
  code VARCHAR(60) NOT NULL COMMENT 'Código único do mundo, usado pela aplicação.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome do mundo.',
  description TEXT NULL COMMENT 'Descrição do mundo.',
  min_player_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível mínimo recomendado/exigido.',
  visual_asset_key VARCHAR(120) NULL COMMENT 'Imagem do mapa estático, como a ilha/mundo base.',
  base_poi_count TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Quantidade base de pontos de interesse gerados por rotação.',
  reset_interval_minutes INT UNSIGNED NOT NULL DEFAULT 360 COMMENT 'Intervalo padrão para resetar pontos de interesse.',
  status ENUM('draft','active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status do mundo.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data da última atualização.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_world_templates_code (code),
  KEY idx_world_templates_level (min_player_level, status)
) ENGINE=InnoDB COMMENT='Mundos estáticos com pontos de interesse temporários.';

CREATE TABLE player_worlds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Instância do mundo para um jogador.',
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador dono do mundo.',
  world_template_id BIGINT UNSIGNED NOT NULL COMMENT 'Template do mundo.',
  world_seed BIGINT UNSIGNED NOT NULL COMMENT 'Seed persistente usada para gerar POIs do jogador neste mundo.',
  world_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível de progressão do jogador neste mundo.',
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Quando o mundo foi aberto para o jogador.',
  last_poi_reset_at DATETIME NULL COMMENT 'Último reset de pontos de interesse.',
  next_poi_reset_at DATETIME NULL COMMENT 'Próximo reset de pontos de interesse.',
  status ENUM('active','locked','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status do mundo para o jogador.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_player_world (player_id, world_template_id),
  KEY idx_player_worlds_seed (world_seed),
  KEY idx_player_worlds_reset (next_poi_reset_at),
  CONSTRAINT fk_player_worlds_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_worlds_template FOREIGN KEY (world_template_id) REFERENCES world_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Mundos personalizados por jogador, com seed própria.';

CREATE TABLE biome_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificador do bioma.',
  code VARCHAR(60) NOT NULL COMMENT 'Código único do bioma.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido do bioma.',
  description TEXT NULL COMMENT 'Descrição do bioma.',
  visual_asset_key VARCHAR(120) NULL COMMENT 'Imagem/fundo do submapa.',
  difficulty_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Dificuldade base do bioma.',
  config JSON NULL COMMENT 'Configuração do bioma: densidade de monstros, árvores, rochas, baús etc.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status do bioma.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_biome_code (code),
  KEY idx_biome_difficulty (difficulty_level, status)
) ENGINE=InnoDB COMMENT='Definições de biomas usados em pontos de interesse e submapas.';

CREATE TABLE point_of_interest_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de tipo de ponto de interesse.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único do tipo de POI.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome do ponto de interesse.',
  description TEXT NULL COMMENT 'Descrição exibida.',
  biome_id BIGINT UNSIGNED NOT NULL COMMENT 'Bioma principal do POI.',
  min_world_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível mínimo do mundo para aparecer.',
  base_duration_seconds INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Duração base da expedição.',
  base_spawn_weight DECIMAL(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'Peso base para geração aleatória.',
  rarity_weight DECIMAL(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'Peso de raridade do POI; menor = mais raro.',
  config JSON NULL COMMENT 'Configuração do submapa: tamanho, densidade e regras específicas.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status da definição.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_poi_definition_code (code),
  KEY idx_poi_def_world_level (min_world_level, status),
  KEY idx_poi_def_biome (biome_id),
  CONSTRAINT fk_poi_def_biome FOREIGN KEY (biome_id) REFERENCES biome_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Tipos possíveis de pontos de interesse temporários.';

CREATE TABLE player_points_of_interest (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'POI gerado para um jogador.',
  player_world_id BIGINT UNSIGNED NOT NULL COMMENT 'Mundo do jogador onde o POI aparece.',
  poi_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Definição usada.',
  poi_seed BIGINT UNSIGNED NOT NULL COMMENT 'Seed própria do POI para gerar submapa.',
  map_x DECIMAL(7,4) NOT NULL COMMENT 'Posição X percentual/normalizada no mapa estático.',
  map_y DECIMAL(7,4) NOT NULL COMMENT 'Posição Y percentual/normalizada no mapa estático.',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Quando foi gerado.',
  expires_at DATETIME NOT NULL COMMENT 'Quando expira caso não seja explorado.',
  explored_at DATETIME NULL COMMENT 'Quando foi consumido/explorado.',
  status ENUM('available','in_progress','explored','expired') NOT NULL DEFAULT 'available' COMMENT 'Estado do POI.',
  PRIMARY KEY (id),
  KEY idx_player_poi_world_status (player_world_id, status, expires_at),
  KEY idx_player_poi_definition (poi_definition_id),
  KEY idx_player_poi_seed (poi_seed),
  CONSTRAINT fk_player_poi_world FOREIGN KEY (player_world_id) REFERENCES player_worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_poi_definition FOREIGN KEY (poi_definition_id) REFERENCES point_of_interest_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Pontos de interesse temporários gerados por seed para cada jogador.';

CREATE TABLE expedition_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Execução de uma entrada em submapa temporário.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público da expedição.',
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador da expedição.',
  player_poi_id BIGINT UNSIGNED NOT NULL COMMENT 'POI consumido pela expedição.',
  run_seed BIGINT UNSIGNED NOT NULL COMMENT 'Seed da sessão, derivada do POI e contador.',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Início da expedição.',
  scheduled_end_at DATETIME NOT NULL COMMENT 'Fim programado conforme tempo disponível.',
  ended_at DATETIME NULL COMMENT 'Fim real da expedição.',
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Duração concedida.',
  result ENUM('running','completed','escaped','failed','timeout','abandoned') NOT NULL DEFAULT 'running' COMMENT 'Resultado da expedição.',
  snapshot JSON NULL COMMENT 'Snapshot leve do mapa gerado, apenas quando necessário para auditoria/debug.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_expedition_public_id (public_id),
  KEY idx_expedition_player_status (player_id, result, started_at),
  KEY idx_expedition_poi (player_poi_id),
  CONSTRAINT fk_expedition_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_expedition_poi FOREIGN KEY (player_poi_id) REFERENCES player_points_of_interest(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Sessões temporárias de exploração dentro de um ponto de interesse.';

CREATE TABLE expedition_entities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entidade gerada dentro de uma expedição.',
  expedition_run_id BIGINT UNSIGNED NOT NULL COMMENT 'Expedição dona da entidade.',
  entity_type ENUM('resource_node','monster','chest','event') NOT NULL COMMENT 'Tipo de entidade.',
  definition_id BIGINT UNSIGNED NOT NULL COMMENT 'ID da definição correspondente ao tipo.',
  entity_seed BIGINT UNSIGNED NOT NULL COMMENT 'Seed individual da entidade.',
  pos_x DECIMAL(8,3) NOT NULL COMMENT 'Posição X no submapa.',
  pos_y DECIMAL(8,3) NOT NULL COMMENT 'Posição Y no submapa.',
  state ENUM('active','depleted','killed','opened','expired') NOT NULL DEFAULT 'active' COMMENT 'Estado atual.',
  current_hp DECIMAL(12,3) NULL COMMENT 'Vida atual, quando aplicável.',
  quality_potential DECIMAL(6,3) NULL COMMENT 'Potencial de qualidade do recurso gerado.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de geração.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última alteração.',
  PRIMARY KEY (id),
  KEY idx_expedition_entities_run_type (expedition_run_id, entity_type, state),
  KEY idx_expedition_entities_definition (entity_type, definition_id),
  CONSTRAINT fk_expedition_entities_run FOREIGN KEY (expedition_run_id) REFERENCES expedition_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Árvores, rochas, moitas, baús e monstros gerados em uma expedição temporária.';

-- =========================================================
-- 3) RESOURCE SOURCES / MONSTERS / LOOT
-- =========================================================

CREATE TABLE resource_source_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de fonte de recurso.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único da fonte.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido.',
  source_type ENUM('tree','rock','bush','ore_vein','plant','structure') NOT NULL COMMENT 'Tipo da fonte.',
  required_tool_type ENUM('hand','axe','pickaxe','knife','hammer','none') NOT NULL DEFAULT 'hand' COMMENT 'Ferramenta exigida.',
  min_tool_tier INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tier mínimo de ferramenta.',
  base_hp DECIMAL(12,3) NOT NULL DEFAULT 10.000 COMMENT 'Vida/durabilidade da fonte.',
  extraction_difficulty DECIMAL(12,3) NOT NULL DEFAULT 1.000 COMMENT 'Dificuldade de extração.',
  loot_table_id BIGINT UNSIGNED NULL COMMENT 'Tabela de recursos gerados.',
  config JSON NULL COMMENT 'Configurações de animação, respawn visual e variações.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_resource_source_code (code),
  KEY idx_resource_source_type (source_type, status),
  KEY idx_resource_source_loot (loot_table_id)
) ENGINE=InnoDB COMMENT='Definições de árvores, rochas, moitas e outras fontes de recurso.';

CREATE TABLE monster_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de monstro.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único do monstro.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido.',
  family VARCHAR(60) NOT NULL COMMENT 'Família: beast, undead, golem etc.',
  min_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível mínimo.',
  base_hp DECIMAL(12,3) NOT NULL DEFAULT 10.000 COMMENT 'Vida base.',
  base_attack DECIMAL(12,3) NOT NULL DEFAULT 1.000 COMMENT 'Ataque base.',
  base_defense DECIMAL(12,3) NOT NULL DEFAULT 0.000 COMMENT 'Defesa base.',
  movement_speed DECIMAL(8,3) NOT NULL DEFAULT 1.000 COMMENT 'Velocidade no submapa.',
  loot_table_id BIGINT UNSIGNED NULL COMMENT 'Tabela de loot biológico/material.',
  config JSON NULL COMMENT 'IA, alcance, comportamento e variações.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_monster_code (code),
  KEY idx_monster_level_family (min_level, family, status),
  KEY idx_monster_loot (loot_table_id)
) ENGINE=InnoDB COMMENT='Definições de monstros usados como fontes ativas de recursos.';

CREATE TABLE chest_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de baú escondido.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único do baú.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido.',
  min_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível mínimo.',
  unlock_rule ENUM('free','requires_key','requires_tool','requires_stat') NOT NULL DEFAULT 'free' COMMENT 'Regra de abertura.',
  loot_table_id BIGINT UNSIGNED NULL COMMENT 'Tabela de loot do baú.',
  config JSON NULL COMMENT 'Configuração visual e de dificuldade.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_chest_code (code),
  KEY idx_chest_level (min_level, status),
  KEY idx_chest_loot (loot_table_id)
) ENGINE=InnoDB COMMENT='Definições de baús escondidos em submapas.';

CREATE TABLE loot_tables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Tabela de loot genérica.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único da tabela.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome interno/administrativo.',
  description TEXT NULL COMMENT 'Descrição do uso da tabela.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_loot_table_code (code)
) ENGINE=InnoDB COMMENT='Tabelas de loot para recursos, monstros e baús.';

CREATE TABLE loot_table_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entrada individual de uma tabela de loot.',
  loot_table_id BIGINT UNSIGNED NOT NULL COMMENT 'Tabela relacionada.',
  item_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Item/material que pode ser gerado.',
  min_quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade mínima.',
  max_quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade máxima.',
  drop_chance DECIMAL(8,5) NOT NULL DEFAULT 1.00000 COMMENT 'Chance base entre 0 e 1.',
  min_tool_tier INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tier mínimo da ferramenta para rolar este drop.',
  is_rare TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indica se entra em modificadores de descoberta rara.',
  quality_modifier DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Modificador de qualidade do material gerado.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  KEY idx_loot_entries_table (loot_table_id, status),
  KEY idx_loot_entries_item (item_definition_id),
  CONSTRAINT fk_loot_entries_table FOREIGN KEY (loot_table_id) REFERENCES loot_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Entradas de drops, com chance, quantidade e tier mínimo.';

-- =========================================================
-- 4) ITEMS / MATERIALS / PROPERTIES
-- =========================================================

CREATE TABLE item_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Categoria principal do item.',
  code VARCHAR(60) NOT NULL COMMENT 'Código único da categoria.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_category_code (code)
) ENGINE=InnoDB COMMENT='Categorias: material, weapon, armor, tool, consumable, currency etc.';

CREATE TABLE material_families (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Família material.',
  code VARCHAR(60) NOT NULL COMMENT 'Código único da família.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido.',
  influence_config JSON NULL COMMENT 'Pesos de influência em atributos de crafting.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_material_family_code (code)
) ENGINE=InnoDB COMMENT='Famílias de materiais como iron, wood, leather, herb, crystal.';

CREATE TABLE material_origins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Origem material.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único da origem.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome exibido.',
  biome_id BIGINT UNSIGNED NULL COMMENT 'Bioma associado, quando aplicável.',
  influence_config JSON NULL COMMENT 'Tendências de propriedades associadas à origem.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_material_origin_code (code),
  KEY idx_material_origin_biome (biome_id),
  CONSTRAINT fk_material_origin_biome FOREIGN KEY (biome_id) REFERENCES biome_definitions(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Origem dos materiais: floresta, campo rochoso, ruína antiga etc.';

CREATE TABLE item_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Molde/base do item, não a instância única.',
  code VARCHAR(100) NOT NULL COMMENT 'Código único do item base.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome base exibido.',
  description TEXT NULL COMMENT 'Descrição base.',
  category_id BIGINT UNSIGNED NOT NULL COMMENT 'Categoria do item.',
  material_family_id BIGINT UNSIGNED NULL COMMENT 'Família material, se for material.',
  stackable TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se pode empilhar.',
  max_stack INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade máxima por stack.',
  grid_w TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Largura padrão no GridStack.',
  grid_h TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Altura padrão no GridStack.',
  equip_slot_code VARCHAR(40) NULL COMMENT 'Slot onde equipa, se for equipamento.',
  base_config JSON NULL COMMENT 'Ranges base, propriedades possíveis e configurações.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_definition_code (code),
  KEY idx_item_def_category (category_id, status),
  KEY idx_item_def_family (material_family_id),
  CONSTRAINT fk_item_def_category FOREIGN KEY (category_id) REFERENCES item_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_def_family FOREIGN KEY (material_family_id) REFERENCES material_families(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Definições/moldes de itens. Itens reais ficam em item_instances.';

CREATE TABLE item_property_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de propriedade/atributo de item.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único da propriedade.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome exibido.',
  value_type ENUM('integer','decimal','percent','text','boolean') NOT NULL DEFAULT 'decimal' COMMENT 'Tipo de valor.',
  polarity ENUM('positive','negative','neutral') NOT NULL DEFAULT 'neutral' COMMENT 'Se a propriedade tende a ser boa, ruim ou neutra.',
  applies_to JSON NULL COMMENT 'Categorias/tipos de itens onde pode aparecer.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_property_code (code)
) ENGINE=InnoDB COMMENT='Propriedades possíveis: dano, crítico, durabilidade, extração, resistência etc.';

CREATE TABLE item_instances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Instância real do item. Pode ser stack material ou equipamento único.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público da instância.',
  item_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Molde/base do item.',
  owner_player_id BIGINT UNSIGNED NULL COMMENT 'Dono atual, nulo se estiver em marketplace/estado sistêmico.',
  quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade no stack; equipamentos únicos usam 1.',
  quality_value DECIMAL(7,3) NULL COMMENT 'Qualidade numérica média 0-100.',
  quality_bucket ENUM('poor','impure','standard','fine','pure','pristine') NULL COMMENT 'Faixa de qualidade para empilhamento e busca.',
  material_origin_id BIGINT UNSIGNED NULL COMMENT 'Origem principal do material.',
  item_name VARCHAR(160) NULL COMMENT 'Nome final gerado; se nulo, usa o nome base.',
  crafted_by_player_id BIGINT UNSIGNED NULL COMMENT 'Jogador que criou o item.',
  crafting_event_id BIGINT UNSIGNED NULL COMMENT 'Evento de craft que gerou a instância.',
  current_durability DECIMAL(12,3) NULL COMMENT 'Durabilidade atual.',
  max_durability DECIMAL(12,3) NULL COMMENT 'Durabilidade máxima.',
  bind_type ENUM('none','account','player') NOT NULL DEFAULT 'none' COMMENT 'Vínculo do item.',
  state ENUM('normal','equipped','listed','reserved','consumed','destroyed','expired') NOT NULL DEFAULT 'normal' COMMENT 'Estado da instância.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_instance_public_id (public_id),
  KEY idx_item_instances_owner_state (owner_player_id, state),
  KEY idx_item_instances_definition_quality (item_definition_id, quality_bucket, material_origin_id),
  KEY idx_item_instances_crafter (crafted_by_player_id),
  KEY idx_item_instances_crafting_event (crafting_event_id),
  CONSTRAINT fk_item_instance_definition FOREIGN KEY (item_definition_id) REFERENCES item_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_instance_owner FOREIGN KEY (owner_player_id) REFERENCES players(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_instance_origin FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_instance_crafter FOREIGN KEY (crafted_by_player_id) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Itens reais: materiais empilháveis e equipamentos únicos com identidade própria.';

CREATE TABLE item_instance_properties (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Propriedade gerada em uma instância de item.',
  item_instance_id BIGINT UNSIGNED NOT NULL COMMENT 'Item relacionado.',
  property_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Propriedade aplicada.',
  numeric_value DECIMAL(14,4) NULL COMMENT 'Valor numérico da propriedade.',
  text_value VARCHAR(255) NULL COMMENT 'Valor textual, se aplicável.',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem de exibição.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_property_once (item_instance_id, property_definition_id),
  KEY idx_item_prop_property_value (property_definition_id, numeric_value),
  CONSTRAINT fk_item_prop_instance FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_prop_definition FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Atributos variáveis de uma instância: dano, crítico, defesa, extração etc.';

CREATE TABLE item_material_composition (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Composição material de um item processado ou equipamento.',
  item_instance_id BIGINT UNSIGNED NOT NULL COMMENT 'Item que guarda a composição.',
  material_family_id BIGINT UNSIGNED NULL COMMENT 'Família material usada.',
  material_origin_id BIGINT UNSIGNED NULL COMMENT 'Origem do componente.',
  percentage DECIMAL(6,3) NOT NULL COMMENT 'Percentual de composição, de 0 a 100.',
  average_quality DECIMAL(7,3) NULL COMMENT 'Qualidade média desse componente.',
  PRIMARY KEY (id),
  KEY idx_item_composition_instance (item_instance_id),
  KEY idx_item_composition_origin (material_origin_id),
  CONSTRAINT fk_item_composition_instance FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_composition_family FOREIGN KEY (material_family_id) REFERENCES material_families(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_composition_origin FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Composição interna de materiais, útil para crafting e marketplace avançado.';

-- =========================================================
-- 5) INVENTORY / GRIDSTACK / EQUIPMENT
-- =========================================================

CREATE TABLE inventory_containers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Contêiner de inventário compatível com GridStack.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público do contêiner.',
  owner_player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador dono.',
  container_type ENUM('backpack','chest','equipment_stash','expedition_loot','market_buffer') NOT NULL DEFAULT 'backpack' COMMENT 'Tipo do contêiner.',
  name VARCHAR(100) NOT NULL COMMENT 'Nome exibido.',
  grid_columns TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Colunas do GridStack.',
  grid_rows TINYINT UNSIGNED NOT NULL DEFAULT 6 COMMENT 'Linhas visuais/lógicas do GridStack.',
  max_weight DECIMAL(12,3) NULL COMMENT 'Peso máximo opcional.',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem do contêiner.',
  status ENUM('active','locked','deleted') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_public_id (public_id),
  KEY idx_inventory_owner_type (owner_player_id, container_type, status),
  CONSTRAINT fk_inventory_owner FOREIGN KEY (owner_player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Inventários, baús e buffers usando coordenadas GridStack.';

CREATE TABLE inventory_grid_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Posição de um item dentro de um contêiner GridStack.',
  inventory_container_id BIGINT UNSIGNED NOT NULL COMMENT 'Contêiner onde o item está.',
  item_instance_id BIGINT UNSIGNED NOT NULL COMMENT 'Instância do item posicionada.',
  grid_x SMALLINT UNSIGNED NOT NULL COMMENT 'Posição X do GridStack.',
  grid_y SMALLINT UNSIGNED NOT NULL COMMENT 'Posição Y do GridStack.',
  grid_w TINYINT UNSIGNED NOT NULL COMMENT 'Largura ocupada no GridStack.',
  grid_h TINYINT UNSIGNED NOT NULL COMMENT 'Altura ocupada no GridStack.',
  rotated TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se está rotacionado.',
  locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se o frontend deve impedir arraste.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de inserção.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_item_instance (item_instance_id),
  KEY idx_inventory_grid_container (inventory_container_id, grid_y, grid_x),
  CONSTRAINT fk_inventory_grid_container FOREIGN KEY (inventory_container_id) REFERENCES inventory_containers(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_grid_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Posições x/y/w/h dos itens no GridStack. Não guardar inventário em JSON.';

CREATE TABLE equipment_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Slot de equipamento disponível.',
  code VARCHAR(40) NOT NULL COMMENT 'Código único do slot.',
  name VARCHAR(80) NOT NULL COMMENT 'Nome exibido.',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem de exibição.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_equipment_slot_code (code)
) ENGINE=InnoDB COMMENT='Slots: weapon, helmet, chest, gloves, pants, boots, ring etc.';

CREATE TABLE player_equipment (
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador equipado.',
  equipment_slot_id BIGINT UNSIGNED NOT NULL COMMENT 'Slot equipado.',
  item_instance_id BIGINT UNSIGNED NULL COMMENT 'Item equipado.',
  equipped_at DATETIME NULL COMMENT 'Quando equipou.',
  PRIMARY KEY (player_id, equipment_slot_id),
  UNIQUE KEY uq_equipped_item (item_instance_id),
  CONSTRAINT fk_player_equipment_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_equipment_slot FOREIGN KEY (equipment_slot_id) REFERENCES equipment_slots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_player_equipment_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Equipamentos atuais do jogador.';

-- =========================================================
-- 6) CRAFTING / PROCESSING / PROFICIENCY
-- =========================================================

CREATE TABLE crafting_station_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de estação de crafting.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único da estação.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome exibido.',
  station_type ENUM('workbench','furnace','forge','mint','alchemy','leatherwork','jewelcraft') NOT NULL COMMENT 'Tipo de estação.',
  tier INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Tier da estação.',
  quality_modifier DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Modificador de qualidade.',
  property_modifier DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Modificador de propriedades especiais.',
  config JSON NULL COMMENT 'Configurações adicionais.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_station_def_code (code)
) ENGINE=InnoDB COMMENT='Definições de bancadas, forjas, fornos e cunhagem.';

CREATE TABLE player_crafting_stations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Estação construída/possuída pelo jogador.',
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador dono.',
  station_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Definição da estação.',
  player_world_id BIGINT UNSIGNED NULL COMMENT 'Mundo onde está instalada, se aplicável.',
  custom_name VARCHAR(120) NULL COMMENT 'Nome customizado.',
  durability DECIMAL(12,3) NULL COMMENT 'Durabilidade opcional.',
  state ENUM('active','broken','upgrading','deleted') NOT NULL DEFAULT 'active' COMMENT 'Estado.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (id),
  KEY idx_player_station_owner (player_id, state),
  CONSTRAINT fk_player_station_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_station_def FOREIGN KEY (station_definition_id) REFERENCES crafting_station_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_player_station_world FOREIGN KEY (player_world_id) REFERENCES player_worlds(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Estações reais do jogador, craftadas/upgradadas.';

CREATE TABLE proficiency_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de proficiência.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome exibido.',
  description TEXT NULL COMMENT 'Descrição da progressão.',
  config JSON NULL COMMENT 'Curvas de XP, bônus e distribuição.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_proficiency_code (code)
) ENGINE=InnoDB COMMENT='Proficiências: mining, woodcutting, blacksmithing, alchemy etc.';

CREATE TABLE player_proficiencies (
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador.',
  proficiency_id BIGINT UNSIGNED NOT NULL COMMENT 'Proficiência.',
  level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível atual.',
  experience BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Experiência acumulada.',
  total_actions BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total de ações ligadas à proficiência.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (player_id, proficiency_id),
  KEY idx_player_prof_level (proficiency_id, level),
  CONSTRAINT fk_player_prof_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_prof_def FOREIGN KEY (proficiency_id) REFERENCES proficiency_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Evolução natural por atividade, sem classe fixa.';

CREATE TABLE crafting_recipes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Receita de processamento/crafting.',
  code VARCHAR(100) NOT NULL COMMENT 'Código único da receita.',
  name VARCHAR(140) NOT NULL COMMENT 'Nome exibido.',
  recipe_type ENUM('processing','tool','weapon','armor','consumable','currency','station') NOT NULL COMMENT 'Tipo da receita.',
  station_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Estação exigida.',
  output_item_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Item base produzido.',
  proficiency_id BIGINT UNSIGNED NULL COMMENT 'Proficiência associada.',
  min_proficiency_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível mínimo de proficiência.',
  base_quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade base produzida.',
  base_config JSON NULL COMMENT 'Ranges, propriedade possíveis e fórmulas do resultado.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_code (code),
  KEY idx_recipe_type_status (recipe_type, status),
  KEY idx_recipe_station (station_definition_id),
  KEY idx_recipe_output (output_item_definition_id),
  CONSTRAINT fk_recipe_station FOREIGN KEY (station_definition_id) REFERENCES crafting_station_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_output FOREIGN KEY (output_item_definition_id) REFERENCES item_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_proficiency FOREIGN KEY (proficiency_id) REFERENCES proficiency_definitions(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Receitas definem tipo de saída, não resultado final fixo.';

CREATE TABLE crafting_recipe_inputs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entrada exigida por receita.',
  crafting_recipe_id BIGINT UNSIGNED NOT NULL COMMENT 'Receita.',
  accepted_item_definition_id BIGINT UNSIGNED NULL COMMENT 'Item específico aceito, quando aplicável.',
  accepted_material_family_id BIGINT UNSIGNED NULL COMMENT 'Família aceita, quando genérica.',
  required_quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade necessária.',
  consume TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Se consome o item.',
  input_role VARCHAR(60) NOT NULL COMMENT 'Papel: main_metal, handle_wood, binding etc.',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem na UI.',
  PRIMARY KEY (id),
  KEY idx_recipe_inputs_recipe (crafting_recipe_id),
  KEY idx_recipe_inputs_item (accepted_item_definition_id),
  CONSTRAINT fk_recipe_inputs_recipe FOREIGN KEY (crafting_recipe_id) REFERENCES crafting_recipes(id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_inputs_item FOREIGN KEY (accepted_item_definition_id) REFERENCES item_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_inputs_family FOREIGN KEY (accepted_material_family_id) REFERENCES material_families(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Materiais requeridos. Jogador deve escolher instâncias específicas no craft.';

CREATE TABLE crafting_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Evento auditável de crafting.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público do evento.',
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador que craftou.',
  crafting_recipe_id BIGINT UNSIGNED NOT NULL COMMENT 'Receita usada.',
  station_instance_id BIGINT UNSIGNED NULL COMMENT 'Estação real usada.',
  craft_seed BIGINT UNSIGNED NOT NULL COMMENT 'Seed permanente do craft; impede reroll por reload.',
  proficiency_level INT UNSIGNED NULL COMMENT 'Nível da proficiência no momento.',
  result_item_instance_id BIGINT UNSIGNED NULL COMMENT 'Item gerado.',
  input_snapshot JSON NOT NULL COMMENT 'Snapshot das instâncias e qualidades consumidas.',
  result_snapshot JSON NULL COMMENT 'Snapshot do resultado para auditoria.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data do craft.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_crafting_event_public_id (public_id),
  KEY idx_crafting_events_player_recipe (player_id, crafting_recipe_id, created_at),
  KEY idx_crafting_events_result (result_item_instance_id),
  CONSTRAINT fk_crafting_event_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_crafting_event_recipe FOREIGN KEY (crafting_recipe_id) REFERENCES crafting_recipes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_crafting_event_station FOREIGN KEY (station_instance_id) REFERENCES player_crafting_stations(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Histórico dos crafts e seed final de geração de item único.';

ALTER TABLE item_instances
  ADD CONSTRAINT fk_item_instance_crafting_event FOREIGN KEY (crafting_event_id) REFERENCES crafting_events(id) ON DELETE SET NULL;

-- =========================================================
-- 7) WALLET / COIN MINTING / ECONOMIC LEDGER
-- =========================================================

CREATE TABLE wallets (
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador dono da carteira.',
  coin_balance BIGINT NOT NULL DEFAULT 0 COMMENT 'Saldo de moedas cunhadas, em unidade inteira.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (player_id),
  CONSTRAINT fk_wallet_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Saldo monetário. Moedas entram por cunhagem e saem por sinks/marketplace.';

CREATE TABLE wallet_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Movimento auditável de moeda.',
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador afetado.',
  amount BIGINT NOT NULL COMMENT 'Valor positivo ou negativo.',
  balance_after BIGINT NOT NULL COMMENT 'Saldo após a transação.',
  transaction_type ENUM('mint','market_buy','market_sell','market_fee','world_unlock','poi_upgrade','repair','station_upgrade','admin_adjustment') NOT NULL COMMENT 'Origem econômica.',
  reference_type VARCHAR(50) NULL COMMENT 'Tipo de referência externa.',
  reference_id BIGINT UNSIGNED NULL COMMENT 'ID da referência externa.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data da transação.',
  PRIMARY KEY (id),
  KEY idx_wallet_tx_player_date (player_id, created_at),
  KEY idx_wallet_tx_type_date (transaction_type, created_at),
  CONSTRAINT fk_wallet_tx_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Livro razão da economia. Essencial contra inflação/fraude.';

-- =========================================================
-- 8) MARKETPLACE / SUPPLY-DEMAND PRICING DATA
-- =========================================================

CREATE TABLE market_listings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Oferta ativa ou encerrada no marketplace.',
  public_id CHAR(36) NOT NULL COMMENT 'UUID público da oferta.',
  seller_player_id BIGINT UNSIGNED NOT NULL COMMENT 'Vendedor.',
  item_instance_id BIGINT UNSIGNED NOT NULL COMMENT 'Item ofertado.',
  quantity_listed INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade ofertada; para item único sempre 1.',
  quantity_remaining INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade ainda disponível.',
  unit_price BIGINT UNSIGNED NOT NULL COMMENT 'Preço unitário em moedas.',
  status ENUM('active','sold','cancelled','expired') NOT NULL DEFAULT 'active' COMMENT 'Status da oferta.',
  listed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de listagem.',
  expires_at DATETIME NULL COMMENT 'Expiração opcional.',
  closed_at DATETIME NULL COMMENT 'Data de encerramento.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_market_listing_public_id (public_id),
  UNIQUE KEY uq_market_listing_item (item_instance_id),
  KEY idx_market_active_item_price (status, item_instance_id, unit_price),
  KEY idx_market_seller_status (seller_player_id, status),
  CONSTRAINT fk_market_listing_seller FOREIGN KEY (seller_player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_market_listing_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Ofertas do marketplace compartilhado. Preço é definido por jogadores.';

CREATE TABLE market_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Venda efetivada no marketplace.',
  listing_id BIGINT UNSIGNED NOT NULL COMMENT 'Oferta relacionada.',
  buyer_player_id BIGINT UNSIGNED NOT NULL COMMENT 'Comprador.',
  seller_player_id BIGINT UNSIGNED NOT NULL COMMENT 'Vendedor.',
  item_instance_id BIGINT UNSIGNED NOT NULL COMMENT 'Item vendido.',
  quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quantidade vendida.',
  unit_price BIGINT UNSIGNED NOT NULL COMMENT 'Preço unitário efetivo.',
  gross_amount BIGINT UNSIGNED NOT NULL COMMENT 'Total bruto.',
  marketplace_fee BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Taxa removida da economia.',
  net_amount BIGINT UNSIGNED NOT NULL COMMENT 'Valor líquido ao vendedor.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data da compra.',
  PRIMARY KEY (id),
  KEY idx_market_tx_item_date (item_instance_id, created_at),
  KEY idx_market_tx_buyer_date (buyer_player_id, created_at),
  KEY idx_market_tx_seller_date (seller_player_id, created_at),
  CONSTRAINT fk_market_tx_listing FOREIGN KEY (listing_id) REFERENCES market_listings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_market_tx_buyer FOREIGN KEY (buyer_player_id) REFERENCES players(id) ON DELETE RESTRICT,
  CONSTRAINT fk_market_tx_seller FOREIGN KEY (seller_player_id) REFERENCES players(id) ON DELETE RESTRICT,
  CONSTRAINT fk_market_tx_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Transações de mercado usadas para histórico e oferta/demanda.';

CREATE TABLE market_price_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Snapshot agregado para preço dinâmico informativo.',
  item_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Item base analisado.',
  quality_bucket ENUM('poor','impure','standard','fine','pure','pristine') NULL COMMENT 'Faixa de qualidade agregada.',
  material_origin_id BIGINT UNSIGNED NULL COMMENT 'Origem agregada.',
  period_start DATETIME NOT NULL COMMENT 'Início do período.',
  period_end DATETIME NOT NULL COMMENT 'Fim do período.',
  lowest_listing BIGINT UNSIGNED NULL COMMENT 'Menor preço listado no período.',
  average_sale_price DECIMAL(18,4) NULL COMMENT 'Preço médio de venda.',
  highest_sale_price BIGINT UNSIGNED NULL COMMENT 'Maior venda.',
  volume_sold BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Volume vendido.',
  active_supply BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Oferta ativa no fechamento.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação do snapshot.',
  PRIMARY KEY (id),
  KEY idx_market_snapshot_lookup (item_definition_id, quality_bucket, material_origin_id, period_end),
  CONSTRAINT fk_market_snapshot_item FOREIGN KEY (item_definition_id) REFERENCES item_definitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_market_snapshot_origin FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Dados agregados para UI de tendência, sem preço fixo sistêmico.';

-- =========================================================
-- 9) UPGRADES / TIME EXTENSION / MONEY SINKS
-- =========================================================

CREATE TABLE upgrade_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Definição de upgrade permanente.',
  code VARCHAR(80) NOT NULL COMMENT 'Código único.',
  name VARCHAR(120) NOT NULL COMMENT 'Nome exibido.',
  upgrade_type ENUM('expedition_time','inventory_size','poi_count','world_access','station_bonus') NOT NULL COMMENT 'Tipo de upgrade.',
  max_level INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nível máximo.',
  config JSON NULL COMMENT 'Bônus por nível e fórmula de custo.',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Status.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_upgrade_code (code)
) ENGINE=InnoDB COMMENT='Upgrades como aumentar tempo do mapa de 1 minuto.';

CREATE TABLE player_upgrades (
  player_id BIGINT UNSIGNED NOT NULL COMMENT 'Jogador.',
  upgrade_definition_id BIGINT UNSIGNED NOT NULL COMMENT 'Upgrade.',
  level INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nível comprado.',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização.',
  PRIMARY KEY (player_id, upgrade_definition_id),
  CONSTRAINT fk_player_upgrade_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_player_upgrade_definition FOREIGN KEY (upgrade_definition_id) REFERENCES upgrade_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Upgrades comprados pelo jogador.';

-- =========================================================
-- 10) GAMEPLAY EVENTS / AUDIT LOGS
-- =========================================================

CREATE TABLE gameplay_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Evento geral de gameplay para auditoria/analytics.',
  player_id BIGINT UNSIGNED NULL COMMENT 'Jogador relacionado.',
  event_type VARCHAR(80) NOT NULL COMMENT 'Tipo do evento.',
  reference_type VARCHAR(60) NULL COMMENT 'Tipo da entidade referenciada.',
  reference_id BIGINT UNSIGNED NULL COMMENT 'ID da entidade referenciada.',
  payload JSON NULL COMMENT 'Dados extras do evento.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data do evento.',
  PRIMARY KEY (id, created_at),
  KEY idx_gameplay_events_player_date (player_id, created_at),
  KEY idx_gameplay_events_type_date (event_type, created_at)
) ENGINE=InnoDB COMMENT='Log de gameplay particionável por data.'
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p2026q1 VALUES LESS THAN (TO_DAYS('2026-04-01')),
  PARTITION p2026q2 VALUES LESS THAN (TO_DAYS('2026-07-01')),
  PARTITION p2026q3 VALUES LESS THAN (TO_DAYS('2026-10-01')),
  PARTITION p2026q4 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION pmax VALUES LESS THAN MAXVALUE
);

-- =========================================================
-- 11) INITIAL SEED DATA
-- =========================================================

INSERT INTO item_categories (code, name) VALUES
('material', 'Material'),
('processed_material', 'Material Processado'),
('weapon', 'Arma'),
('armor', 'Armadura'),
('tool', 'Ferramenta'),
('consumable', 'Consumível'),
('currency', 'Moeda'),
('station', 'Estação');

INSERT INTO equipment_slots (code, name, sort_order) VALUES
('weapon', 'Arma', 10),
('helmet', 'Capacete', 20),
('chest', 'Peitoral', 30),
('gloves', 'Luvas', 40),
('pants', 'Calças', 50),
('boots', 'Botas', 60),
('ring_1', 'Anel 1', 70),
('ring_2', 'Anel 2', 80),
('tool', 'Ferramenta Ativa', 90);

INSERT INTO item_property_definitions (code, name, value_type, polarity) VALUES
('min_damage', 'Dano mínimo', 'decimal', 'positive'),
('max_damage', 'Dano máximo', 'decimal', 'positive'),
('attack_speed_percent', 'Velocidade de ataque', 'percent', 'positive'),
('critical_chance_percent', 'Chance crítica', 'percent', 'positive'),
('critical_damage_percent', 'Dano crítico', 'percent', 'positive'),
('defense', 'Defesa', 'decimal', 'positive'),
('max_health', 'Vida máxima', 'integer', 'positive'),
('durability', 'Durabilidade', 'decimal', 'positive'),
('extraction_efficiency_percent', 'Eficiência de extração', 'percent', 'positive'),
('rare_discovery_percent', 'Descoberta rara', 'percent', 'positive'),
('damage_vs_beasts_percent', 'Dano contra feras', 'percent', 'positive'),
('fire_damage', 'Dano de fogo', 'decimal', 'positive'),
('poison_resistance_percent', 'Resistência a veneno', 'percent', 'positive'),
('reduced_durability_percent', 'Durabilidade reduzida', 'percent', 'negative');

INSERT INTO material_families (code, name, influence_config) VALUES
('wood', 'Madeira', JSON_OBJECT('attack_speed', 0.60, 'durability', 0.25, 'tool_efficiency', 0.15)),
('stone', 'Pedra', JSON_OBJECT('durability', 0.70, 'defense', 0.30)),
('iron', 'Ferro', JSON_OBJECT('damage', 0.60, 'durability', 0.40)),
('leather', 'Couro', JSON_OBJECT('critical', 0.35, 'durability', 0.35, 'speed', 0.30)),
('herb', 'Erva', JSON_OBJECT('consumable_power', 0.80, 'special_effect', 0.20)),
('gold', 'Ouro', JSON_OBJECT('currency', 1.00));

INSERT INTO biome_definitions (code, name, description, difficulty_level, config) VALUES
('starter_grassland', 'Campo Inicial', 'Área segura com recursos básicos.', 1, JSON_OBJECT('tree_density', 0.35, 'rock_density', 0.20, 'bush_density', 0.45, 'monster_density', 0.10, 'chest_chance', 0.05)),
('forest', 'Floresta', 'Alta concentração de madeira, moitas e feras.', 2, JSON_OBJECT('tree_density', 0.75, 'rock_density', 0.10, 'bush_density', 0.55, 'monster_density', 0.25, 'chest_chance', 0.08)),
('rocky_field', 'Campo Rochoso', 'Área rica em rochas, minério e criaturas resistentes.', 3, JSON_OBJECT('tree_density', 0.10, 'rock_density', 0.75, 'bush_density', 0.15, 'monster_density', 0.30, 'chest_chance', 0.06));

INSERT INTO material_origins (code, name, biome_id, influence_config)
SELECT 'starter_grassland', 'Campo Inicial', id, JSON_OBJECT('neutral', 1.0) FROM biome_definitions WHERE code='starter_grassland';
INSERT INTO material_origins (code, name, biome_id, influence_config)
SELECT 'forest_origin', 'Origem Florestal', id, JSON_OBJECT('attack_speed_weight', 0.10, 'wood_quality_weight', 0.15) FROM biome_definitions WHERE code='forest';
INSERT INTO material_origins (code, name, biome_id, influence_config)
SELECT 'rocky_field_origin', 'Origem Rochosa', id, JSON_OBJECT('durability_weight', 0.12, 'mineral_quality_weight', 0.15) FROM biome_definitions WHERE code='rocky_field';

INSERT INTO crafting_station_definitions (code, name, station_type, tier, quality_modifier, property_modifier) VALUES
('primitive_workbench', 'Bancada Primitiva', 'workbench', 1, 0.0000, 0.0000),
('furnace', 'Forno', 'furnace', 1, 0.0200, 0.0000),
('blacksmith_forge', 'Forja de Ferreiro', 'forge', 1, 0.0300, 0.0100),
('minting_forge', 'Forja de Cunhagem', 'mint', 1, 0.0000, 0.0000);

INSERT INTO proficiency_definitions (code, name, description) VALUES
('woodcutting', 'Lenhador', 'Evolui cortando árvores e preservando madeira.'),
('mining', 'Mineração', 'Evolui minerando rochas e veios.'),
('blacksmithing', 'Ferraria', 'Evolui processando metais e criando armas/ferramentas.'),
('leatherworking', 'Couraria', 'Evolui processando couro e armaduras leves.'),
('exploration', 'Exploração', 'Evolui entrando em pontos de interesse e descobrindo mapas.');

INSERT INTO world_templates (code, name, description, min_player_level, base_poi_count, reset_interval_minutes) VALUES
('green_isle', 'Ilha Verde', 'Primeiro mundo estático com pontos de interesse temporários.', 1, 5, 360);

INSERT INTO point_of_interest_definitions (code, name, description, biome_id, min_world_level, base_duration_seconds, base_spawn_weight, rarity_weight, config)
SELECT 'small_forest', 'Pequena Floresta', 'Submapa temporário com árvores, moitas e feras fracas.', id, 1, 60, 1.0000, 1.0000, JSON_OBJECT('size', 'small', 'resource_focus', 'wood') FROM biome_definitions WHERE code='forest';
INSERT INTO point_of_interest_definitions (code, name, description, biome_id, min_world_level, base_duration_seconds, base_spawn_weight, rarity_weight, config)
SELECT 'abandoned_mine', 'Mina Abandonada', 'Submapa temporário com rochas, minério e criaturas resistentes.', id, 1, 60, 0.8000, 0.9000, JSON_OBJECT('size', 'small', 'resource_focus', 'ore') FROM biome_definitions WHERE code='rocky_field';

-- Categorias auxiliares para inserts de itens.
SET @cat_material := (SELECT id FROM item_categories WHERE code='material');
SET @cat_processed := (SELECT id FROM item_categories WHERE code='processed_material');
SET @cat_weapon := (SELECT id FROM item_categories WHERE code='weapon');
SET @cat_tool := (SELECT id FROM item_categories WHERE code='tool');
SET @cat_currency := (SELECT id FROM item_categories WHERE code='currency');
SET @fam_wood := (SELECT id FROM material_families WHERE code='wood');
SET @fam_iron := (SELECT id FROM material_families WHERE code='iron');
SET @fam_leather := (SELECT id FROM material_families WHERE code='leather');
SET @fam_gold := (SELECT id FROM material_families WHERE code='gold');

INSERT INTO item_definitions (code, name, description, category_id, material_family_id, stackable, max_stack, grid_w, grid_h, base_config) VALUES
('wood', 'Madeira', 'Material primário obtido de árvores.', @cat_material, @fam_wood, 1, 100, 1, 2, NULL),
('iron_trace', 'Traço de Ferro', 'Fragmentos minerais usados para produzir lingotes.', @cat_material, @fam_iron, 1, 100, 1, 1, NULL),
('coal', 'Carvão', 'Combustível usado em fornos e forjas.', @cat_material, NULL, 1, 100, 1, 1, NULL),
('leather_strip', 'Tira de Couro', 'Componente de amarração e empunhadura.', @cat_processed, @fam_leather, 1, 100, 1, 1, NULL),
('wood_plank', 'Tábua de Madeira', 'Madeira processada para crafting.', @cat_processed, @fam_wood, 1, 100, 1, 2, NULL),
('iron_ingot', 'Lingote de Ferro', 'Ferro processado para equipamentos.', @cat_processed, @fam_iron, 1, 50, 1, 1, NULL),
('gold_ore', 'Minério de Ouro', 'Material primário para cunhagem de moeda.', @cat_material, @fam_gold, 1, 100, 1, 1, NULL),
('gold_coin', 'Moeda de Ouro', 'Moeda criada por jogadores via cunhagem.', @cat_currency, @fam_gold, 1, 999999, 1, 1, NULL),
('iron_sword', 'Espada de Ferro', 'Arma gerada por crafting; cada instância pode ser diferente.', @cat_weapon, NULL, 0, 1, 1, 3, 'weapon', JSON_OBJECT('base_min_damage', JSON_ARRAY(8,12), 'base_max_damage', JSON_ARRAY(14,20), 'base_durability', JSON_ARRAY(50,90))),
('stone_pickaxe', 'Picareta de Pedra', 'Ferramenta básica de mineração.', @cat_tool, NULL, 0, 1, 2, 3, 'tool', JSON_OBJECT('tool_type','pickaxe','tier',1,'extraction_power',20,'efficiency',0.35));

SET @station_workbench := (SELECT id FROM crafting_station_definitions WHERE code='primitive_workbench');
SET @station_furnace := (SELECT id FROM crafting_station_definitions WHERE code='furnace');
SET @station_forge := (SELECT id FROM crafting_station_definitions WHERE code='blacksmith_forge');
SET @station_mint := (SELECT id FROM crafting_station_definitions WHERE code='minting_forge');
SET @prof_blacksmithing := (SELECT id FROM proficiency_definitions WHERE code='blacksmithing');
SET @id_wood := (SELECT id FROM item_definitions WHERE code='wood');
SET @id_wood_plank := (SELECT id FROM item_definitions WHERE code='wood_plank');
SET @id_iron_trace := (SELECT id FROM item_definitions WHERE code='iron_trace');
SET @id_coal := (SELECT id FROM item_definitions WHERE code='coal');
SET @id_iron_ingot := (SELECT id FROM item_definitions WHERE code='iron_ingot');
SET @id_leather_strip := (SELECT id FROM item_definitions WHERE code='leather_strip');
SET @id_iron_sword := (SELECT id FROM item_definitions WHERE code='iron_sword');
SET @id_gold_ore := (SELECT id FROM item_definitions WHERE code='gold_ore');
SET @id_gold_coin := (SELECT id FROM item_definitions WHERE code='gold_coin');

INSERT INTO crafting_recipes (code, name, recipe_type, station_definition_id, output_item_definition_id, proficiency_id, base_quantity, base_config) VALUES
('process_wood_plank', 'Processar Tábua de Madeira', 'processing', @station_workbench, @id_wood_plank, NULL, 2, JSON_OBJECT('preserve_quality', true)),
('smelt_iron_ingot', 'Fundir Lingote de Ferro', 'processing', @station_furnace, @id_iron_ingot, @prof_blacksmithing, 1, JSON_OBJECT('preserve_quality', true)),
('craft_iron_sword', 'Forjar Espada de Ferro', 'weapon', @station_forge, @id_iron_sword, @prof_blacksmithing, 1, JSON_OBJECT('unique_result', true, 'max_special_properties', 3)),
('mint_gold_coins', 'Cunhar Moedas de Ouro', 'currency', @station_mint, @id_gold_coin, NULL, 10, JSON_OBJECT('money_creation', true));

SET @recipe_plank := (SELECT id FROM crafting_recipes WHERE code='process_wood_plank');
SET @recipe_ingot := (SELECT id FROM crafting_recipes WHERE code='smelt_iron_ingot');
SET @recipe_sword := (SELECT id FROM crafting_recipes WHERE code='craft_iron_sword');
SET @recipe_coin := (SELECT id FROM crafting_recipes WHERE code='mint_gold_coins');

INSERT INTO crafting_recipe_inputs (crafting_recipe_id, accepted_item_definition_id, required_quantity, input_role, sort_order) VALUES
(@recipe_plank, @id_wood, 1, 'raw_wood', 10),
(@recipe_ingot, @id_iron_trace, 5, 'main_metal', 10),
(@recipe_ingot, @id_coal, 1, 'fuel', 20),
(@recipe_sword, @id_iron_ingot, 5, 'main_metal', 10),
(@recipe_sword, @id_wood_plank, 2, 'handle_wood', 20),
(@recipe_sword, @id_leather_strip, 1, 'binding', 30),
(@recipe_coin, @id_gold_ore, 1, 'gold_source', 10),
(@recipe_coin, @id_coal, 1, 'fuel', 20);

INSERT INTO loot_tables (code, name) VALUES
('tree_basic', 'Árvore Básica'),
('rock_basic', 'Rocha Básica'),
('chest_basic', 'Baú Básico');

SET @loot_tree := (SELECT id FROM loot_tables WHERE code='tree_basic');
SET @loot_rock := (SELECT id FROM loot_tables WHERE code='rock_basic');

INSERT INTO loot_table_entries (loot_table_id, item_definition_id, min_quantity, max_quantity, drop_chance, min_tool_tier, is_rare) VALUES
(@loot_tree, @id_wood, 2, 6, 1.00000, 0, 0),
(@loot_rock, @id_iron_trace, 1, 4, 0.35000, 1, 0),
(@loot_rock, @id_coal, 1, 2, 0.12000, 1, 0),
(@loot_rock, @id_gold_ore, 1, 1, 0.01500, 2, 1);

INSERT INTO resource_source_definitions (code, name, source_type, required_tool_type, min_tool_tier, base_hp, extraction_difficulty, loot_table_id) VALUES
('small_tree', 'Árvore Pequena', 'tree', 'axe', 1, 30, 10, @loot_tree),
('small_rock', 'Rocha Pequena', 'rock', 'pickaxe', 1, 40, 15, @loot_rock),
('bush', 'Moita', 'bush', 'hand', 0, 10, 1, NULL);

INSERT INTO upgrade_definitions (code, name, upgrade_type, max_level, config) VALUES
('expedition_time_plus', 'Aumentar Tempo de Expedição', 'expedition_time', 20, JSON_OBJECT('seconds_per_level', 10, 'base_cost', 100, 'cost_multiplier', 1.35)),
('backpack_rows_plus', 'Expandir Mochila', 'inventory_size', 10, JSON_OBJECT('rows_per_level', 1, 'base_cost', 250, 'cost_multiplier', 1.60));

SET FOREIGN_KEY_CHECKS = 1;

-- Fim do schema V2.
