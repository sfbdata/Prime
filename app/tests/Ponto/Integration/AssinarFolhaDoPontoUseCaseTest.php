<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\DTO\DadosDoAceiteDaFolha;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;
use App\Ponto\Service\EstadoDoAceiteDaFolha;
use App\Ponto\Service\FuncaoDoAceite;
use App\Ponto\UseCase\AssinarFolhaDoPontoUseCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * O aceite mensal da folha (spec ponto-desenho-0811, E16, P22 e P33; decisões 1, 3, 14, 16, 18 e 19): só o mês fechado
 * da lista da pessoa, uma vez por versão da folha, com a folha vista emitida junto, o histórico numerado, a trilha na
 * mesma transação e cada escritório com o seu.
 */
#[Group('ponto')]
#[CoversClass(AssinarFolhaDoPontoUseCase::class)]
#[CoversClass(EstadoDoAceiteDaFolha::class)]
#[CoversClass(FuncaoDoAceite::class)]
final class AssinarFolhaDoPontoUseCaseTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    private const SEGREDO = 'segredo-de-teste-do-aceite';

    private Tenant $escritorio;
    private User $maria;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        // O segredo entra no container antes do primeiro uso e não pode ser trocado depois: o teste sem ele é a exceção.
        $this->segredo($this->name() === 'testSemOSegredo' ? null : self::SEGREDO);
        $this->escritorio = $this->escritorio('Escritório do aceite');
        $this->maria = $this->pessoaComOutubro($this->escritorio);
    }

    #[TestDox('Concordo: aceite número 1 sobre a folha vista emitida agora; trilha "Folha emitida" e "Folha assinada" (decisão 18)')]
    public function testConcordo(): void
    {
        $saida = $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo', '', '189.6.10.2', 'Mozilla/5.0'), $this->maria, $this->escritorio);

        $aceites = $this->aceitesNoBanco($this->escritorio, $this->maria);
        $folhas = $this->folhasNoBanco($this->escritorio, $this->maria);
        self::assertCount(1, $aceites);
        self::assertCount(1, $folhas, 'a folha vista é uma emissão nova');
        self::assertSame([1, 'concordo', null, '189.6.10.2', 'Mozilla/5.0'], [(int) $aceites[0]['numero'], $aceites[0]['situacao'], $aceites[0]['ressalva'], $aceites[0]['ip'], $aceites[0]['navegador']]);
        self::assertSame((int) $folhas[0]['id'], (int) $aceites[0]['folha_emitida_id']);
        self::assertSame($this->maria->getId(), (int) $folhas[0]['emitida_por_id']);
        self::assertSame(['folha_emitida', 'folha_assinada'], $this->acoesNaTrilha($this->escritorio));
        self::assertSame([(int) $folhas[0]['id'], (int) $aceites[0]['id']], [$this->trilhaNoBanco($this->escritorio)[0]['detalhe']['folha_emitida_id'], $this->trilhaNoBanco($this->escritorio)[1]['detalhe']['aceite_id']]);
        self::assertSame([1, false, true, $folhas[0]['codigo']], [$saida->numero, $saida->podeAssinar, $saida->carimbaAFolha(), $saida->codigoDaFolhaVista]);
    }

    #[TestDox('Ressalva: gravada aparada, evento "Folha assinada com ressalva" sem o texto da ressalva na trilha')]
    public function testRessalva(): void
    {
        $saida = $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'ressalva', "  O dia 6 foi de folga.\n"), $this->maria, $this->escritorio);

        $aceites = $this->aceitesNoBanco($this->escritorio, $this->maria);
        self::assertSame(['ressalva', 'O dia 6 foi de folga.'], [$aceites[0]['situacao'], $aceites[0]['ressalva']]);
        self::assertSame(['folha_emitida', 'folha_assinada_com_ressalva'], $this->acoesNaTrilha($this->escritorio));
        self::assertStringNotContainsString('folga', json_encode($this->trilhaNoBanco($this->escritorio), \JSON_THROW_ON_ERROR));
        self::assertSame('Ressalva em 09/11/2026', $saida->textoDoSelo());
    }

    #[TestDox('Mês aberto (o mês corrente pelo relógio do servidor) e mês futuro: recusa e não grava nada')]
    public function testMesAberto(): void
    {
        $this->diaCompleto($this->maria, $this->escritorio, '2026-11-03');

        self::assertSame(AssinaturaDaFolhaRecusadaException::MES_ABERTO, $this->recusa(new AssinarFolhaInput(2026, 11, 'concordo'))->motivo);
        self::assertSame(AssinaturaDaFolhaRecusadaException::MES_ABERTO, $this->recusa(new AssinarFolhaInput(2026, 12, 'concordo'))->motivo);
        $this->assertNadaGravado();
    }

    #[TestDox('Mês antes do início da contagem neste escritório: "Este mês não está na sua folha de ponto."')]
    public function testMesForaDaFolha(): void
    {
        $recusa = $this->recusa(new AssinarFolhaInput(2026, 9, 'concordo'));

        self::assertSame([AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA, 'Este mês não está na sua folha de ponto.'], [$recusa->motivo, $recusa->getMessage()]);
        $this->assertNadaGravado();
    }

    /** @param array{int, int} $competencia */
    #[DataProvider('competenciasForaDoCalendario')]
    #[TestDox('Competência fora do calendário ($_dataName): "Este mês não está na sua folha de ponto." (nunca 500) e nada gravado')]
    public function testCompetenciaForaDoCalendario(array $competencia): void
    {
        $recusa = $this->recusa(new AssinarFolhaInput($competencia[0], $competencia[1], 'concordo'));

        self::assertSame([AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA, 'Este mês não está na sua folha de ponto.'], [$recusa->motivo, $recusa->getMessage()]);
        $this->assertNadaGravado();
    }

    /** @param array{int, int} $competencia */
    #[DataProvider('competenciasForaDoCalendario')]
    #[TestDox('Estado da barra com competência fora do calendário ($_dataName): sem aceite e sem poder assinar (nunca 500), sem montar a folha')]
    public function testEstadoDeCompetenciaForaDoCalendario(array $competencia): void
    {
        $estado = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, $competencia[0], $competencia[1]);

        self::assertSame(
            [$competencia[0], $competencia[1], null, false, false, AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA, null],
            [$estado->ano, $estado->mes, $estado->situacao, $estado->alterada, $estado->podeAssinar, $estado->impedimento, $estado->textoDoSelo()],
        );
        $this->assertNadaGravado();
    }

    /** @return iterable<string, array{array{int, int}}> */
    public static function competenciasForaDoCalendario(): iterable
    {
        yield 'mês 0' => [[2026, 0]];
        yield 'mês 13' => [[2026, 13]];
        yield 'mês negativo' => [[2026, -1]];
        yield 'ano com três dígitos' => [[999, 10]];
        yield 'ano com cinco dígitos' => [[10000, 10]];
    }

    #[TestDox('Ressalva vazia: "Descreva a ressalva." e nada gravado (a regra vale também sem o formulário)')]
    public function testRessalvaVazia(): void
    {
        $recusa = $this->recusa(new AssinarFolhaInput(2026, 10, 'ressalva', '   '));

        self::assertSame([AssinaturaDaFolhaRecusadaException::RESSALVA_INVALIDA, 'Descreva a ressalva.'], [$recusa->motivo, $recusa->getMessage()]);
        $this->assertNadaGravado();
    }

    #[TestDox('Decisão 1: a segunda assinatura da mesma folha é recusada ("Esta folha já foi assinada.")')]
    public function testSegundaAssinaturaRecusada(): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);

        $recusa = $this->recusa(new AssinarFolhaInput(2026, 10, 'ressalva', 'Mudei de ideia.'));

        self::assertSame([AssinaturaDaFolhaRecusadaException::JA_ASSINADA, 'Esta folha já foi assinada.'], [$recusa->motivo, $recusa->getMessage()]);
        self::assertCount(1, $this->aceitesNoBanco($this->escritorio, $this->maria));
        self::assertCount(1, $this->folhasNoBanco($this->escritorio, $this->maria));
    }

    #[TestDox('Decisões 1 e 3: a folha alterada depois do aceite (batida nova) volta a poder assinar; o novo aceite é o número 2')]
    public function testAlteradaPermiteONumeroDois(): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);
        $this->diaCompleto($this->maria, $this->escritorio, '2026-10-07');

        $antes = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);
        $saida = $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);
        $depois = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);

        self::assertSame([true, true, 'Alterada depois do aceite', 1], [$antes->alterada, $antes->podeAssinar, $antes->textoDoSelo(), $antes->numero]);
        self::assertSame(2, $saida->numero);
        self::assertSame([false, false, 2], [$depois->alterada, $depois->podeAssinar, $depois->numero]);
        self::assertSame([1, 2], array_map('intval', array_column($this->aceitesNoBanco($this->escritorio, $this->maria), 'numero')));
    }

    #[TestDox('Corrida: o segundo envio leu "sem aceite" antes de o primeiro gravar; o número sai dessa mesma leitura (1) e o índice único o segura')]
    public function testCorridaPeloMesmoNumero(): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);
        // Só a leitura do último aceite atrasada (o cenário real): o primeiro envio já gravou o número 1 quando o
        // segundo chega à gravação, mas o segundo decidiu "pode assinar" sobre a leitura de antes.
        $atrasado = new class (static::getContainer()->get(ManagerRegistry::class)) extends AceiteDaFolhaDoPontoRepository {
            public function ultimoDaCompetencia(Tenant $escritorio, User $pessoa, int $ano, int $mes): ?AceiteDaFolhaDoPonto
            {
                return null;
            }
        };

        try {
            $this->assinarFolha(aceites: $atrasado)->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);
            self::fail('O índice único deveria recusar o segundo aceite de número 1.');
        } catch (AssinaturaDaFolhaRecusadaException $recusa) {
            self::assertSame([AssinaturaDaFolhaRecusadaException::JA_ASSINADA, 'Esta folha já foi assinada.'], [$recusa->motivo, $recusa->getMessage()]);
        }

        self::assertCount(1, $this->aceitesNoBanco($this->escritorio, $this->maria));
        self::assertCount(1, $this->folhasNoBanco($this->escritorio, $this->maria), 'a folha vista do segundo voltou junto');
        self::assertSame(['folha_emitida', 'folha_assinada'], $this->acoesNaTrilha($this->escritorio));
    }

    #[TestDox('Decisão 16: sem o segredo do HMAC, o aceite não está disponível: não pode assinar e nada é gravado')]
    public function testSemOSegredo(): void
    {
        self::assertFalse($this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10)->podeAssinar);
        self::assertSame(AssinaturaDaFolhaRecusadaException::FUNCAO_DESLIGADA, $this->recusa(new AssinarFolhaInput(2026, 10, 'concordo'))->motivo);
        $this->assertNadaGravado();
    }

    #[TestDox('Decisão 13: a função desligada no escritório recusa e esconde o "pode assinar"')]
    public function testDesligadaNoEscritorio(): void
    {
        $this->desligarNoEscritorio($this->escritorio, $this->maria);

        self::assertFalse($this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10)->podeAssinar);
        self::assertSame(AssinaturaDaFolhaRecusadaException::FUNCAO_DESLIGADA, $this->recusa(new AssinarFolhaInput(2026, 10, 'concordo'))->motivo);
        $this->assertNadaGravado();
    }

    #[TestDox('Decisão 13: a função desligada pelo Master no Global recusa em todos os escritórios')]
    public function testDesligadaNoGlobal(): void
    {
        $this->desligarNoGlobal();

        $recusa = $this->recusa(new AssinarFolhaInput(2026, 10, 'concordo'));

        self::assertSame([AssinaturaDaFolhaRecusadaException::FUNCAO_DESLIGADA, 'O aceite da folha não está disponível neste escritório.'], [$recusa->motivo, $recusa->getMessage()]);
        $this->assertNadaGravado();
    }

    #[TestDox('Atomicidade: a trilha que falha não deixa nem o aceite nem a folha vista')]
    public function testTrilhaQueFalha(): void
    {
        try {
            $this->assinarFolha($this->trilhaQueFalha())->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);
            self::fail('A falha da trilha deveria subir.');
        } catch (\RuntimeException $e) {
            self::assertSame('Falha simulada da trilha.', $e->getMessage());
        }

        $this->assertNadaGravado();
    }

    #[TestDox('Decisão 14: relidos do banco, aceito_em e emitida_em são os instantes dos textos selados, e os dois selos conferem')]
    public function testColunasIguaisAosTextosSelados(): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'ressalva', 'O dia 6 foi de folga.', '189.6.10.2', str_repeat('n', 300)), $this->maria, $this->escritorio);
        $id = (int) $this->aceitesNoBanco($this->escritorio, $this->maria)[0]['id'];
        $this->em()->clear();

        $aceite = $this->em()->find(AceiteDaFolhaDoPonto::class, $id);
        self::assertNotNull($aceite);
        $vista = $aceite->getFolhaVista();
        $dadosDoAceite = DadosDoAceiteDaFolha::deCanonico($aceite->getDadosAssinados());
        $dadosDaFolha = DadosDaFolhaEmitida::deCanonico($vista->getDadosAssinados());

        self::assertSame($dadosDoAceite->aceitoEm->format(\DATE_ATOM), $aceite->getAceitoEm()->format(\DATE_ATOM));
        self::assertSame($dadosDaFolha->emitidaEm->format(\DATE_ATOM), $vista->getEmitidaEm()->format(\DATE_ATOM));
        self::assertSame('2026-11-09 10:15:30', $aceite->getAceitoEm()->format('Y-m-d H:i:s'));
        self::assertSame([str_repeat('n', 255), $vista->getCodigo()], [$aceite->getNavegador(), $dadosDoAceite->codigoDaFolhaVista], 'o navegador cortado antes de selar');
        self::assertSame($dadosDoAceite->navegador, $aceite->getNavegador());
        self::assertTrue($this->seloDaFolha()->conferir($aceite->getDadosAssinados(), $aceite->getHmac(), $aceite->getVersaoChave()));
        self::assertTrue($this->seloDaFolha()->conferir($vista->getDadosAssinados(), $vista->getHmac(), $vista->getVersaoChave()));
    }

    #[TestDox('Decisão 3: a folha emitida depois do aceite leva o carimbo; depois de alterada, sai sem carimbo')]
    public function testCarimboNaFolhaEmitida(): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'ressalva', 'O dia 6 foi de folga.'), $this->maria, $this->escritorio);

        $carimbada = $this->emitirFolha()->executar($this->escritorio, $this->maria, 2026, 10, $this->maria);
        $this->diaCompleto($this->maria, $this->escritorio, '2026-10-07');
        $alterada = $this->emitirFolha()->executar($this->escritorio, $this->maria, 2026, 10, $this->maria);

        self::assertSame(['Aceite com ressalva em 09/11/2026, 10:15:30', 'Ressalva: O dia 6 foi de folga.'], [$carimbada->carimbo?->texto(), $carimbada->carimbo?->textoDaRessalva()]);
        $folhas = $this->folhasNoBanco($this->escritorio, $this->maria);
        self::assertSame(SituacaoDoAceiteDaFolha::Ressalva, DadosDaFolhaEmitida::deCanonico((string) $folhas[1]['dados_assinados'])->aceiteSituacao, 'o aceite carimbado entra nos dados assinados');
        self::assertNull($alterada->carimbo);
        self::assertNull(DadosDaFolhaEmitida::deCanonico((string) $folhas[2]['dados_assinados'])->aceiteSituacao);
    }

    #[TestDox('Multi-escritório: o aceite do A não vale no B; sem batida no B o mês não está na folha de lá; no B o histórico é próprio')]
    public function testCadaEscritorioComOSeu(): void
    {
        $b = $this->escritorio('Escritório B');
        $this->vincular($this->maria, $b);
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $this->escritorio);

        $noB = $this->estadoDoAceite()->daCompetencia($b, $this->maria, 2026, 10);
        $recusa = $this->recusaDe(fn () => $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $b));
        $this->diaCompleto($this->maria, $b, '2026-10-08');
        $saidaNoB = $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo'), $this->maria, $b);

        self::assertSame([false, null], [$noB->temAceite(), $noB->numero]);
        self::assertSame(AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA, $recusa->motivo);
        self::assertSame(1, $saidaNoB->numero, 'o número recomeça no escritório B');
        self::assertCount(1, $this->aceitesNoBanco($this->escritorio, $this->maria));
        self::assertCount(1, $this->aceitesNoBanco($b, $this->maria));
        self::assertSame(['folha_emitida', 'folha_assinada'], $this->acoesNaTrilha($b));
    }

    private function recusa(AssinarFolhaInput $pedido): AssinaturaDaFolhaRecusadaException
    {
        return $this->recusaDe(fn () => $this->assinarFolha()->executar($pedido, $this->maria, $this->escritorio));
    }

    private function recusaDe(callable $acao): AssinaturaDaFolhaRecusadaException
    {
        try {
            $acao();
        } catch (AssinaturaDaFolhaRecusadaException $recusa) {
            return $recusa;
        }
        self::fail('A assinatura deveria ter sido recusada.');
    }

    private function assertNadaGravado(): void
    {
        self::assertSame([], $this->aceitesNoBanco($this->escritorio, $this->maria));
        self::assertSame([], $this->folhasNoBanco($this->escritorio, $this->maria));
        self::assertSame([], $this->acoesNaTrilha($this->escritorio));
    }
}
