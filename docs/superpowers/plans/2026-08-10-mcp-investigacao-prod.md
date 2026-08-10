# MCP de investigação em produção (v1, somente leitura) — Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development`
> (recomendado) ou `superpowers:executing-plans` para executar tarefa a tarefa. Os passos
> usam checkbox (`- [ ]`) para acompanhamento.

**Spec:** [2026-08-10-mcp-investigacao-prod-design.md](../specs/2026-08-10-mcp-investigacao-prod-design.md)

**Objetivo:** dar ao dono uma porta estruturada e comprovadamente incapaz de escrever para
investigar dados de produção a partir do Claude Code na máquina local.

**Arquitetura:** um comando Symfony (`mcp:server`) fala o protocolo MCP por STDIO usando o
SDK oficial `mcp/sdk`. O Claude Code lança esse comando através de
`ssh bluejus 'docker exec -i jusprime_php_prod …'` — o SSH é apenas o cano, uma conexão por
sessão. Duas ferramentas (`consultar_sql`, `descrever_esquema`) leem o banco por uma conexão
DBAL **separada**, construída à mão com `DriverManager`, cujo usuário PostgreSQL só tem
`SELECT`.

**Stack:** PHP 8.2+, Symfony 7.4, Doctrine DBAL 4 (via ORM 3.x), PostgreSQL 15, `mcp/sdk`,
PHPUnit.

## Restrições globais

- **Idioma:** código, comentários, testes e commits em **português brasileiro**.
  `camelCase` métodos/variáveis, `PascalCase` classes, `snake_case` nomes de ferramenta MCP.
- **Todo comando roda dentro do container:**
  `docker exec jusprime_php_dev bash -c 'cd app && …'`. Nunca `php`/`composer` fora.
- **`stdout` é do protocolo.** Nenhum `echo`, `dump`, `var_dump`, `$output->writeln()` ou
  handler de log pode escrever em `stdout` no caminho do `mcp:server`. Diagnóstico vai para
  `stderr` ou arquivo.
- **`mcp/sdk` fica com versão fixa** (`"mcp/sdk": "0.7.0"`), não `^0.7` — o pacote é
  declarado experimental pelos mantenedores até a 1.0.
- **Nada escreve.** Nenhuma tarefa deste plano cria `INSERT`, `UPDATE`, `DELETE`, migração
  ou entidade. Se alguma tarefa parecer exigir isso, pare e alinhe com o dono.
- **PHPUnit roda com `failOnDeprecation/Notice/Warning` ativos** — um deprecation derruba a
  suíte inteira.
- **`DATABASE_URL_LEITURA` não tem default.** Ausente, o servidor falha com mensagem clara.
  O build de produção faz `rm .env`, então default nenhum daquele arquivo chega em prod.

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/src/Mcp/Service/ConexaoLeitura.php` | Constrói e guarda a conexão DBAL somente-leitura; aplica `statement_timeout` e `default_transaction_read_only`; executa consulta com teto de linhas |
| `app/src/Mcp/Tool/ConsultarSqlTool.php` | Ferramenta `consultar_sql`: recebe SQL, devolve colunas/linhas/truncado, registra no log |
| `app/src/Mcp/Tool/DescreverEsquemaTool.php` | Ferramenta `descrever_esquema`: lista tabelas ou descreve colunas e índices de uma |
| `app/src/Mcp/Command/McpServerCommand.php` | Monta o servidor MCP, registra as duas ferramentas e roda o transporte STDIO |
| `app/config/services.yaml` | Injeta o DSN de leitura em `ConexaoLeitura` |
| `app/config/packages/monolog.yaml` | Canal `mcp`, isolado do handler de console (que escreve em stdout) |
| `app/composer.json` | Dependência `mcp/sdk` fixada |
| `docs/runbooks/mcp-investigacao-prod.md` | Passos manuais do dono: role no Postgres, `.env.prod`, alias SSH, deploy, config do Claude Code |
| `app/tests/Mcp/Functional/ConexaoLeituraTest.php` | Prova que a conexão recusa escrita, respeita o teto e anuncia truncamento |
| `app/tests/Mcp/Functional/DescreverEsquemaToolTest.php` | Prova que o esquema devolvido bate com o banco |
| `app/tests/Mcp/Functional/McpServerCommandTest.php` | Prova o handshake MCP e a limpeza do `stdout`, rodando o comando como processo real |

---

## Task 1: Dependência e esqueleto do servidor MCP

Entrega: o comando `mcp:server` sobe, completa o handshake MCP e não suja o `stdout`.
Nenhuma ferramenta ainda.

**Arquivos:**
- Modificar: `app/composer.json`
- Criar: `app/src/Mcp/Command/McpServerCommand.php`
- Criar: `app/tests/Mcp/Functional/McpServerCommandTest.php`

**Interfaces:**
- Consome: nada (primeira tarefa)
- Produz: comando de console `mcp:server`; classe
  `App\Mcp\Command\McpServerCommand` com construtor **sem argumentos** nesta tarefa (as
  ferramentas entram na Task 5)

- [ ] **Passo 1: Instalar o SDK com versão fixa**

```bash
docker exec jusprime_php_dev bash -c 'cd app && composer require mcp/sdk:0.7.0'
```

Confirme que o `composer.json` ficou com `"mcp/sdk": "0.7.0"` — sem `^`. Se o Composer
gravou `^0.7.0`, edite à mão e rode `composer update mcp/sdk --lock`.

- [ ] **Passo 2: Escrever o teste que falha**

`app/tests/Mcp/Functional/McpServerCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Roda o comando como PROCESSO DE VERDADE. É a única forma de provar que o stdout carrega
 * exclusivamente JSON-RPC: um CommandTester não enxergaria um `echo` perdido nem um handler
 * de log escrevendo na saída padrão.
 */
final class McpServerCommandTest extends TestCase
{
    private const HANDSHAKE = [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'teste', 'version' => '1.0.0'],
        ]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ];

    public function testHandshakeRespondeEStdoutSoTemJsonRpc(): void
    {
        $processo = $this->rodarServidor(self::HANDSHAKE);

        self::assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());

        $linhas = $this->linhasDaSaida($processo->getOutput());
        self::assertNotEmpty($linhas, 'servidor não respondeu nada no stdout');

        foreach ($linhas as $linha) {
            $decodificado = json_decode($linha, true);
            self::assertIsArray(
                $decodificado,
                sprintf('stdout tem linha que não é JSON-RPC: %s', $linha),
            );
            self::assertSame('2.0', $decodificado['jsonrpc'] ?? null);
        }
    }

    public function testRespondeAoInitializeComONomeDoServidor(): void
    {
        $processo = $this->rodarServidor(self::HANDSHAKE);

        $resposta = $this->respostaComId($processo->getOutput(), 1);

        self::assertNotNull($resposta, 'nenhuma resposta para o initialize (id 1)');
        self::assertSame('JusPrime (leitura)', $resposta['result']['serverInfo']['name']);
    }

    /** @param list<array<string, mixed>> $mensagens */
    private function rodarServidor(array $mensagens): Process
    {
        $entrada = '';
        foreach ($mensagens as $mensagem) {
            $entrada .= json_encode($mensagem, \JSON_THROW_ON_ERROR) . "\n";
        }

        $processo = new Process(
            ['php', 'bin/console', 'mcp:server'],
            dirname(__DIR__, 3),          // .../app
            ['APP_ENV' => 'test'],
        );
        $processo->setInput($entrada);    // fecha o STDIN ao terminar → o servidor encerra
        $processo->setTimeout(30);
        $processo->run();

        return $processo;
    }

    /** @return list<string> */
    private function linhasDaSaida(string $saida): array
    {
        return array_values(array_filter(
            preg_split('/\R/', $saida) ?: [],
            static fn (string $linha): bool => trim($linha) !== '',
        ));
    }

    /** @return array<string, mixed>|null */
    private function respostaComId(string $saida, int $id): ?array
    {
        foreach ($this->linhasDaSaida($saida) as $linha) {
            $decodificado = json_decode($linha, true);
            if (is_array($decodificado) && ($decodificado['id'] ?? null) === $id) {
                return $decodificado;
            }
        }

        return null;
    }
}
```

- [ ] **Passo 3: Rodar o teste e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/McpServerCommandTest.php'
```

Esperado: FALHA. O comando `mcp:server` não existe, então o processo sai com código
diferente de 0 e `getErrorOutput()` traz `Command "mcp:server" is not defined`.

- [ ] **Passo 4: Criar o comando**

`app/src/Mcp/Command/McpServerCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Command;

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Servidor MCP de LEITURA para investigação em produção.
 *
 * Falado por STDIO: o stdout carrega exclusivamente JSON-RPC. Nada pode ser escrito na saída
 * padrão a partir daqui — nem `$output->writeln()`, nem log, nem `dump()`. Qualquer byte fora
 * do protocolo corrompe a sessão e o cliente mostra apenas "servidor falhou", sem pista.
 *
 * Lançado remotamente por:
 *   ssh bluejus 'docker exec -i jusprime_php_prod php bin/console mcp:server'
 */
#[AsCommand(
    name: 'mcp:server',
    description: 'Servidor MCP de leitura (investigação em produção)',
)]
final class McpServerCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $servidor = Server::builder()
            ->setServerInfo('JusPrime (leitura)', '1.0.0')
            ->setInstructions(
                'Acesso SOMENTE LEITURA ao banco do JusPrime. Nenhuma ferramenta grava dados.',
            )
            ->build();

        return $servidor->run(new StdioTransport());
    }
}
```

- [ ] **Passo 5: Rodar o teste e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/McpServerCommandTest.php'
```

Esperado: PASSA, 2 testes.

Se `testHandshakeRespondeEStdoutSoTemJsonRpc` falhar apontando linha que não é JSON, **não
relaxe o teste** — é exatamente o defeito que ele existe para pegar. Ache quem escreveu em
`stdout` (provavelmente um handler do Monolog; a Task 6 trata disso) e corrija a origem.

- [ ] **Passo 6: Commit**

```bash
git add app/composer.json app/composer.lock app/src/Mcp/Command/McpServerCommand.php app/tests/Mcp/Functional/McpServerCommandTest.php
git commit -m "sobe o servidor mcp de leitura falando stdio"
```

---

## Task 2: Conexão somente-leitura, provada contra o banco

Entrega: `ConexaoLeitura` conecta com o usuário restrito, recusa escrita, aplica timeout e
devolve consulta com teto de linhas.

**Arquivos:**
- Criar: `app/src/Mcp/Service/ConexaoLeitura.php`
- Modificar: `app/config/services.yaml`
- Criar: `app/tests/Mcp/Functional/ConexaoLeituraTest.php`

**Interfaces:**
- Consome: nada da Task 1
- Produz:
  - `App\Mcp\Service\ConexaoLeitura::__construct(string $dsn, int $timeoutSegundos = 15)`
  - `ConexaoLeitura::consultar(string $sql, int $limite, array $parametros = []): array` →
    devolve `['colunas' => list<string>, 'linhas' => list<array<string,mixed>>,
    'truncado' => bool]`
  - `ConexaoLeitura::conexao(): \Doctrine\DBAL\Connection` (pública para o teste)

- [ ] **Passo 1: Escrever o teste que falha**

`app/tests/Mcp/Functional/ConexaoLeituraTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * A garantia de que este MCP não escreve NÃO é análise do texto do SQL — isso se burla com
 * comentário, encadeamento ou CTE com RETURNING. A garantia é o próprio PostgreSQL recusar,
 * porque o usuário não tem permissão. Este teste prova a recusa contra o banco de verdade.
 *
 * A role é criada por uma conexão SEPARADA, fora da transação do DAMA: uma role criada dentro
 * da transação do teste não existiria para uma conexão nova, que é justamente o que vamos
 * abrir em seguida.
 */
final class ConexaoLeituraTest extends TestCase
{
    private const ROLE = 'jusprime_leitura_teste';
    private const SENHA = 'leitura_teste';

    private static string $dsnLeitura = '';

    public static function setUpBeforeClass(): void
    {
        $dsnAdmin = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($dsnAdmin, 'DATABASE_URL não definida no ambiente de teste');

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
        $params = $parser->parse($dsnAdmin);
        $admin = DriverManager::getConnection($params);

        $banco = $params['dbname'] ?? 'saas';

        // DO block porque o PostgreSQL não tem CREATE ROLE IF NOT EXISTS.
        $admin->executeStatement(sprintf(
            "DO $$ BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '%s') THEN
                    CREATE ROLE %s LOGIN PASSWORD '%s';
                END IF;
            END $$;",
            self::ROLE,
            self::ROLE,
            self::SENHA,
        ));
        $admin->executeStatement(sprintf('GRANT CONNECT ON DATABASE "%s" TO %s', $banco, self::ROLE));
        $admin->executeStatement(sprintf('GRANT USAGE ON SCHEMA public TO %s', self::ROLE));
        $admin->executeStatement(sprintf('GRANT SELECT ON ALL TABLES IN SCHEMA public TO %s', self::ROLE));
        $admin->executeStatement(sprintf(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO %s',
            self::ROLE,
        ));

        $params['user'] = self::ROLE;
        $params['password'] = self::SENHA;
        self::$dsnLeitura = sprintf(
            'pgsql://%s:%s@%s:%d/%s',
            self::ROLE,
            self::SENHA,
            $params['host'] ?? 'db',
            $params['port'] ?? 5432,
            $banco,
        );

        $admin->close();
    }

    public function testRecusaInsert(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $conexao->conexao()->executeStatement(
            'INSERT INTO tenant (nome, ativo) VALUES (\'invasor\', true)',
        );
    }

    public function testRecusaUpdate(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $conexao->conexao()->executeStatement('UPDATE tenant SET nome = \'invadido\'');
    }

    public function testRecusaDelete(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $conexao->conexao()->executeStatement('DELETE FROM tenant');
    }

    public function testLeituraFunciona(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT 1 AS um, 2 AS dois', 500);

        self::assertSame(['um', 'dois'], $resultado['colunas']);
        self::assertSame([['um' => 1, 'dois' => 2]], $resultado['linhas']);
        self::assertFalse($resultado['truncado']);
    }

    public function testTetoDeLinhasTruncaEAvisa(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT generate_series(1, 100) AS n', 10);

        self::assertCount(10, $resultado['linhas']);
        self::assertTrue($resultado['truncado']);
    }

    public function testResultadoExatamenteNoTetoNaoDizQueTruncou(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT generate_series(1, 10) AS n', 10);

        self::assertCount(10, $resultado['linhas']);
        self::assertFalse($resultado['truncado'], 'nao truncou nada, nao pode dizer que truncou');
    }

    public function testConsultaVaziaDevolveColunasVazias(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT 1 AS n WHERE false', 500);

        self::assertSame([], $resultado['linhas']);
        self::assertSame([], $resultado['colunas']);
        self::assertFalse($resultado['truncado']);
    }

    public function testDsnVazioFalhaComMensagemClara(): void
    {
        $conexao = new ConexaoLeitura('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DATABASE_URL_LEITURA/');

        $conexao->conexao();
    }
}
```

- [ ] **Passo 2: Rodar o teste e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/ConexaoLeituraTest.php'
```

Esperado: FALHA com `Class "App\Mcp\Service\ConexaoLeitura" not found`.

- [ ] **Passo 3: Implementar a conexão**

`app/src/Mcp/Service/ConexaoLeitura.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Conexão SOMENTE LEITURA com o banco, usada apenas pelas ferramentas MCP.
 *
 * Construída à mão com DriverManager, e não como uma segunda conexão do bundle do Doctrine,
 * de propósito: declarar `doctrine.dbal.connections` obrigaria a reestruturar a conexão
 * padrão do sistema inteiro. Aqui o raio de alcance é zero — nada fora do MCP enxerga isto.
 *
 * A tranca real é o usuário do PostgreSQL, que só tem SELECT. As duas linhas de `SET` abaixo
 * são cinto e suspensório: ajudam contra engano, mas não são a garantia.
 */
final class ConexaoLeitura
{
    private ?Connection $conexao = null;

    public function __construct(
        private readonly string $dsn,
        private readonly int $timeoutSegundos = 15,
    ) {}

    public function conexao(): Connection
    {
        if ($this->conexao !== null) {
            return $this->conexao;
        }

        if (trim($this->dsn) === '') {
            throw new \RuntimeException(
                'DATABASE_URL_LEITURA não está configurada. O MCP não sobe sem a conexão de '
                . 'leitura — configure a variável no .env.prod da VPS.',
            );
        }

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
        $conexao = DriverManager::getConnection($parser->parse($this->dsn));

        $conexao->executeStatement(sprintf('SET statement_timeout = %d', $this->timeoutSegundos * 1000));
        $conexao->executeStatement('SET default_transaction_read_only = on');

        return $this->conexao = $conexao;
    }

    /**
     * Lê linha a linha, parando na primeira além do teto. Carregar tudo para cortar depois
     * derrubaria o processo por memória numa consulta grande — e aí o teto não teria servido
     * para nada.
     *
     * @param array<string, mixed> $parametros Parâmetros ligados (`:nome` no SQL)
     *
     * @return array{colunas: list<string>, linhas: list<array<string, mixed>>, truncado: bool}
     */
    public function consultar(string $sql, int $limite, array $parametros = []): array
    {
        $resultado = $this->conexao()->executeQuery($sql, $parametros);

        $linhas = [];
        $truncado = false;

        while (($linha = $resultado->fetchAssociative()) !== false) {
            if (count($linhas) >= $limite) {
                $truncado = true;
                break;
            }
            $linhas[] = $linha;
        }

        $resultado->free();

        return [
            'colunas'  => $linhas === [] ? [] : array_keys($linhas[0]),
            'linhas'   => $linhas,
            'truncado' => $truncado,
        ];
    }
}
```

- [ ] **Passo 4: Registrar o serviço com o DSN injetado**

Em `app/config/services.yaml`, dentro de `services:`, ao lado dos outros serviços
explícitos:

```yaml
    # Conexão somente-leitura das ferramentas MCP. SEM default de propósito: ausente a
    # variável, o servidor falha com mensagem clara em vez de cair na conexão de escrita.
    App\Mcp\Service\ConexaoLeitura:
        arguments:
            $dsn: '%env(default::DATABASE_URL_LEITURA)%'
```

- [ ] **Passo 5: Rodar o teste e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/ConexaoLeituraTest.php'
```

Esperado: PASSA, 8 testes.

- [ ] **Passo 6: Provar o teste reintroduzindo o defeito**

Teste verde não prova nada sozinho. Troque temporariamente, em `ConexaoLeituraTest`, o DSN
de leitura pelo DSN administrativo:

```php
// TEMPORÁRIO — apagar depois de conferir
self::$dsnLeitura = $_ENV['DATABASE_URL'];
```

Rode de novo. Esperado: `testRecusaInsert`, `testRecusaUpdate` e `testRecusaDelete`
**falham** (o admin escreve à vontade). Se algum continuar verde, o teste é decorativo e
precisa ser corrigido antes de seguir.

**Desfaça a alteração** e confirme que a suíte volta verde antes do commit.

- [ ] **Passo 7: Commit**

```bash
git add app/src/Mcp/Service/ConexaoLeitura.php app/config/services.yaml app/tests/Mcp/Functional/ConexaoLeituraTest.php
git commit -m "conexao de leitura do mcp e o banco recusa qualquer escrita"
```

---

## Task 3: Ferramenta `consultar_sql`

Entrega: a ferramenta que executa a consulta, aplica o teto de 500 linhas, registra no log e
transforma erro de banco em erro de ferramenta legível.

**Arquivos:**
- Criar: `app/src/Mcp/Tool/ConsultarSqlTool.php`
- Criar: `app/tests/Mcp/Functional/ConsultarSqlToolTest.php`

**Interfaces:**
- Consome: `App\Mcp\Service\ConexaoLeitura::consultar(string $sql, int $limite): array`
  (Task 2)
- Produz:
  - `App\Mcp\Tool\ConsultarSqlTool::__construct(ConexaoLeitura $conexao, LoggerInterface $mcpLogger)`
  - `ConsultarSqlTool::consultar(string $sql): array` → `['colunas' => …, 'linhas' => …,
    'total' => int, 'truncado' => bool]`
  - Constante pública `ConsultarSqlTool::LIMITE_LINHAS = 500`

- [ ] **Passo 1: Escrever o teste que falha**

`app/tests/Mcp/Functional/ConsultarSqlToolTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Mcp\Tool\ConsultarSqlTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConsultarSqlToolTest extends TestCase
{
    private function ferramenta(): ConsultarSqlTool
    {
        $dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($dsn);

        // Neste teste a conexão administrativa serve: o que está sob prova é o
        // comportamento da FERRAMENTA (teto, formato, erro). A recusa de escrita já foi
        // provada em ConexaoLeituraTest, contra a role restrita.
        return new ConsultarSqlTool(new ConexaoLeitura($dsn), new NullLogger());
    }

    public function testDevolveColunasLinhasETotal(): void
    {
        $resultado = $this->ferramenta()->consultar('SELECT 7 AS sete');

        self::assertSame(['sete'], $resultado['colunas']);
        self::assertSame([['sete' => 7]], $resultado['linhas']);
        self::assertSame(1, $resultado['total']);
        self::assertFalse($resultado['truncado']);
    }

    public function testTruncaEm500Linhas(): void
    {
        $resultado = $this->ferramenta()->consultar('SELECT generate_series(1, 900) AS n');

        self::assertSame(ConsultarSqlTool::LIMITE_LINHAS, $resultado['total']);
        self::assertCount(ConsultarSqlTool::LIMITE_LINHAS, $resultado['linhas']);
        self::assertTrue($resultado['truncado']);
    }

    public function testSqlInvalidoViraErroDeFerramentaComMensagemEmPortugues(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Falha ao executar a consulta/');

        $this->ferramenta()->consultar('SELECT * FROM tabela_que_nao_existe_nenhuma');
    }

    public function testConsultaLentaEstouraOTimeoutEViraErroDeFerramenta(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        // 1 segundo de teto contra um sleep de 3: prova o statement_timeout sem fazer a
        // suíte esperar os 15 segundos de produção.
        $ferramenta = new ConsultarSqlTool(new ConexaoLeitura($dsn, 1), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Falha ao executar a consulta/');

        $ferramenta->consultar('SELECT pg_sleep(3)');
    }

    public function testRegistraAConsultaNoLog(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $registros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->registros[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        (new ConsultarSqlTool(new ConexaoLeitura($dsn), $logger))->consultar('SELECT 1 AS n');

        self::assertCount(1, $logger->registros);
        self::assertSame('SELECT 1 AS n', $logger->registros[0]['context']['sql']);
        self::assertSame(1, $logger->registros[0]['context']['linhas']);
        self::assertArrayHasKey('duracao_ms', $logger->registros[0]['context']);
    }
}
```

- [ ] **Passo 2: Rodar o teste e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/ConsultarSqlToolTest.php'
```

Esperado: FALHA com `Class "App\Mcp\Tool\ConsultarSqlTool" not found`.

- [ ] **Passo 3: Implementar a ferramenta**

`app/src/Mcp/Tool/ConsultarSqlTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Service\ConexaoLeitura;
use Psr\Log\LoggerInterface;

/**
 * Ferramenta MCP `consultar_sql`.
 *
 * Não filtra tenant, não checa permissão e não passa por UseCase — enxerga o banco inteiro,
 * de todos os escritórios. É a ferramenta de investigação do dono, e é o mesmo alcance que o
 * `psql` que ele já usa à mão. Decisão consciente, registrada na spec.
 */
final class ConsultarSqlTool
{
    public const LIMITE_LINHAS = 500;

    public function __construct(
        private readonly ConexaoLeitura $conexao,
        private readonly LoggerInterface $mcpLogger,
    ) {}

    /**
     * Executa uma consulta SELECT no banco do JusPrime e devolve as linhas.
     *
     * Somente leitura: o usuário do banco não tem permissão de escrita. No máximo 500 linhas
     * são devolvidas; acima disso o campo `truncado` vem true e a consulta deve ser refinada
     * (com LIMIT, agregação ou filtro mais estreito).
     *
     * @param string $sql Consulta SELECT a executar
     *
     * @return array{colunas: list<string>, linhas: list<array<string, mixed>>, total: int, truncado: bool}
     */
    public function consultar(string $sql): array
    {
        $inicio = microtime(true);

        try {
            $resultado = $this->conexao->consultar($sql, self::LIMITE_LINHAS);
        } catch (\Throwable $erro) {
            $this->mcpLogger->error('consulta falhou', [
                'sql'  => $sql,
                'erro' => $erro->getMessage(),
            ]);

            // Vira CallToolResult::error() no SDK — não derruba o processo, então uma consulta
            // errada custa uma resposta, não a sessão inteira.
            throw new \RuntimeException(
                sprintf('Falha ao executar a consulta: %s', $erro->getMessage()),
                0,
                $erro,
            );
        }

        $duracaoMs = (int) round((microtime(true) - $inicio) * 1000);

        $this->mcpLogger->info('consulta executada', [
            'sql'        => $sql,
            'linhas'     => count($resultado['linhas']),
            'truncado'   => $resultado['truncado'],
            'duracao_ms' => $duracaoMs,
        ]);

        return [
            'colunas'  => $resultado['colunas'],
            'linhas'   => $resultado['linhas'],
            'total'    => count($resultado['linhas']),
            'truncado' => $resultado['truncado'],
        ];
    }
}
```

- [ ] **Passo 4: Rodar o teste e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/ConsultarSqlToolTest.php'
```

Esperado: PASSA, 5 testes.

- [ ] **Passo 5: Commit**

```bash
git add app/src/Mcp/Tool/ConsultarSqlTool.php app/tests/Mcp/Functional/ConsultarSqlToolTest.php
git commit -m "ferramenta consultar_sql com teto de linhas e log de auditoria"
```

---

## Task 4: Ferramenta `descrever_esquema`

Entrega: a ferramenta que lista tabelas e descreve colunas/índices — o que impede o modelo de
inventar nome de coluna ao escrever SQL.

**Arquivos:**
- Criar: `app/src/Mcp/Tool/DescreverEsquemaTool.php`
- Criar: `app/tests/Mcp/Functional/DescreverEsquemaToolTest.php`

**Interfaces:**
- Consome: `App\Mcp\Service\ConexaoLeitura::consultar(string $sql, int $limite): array`
  (Task 2)
- Produz:
  - `App\Mcp\Tool\DescreverEsquemaTool::__construct(ConexaoLeitura $conexao)`
  - `DescreverEsquemaTool::descrever(?string $tabela = null): array`

- [ ] **Passo 1: Escrever o teste que falha**

`app/tests/Mcp/Functional/DescreverEsquemaToolTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Mcp\Tool\DescreverEsquemaTool;
use PHPUnit\Framework\TestCase;

final class DescreverEsquemaToolTest extends TestCase
{
    private function ferramenta(): DescreverEsquemaTool
    {
        $dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($dsn);

        return new DescreverEsquemaTool(new ConexaoLeitura($dsn));
    }

    public function testSemArgumentoListaAsTabelas(): void
    {
        $resultado = $this->ferramenta()->descrever();

        self::assertContains('tenant', $resultado['tabelas']);
        self::assertContains('cliente', $resultado['tabelas']);
        self::assertArrayNotHasKey('colunas', $resultado);
    }

    public function testDescreveColunasDeUmaTabela(): void
    {
        $resultado = $this->ferramenta()->descrever('cliente');

        self::assertSame('cliente', $resultado['tabela']);

        $porNome = array_column($resultado['colunas'], null, 'coluna');

        self::assertArrayHasKey('email', $porNome, 'coluna email sumiu da tabela cliente');
        self::assertSame('NAO', $porNome['email']['aceita_nulo']);
        self::assertArrayHasKey('tenant_id', $porNome);
    }

    public function testTrazOsIndicesDaTabela(): void
    {
        $resultado = $this->ferramenta()->descrever('cliente');

        self::assertNotEmpty($resultado['indices']);
    }

    public function testTabelaInexistenteFalhaComMensagemClara(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/não existe/');

        $this->ferramenta()->descrever('tabela_que_nao_existe_nenhuma');
    }
}
```

- [ ] **Passo 2: Rodar o teste e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/DescreverEsquemaToolTest.php'
```

Esperado: FALHA com `Class "App\Mcp\Tool\DescreverEsquemaTool" not found`.

- [ ] **Passo 3: Implementar a ferramenta**

`app/src/Mcp/Tool/DescreverEsquemaTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Service\ConexaoLeitura;

/**
 * Ferramenta MCP `descrever_esquema`.
 *
 * Não é conveniência: sem ela, quem escreve o SQL chuta nome de coluna. Já custou medição
 * errada neste projeto — uma consulta que roda contra coluna inventada não dá erro óbvio,
 * dá número errado.
 */
final class DescreverEsquemaTool
{
    private const LIMITE = 2000;

    public function __construct(
        private readonly ConexaoLeitura $conexao,
    ) {}

    /**
     * Descreve o esquema do banco do JusPrime.
     *
     * Sem argumento, lista todas as tabelas. Com o nome de uma tabela, devolve suas colunas
     * (tipo, se aceita nulo, valor padrão) e seus índices. Use SEMPRE antes de escrever uma
     * consulta contra uma tabela que você não conhece.
     *
     * @param string|null $tabela Nome da tabela a descrever; omita para listar todas
     *
     * @return array<string, mixed>
     */
    public function descrever(?string $tabela = null): array
    {
        if ($tabela === null || trim($tabela) === '') {
            return ['tabelas' => $this->listarTabelas()];
        }

        $tabela = trim($tabela);

        if (!in_array($tabela, $this->listarTabelas(), true)) {
            throw new \RuntimeException(sprintf(
                'A tabela "%s" não existe no schema public. Rode descrever_esquema sem '
                . 'argumento para ver a lista.',
                $tabela,
            ));
        }

        return [
            'tabela'  => $tabela,
            'colunas' => $this->colunas($tabela),
            'indices' => $this->indices($tabela),
        ];
    }

    /** @return list<string> */
    private function listarTabelas(): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
             ORDER BY table_name",
            self::LIMITE,
        );

        return array_map(
            static fn (array $linha): string => (string) $linha['table_name'],
            $resultado['linhas'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function colunas(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT column_name AS coluna,
                    data_type AS tipo,
                    CASE is_nullable WHEN 'YES' THEN 'SIM' ELSE 'NAO' END AS aceita_nulo,
                    column_default AS padrao
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :tabela
             ORDER BY ordinal_position",
            self::LIMITE,
            ['tabela' => $tabela],
        );

        return $resultado['linhas'];
    }

    /** @return list<array<string, mixed>> */
    private function indices(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT indexname AS indice, indexdef AS definicao
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = :tabela
             ORDER BY indexname",
            self::LIMITE,
            ['tabela' => $tabela],
        );

        return $resultado['linhas'];
    }
}
```

- [ ] **Passo 4: Rodar o teste e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/DescreverEsquemaToolTest.php'
```

Esperado: PASSA, 4 testes.

Se `testDescreveColunasDeUmaTabela` falhar dizendo que `email` aceita nulo, **não ajuste o
teste** — confira o mapeamento real em `app/src/Cliente/Entity/Cliente.php`. O teste existe
para bater com o banco, e divergência aqui é informação, não incômodo.

- [ ] **Passo 5: Commit**

```bash
git add app/src/Mcp/Tool/DescreverEsquemaTool.php app/tests/Mcp/Functional/DescreverEsquemaToolTest.php
git commit -m "ferramenta descrever_esquema para o sql nao chutar coluna"
```

---

## Task 5: Ligar as ferramentas ao servidor

Entrega: `tools/list` anuncia as duas ferramentas e `tools/call` executa de verdade, ponta a
ponta pelo protocolo.

**Arquivos:**
- Modificar: `app/src/Mcp/Command/McpServerCommand.php`
- Modificar: `app/tests/Mcp/Functional/McpServerCommandTest.php`

**Interfaces:**
- Consome: `ConsultarSqlTool::consultar(string $sql): array` (Task 3),
  `DescreverEsquemaTool::descrever(?string $tabela): array` (Task 4)
- Produz: ferramentas MCP nomeadas `consultar_sql` e `descrever_esquema`

- [ ] **Passo 1: Acrescentar os testes que falham**

Adicione a `McpServerCommandTest`:

```php
    public function testAnunciaAsDuasFerramentas(): void
    {
        $mensagens = self::HANDSHAKE;
        $processo = $this->rodarServidor($mensagens);

        $resposta = $this->respostaComId($processo->getOutput(), 2);

        self::assertNotNull($resposta, 'nenhuma resposta para tools/list (id 2)');

        $nomes = array_column($resposta['result']['tools'], 'name');
        sort($nomes);

        self::assertSame(['consultar_sql', 'descrever_esquema'], $nomes);
    }

    public function testChamarConsultarSqlDevolveResultado(): void
    {
        $mensagens = self::HANDSHAKE;
        $mensagens[] = [
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'consultar_sql',
                'arguments' => ['sql' => 'SELECT 42 AS resposta'],
            ],
        ];

        $processo = $this->rodarServidor($mensagens);
        $resposta = $this->respostaComId($processo->getOutput(), 3);

        self::assertNotNull($resposta, 'nenhuma resposta para tools/call (id 3)');
        self::assertNotTrue($resposta['result']['isError'] ?? false, json_encode($resposta));
        self::assertStringContainsString('42', $resposta['result']['content'][0]['text']);
    }

    public function testSqlInvalidoViraErroDeFerramentaSemDerrubarOServidor(): void
    {
        $mensagens = self::HANDSHAKE;
        $mensagens[] = [
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'consultar_sql',
                'arguments' => ['sql' => 'SELECT * FROM nao_existe_essa_tabela'],
            ],
        ];
        // Se o servidor tivesse morrido no erro acima, esta última mensagem ficaria sem resposta.
        $mensagens[] = ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'];

        $processo = $this->rodarServidor($mensagens);

        $erro = $this->respostaComId($processo->getOutput(), 3);
        self::assertNotNull($erro);
        self::assertTrue($erro['result']['isError'] ?? false, 'erro de SQL deveria virar isError');

        self::assertNotNull(
            $this->respostaComId($processo->getOutput(), 4),
            'o servidor morreu no erro de SQL em vez de seguir respondendo',
        );
    }
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/McpServerCommandTest.php'
```

Esperado: FALHA — `tools/list` devolve lista vazia, então `assertSame` reclama de `[]`.

- [ ] **Passo 3: Registrar as ferramentas no comando**

Substitua o `execute()` de `McpServerCommand` (e acrescente o construtor):

```php
    public function __construct(
        private readonly ConsultarSqlTool $consultarSql,
        private readonly DescreverEsquemaTool $descreverEsquema,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $servidor = Server::builder()
            ->setServerInfo('JusPrime (leitura)', '1.0.0')
            ->setInstructions(
                'Acesso SOMENTE LEITURA ao banco do JusPrime. Nenhuma ferramenta grava dados. '
                . 'Chame descrever_esquema antes de escrever SQL contra uma tabela desconhecida — '
                . 'nome de coluna chutado devolve número errado, não erro.',
            )
            ->addTool(
                handler: [$this->consultarSql, 'consultar'],
                name: 'consultar_sql',
            )
            ->addTool(
                handler: [$this->descreverEsquema, 'descrever'],
                name: 'descrever_esquema',
            )
            ->build();

        return $servidor->run(new StdioTransport());
    }
```

Acrescente os `use`:

```php
use App\Mcp\Tool\ConsultarSqlTool;
use App\Mcp\Tool\DescreverEsquemaTool;
```

Descrição e schema de entrada saem do docblock e da assinatura por reflexão — por isso os
docblocks das Tasks 3 e 4 são conteúdo, não enfeite.

- [ ] **Passo 4: Rodar e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp/Functional/McpServerCommandTest.php'
```

Esperado: PASSA, 5 testes.

- [ ] **Passo 5: Commit**

```bash
git add app/src/Mcp/Command/McpServerCommand.php app/tests/Mcp/Functional/McpServerCommandTest.php
git commit -m "servidor mcp anuncia e executa as duas ferramentas de leitura"
```

---

## Task 6: Canal de log isolado do stdout e runbook do dono

Entrega: o log do MCP não corrompe o protocolo, e o dono tem o passo a passo para colocar
isso em produção.

**Arquivos:**
- Modificar: `app/config/packages/monolog.yaml`
- Criar: `docs/runbooks/mcp-investigacao-prod.md`

**Interfaces:**
- Consome: o serviço de log injetado como `$mcpLogger` em `ConsultarSqlTool` (Task 3) — o
  autowiring do Monolog liga o canal `mcp` a esse nome de argumento
- Produz: canal Monolog `mcp`

- [ ] **Passo 1: Declarar o canal e tirá-lo do console**

Em `app/config/packages/monolog.yaml`:

```yaml
monolog:
    channels:
        - deprecation
        - audit
        - mcp
```

No bloco `when@dev`, o handler `console` escreve na **saída padrão** — em modo STDIO isso
corrompe o protocolo. Exclua o canal:

```yaml
            console:
                type: console
                process_psr_3_messages: false
                channels: ["!event", "!doctrine", "!console", "!mcp"]
```

E acrescente, em `when@dev` e em `when@prod`, o handler de arquivo do MCP:

```yaml
            mcp:
                type: stream
                path: "%kernel.logs_dir%/mcp.log"
                level: info
                channels: ["mcp"]
```

Em `when@prod`, tire também o canal do handler `main` (que despacha para `nested`):

```yaml
            main:
                channels: ["!deprecation", "!audit", "!mcp"]
```

> **Por que arquivo e não `php://stderr`, que é a convenção de prod deste projeto:** o
> `stderr` de um `docker exec` vai para o cano do SSH, não para o `docker logs`. O Claude
> Code descarta esse fluxo, então o registro das consultas se perderia justamente onde ele
> serve para alguma coisa. Em compensação, o arquivo mora dentro do container: **um redeploy
> apaga o histórico**. É aceitável para trilha de investigação; não use como registro
> permanente.

- [ ] **Passo 2: Conferir que o canal existe e o stdout continua limpo**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console lint:yaml config'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Mcp'
```

Esperado: YAML válido e a pasta `tests/Mcp` inteira verde, com
`testHandshakeRespondeEStdoutSoTemJsonRpc` passando.

- [ ] **Passo 3: Escrever o runbook**

`docs/runbooks/mcp-investigacao-prod.md`, com estas cinco seções e os comandos prontos para
colar:

1. **Criar a role de leitura no PostgreSQL de prod** (executado pelo dono na VPS):

```bash
docker exec jusprime_db_prod psql -U jusprime -d prime -c "
CREATE ROLE jusprime_leitura LOGIN PASSWORD 'TROQUE_ESTA_SENHA';
GRANT CONNECT ON DATABASE prime TO jusprime_leitura;
GRANT USAGE ON SCHEMA public TO jusprime_leitura;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO jusprime_leitura;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO jusprime_leitura;
"
```

2. **Definir a variável no `.env.prod` da VPS** (o build de produção faz `rm .env`, então
   isto não pode morar no `.env` versionado):

```
DATABASE_URL_LEITURA="pgsql://jusprime_leitura:TROQUE_ESTA_SENHA@db:5432/prime"
```

3. **Alias SSH** em `~/.ssh/config` na máquina local:

```
Host bluejus
    HostName 72.60.146.89
    User root
    IdentityFile ~/.ssh/id_ed25519
    ServerAliveInterval 30
```

4. **Deploy** — sem ele, `mcp/sdk` não existe em prod (imagem *baked*):

```bash
# Execute manualmente no terminal externo, dentro da VPS
./scripts/deploy-prod-tls.sh
```

5. **Registrar o servidor no Claude Code**, na máquina local:

```bash
claude mcp add jusprime-prod -- ssh bluejus "docker exec -i jusprime_php_prod php bin/console mcp:server"
```

Inclua também uma seção **"conferir que funcionou"**, mandando pedir
`descrever_esquema` sem argumento e depois um `SELECT count(*) FROM tenant`, e uma seção
**"se der errado"** cobrindo os dois modos de falha mais prováveis: `DATABASE_URL_LEITURA`
ausente (mensagem clara vinda de `ConexaoLeitura`) e sujeira no `stdout` (o cliente diz
apenas "servidor falhou"; diagnostique rodando o comando à mão com a entrada do handshake).

- [ ] **Passo 4: Rodar a suíte inteira**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'
```

Esperado: verde. A contagem deve subir em **22 testes** em relação à base
(2 na Task 1 + 8 na Task 2 + 5 na Task 3 + 4 na Task 4 + 3 na Task 5).

- [ ] **Passo 5: Commit**

```bash
git add app/config/packages/monolog.yaml docs/runbooks/mcp-investigacao-prod.md
git commit -m "isola o log do mcp do stdout e documenta a subida em producao"
```

---

## Verificação final (antes de entregar ao dono)

- [ ] `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'` — suíte inteira verde
- [ ] `docker exec jusprime_php_dev bash -c 'cd app && php bin/console lint:container'`
- [ ] `git log --oneline -6` mostra os seis commits, e `git status` está limpo
- [ ] Nenhum arquivo fora de `app/src/Mcp/`, `app/tests/Mcp/`, `app/config/`,
      `app/composer.*` e `docs/` foi tocado
- [ ] `grep -rn "echo\|var_dump\|dump(" app/src/Mcp/` não devolve nada

**O smoke contra produção é do dono.** O SSH do agente para a VPS foi bloqueado por
classificador em sessão anterior; tudo acima roda contra o dev. Entregue com a suíte verde e
diga o que precisa ser olhado: as cinco etapas do runbook, nessa ordem.
