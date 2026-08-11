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

        // $_ENV['DATABASE_URL'] chega SEM o sufixo de teste: quem aplica '_test%TEST_TOKEN%'
        // é o `dbname_suffix` do doctrine.yaml, e só dentro do container de serviços do
        // Symfony — uma conexão manual via DriverManager não passa por ali. Sem recalcular o
        // sufixo aqui, este `admin` cairia no banco de DEV ("saas"), não no banco de teste da
        // frente ("saas_test<TEST_TOKEN>"): a role seria criada no banco errado e os GRANT
        // seguintes (que dependem de EM QUAL banco a conexão está) não valeriam para o banco
        // onde a suíte realmente roda.
        $banco = ($params['dbname'] ?? 'saas') . '_test' . (getenv('TEST_TOKEN') ?: '');
        $params['dbname'] = $banco;

        $admin = DriverManager::getConnection($params);

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

    /**
     * Os quatro testes de recusa abaixo (Insert/Update/Delete + o que isola o GRANT)
     * desligam `default_transaction_read_only` antes de tentar escrever. Não é ruído: o
     * PostgreSQL checa o modo somente-leitura da transação ANTES de checar ACL — confirmado
     * batendo direto no banco. Com o `SET ... = on` que `ConexaoLeitura::conexao()` aplica
     * (o "cinto"), TODA tentativa de escrita — do role restrito e também do admin — para na
     * mensagem "cannot execute ... in a read-only transaction", e a checagem de permissão
     * nunca chega a rodar. Isso tem duas consequências:
     *
     *   1. Não dá para afirmar "permission denied" sem desligar o cinto primeiro — a
     *      mensagem simplesmente não existe no caminho padrão, para NENHUM usuário.
     *   2. Sem desligar o cinto, o Passo 6 (trocar o DSN pelo administrativo) nunca
     *      distingue "recusado pela role" de "recusado pela sessão": as duas causas dão a
     *      MESMA mensagem, e o teste fica verde com qualquer DSN — foi assim que a primeira
     *      versão deste arquivo passou 8/8 mesmo com o DSN admin.
     *
     * Desligar o cinto pela própria sessão não exige privilégio nenhum (é só um `SET`) — é
     * exatamente o que um agente curioso conseguiria fazer via `consultar()`. Por isso a
     * "fivela" (o GRANT do role, testado aqui SEM a ajuda do cinto) é que precisa segurar
     * sozinha, e é o que estes testes agora provam.
     */
    public function testRecusaInsert(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);
        $conexao->conexao()->executeStatement('SET default_transaction_read_only = off');

        // Não basta capturar `DBAL\Exception` — isso também aceitaria "coluna não existe" ou
        // erro de sintaxe como se fosse recusa de permissão (foi exatamente o que aconteceu
        // com o typo `nome`/`ativo` numa versão anterior deste teste). Afirmar a MENSAGEM
        // real do PostgreSQL ("permission denied for table ...") é o que garante que a
        // recusa é por ACL, e não por qualquer outro motivo que também lançaria a mesma
        // exceção genérica.
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        // Colunas reais de `tenant`: `name`/`is_active` (não `nome`/`ativo` — a entidade é
        // legado, em inglês).
        $conexao->conexao()->executeStatement(
            'INSERT INTO tenant (name, is_active, created_at) VALUES (\'invasor\', true, now())',
        );
    }

    public function testRecusaUpdate(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);
        $conexao->conexao()->executeStatement('SET default_transaction_read_only = off');

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        $conexao->conexao()->executeStatement('UPDATE tenant SET name = \'invadido\'');
    }

    public function testRecusaDelete(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);
        $conexao->conexao()->executeStatement('SET default_transaction_read_only = off');

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        $conexao->conexao()->executeStatement('DELETE FROM tenant');
    }

    /**
     * Existe separado dos três `testRecusa*` acima, com nome e comentário explícitos, porque
     * é ESTE que qualquer leitor daqui a seis meses vai procurar quando quiser confirmar "e
     * se o cinto (`SET`) não estivesse lá, ainda travaria?" sem precisar reconstruir esse
     * raciocínio a partir de um teste de INSERT genérico. Mecanicamente ele faz o mesmo que
     * `testRecusaInsert` (que também precisa desligar o cinto para a mensagem virar
     * "permission denied" de verdade — ver o comentário acima) — a duplicação é proposital:
     * é o preço de deixar a prova do GRANT nomeada e óbvia, em vez de implícita.
     *
     * A garantia de fundo: sem este teste (ou o equivalente dentro de `testRecusaInsert`), a
     * spec inteira ficaria apoiada só no `SET` — que é sessão, não permissão, e qualquer
     * chamador desfaz com um `SET` comum, sem privilégio nenhum.
     */
    public function testGrantRecusaEscritaMesmoComOReadOnlyDaSessaoDesligado(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);
        $conexao->conexao()->executeStatement('SET default_transaction_read_only = off');

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        $conexao->conexao()->executeStatement(
            'INSERT INTO tenant (name, is_active, created_at) VALUES (\'invasor\', true, now())',
        );
    }

    /**
     * Os testes de recusa acima provam a RECUSA, mas nenhum inspeciona a SESSÃO em si — e
     * nenhum deles passa um `timeoutSegundos` diferente do default. Sem este teste, apagar as
     * duas linhas de `SET` em `ConexaoLeitura::conexao()` (ou trocar `* 1000` por `* 1` no
     * cálculo do timeout) não deixaria NADA vermelho: os `testRecusa*` seguem recusando pelo
     * GRANT (que é a garantia real, independente do `SET`), e os testes de leitura nem olham
     * para `SHOW`. Este teste bate direto no `SHOW` do PostgreSQL — não confia no que
     * `ConexaoLeitura` *diz* que configurou, confere o que a sessão *tem* configurado.
     */
    public function testConstrutorAplicaTimeoutESessaoSomenteLeituraNaConexaoReal(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura, 7);

        $timeout = $conexao->conexao()->fetchOne('SHOW statement_timeout');
        $somenteLeitura = $conexao->conexao()->fetchOne('SHOW default_transaction_read_only');

        self::assertSame('7s', $timeout);
        self::assertSame('on', $somenteLeitura);
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
