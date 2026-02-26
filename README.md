# GitBook Framework (PHP)

Um micro-framework PHP com roteamento por convenção, suporte a middlewares e utilitários HTTP.

## Estrutura atual do projeto

```text
bootstrap/           # Inicialização da aplicação
public/              # Front controller (index.php)
src/
  Core/              # Núcleo (dispatch, model base, matching de rota)
  Http/              # Abstrações HTTP (Request, Response, Route, Middleware)
  Middlewares/       # Middlewares prontos para uso
template/            # Estrutura de templates/views
```

## Fluxo de execução

1. `public/index.php` carrega o bootstrap.
2. `bootstrap/init.php` inicia sessão, autoload, `.env` e rotas.
3. `App\Core\Core::dispatch()` resolve método/URL, executa middlewares e chama controller/action.
4. `App\Http\Response` envia a resposta.

## Melhorias aplicadas nesta revisão

- **Resolução consistente de template/controller base** no `Core`, evitando duplicação de namespace no fluxo de `NotFound`.
- **Fallback seguro de domínio/subdomínio** em `Core::getSubdomainHost()` para ambientes que não definem `$_SERVER['DEFAULT_DOMINIO']`.
- **Envio de resposta mais robusto** em `Response::send()`, evitando warning quando `Content-Type` não foi previamente definido.

## Próximos passos recomendados

- Criar `tests/` com validação do roteador e dos helpers HTTP.
- Extrair configuração de ambiente para uma camada `config/` versionada.
- Formalizar estrutura de módulos (`Controllers`, `Services`, `Repositories`) para facilitar crescimento.
- Adicionar pipeline de qualidade (`php -l`, PHPStan, PHPUnit) no CI.

## Como rodar

1. Instale dependências:

```bash
composer install
```

2. Copie o arquivo de ambiente:

```bash
cp .env-example .env
```

3. Suba o servidor:

```bash
php -S localhost:8000 -t public
```
