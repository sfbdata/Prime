<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Tests\Mcp\BancoDeLeituraDeTeste;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * A garantia de que este MCP não escreve NÃO é análise do texto do SQL — isso se burla com
 * comentário, encadeamento ou CTE com RETURNING. A garantia é o próprio PostgreSQL recusar,
 * porque o usuário não tem permissão. Este teste prova a recusa contra o banco de verdade.
 *
 * A role restrita usada aqui é provisionada por `BancoDeLeituraDeTeste` (conexão separada, fora
 * da transação do DAMA — uma role criada dentro da transação do teste não existiria para uma
 * conexão nova, que é justamente o que vamos abrir em seguida).
 */
final class ConexaoLeituraTest extends TestCase
{
    private static string $dsnLeitura = '';
    private static string $dsnSemInsert = '';

    public static function setUpBeforeClass(): void
    {
        self::$dsnLeitura = BancoDeLeituraDeTeste::dsnLeitura();
        self::$dsnSemInsert = BancoDeLeituraDeTeste::dsnSemInsert();
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

    /**
     * O par que transforma a promessa do runbook em invariante.
     *
     * Até esta correção, NADA verificava que `DATABASE_URL_LEITURA` apontava para a role
     * restrita: o "conferir que funcionou" do runbook só testa que a LEITURA funciona, e a
     * leitura funciona igualmente bem com o DSN administrativo. Com o DSN errado, um
     * `SET default_transaction_read_only = off` avulso (que não exige privilégio nenhum e cabe
     * numa única chamada de `consultar_sql`, já que a conexão é memoizada e o processo dura a
     * sessão inteira) devolveria escrita plena — inclusive
     * `WITH x AS (DELETE FROM tenant ... RETURNING id) SELECT * FROM x`.
     *
     * Este primeiro teste é o controle: com a role certa, a conexão sobe normalmente.
     */
    public function testRoleRestritaSobeNormalmente(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        self::assertSame(
            BancoDeLeituraDeTeste::ROLE,
            $conexao->conexao()->fetchOne('SELECT current_user'),
        );
    }

    /**
     * E este é o que importa: com o DSN administrativo — exatamente o engano que o runbook não
     * conseguia detectar — a conexão é RECUSADA.
     */
    public function testDsnAdministrativoERecusadoPorTerPermissaoDeEscrita(): void
    {
        $conexao = new ConexaoLeitura(BancoDeLeituraDeTeste::dsnAdministrativo());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/permissão de ESCRITA/');

        $conexao->conexao();
    }

    /**
     * A verificação acima compara o retorno de `fetchOne` contra uma lista de valores
     * (`true`, `'t'`, `'true'`, `'1'`, `1`). Este teste mede qual é o valor REAL neste driver,
     * em vez de deixar a suposição implícita: se um upgrade de PHP/DBAL mudar o tipo devolvido,
     * é aqui que aparece — e não em silêncio, com a invariante deixando passar tudo.
     */
    public function testFetchOneDevolveBooleanoNativoParaAVerificacaoDeEscrita(): void
    {
        $sql = "SELECT bool_or(has_table_privilege(current_user, c.oid, 'INSERT, UPDATE, DELETE, TRUNCATE'))
                FROM pg_class c
                WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p', 'f')";

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);

        $admin = DriverManager::getConnection($parser->parse(BancoDeLeituraDeTeste::dsnAdministrativo()));
        $leitura = DriverManager::getConnection($parser->parse(self::$dsnLeitura));

        self::assertSame(true, $admin->fetchOne($sql), 'admin: esperado booleano nativo true');
        self::assertSame(false, $leitura->fetchOne($sql), 'role restrita: esperado booleano nativo false');

        $admin->close();
        $leitura->close();
    }

    /**
     * O buraco que este teste fecha: até esta correção, `ConexaoLeitura` só perguntava ao
     * Postgres por `INSERT` (`has_table_privilege(..., 'INSERT')`). Uma role com `SELECT,
     * UPDATE, DELETE` — sem `INSERT` — não tem NENHUM privilégio de `INSERT`, então passava pela
     * checagem como se fosse somente-leitura. Na prática ela escreve de verdade: basta um `SET
     * default_transaction_read_only = off` (que não exige privilégio nenhum, e é exatamente o
     * "cinto" que `consultar_sql` consegue desligar) para o `UPDATE`/`DELETE` valerem.
     *
     * Este teste tem que ficar VERMELHO se `SQL_PODE_ESCREVER` voltar a perguntar só por
     * `INSERT` — é a prova por mutação registrada no relatório da tarefa.
     */
    public function testRecusaRoleComUpdateEDeleteMasSemInsert(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnSemInsert);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/permissão de ESCRITA/');

        $conexao->conexao();
    }
}
