<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Service\CodigoDaFolha;
use App\Ponto\Service\ConteudoDaFolhaDoMes;
use App\Ponto\Service\EmissaoDaFolha;
use App\Ponto\Service\RelogioDaFolha;
use App\Ponto\UseCase\EmitirFolhaDoPontoUseCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A emissão da folha do mês com código BJF (spec ponto-desenho-0811, E16, decisões 2, 14, 16 e 18): cada PDF é uma
 * emissão nova com linha e evento próprios, o instante da coluna é o do texto selado, sem o segredo nada é gravado, a
 * trilha que falha desfaz a folha e a folha de um escritório só tem o que é dele.
 */
#[Group('ponto')]
#[CoversClass(EmitirFolhaDoPontoUseCase::class)]
#[CoversClass(EmissaoDaFolha::class)]
#[CoversClass(ConteudoDaFolhaDoMes::class)]
#[CoversClass(RelogioDaFolha::class)]
final class EmitirFolhaDoPontoUseCaseTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
    }

    #[TestDox('Com o segredo: uma linha em ponto_folha_emitida, o evento "Folha emitida" com o id e o conteúdo com a validação')]
    public function testEmiteComCodigoETrilha(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);

        $conteudo = $this->emitir()->executar($escritorio, $maria, 2026, 10, $maria);

        $folhas = $this->folhasNoBanco($escritorio, $maria);
        self::assertCount(1, $folhas);
        self::assertNotNull($conteudo->validacao);
        self::assertSame($folhas[0]['codigo'], $conteudo->validacao->codigo);
        self::assertTrue(CodigoDaFolha::temFormato($conteudo->validacao->codigo));
        self::assertSame($conteudo->impressaoDosRegistros(), $folhas[0]['impressao_registros']);
        self::assertSame([['acao' => 'folha_emitida', 'autor_id' => $maria->getId(), 'id' => (int) $folhas[0]['id']]], $this->eventos($escritorio));
        self::assertSame(['08:00', '12:00', '13:00', '17:00'], $conteudo->linhas[4]->batidas, 'o dia 5 de outubro');
        self::assertNull($conteudo->carimbo, 'sem aceite, sem carimbo');
    }

    #[TestDox('Decisão 2: duas emissões do mesmo mês dão dois códigos, duas linhas e dois eventos (nada é reaproveitado)')]
    public function testDuasEmissoesDuasLinhas(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);

        $primeira = $this->emitir()->executar($escritorio, $maria, 2026, 10, $maria);
        $segunda = $this->emitir()->executar($escritorio, $maria, 2026, 10, $maria);

        self::assertNotSame($primeira->validacao?->codigo, $segunda->validacao?->codigo);
        self::assertCount(2, $this->folhasNoBanco($escritorio, $maria));
        self::assertSame(['folha_emitida', 'folha_emitida'], $this->acoesNaTrilha($escritorio));
    }

    #[TestDox('P89: o gestor que exporta a folha de outro fica em emitida_por e é o autor do evento; a folha é da pessoa')]
    public function testGestorEmiteAFolhaDeOutro(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);
        $gestor = $this->pessoa('Gestora');

        $this->emitir()->executar($escritorio, $maria, 2026, 10, $gestor);

        $folhas = $this->folhasNoBanco($escritorio, $maria);
        self::assertCount(1, $folhas);
        self::assertSame($gestor->getId(), (int) $folhas[0]['emitida_por_id']);
        self::assertSame($gestor->getId(), $this->eventos($escritorio)[0]['autor_id']);
        self::assertSame($maria->getId(), $this->trilhaNoBanco($escritorio)[0]['detalhe']['colaborador_id'] ?? null);
    }

    #[TestDox('Decisão 16: sem o segredo do HMAC, devolve o conteúdo sem validação e não grava folha nem evento')]
    public function testSemOSegredoNaoGrava(): void
    {
        $this->segredo(null);
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);

        $conteudo = $this->emitir()->executar($escritorio, $maria, 2026, 10, $maria);

        self::assertNull($conteudo->validacao);
        self::assertSame([], $this->folhasNoBanco($escritorio, $maria));
        self::assertSame([], $this->acoesNaTrilha($escritorio));
        self::assertSame('FOLHA DE PONTO · 10/2026', $conteudo->titulo());
    }

    #[TestDox('Decisão 14: relida do banco, a coluna emitida_em é o instante do texto selado, e o selo confere')]
    public function testColunaIgualAoTextoSelado(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);
        $this->emitir()->executar($escritorio, $maria, 2026, 10, $maria);
        $id = (int) $this->folhasNoBanco($escritorio, $maria)[0]['id'];
        $this->em()->clear();

        $relida = $this->em()->find(FolhaEmitidaDoPonto::class, $id);
        self::assertNotNull($relida);
        $dados = DadosDaFolhaEmitida::deCanonico($relida->getDadosAssinados());

        self::assertSame($dados->emitidaEm->format(\DATE_ATOM), $relida->getEmitidaEm()->format(\DATE_ATOM));
        self::assertSame('2026-11-09 10:15:30', $relida->getEmitidaEm()->format('Y-m-d H:i:s'));
        self::assertSame($dados->impressaoRegistros, $relida->getImpressaoRegistros());
        self::assertTrue($this->seloDaFolha()->conferir($relida->getDadosAssinados(), $relida->getHmac(), $relida->getVersaoChave()));
    }

    #[TestDox('Atomicidade: a trilha que falha ao gravar o evento desfaz a folha emitida')]
    public function testTrilhaQueFalhaNaoDeixaAFolha(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);
        $emitir = $this->emitirFolha($this->trilhaQueFalha());

        try {
            $emitir->executar($escritorio, $maria, 2026, 10, $maria);
            self::fail('A falha da trilha deveria subir.');
        } catch (\RuntimeException $e) {
            self::assertSame('Falha simulada da trilha.', $e->getMessage());
        }

        self::assertSame([], $this->folhasNoBanco($escritorio, $maria), 'a folha voltou com a transação');
        self::assertSame([], $this->acoesNaTrilha($escritorio));
    }

    #[TestDox('Multi-escritório: a folha do escritório B não traz as batidas do A, e a linha e o evento ficam no B')]
    public function testFolhaSoDoEscritorio(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        [$a, $b] = [$this->escritorio('Escritório A'), $this->escritorio('Escritório B')];
        $maria = $this->pessoaComOutubro($a);
        $this->vincular($maria, $b);

        $conteudo = $this->emitir()->executar($b, $maria, 2026, 10, $maria);

        self::assertSame(['', '', '', ''], $conteudo->linhas[4]->batidas, 'as batidas do dia 5 são do escritório A');
        self::assertCount(1, $this->folhasNoBanco($b, $maria));
        self::assertSame([], $this->folhasNoBanco($a, $maria));
        self::assertSame(['folha_emitida'], $this->acoesNaTrilha($b));
        self::assertSame([], $this->acoesNaTrilha($a));
    }

    #[TestDox('Competência fora do calendário (mês 13) é recusada antes de montar a folha')]
    public function testCompetenciaInvalida(): void
    {
        $this->segredo('segredo-de-teste-da-folha');
        $escritorio = $this->escritorio('Escritório da folha');
        $maria = $this->pessoaComOutubro($escritorio);

        $this->expectException(\InvalidArgumentException::class);
        $this->emitir()->executar($escritorio, $maria, 2026, 13, $maria);
    }

    private function emitir(): EmitirFolhaDoPontoUseCase
    {
        return $this->emitirFolha();
    }

    /** @return list<array{acao: string, autor_id: ?int, id: ?int}> */
    private function eventos(Tenant $escritorio): array
    {
        return array_map(static fn (array $evento): array => ['acao' => $evento['acao'], 'autor_id' => $evento['autor_id'], 'id' => isset($evento['detalhe']['folha_emitida_id']) ? (int) $evento['detalhe']['folha_emitida_id'] : null], $this->trilhaNoBanco($escritorio));
    }
}
