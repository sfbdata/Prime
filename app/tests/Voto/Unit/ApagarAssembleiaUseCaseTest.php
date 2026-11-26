<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\ApagarAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Apagar a votação que não está ativa: só com a confirmação digitada, sem pauta aberta ou programada e só do próprio escritório. */
#[CoversClass(ApagarAssembleiaUseCase::class)]
#[CoversClass(Assembleia::class)]
final class ApagarAssembleiaUseCaseTest extends TestCase
{
    use ComFotosDasCandidaturas;
    private const RECUSA = 'Só votação sem pauta aberta ou programada pode ser apagada.';

    private AssembleiaRepository&MockObject $assembleias;
    private Tenant $tenant;
    private Assembleia $assembleia;
    private PautaDaAssembleia $pauta;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->assembleia = new Assembleia($this->tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $this->pauta = new PautaDaAssembleia($this->assembleia, 1, 'Contas');
        new OpcaoDaPauta($this->pauta, 1, 'Sim');
        new OpcaoDaPauta($this->pauta, 2, 'Não');
        new UnidadeDaAssembleia($this->assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $this->assembleia->publicar(new \DateTimeImmutable('-2 hours'));
        $this->assembleias = $this->createMock(AssembleiaRepository::class);
        $this->assembleias->method('buscarDoEscritorio')->willReturnCallback(
            fn (int $id, Tenant $t): ?Assembleia => 5 === $id && $t === $this->tenant ? $this->assembleia : null,
        );
    }

    #[TestDox('Encerrada com o nome digitado (sem diferenciar maiúsculas) ou APAGAR: remove com flush')]
    public function testApagaEncerrada(): void
    {
        $this->encerrarPauta();
        $this->assembleias->expects(self::exactly(2))->method('remover')->with($this->assembleia, true);

        $this->caso()->executar($this->tenant, 5, '  ago 2026 ');
        $this->caso()->executar($this->tenant, 5, 'apagar');
    }

    #[TestDox('Pauta aberta (ou suspensa): recusa e nada é removido')]
    public function testAbertaRecusa(): void
    {
        $this->pauta->abrir('Mesa', 1, null, new \DateTimeImmutable('-5 minutes'));
        $this->assembleias->expects(self::never())->method('remover');

        $this->esperaRecusa(self::RECUSA, 'APAGAR');
        $this->pauta->suspender('Queda de energia', 'Maria');
        $this->esperaRecusa(self::RECUSA, 'APAGAR');
    }

    #[TestDox('Pauta programada para abrir: recusa; depois de arquivada a programação não abre mais e pode apagar')]
    public function testProgramadaRecusa(): void
    {
        $this->assembleia = new Assembleia($this->tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($this->assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($this->assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $pauta->definirParticipacao(new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'), [], OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto);
        $this->assembleia->publicar(new \DateTimeImmutable('-1 hour'));
        $this->assembleias->expects(self::once())->method('remover');
        $this->esperaRecusa(self::RECUSA, 'APAGAR');

        $this->assembleia->arquivar('Teste', 'Maria');
        $this->caso()->executar($this->tenant, 5, 'APAGAR');
    }

    #[TestDox('Nunca aberta e não arquivada, ou arquivada: apaga (votação de teste ou irrelevante)')]
    public function testNuncaAbertaApaga(): void
    {
        $this->assembleias->expects(self::exactly(2))->method('remover');

        $this->caso()->executar($this->tenant, 5, 'APAGAR');
        $this->assembleia->arquivar('Irrelevante', 'Maria');
        $this->caso()->executar($this->tenant, 5, 'APAGAR');
    }

    #[TestDox('Sem pauta nenhuma: apaga')]
    public function testSemPautaApaga(): void
    {
        $vazia = new Assembleia($this->tenant, 'AGO 2027', PesoDoVoto::Unidade, 'Gestora');
        $vazia->exigirApagavel();
        $this->addToAssertionCount(1);
    }

    #[TestDox('Confirmação vazia ou errada: recusa e nada é removido')]
    public function testConfirmacaoErrada(): void
    {
        $this->encerrarPauta();
        $this->assembleias->expects(self::never())->method('remover');

        foreach (['', '   ', 'AGO', 'apagar tudo'] as $digitado) {
            $this->esperaRecusa('Para apagar, digite o nome da assembleia ou APAGAR.', $digitado);
        }
    }

    #[TestDox('Outro escritório ou id inexistente: AssembleiaNaoEncontradaException e nada é removido')]
    public function testPosse(): void
    {
        $this->encerrarPauta();
        $this->assembleias->expects(self::never())->method('remover');

        foreach ([[new Tenant(), 5], [$this->tenant, 6]] as [$tenant, $id]) {
            try {
                $this->caso()->executar($tenant, $id, 'APAGAR');
                self::fail('Devia dar "não encontrada".');
            } catch (AssembleiaNaoEncontradaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Link da sala virtual: só http ou https, até 500 caracteres; vazio apaga')]
    public function testLinkDaSalaVirtual(): void
    {
        $this->assembleia->definirLinkDaSalaVirtual('  https://meet.google.com/abc-defg-hij ');
        self::assertSame('https://meet.google.com/abc-defg-hij', $this->assembleia->getLinkSalaVirtual());

        foreach (['javascript:alert(1)', 'ftp://exemplo.com/sala', 'meet.google.com/abc', 'https://', 'https://exemplo.com/' . str_repeat('a', 500)] as $invalido) {
            try {
                $this->assembleia->definirLinkDaSalaVirtual($invalido);
                self::fail('Devia recusar ' . $invalido);
            } catch (OperacaoInvalidaNaAssembleiaException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('https://meet.google.com/abc-defg-hij', $this->assembleia->getLinkSalaVirtual(), 'o inválido não troca o link');

        $this->assembleia->definirLinkDaSalaVirtual('');
        self::assertNull($this->assembleia->getLinkSalaVirtual());
    }

    private function encerrarPauta(): void
    {
        $this->pauta->abrir('Mesa', 1, null, new \DateTimeImmutable('-1 hour'));
        $this->pauta->encerrar('Mesa', 'Fim da votação', new \DateTimeImmutable('-10 minutes'));
    }

    private function esperaRecusa(string $mensagem, string $digitado): void
    {
        try {
            $this->caso()->executar($this->tenant, 5, $digitado);
            self::fail('Devia recusar.');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
    }

    private function caso(): ApagarAssembleiaUseCase
    {
        return new ApagarAssembleiaUseCase($this->assembleias, new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore())), $this->fotosDasCandidaturas());
    }
}
