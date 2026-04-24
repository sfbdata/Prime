# tests/ — Regras de Testes

## Estrutura espelha src/

```
tests/
├── <Dominio>/
│   ├── Unit/           — TestCase puro, sem kernel, sem banco
│   └── Functional/     — WebTestCase com KernelBrowser
```

## Quando usar cada tipo

| O que testar | Classe base | Kernel | Banco |
|---|---|---|---|
| UseCase, Entity, VO (com mocks) | `TestCase` | ❌ | ❌ |
| Service com DI, Repository, Listener | `KernelTestCase` | ✅ | opcional |
| Controller via HTTP | `WebTestCase` | ✅ | ✅ |

## Estrutura de teste unitário

```php
<?php
declare(strict_types=1);
namespace App\Tests\<Dominio>\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(<ClasseTestada>::class)]
final class <Nome>Test extends TestCase
{
    public function testDeveRetornarX(): void
    {
        // Arrange
        $sut = new <ClasseTestada>();

        // Act
        $resultado = $sut->metodo();

        // Assert
        self::assertSame('esperado', $resultado);
    }
}
```

## Estrutura de teste funcional

```php
<?php
declare(strict_types=1);
namespace App\Tests\<Dominio>\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class <Nome>ControllerTest extends WebTestCase
{
    public function testListarRetorna200(): void
    {
        $client = static::createClient();
        // autenticar usuário de teste
        $client->request('GET', '/rota');

        self::assertResponseIsSuccessful();
    }
}
```

## Regras obrigatórias

- Usar atributos PHPUnit: `#[DataProvider]`, `#[CoversClass]`, `#[Group]`, `#[TestDox]`
- **Nunca mockar `EntityManager` diretamente** — crie uma interface e mocke ela
- **Nunca mockar o que não é seu** — só mockar abstrações próprias (interfaces do domínio)
- DAMA/DoctrineTestBundle para rollback automático em testes funcionais
- Mocks com PHPUnit nativo: `createMock()`, `createStub()` — não usar Prophecy

## Fixtures com Foundry v2

```php
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class ClienteFactory extends PersistentProxyObjectFactory
{
    public static function class(): string { return Cliente::class; }

    protected function defaults(): array
    {
        return [
            'nome' => self::faker()->company(),
            'email' => self::faker()->companyEmail(),
        ];
    }
}

// No teste:
$cliente = ClienteFactory::createOne(['nome' => 'Teste Ltda']);
```

## Cobertura mínima esperada

- Domain core (UseCases, entities com invariantes): 100%
- Controllers (happy path + erro): todos cobertos por Functional
- Target global: 80%

## Rodar testes

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/<Dominio>/'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --coverage-text'
```

## Testes legados — não confiar sem verificar

Testes existentes escritos antes deste guia podem ter problemas silenciosos. Antes de usar um teste legado como garantia de segurança para refatoração, verifique:

- **Mocka `EntityManager` diretamente?** — não é confiável; crie uma interface e mocke ela
- **Usa Prophecy?** — reescrever com PHPUnit nativo (`createMock`, `createStub`)
- **Usa annotations PHPDoc** (`@test`, `@dataProvider`)? — migrar para atributos (`#[Test]`, `#[DataProvider]`)
- **Testa implementação em vez de comportamento?** — testes acoplados a detalhes internos quebram na refatoração sem motivo real
- **Falta rollback de banco?** — sem DAMA/DoctrineTestBundle, testes funcionais podem contaminar uns aos outros

Se qualquer um desses problemas estiver presente, refatore o teste antes de usá-lo como base de segurança. Um teste com defeito dá falsa confiança — é pior do que não ter teste.

## Relação obrigatória: UseCase + testes

Todo UseCase deve ter seu teste unitário verificado ou criado junto. Nunca implementar ou refatorar um UseCase sem garantir que o teste unitário correspondente existe e está correto. O mesmo vale para o controller associado: functional test cobre o happy path e pelo menos um caso de erro.
