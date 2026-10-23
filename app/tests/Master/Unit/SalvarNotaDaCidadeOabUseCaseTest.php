<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\SalvarNotaDaSeccionalInput;
use App\Master\Entity\NotaDaCidadeOab;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\NotaDaCidadeOabRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\UseCase\SalvarNotaDaCidadeOabUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Nota por cidade da janela da OAB (desenho 05, L896: chave c:<UF>:<cidade>). */
#[CoversClass(SalvarNotaDaCidadeOabUseCase::class)]
#[CoversClass(NotaDaCidadeOab::class)]
final class SalvarNotaDaCidadeOabUseCaseTest extends TestCase
{
    private SeccionalOabRepository&MockObject $seccionais;
    private NotaDaCidadeOabRepository&MockObject $notas;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private SeccionalOab $seccional;
    private User $master;

    protected function setUp(): void
    {
        $this->seccionais = $this->createMock(SeccionalOabRepository::class);
        $this->notas = $this->createMock(NotaDaCidadeOabRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->seccional = new SeccionalOab('AM', 'OAB Seccional Amazonas');
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
        $this->seccionais->method('buscarPorUf')->willReturnCallback(fn (string $uf): ?SeccionalOab => $uf === 'AM' ? $this->seccional : null);
    }

    #[TestDox('sem nota da cidade: cria a nota com cidade, quem e quando, travada e dentro do escopo de plataforma')]
    public function testCria(): void
    {
        $this->travaEntrega('Manaus', null);
        $gravada = null;
        $this->notas->expects($this->once())->method('salvar')
            ->willReturnCallback(function (NotaDaCidadeOab $nota) use (&$gravada): void {
                self::assertTrue($this->escopo->estaLigado());
                $gravada = $nota;
            });

        $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', ['texto' => ' Falar com a subseção ', 'cidade' => 'Manaus']));

        self::assertInstanceOf(NotaDaCidadeOab::class, $gravada);
        self::assertSame('Manaus', $gravada->getCidade());
        self::assertSame('Falar com a subseção', $gravada->getTexto());
        self::assertSame($this->master, $gravada->getAtualizadaPor());
        self::assertSame('2026-10-21 09:00', $gravada->getAtualizadaEm()->format('Y-m-d H:i'));
    }

    #[TestDox('a capital chega sem acento e em outra caixa ("  manaus "): grava o nome canônico "Manaus"')]
    public function testNomeCanonico(): void
    {
        $this->travaEntrega('Manaus', null);
        $gravada = null;
        $this->notas->expects($this->once())->method('salvar')
            ->willReturnCallback(function (NotaDaCidadeOab $nota) use (&$gravada): void {
                $gravada = $nota;
            });

        self::assertSame('Manaus', $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', ['texto' => 'x', 'cidade' => '  MANAUS '])));

        self::assertSame('Manaus', $gravada?->getCidade());
    }

    #[TestDox('com nota da cidade: reescreve a mesma nota (uma por UF e cidade), sem criar outra')]
    public function testReescreve(): void
    {
        $nota = new NotaDaCidadeOab($this->seccional, 'Manaus', 'Antiga', null, new \DateTimeImmutable('2026-10-01'));
        $this->travaEntrega('Manaus', $nota);
        $this->notas->expects($this->never())->method('salvar');

        $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', ['texto' => 'Nova', 'cidade' => 'Manaus']));

        self::assertSame('Nova', $nota->getTexto());
        self::assertSame($this->master, $nota->getAtualizadaPor());
    }

    #[TestDox('texto vazio apaga a nota da cidade; sem nota gravada, não faz nada')]
    public function testVazioApaga(): void
    {
        $nota = new NotaDaCidadeOab($this->seccional, 'Manaus', 'Antiga', null, new \DateTimeImmutable('2026-10-01'));
        $this->travaEntrega('Manaus', $nota);
        $this->notas->expects($this->never())->method('salvar');
        $this->notas->expects($this->once())->method('remover')->with($nota);

        $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', ['texto' => '   ', 'cidade' => 'Manaus']));
    }

    #[TestDox('sem cidade, cidade que não é a capital da UF (inclusive capital de outra UF) ou texto longo: recusa (422) e não toca no banco')]
    public function testRecusas(): void
    {
        $this->notas->expects($this->never())->method('gravarComTrava');
        $casos = [
            [['texto' => 'x'], SeccionalRecusada::RECUSA_CIDADE_DA_NOTA_OBRIGATORIA],
            [['texto' => 'x', 'cidade' => 'Parintins'], SeccionalRecusada::RECUSA_CIDADE_DA_NOTA_FORA_DAS_CAPITAIS],
            [['texto' => 'x', 'cidade' => 'Belém'], SeccionalRecusada::RECUSA_CIDADE_DA_NOTA_FORA_DAS_CAPITAIS],
            [['texto' => 'x', 'cidade' => str_repeat('a', 121)], SeccionalRecusada::RECUSA_CIDADE_DA_NOTA_FORA_DAS_CAPITAIS],
            [['texto' => str_repeat('a', 1001), 'cidade' => 'Manaus'], SeccionalRecusada::RECUSA_NOTA_LONGA],
        ];

        foreach ($casos as [$corpo, $recusa]) {
            try {
                $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', $corpo));
                self::fail('deveria recusar: ' . $recusa);
            } catch (SeccionalRecusada $e) {
                self::assertSame(SeccionalRecusada::CHAVE_DA_RECUSA[$recusa], $e->chave());
            }
        }
    }

    #[TestDox('UF sem linha de seccional: 404')]
    public function testSemSeccional(): void
    {
        $this->expectException(SeccionalNaoEncontrada::class);

        $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'RR', ['texto' => 'x', 'cidade' => 'Boa Vista']));
    }

    private function travaEntrega(string $cidade, ?NotaDaCidadeOab $nota): void
    {
        $this->notas->expects($this->once())->method('gravarComTrava')->with($this->seccional, $cidade)
            ->willReturnCallback(function (SeccionalOab $s, string $c, callable $acao) use ($nota): void {
                self::assertTrue($this->escopo->estaLigado(), 'a gravação travada roda dentro do escopo de plataforma');
                $acao($nota);
            });
    }

    private function useCase(): SalvarNotaDaCidadeOabUseCase
    {
        return new SalvarNotaDaCidadeOabUseCase($this->seccionais, $this->notas, new MockClock('2026-10-21 09:00:00'), $this->escopo);
    }
}
