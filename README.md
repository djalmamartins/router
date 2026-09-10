# MovesCode Router

Componente oficial de roteamento HTTP do MovesOS para PHP 8.2+. Registra rotas, resolve parâmetros, executa controllers ou callables, aplica middleware, gera URLs nomeadas e trata erros de despacho.

## Instalação

```bash
composer require movescode/router:^1.1
```

```php
use MovesCode\Router\Router;

$router = new Router('https://www.exemplo.com.br');
$router->get('/', fn () => print 'Página inicial');
$router->dispatch();
```

Direcione requisições que não correspondam a arquivos reais para o front controller. O Router aceita o path de `REQUEST_URI` e também `$_GET['route']`.

## Domínios e subdomínios

Use o domínio principal para a camada pública e subdomínios para separar cada aplicação:

```php
$router = new Router('https://moves.com.br');

$router->subdomain(''); // moves.com.br: site público
$router->get('/', 'Web\\Home:index', 'web.home');

$router->subdomain('studio'); // studio.moves.com.br: gestão e criação
$router->get('/', 'Studio\\Dashboard:index', 'studio.home');

$router->subdomain('app'); // app.moves.com.br: aplicação do usuário
$router->get('/', 'App\\Dashboard:index', 'app.home');

$router->subdomain('api'); // api.moves.com.br: API para web e React Native
$router->get('/users/{id}', 'Api\\Users:show', 'api.users.show');
```

`subdomain('')` representa exatamente o host informado no construtor. `subdomain(null)` remove a restrição e faz as próximas rotas aceitarem qualquer host. Para informar o host completo, use `domain('admin.moves.com.br')`.

Rotas de múltiplos clientes também podem capturar parâmetros do domínio:

```php
$router->domain('{tenant}.moves.com.br');
$router->get('/dashboard', function (array $data): void {
    echo $data['tenant'];
}, 'tenant.dashboard');

echo $router->route('tenant.dashboard', ['tenant' => 'cliente']);
// https://cliente.moves.com.br/dashboard
```

Os parâmetros do domínio e do caminho são entregues juntos ao handler. Nomes repetidos entre domínio e caminho são rejeitados.

### Arquitetura recomendada

- `moves.com.br`: website, páginas públicas, conteúdo e autenticação inicial.
- `studio.moves.com.br`: painel administrativo, gestão e ferramentas internas.
- `app.moves.com.br`: aplicação web autenticada utilizada pelo cliente.
- `api.moves.com.br`: endpoints JSON consumidos pelo app web, integrações e aplicativo React Native.

O aplicativo React Native não executa este Router. Ele envia requisições HTTPS para `api.moves.com.br`; o Router recebe essas requisições no servidor e encaminha cada endpoint ao controller da API.

### DNS, servidor e HTTPS

O Router não cria entradas DNS. Configure registros `A`, `AAAA` ou `CNAME` para cada subdomínio — ou um wildcard `*.moves.com.br` quando apropriado — apontando para o servidor. Apache ou Nginx também precisa aceitar esses hosts e direcioná-los ao mesmo front controller. Em produção, use certificado TLS que cubra o domínio principal e os subdomínios necessários.

O host recebido é comparado sem a porta e nunca é usado para gerar URLs nomeadas. Essas URLs são construídas apenas com os domínios previamente registrados na aplicação.

## Métodos HTTP

```php
$router->get('/posts', 'Posts:index');
$router->post('/posts', 'Posts:store');
$router->put('/posts/{id}', 'Posts:update');
$router->patch('/posts/{id}', 'Posts:patch');
$router->delete('/posts/{id}', 'Posts:delete');
```

Formulários POST podem simular `PUT`, `PATCH` ou `DELETE` por meio do campo `_method`.

## Grupos e namespaces

```php
$router->group('studio')->namespace('App\\Controllers\\Studio');
$router->get('/', 'Dashboard:home');
$router->group('studio/posts');
$router->get('/{id}', 'Posts:show');
```

`group('')` retorna os próximos registros à raiz. Cada grupo informado é absoluto.

## Controllers e callables

O formato do controller é `Classe:método`. A classe recebe o Router no construtor e o método recebe os parâmetros:

```php
final class Posts
{
    public function __construct(private Router $router) {}
    public function show(array $data): void { echo $data['id']; }
}
```

Callables podem receber os dados e, opcionalmente, o Router:

```php
$router->get('/hello/{name}', function (array $data, Router $router): void {
    echo "Olá, {$data['name']}";
});
```

## Rotas nomeadas

```php
$router->get('/posts/{id}/edit', 'Posts:edit', 'post.edit');
$url = $router->route('post.edit', ['id' => 10, 'tab' => 'media']);
// https://www.exemplo.com.br/posts/10/edit?tab=media
```

Parâmetros do path são codificados; valores excedentes formam a query string.

## Middleware

O Router usa `movescode/middleware:^0.1` para executar o handler dentro de um Pipeline. Para novos middlewares, implemente `MovesCode\Middleware\MiddlewareInterface`:

```php
use MovesCode\Middleware\MiddlewareInterface;

final class ExampleMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        // Código antes do handler.
        $result = $next();
        // Código depois do handler; o resultado pode ser transformado.
        return $result;
    }
}
```

Registre classes explicitamente, na mesma sintaxe de rotas e grupos já existente. As classes precisam poder ser instanciadas sem argumentos. Não são aceitos objetos ou callables como registro de middleware do Router.

```php
$router->group('studio', AuthMiddleware::class);
$router->get('/admin', 'Dashboard:admin', middleware: [
    AuthMiddleware::class,
    AdminMiddleware::class,
]);
```

A ordem é grupo antes de rota, preservando a primeira ocorrência de cada classe. Se A pertence ao grupo e B à rota, a execução é `A BEFORE → B BEFORE → Handler → B AFTER → A AFTER`. Classes repetidas no grupo e na rota continuam sendo executadas uma única vez.

Um middleware pode retornar sem chamar `$next()` para interromper a cadeia. Nenhum middleware posterior é instanciado, e o handler não é executado. Cada continuação só pode ser usada uma vez durante `handle()`; a proteção pertence ao pacote `movescode/middleware`, sem duplicação no Router.

### Compatibilidade com middleware legado

O formato `handle(Router $router): bool` continua aceito. Somente `true` avança; qualquer outro retorno interrompe. Uma classe que implementa `MiddlewareInterface` usa o contrato novo; as demais seguem a chamada legada. Ambos podem ser combinados na mesma lista. A migração é opcional nesta versão: implemente a interface, troque o argumento Router por `callable $next` e substitua o retorno de autorização `true` por `return $next()`.

O Router resolve e instancia cada classe somente quando sua etapa é alcançada. Isso preserva os efeitos de construtores e a interrupção legada. A classe interna `Internal\RegisteredMiddleware` adapta essa resolução ao contrato; não é API pública suportada. Controllers continuam recebendo Router no construtor, e callables continuam recebendo parâmetros e Router conforme a assinatura existente.

### Retornos e falhas

`dispatch(): bool` permanece inalterado. Retorna `true` quando o handler termina normalmente e a cadeia termina sem exceção não tratada. Retorna `false` se o handler não completar (inclusive short-circuit), ou diante dos erros documentados. O valor retornado pelo handler, inclusive `false`, `null` ou objetos, chega ao middleware por `$next()` e pode ser transformado, mas não é retornado por `dispatch()`. O Router não emite respostas retornadas automaticamente; a aplicação continua responsável pela saída.

Exceções percorrem os middlewares externos, que podem tratá-las. Se escaparem do Pipeline, o Router mantém o comportamento existente: `dispatch()` retorna `false` e `error()` retorna `500`, sem expor a mensagem. Se um middleware tratar uma exceção do handler, `dispatch()` permanece `false` porque o handler não completou; nenhum erro 500 é atribuído automaticamente nesse caso. Middleware indisponível mantém o erro 501; erros de construção ou invocação mantêm 500.

## Estado, redirecionamento e erros

- `data()`: parâmetros encontrados.
- `current()`: contexto da rota atual.
- `home()`: URL base.
- `route()`: URL nomeada.
- `redirect()`: header `Location`.
- `error()`: último erro.

Erros possíveis: 400 para handler inseguro, 404 para rota inexistente, 405 para método incompatível, 501 para handler indisponível e 500 para falha da aplicação.

```php
if (!$router->dispatch() && $router->error()) {
    $router->redirect('/ops/' . $router->error());
}
```

## Segurança

Somente handlers registrados podem ser executados. Tokens de classe e método são validados, spoofing é limitado a POST e parâmetros são decodificados uma vez. Registre rotas estáticas antes de rotas dinâmicas amplas.

Exemplos estão em `exemple/`. Licença MIT.

## Desenvolvimento e revisão desta integração

```sh
composer validate --strict
composer install
composer audit
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Execute `php -l` nos arquivos PHP de `src/`, `tests/` e `exemple/`. PHPUnit 11.5 é dependência de desenvolvimento compatível com PHP 8.2. PHPStan 2 analisa `src/` e `tests/` no nível máximo, com PHP 8.2 como alvo. Não havia testes versionados nem PHPStan configurado no repositório original; os testes de regressão foram executados antes da alteração de dispatch e preservados na integração. O `composer.lock` permanece ignorado conforme a política existente da biblioteca; a resolução local usa PHP 8.2.0 como plataforma mínima.

As assinaturas públicas, erros, registro por classe, deduplicação, controllers e rotas foram preservados. A recomendação é uma versão minor **1.2.0**, pois a tag 1.1.0 já existe. Nenhuma tag ou publicação faz parte desta alteração.

Somente o código de registro da aplicação escolhe classes. Nunca derive handlers ou classes de middleware de parâmetros HTTP. Não há resolução automática por input, logging, retries ou novas operações de reflection; a reflexão preexistente de controllers/callables foi mantida. A resolução é local a cada dispatch, sem novos estados globais. Dados de requisição continuam no Router (`data()`/`current()`), como antes; não compartilhe a instância entre execuções concorrentes ou reentrantes. O próximo dispatch reinicializa esse estado.

A cadeia passa a usar pilha proporcional à quantidade de middlewares. Cadeias extremamente profundas podem encontrar limites de memória/pilha do PHP, ao contrário da antiga passagem iterativa. Middlewares são código confiável e seguem responsáveis por seus próprios efeitos e retenção de dados. Mudanças de destrutores observáveis, dependência de stack traces internos e acesso a métodos privados não fazem parte da API suportada. Linux e Windows não foram executados nesta revisão.
