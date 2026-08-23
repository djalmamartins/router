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

Middleware deve possuir `handle(Router $router): bool`. Retorno diferente de `true` interrompe o handler.

```php
$router->group('studio', AuthMiddleware::class);
$router->get('/admin', 'Dashboard:admin', middleware: [
    AuthMiddleware::class,
    AdminMiddleware::class,
]);
```

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
