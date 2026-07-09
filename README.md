# GitBook Framework (PHP)

Um micro-framework PHP com roteamento por convenção, suporte a middlewares e utilitários HTTP.

## Estrutura atual do projeto

```text
bootstrap/           # Inicialização da aplicação
config/              # Configuração versionada
public/              # Front controller (index.php)
src/
  Core/              # Núcleo (dispatch, model base, matching de rota)
  Http/              # Abstrações HTTP (Request, Response, Route, Middleware)
  Middlewares/       # Middlewares prontos para uso
template/            # Estrutura de templates/views
tests/               # Testes automatizados
```

## Fluxo de execução

1. `public/index.php` carrega o bootstrap.
2. `bootstrap/init.php` carrega autoload, `.env`, configurações, inicia sessão hardened e registra o tratador global de erros.
3. Se cache de rotas estiver habilitado (`APP_ROUTE_CACHE=true`), o framework tenta carregar de `bootstrap/cache/routes.php`.
4. `App\Core\Core::dispatch()` resolve método/URL, executa middlewares e chama controller/action.
5. `App\Http\Response` envia a resposta.

## Melhorias aplicadas

- **Semântica HTTP aprimorada**: agora o dispatch retorna `405 Method Not Allowed` quando o path existe para outros métodos e inclui header `Allow`.
- **Parsing de request por Content-Type** em `Request::body()` (`json`, `x-www-form-urlencoded`, `multipart/form-data`).
- **Middleware extensível**: aliases via `config/middleware.php` e suporte a classe direta (FQCN).
- **Tratamento global de exceções** com `ErrorHandler` e resposta consistente entre dev/prod.
- **Camada de configuração versionada** (`config/app.php`, `config/routing.php`, `config/middleware.php`) consumida via `App\Utils\Config`.
- **Resposta de arquivo por streaming** em `Response::file()` (menor consumo de memória).
- **Cache de rotas** com `Route::cacheToFile()` e `Route::loadFromFile()`.
- **Sessão hardened** com cookies configurados antes do `session_start()`, strict mode e rotação via `Auth::login()` / `Session::regenerate()`.
- **Idempotência DB-backed** com reserva atômica, replay de resposta concluída e rejeição de mesma chave com payload diferente.
- **Transações com savepoints** em `DB::transaction()` para chamadas aninhadas.
- **Row locks** no query builder com `forUpdate()` e `sharedLock()`.
- **Auditoria em banco** via `game_audit_logs`, com fallback para JSON lines em arquivo.
- **Base de qualidade** com PHPUnit + PHPStan e testes iniciais.

## Como rodar

1. Instale dependências:

```bash
composer install
```

2. Copie o arquivo de ambiente:

```bash
cp .env-example .env
```

3. (Opcional) habilite cache de rotas em produção:

```env
APP_ROUTE_CACHE=true
```

4. Suba o servidor:

```bash
php -S localhost:8000 -t public
```

## Qualidade

```bash
composer test
composer analyse
```

## Padrao seguro para API transacional

O framework agora inclui um endpoint de referencia para acoes sensiveis:

```text
POST /api/example/action
Controller: App/Api/SecureActionController@store
```

Esse endpoint demonstra o fluxo recomendado para futuras acoes do jogo:

- `auth`: exige jogador autenticado.
- `csrf`: bloqueia POST/PUT/PATCH/DELETE sem token valido.
- `rateLimit:30,60`: limita chamadas por IP, metodo e rota.
- `idempotency:api.example.action`: exige `Idempotency-Key` para evitar duplicidade de acoes.
- `validate:...`: valida o payload antes de chegar no controller.
- `audit:api.example.action`: registra a tentativa de execucao.

Dentro do controller, a validacao e repetida como defesa server-authoritative, o processamento roda dentro de `db_transaction()`/`DB::transaction()` e o resultado fica protegido por idempotencia. Esse e o modelo base para loot, crafting, marketplace, moeda, timers e qualquer acao que nao pode confiar no console do navegador.

Antes de usar endpoints transacionais em gameplay, rode as migrations para criar `idempotency_keys` e `game_audit_logs`:

```bash
php bin/gb migrate
```

Exemplo de payload:

```json
{
  "action": "collect_reward",
  "client_tick": 120,
  "nonce": "unique-client-action-id"
}
```

Headers esperados:

```text
Content-Type: application/json
X-CSRF-TOKEN: <csrf_token>
Idempotency-Key: <uuid-ou-hash-unico-da-acao>
```
