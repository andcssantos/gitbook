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
2. `bootstrap/init.php` inicia sessão, autoload, `.env`, configurações e tratador global de erros.
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
