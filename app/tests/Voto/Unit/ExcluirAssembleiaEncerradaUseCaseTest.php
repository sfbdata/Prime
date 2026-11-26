<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\ExcluirAssembleiaEncerradaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Apagar a votação encerrada: só encerrada, só com a confirmação digitada e só do próprio escritório. */
#[CoversClass(ExcluirAssembleiaEncerradaUseCase::class)]
#[CoversClass(Assembleia::class)]
final class ExcluirAssembleiaEncerradaUseCaseTest extends TestCase
{
    use ComFotosDasCandidaturas;

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

        $this->esperaRecusa('Há votação aberta: encerre antes de apagar.', 'APAGAR');
        $this->pauta->suspender('Queda de energia', 'Maria');
        $this->esperaRecusa('Há votação aberta: encerre antes de apagar.', 'APAGAR');
    }

    #[TestDox('Pauta programada para abrir ou que não abriu: recusa; arquivada com pauta sem abrir pode ser apagada')]
    public function testProgramadaEAguardando(): void
    {
        $this->assembleias->expects(self::once())->method('remover');
        $this->esperaRecusa('Só se apaga votação encerrada: há pauta que ainda não foi aberta.', 'APAGAR');

        $this->assembleia->arquivar('Pauta não deliberada', 'Maria');
        $this->caso()->executar($this->tenant, 5, 'APAGAR');
    }

    #[TestDox('Rascunho: recusa, o rascunho se exclui pelo outro caso de uso')]
    public function testRascunhoRecusa(): void
    {
        $rascunho = new Assembleia($this->tenant, 'AGO 2027', PesoDoVoto::Unidade, 'Gestora');
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $rascunho->exigirApagavelEncerrada();
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

    private function caso(): ExcluirAssembleiaEncerradaUseCase
    {
        return new ExcluirAssembleiaEncerradaUseCase($this->assembleias, new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore())), $this->fotosDasCandidaturas());
    }
}
