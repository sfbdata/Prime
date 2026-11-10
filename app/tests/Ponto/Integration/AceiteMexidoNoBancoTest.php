<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\EstadoDoAceiteDaFolha;
use App\Ponto\UseCase\EmitirFolhaDoPontoUseCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A prova do aceite mexida no banco (spec ponto-desenho-0811, E16, decisões 3, 4 e 14). Com o segredo do HMAC, o aceite
 * só vale se o selo da folha vista e o dele conferem, se a linha dele diz o mesmo que o texto que ele selou e se a folha
 * ligada pela FK é a que ele selou (o código selado nela, o escritório, a pessoa e a competência). O que não confere
 * conta como "alterada depois do aceite": o selo da barra muda, o "Assinar folha" volta e a folha emitida depois sai sem
 * carimbo e sem o aceite nos dados assinados.
 *
 * Cada campo comparado tem um caso que só ele pega: tirar qualquer um da comparação derruba um teste.
 */
#[Group('ponto')]
#[CoversClass(EstadoDoAceiteDaFolha::class)]
#[CoversClass(EmitirFolhaDoPontoUseCase::class)]
final class AceiteMexidoNoBancoTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    /** Um código no formato BJF que nenhuma emissão do teste usa. */
    private const CODIGO_DE_FORA = 'BJF-ABCD-EFGH-JK23';

    private Tenant $escritorio;
    private User $maria;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        $this->segredo('segredo-de-teste-do-aceite-mexido');
        $this->escritorio = $this->escritorio('Escritório do aceite mexido');
        $this->maria = $this->pessoaComOutubro($this->escritorio);
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'ressalva', 'O dia 6 foi de folga.', '189.6.10.2', 'Mozilla/5.0'), $this->maria, $this->escritorio);
    }

    #[TestDox('Sem mexer: a prova confere, o aceite vale e vai carimbado e selado na folha nova (o controle dos casos mexidos)')]
    public function testIntactoVale(): void
    {
        $estado = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);
        $emitida = $this->emitirFolha()->executar($this->escritorio, $this->maria, 2026, 10, $this->maria);

        self::assertSame([false, false, 'Ressalva em 09/11/2026'], [$estado->alterada, $estado->podeAssinar, $estado->textoDoSelo()]);
        self::assertSame('Aceite com ressalva em 09/11/2026, 10:15:30', $emitida->carimbo?->texto());
        self::assertSame(SituacaoDoAceiteDaFolha::Ressalva, $this->ultimaFolhaEmitida()->aceiteSituacao);
    }

    #[DataProvider('provasMexidas')]
    #[TestDox('Mexido no banco ($_dataName): alterada, o "Assinar folha" volta, a folha nova sai sem carimbo e o novo aceite vale')]
    public function testProvaMexidaContaComoAlterada(string ...$sqls): void
    {
        $this->mexer($sqls);

        $this->assertContaComoAlterada();
    }

    /**
     * `:aceite` é o aceite gravado no setUp, `:vista` a folha vista nele e `:codigoVisto` o código dela; `:outra` é uma
     * emissão válida da Maria feita depois do aceite (mesma folha, outro código) e `:daOutraPessoa` a folha válida do
     * João, com os mesmos registros de outubro (a mesma impressão). Cada SQL muda uma linha.
     *
     * @return iterable<string, list<string>>
     */
    public static function provasMexidas(): iterable
    {
        yield 'situação do aceite trocada de ressalva para concordo' => ["UPDATE ponto_folha_aceite SET situacao = 'concordo', ressalva = NULL WHERE id = :aceite"];
        yield 'texto da ressalva trocado' => ["UPDATE ponto_folha_aceite SET ressalva = 'Concordo com tudo.' WHERE id = :aceite"];
        yield 'aceito_em trocado' => ["UPDATE ponto_folha_aceite SET aceito_em = aceito_em - INTERVAL '1 day' WHERE id = :aceite"];
        yield 'IP do aceite trocado' => ["UPDATE ponto_folha_aceite SET ip = '10.0.0.1' WHERE id = :aceite"];
        yield 'navegador do aceite trocado' => ["UPDATE ponto_folha_aceite SET navegador = 'Outro navegador' WHERE id = :aceite"];
        yield 'número do aceite trocado' => ['UPDATE ponto_folha_aceite SET numero = 2 WHERE id = :aceite'];
        yield 'folha vista apontada para outra emissão' => ['UPDATE ponto_folha_aceite SET folha_emitida_id = :outra WHERE id = :aceite'];
        yield 'código da coluna da folha vista trocado' => ["UPDATE ponto_folha_emitida SET codigo = '" . self::CODIGO_DE_FORA . "' WHERE id = :vista"];
        // A coluna `codigo` não tem selo: trocar o código da coluna junto com a FK não pode passar. A outra emissão tem a
        // mesma impressão da folha de agora; só o código selado nela a denuncia.
        yield 'FK para outra emissão com o código da coluna trocado junto' => [
            "UPDATE ponto_folha_emitida SET codigo = '" . self::CODIGO_DE_FORA . "' WHERE id = :vista",
            'UPDATE ponto_folha_emitida SET codigo = :codigoVisto WHERE id = :outra',
            'UPDATE ponto_folha_aceite SET folha_emitida_id = :outra WHERE id = :aceite',
        ];
        yield 'FK para a folha de outra pessoa (mesmos registros) com o código da coluna trocado junto' => [
            "UPDATE ponto_folha_emitida SET codigo = '" . self::CODIGO_DE_FORA . "' WHERE id = :vista",
            'UPDATE ponto_folha_emitida SET codigo = :codigoVisto WHERE id = :daOutraPessoa',
            'UPDATE ponto_folha_aceite SET folha_emitida_id = :daOutraPessoa WHERE id = :aceite',
        ];
        yield 'HMAC do aceite trocado' => ["UPDATE ponto_folha_aceite SET hmac = repeat('0', 64) WHERE id = :aceite"];
        yield 'texto selado do aceite trocado' => ["UPDATE ponto_folha_aceite SET dados_assinados = replace(dados_assinados, 'folga', 'falta') WHERE id = :aceite"];
        // A folha vista que não confere: a impressão dos registros dentro do texto continua igual à da folha de agora,
        // então só a conferência do HMAC a pega.
        yield 'texto selado da folha vista trocado (mesma impressão)' => ["UPDATE ponto_folha_emitida SET dados_assinados = replace(dados_assinados, 'Maria da Silva', 'Marta da Silva') WHERE id = :vista"];
        yield 'HMAC da folha vista trocado' => ["UPDATE ponto_folha_emitida SET hmac = repeat('0', 64) WHERE id = :vista"];
    }

    /**
     * Quem tivesse o segredo (ou um código repetido) poderia selar de novo o texto da folha vista com outro dado e a
     * mesma impressão: o selo dela confere, e só a comparação com o que o aceite selou a pega.
     *
     * @param 'codigo'|'escritorioId'|'pessoaId'|'ano'|'mes' $campo
     */
    #[DataProvider('camposDaFolhaSelada')]
    #[TestDox('Folha vista selada de novo com outro campo ($_dataName), o selo dela conferindo: alterada')]
    public function testFolhaVistaSeladaComOutroDadoContaComoAlterada(string $campo): void
    {
        $original = DadosDaFolhaEmitida::deCanonico($this->aceiteDoSetUp()->getFolhaVista()->getDadosAssinados());
        $valor = match ($campo) {
            'codigo'       => self::CODIGO_DE_FORA,
            'escritorioId' => $original->escritorioId + 1,
            'pessoaId'     => $original->pessoaId + 1,
            'ano'          => $original->ano - 1,
            'mes'          => $original->mes - 1,
        };
        $texto = (new DadosDaFolhaEmitida(...[...get_object_vars($original), $campo => $valor]))->canonico();
        $selo = $this->seloDaFolha()->selar($texto);
        self::assertSame($original->impressaoRegistros, DadosDaFolhaEmitida::deCanonico($texto)->impressaoRegistros, 'a mesma impressão');

        $this->mexer(['UPDATE ponto_folha_emitida SET dados_assinados = :texto, hmac = :hmac, versao_chave = :versao WHERE id = :vista'], ['texto' => $texto, 'hmac' => $selo['hmac'], 'versao' => $selo['versao']]);

        $this->assertContaComoAlterada();
    }

    /** @return iterable<string, array{'codigo'|'escritorioId'|'pessoaId'|'ano'|'mes'}> */
    public static function camposDaFolhaSelada(): iterable
    {
        yield 'código' => ['codigo'];
        yield 'escritório' => ['escritorioId'];
        yield 'pessoa' => ['pessoaId'];
        yield 'ano' => ['ano'];
        yield 'mês' => ['mes'];
    }

    /**
     * O aceite movido para outro escritório, outra pessoa ou outra competência, avaliado lá contra uma folha com os
     * mesmos registros da folha vista (a mesma impressão): só a coluna movida difere do texto selado no aceite.
     *
     * @param 'tenant_id'|'user_id'|'ano'|'mes' $coluna
     */
    #[DataProvider('colunasDoAceite')]
    #[TestDox('Aceite movido no banco ($_dataName), avaliado lá com os mesmos registros: alterada e sem carimbo')]
    public function testAceiteMovidoContaComoAlterado(string $coluna): void
    {
        $agora = $this->relogioDaFolha()->agora();
        $outubro = $this->conteudoDaFolhaDoMes()->montar($this->escritorio, $this->maria, 2026, 10, $agora);
        self::assertFalse($this->estadoDoAceite()->avaliar($this->escritorio, $this->maria, $outubro, $agora)->alterada, 'controle: antes de mover, vale');
        [$escritorio, $pessoa, $ano, $mes, $valor] = $this->destinoDoAceite($coluna);
        $folhaDeLa = new ConteudoDaFolha($ano, $mes, $outubro->cabecalho, $outubro->linhas, $outubro->resumo, $outubro->intervalos);

        $this->mexer([sprintf('UPDATE ponto_folha_aceite SET %s = :valor WHERE id = :aceite', $coluna)], ['valor' => $valor]);
        $estado = $this->estadoDoAceite()->avaliar($escritorio, $pessoa, $folhaDeLa, $agora);

        self::assertSame([true, 'Alterada depois do aceite'], [$estado->alterada, $estado->textoDoSelo()]);
        self::assertNull($this->estadoDoAceite()->aceiteQueCarimba($escritorio, $pessoa, $folhaDeLa), 'sem carimbo');
    }

    /** @return iterable<string, array{'tenant_id'|'user_id'|'ano'|'mes'}> */
    public static function colunasDoAceite(): iterable
    {
        yield 'escritório' => ['tenant_id'];
        yield 'pessoa' => ['user_id'];
        yield 'ano' => ['ano'];
        yield 'mês' => ['mes'];
    }

    /**
     * @param 'tenant_id'|'user_id'|'ano'|'mes' $coluna
     *
     * @return array{Tenant, User, int, int, int} onde o aceite vai parar (escritório, pessoa, ano e mês) e o valor da coluna
     */
    private function destinoDoAceite(string $coluna): array
    {
        return match ($coluna) {
            'tenant_id' => [$outro = $this->escritorio('Outro escritório'), $this->maria, 2026, 10, (int) $outro->getId()],
            'user_id'   => [$this->escritorio, $joao = $this->pessoa('João Souza'), 2026, 10, (int) $joao->getId()],
            'ano'       => [$this->escritorio, $this->maria, 2025, 10, 2025],
            'mes'       => [$this->escritorio, $this->maria, 2026, 9, 9],
        };
    }

    /** O estado, a folha emitida e o aceite novo depois de a prova não conferir. */
    private function assertContaComoAlterada(): void
    {
        $estado = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);
        $emitida = $this->emitirFolha()->executar($this->escritorio, $this->maria, 2026, 10, $this->maria);

        self::assertSame([true, true, 'Alterada depois do aceite'], [$estado->alterada, $estado->podeAssinar, $estado->textoDoSelo()]);
        self::assertFalse($estado->carimbaAFolha());
        self::assertNull($emitida->carimbo, 'sem carimbo na emissão nova');
        self::assertNull($this->ultimaFolhaEmitida()->aceiteSituacao, 'nada desse aceite selado na folha nova');

        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);
        $depois = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);
        self::assertSame([false, false, 'Assinada em 09/11/2026'], [$depois->alterada, $depois->podeAssinar, $depois->textoDoSelo()], 'o aceite novo vale');
    }

    /**
     * Roda os SQL sobre o aceite do setUp (cada um muda uma linha) e recarrega o aceite e a folha ligada do banco.
     *
     * @param list<string>              $sqls
     * @param array<string, int|string> $extras parâmetros além dos de {@see self::parametros()}
     */
    private function mexer(array $sqls, array $extras = []): void
    {
        $aceite = $this->aceiteDoSetUp();
        $parametros = [...$this->parametros(implode("\n", $sqls), $aceite), ...$extras];
        foreach ($sqls as $sql) {
            $usados = array_filter($parametros, static fn (string $nome): bool => preg_match('/:' . $nome . '\b/', $sql) === 1, \ARRAY_FILTER_USE_KEY);
            self::assertSame(1, $this->em()->getConnection()->executeStatement($sql, $usados), $sql);
        }
        $this->em()->refresh($aceite);
        $this->em()->refresh($aceite->getFolhaVista());
    }

    /** @return array<string, int|string> */
    private function parametros(string $sql, AceiteDaFolhaDoPonto $aceite): array
    {
        $vista = $aceite->getFolhaVista();
        $parametros = ['aceite' => (int) $aceite->getId(), 'vista' => (int) $vista->getId(), 'codigoVisto' => $vista->getCodigo()];
        if (str_contains($sql, ':outra')) {
            $parametros['outra'] = $this->idDaFolhaNova($this->maria);
        }
        if (str_contains($sql, ':daOutraPessoa')) {
            $parametros['daOutraPessoa'] = $this->idDaFolhaNova($this->pessoaComOutubro($this->escritorio, 'João Souza'));
        }

        return $parametros;
    }

    /** Emite uma folha de outubro da pessoa (com código e selo válidos) e devolve o id dela. */
    private function idDaFolhaNova(User $pessoa): int
    {
        $this->emitirFolha()->executar($this->escritorio, $pessoa, 2026, 10, $pessoa);

        return (int) array_reverse($this->folhasNoBanco($this->escritorio, $pessoa))[0]['id'];
    }

    private function aceiteDoSetUp(): AceiteDaFolhaDoPonto
    {
        $aceite = $this->em()->find(AceiteDaFolhaDoPonto::class, (int) $this->aceitesNoBanco($this->escritorio, $this->maria)[0]['id']);
        self::assertNotNull($aceite);

        return $aceite;
    }

    private function ultimaFolhaEmitida(): DadosDaFolhaEmitida
    {
        return DadosDaFolhaEmitida::deCanonico((string) array_reverse($this->folhasNoBanco($this->escritorio, $this->maria))[0]['dados_assinados']);
    }
}
