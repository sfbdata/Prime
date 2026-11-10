<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AssinarFolhaInput;
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
 * só vale se o selo da folha vista e o dele conferem e se a linha dele diz o mesmo que o texto que ele selou. O que não
 * confere conta como "alterada depois do aceite": o selo da barra muda, o "Assinar folha" volta e a folha emitida depois
 * sai sem carimbo e sem o aceite nos dados assinados.
 */
#[Group('ponto')]
#[CoversClass(EstadoDoAceiteDaFolha::class)]
#[CoversClass(EmitirFolhaDoPontoUseCase::class)]
final class AceiteMexidoNoBancoTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

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
    public function testProvaMexidaContaComoAlterada(string $sql): void
    {
        $this->mexer($sql);

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
     * `:aceite` é o aceite gravado no setUp, `:vista` a folha vista nele e `:outra` uma emissão válida feita depois dele
     * (mesma folha, outro código).
     *
     * @return iterable<string, array{string}>
     */
    public static function provasMexidas(): iterable
    {
        yield 'situação do aceite trocada de ressalva para concordo' => ["UPDATE ponto_folha_aceite SET situacao = 'concordo', ressalva = NULL WHERE id = :aceite"];
        yield 'texto da ressalva trocado' => ["UPDATE ponto_folha_aceite SET ressalva = 'Concordo com tudo.' WHERE id = :aceite"];
        yield 'aceito_em trocado' => ["UPDATE ponto_folha_aceite SET aceito_em = aceito_em - INTERVAL '1 day' WHERE id = :aceite"];
        yield 'IP do aceite trocado' => ["UPDATE ponto_folha_aceite SET ip = '10.0.0.1' WHERE id = :aceite"];
        yield 'folha vista apontada para outra emissão' => ['UPDATE ponto_folha_aceite SET folha_emitida_id = :outra WHERE id = :aceite'];
        yield 'HMAC do aceite trocado' => ["UPDATE ponto_folha_aceite SET hmac = repeat('0', 64) WHERE id = :aceite"];
        yield 'texto selado do aceite trocado' => ["UPDATE ponto_folha_aceite SET dados_assinados = replace(dados_assinados, 'folga', 'falta') WHERE id = :aceite"];
        // A folha vista que não confere: a impressão dos registros dentro do texto continua igual à da folha de agora,
        // então só a conferência do HMAC a pega.
        yield 'texto selado da folha vista trocado (mesma impressão)' => ["UPDATE ponto_folha_emitida SET dados_assinados = replace(dados_assinados, 'Maria da Silva', 'Marta da Silva') WHERE id = :vista"];
        yield 'HMAC da folha vista trocado' => ["UPDATE ponto_folha_emitida SET hmac = repeat('0', 64) WHERE id = :vista"];
    }

    /** Roda o SQL sobre o aceite do setUp e recarrega o aceite e a folha vista do banco. */
    private function mexer(string $sql): void
    {
        $id = (int) $this->aceitesNoBanco($this->escritorio, $this->maria)[0]['id'];
        $aceite = $this->em()->find(AceiteDaFolhaDoPonto::class, $id);
        self::assertNotNull($aceite);
        $parametros = ['aceite' => $id, 'vista' => $aceite->getFolhaVista()->getId()];
        if (str_contains($sql, ':outra')) {
            $this->emitirFolha()->executar($this->escritorio, $this->maria, 2026, 10, $this->maria);
            $parametros['outra'] = (int) array_reverse($this->folhasNoBanco($this->escritorio, $this->maria))[0]['id'];
        }
        $usados = array_filter($parametros, static fn (string $nome): bool => str_contains($sql, ':' . $nome), \ARRAY_FILTER_USE_KEY);

        self::assertSame(1, $this->em()->getConnection()->executeStatement($sql, $usados));
        $this->em()->refresh($aceite);
        $this->em()->refresh($aceite->getFolhaVista());
    }

    private function ultimaFolhaEmitida(): DadosDaFolhaEmitida
    {
        return DadosDaFolhaEmitida::deCanonico((string) array_reverse($this->folhasNoBanco($this->escritorio, $this->maria))[0]['dados_assinados']);
    }
}
