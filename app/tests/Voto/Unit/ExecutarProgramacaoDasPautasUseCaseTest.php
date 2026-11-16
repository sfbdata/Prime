<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\UseCase\ExecutarProgramacaoDasPautasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** O agendador do horário programado do Voto: abre e encerra na hora e não mexe no que a mesa já mexeu. */
#[CoversClass(ExecutarProgramacaoDasPautasUseCase::class)]
final class ExecutarProgramacaoDasPautasUseCaseTest extends TestCase
{
    private PautaDaAssembleiaRepository&MockObject $pautas;
    private \DateTimeImmutable $abre;
    private \DateTimeImmutable $encerra;
    private \DateTimeImmutable $criada;

    protected function setUp(): void
    {
        $this->pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $this->criada = new \DateTimeImmutable('2026-11-20 08:00:00');
        $this->abre = new \DateTimeImmutable('2026-11-20 09:00:00');
        $this->encerra = new \DateTimeImmutable('2026-11-20 11:00:00');
        // A "trava" devolve a mesma pauta que a consulta achou.
        $this->pautas->method('buscarTravadaDaAssembleia')->willReturnCallback(
            static fn (int $id, Assembleia $a): ?PautaDaAssembleia => $a->getPautas()->filter(static fn (PautaDaAssembleia $p): bool => $p->getId() === $id)->first() ?: null,
        );
    }

    #[TestDox('Na hora de abrir: a pauta abre sozinha, em 1ª convocação, por "Programado", com o prazo no encerramento')]
    public function testAbreNaHora(): void
    {
        $pauta = $this->pauta(1);
        $this->pautas->method('aProgramar')->willReturn([$pauta]);

        $resumo = $this->caso()->executar($this->abre);

        self::assertSame(['abertas' => 1, 'encerradas' => 0], $resumo);
        self::assertSame(EstadoDaPauta::Aberta, $pauta->getEstado());
        self::assertSame('Programado', $pauta->getAbertaPorNome());
        self::assertSame(1, $pauta->getConvocacao());
        self::assertEquals($this->encerra, $pauta->getPrazoAte());
    }

    #[TestDox('Antes da hora de abrir nada acontece')]
    public function testAntesDaHora(): void
    {
        $pauta = $this->pauta(1);
        $this->pautas->method('aProgramar')->willReturn([$pauta]);

        self::assertSame(['abertas' => 0, 'encerradas' => 0], $this->caso()->executar($this->abre->modify('-1 second')));
        self::assertSame(EstadoDaPauta::Aguardando, $pauta->getEstado());
    }

    #[TestDox('Na hora de encerrar: a aberta pela programação encerra sozinha com o motivo "Encerramento programado"')]
    public function testEncerraNaHora(): void
    {
        $pauta = $this->pauta(1);
        $pauta->abrirPorProgramacao($this->abre);
        $this->pautas->method('aProgramar')->willReturn([$pauta]);

        $resumo = $this->caso()->executar($this->encerra);

        self::assertSame(['abertas' => 0, 'encerradas' => 1], $resumo);
        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertSame('Encerramento programado', $pauta->getMotivoEncerramento());
    }

    #[TestDox('Suspensa não encerra (prazo congelado); retirada e cancelada antes de abrir não abrem')]
    public function testNaoMexeEmSuspensaCanceladaERetirada(): void
    {
        $suspensa = $this->pauta(1);
        $suspensa->abrirPorProgramacao($this->abre);
        $suspensa->suspender('Queda de energia', 'Mesa', $this->abre->modify('+5 minutes'));
        $retirada = $this->pauta(2);
        $retirada->retirarDePauta('Fora da pauta', 'Mesa', $this->criada);
        $cancelada = $this->pauta(3);
        $cancelada->cancelar('Erro na pauta', 'Mesa', $this->criada);
        $this->pautas->method('aProgramar')->willReturn([$suspensa, $retirada, $cancelada]);

        $resumo = $this->caso()->executar($this->encerra->modify('+1 hour'));

        self::assertSame(['abertas' => 0, 'encerradas' => 0], $resumo);
        self::assertTrue($suspensa->suspensa());
        self::assertSame(EstadoDaPauta::Aberta, $suspensa->getEstado());
        self::assertSame(EstadoDaPauta::Aguardando, $retirada->getEstado());
        self::assertSame(EstadoDaPauta::Aguardando, $cancelada->getEstado());
    }

    #[TestDox('Pauta de assembleia arquivada não abre; a outra pauta da rodada abre normalmente')]
    public function testArquivadaEIsolamentoDeFalha(): void
    {
        $arquivada = $this->pauta(1);
        $arquivada->getAssembleia()->arquivar('Arquivada de teste', 'Mesa', $this->criada);
        $normal = $this->pauta(2);
        $this->pautas->method('aProgramar')->willReturn([$arquivada, $normal]);

        $resumo = $this->caso()->executar($this->abre);

        self::assertSame(['abertas' => 1, 'encerradas' => 0], $resumo);
        self::assertSame(EstadoDaPauta::Aguardando, $arquivada->getEstado());
        self::assertSame(EstadoDaPauta::Aberta, $normal->getEstado());
    }

    private function caso(): ExecutarProgramacaoDasPautasUseCase
    {
        $transacao = new class () implements TransacaoDoVoto {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }

            public function gravar(): void
            {
            }
        };

        return new ExecutarProgramacaoDasPautasUseCase($this->pautas, $transacao);
    }

    /** Uma assembleia própria, publicada, com a pauta de id dado programada das 9h às 11h. */
    private function pauta(int $id): PautaDaAssembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO ' . $id, PesoDoVoto::Unidade, 'Gestor');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat((string) $id, 64), 'Bloco A');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        (new \ReflectionProperty($pauta, 'id'))->setValue($pauta, $id);
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $pauta->definirParticipacao($this->abre, $this->encerra, [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto, $this->criada);
        $assembleia->publicar($this->criada);

        return $pauta;
    }
}
