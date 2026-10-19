<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\ChaveDoAvisoDoPush;
use App\Pasta\Exception\ChaveDoAvisoTravadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\AvisoDoPushDaPastaRepository;
use App\Pasta\Service\DisponibilidadeDaIaNoAvisoDoPush;
use App\Pasta\UseCase\AlternarAvisoDoPushUseCase;
use App\Pasta\UseCase\MontarAvisosDoPushUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * As chaves do "Avisar quando chegar" (spec push-avisar-quando-chegar §2): a primeira alteração
 * cria a linha a partir dos padrões; e-mail sem o aviso ao responsável e IA indisponível são
 * recusados com a dica; pasta de outro escritório não é tocada.
 */
#[CoversClass(AlternarAvisoDoPushUseCase::class)]
#[CoversClass(MontarAvisosDoPushUseCase::class)]
#[CoversClass(DisponibilidadeDaIaNoAvisoDoPush::class)]
final class AlternarAvisoDoPushUseCaseTest extends TestCase
{
    private AvisoDoPushDaPastaRepository&MockObject $avisos;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $usuario;
    private ?ConfiguracaoDeInteligencia $configuracao = null;

    protected function setUp(): void
    {
        $this->avisos = $this->createMock(AvisoDoPushDaPastaRepository::class);
        $this->tenant = new Tenant();
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->usuario = new User();
    }

    private function ia(bool $plataforma = true): DisponibilidadeDaIaNoAvisoDoPush
    {
        $provedor = $this->createMock(ProvedorDeLinguagem::class);
        $provedor->method('estaConfigurado')->willReturn(true);
        $configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $configuracoes->method('findDoTenant')->willReturnCallback(fn (): ?ConfiguracaoDeInteligencia => $this->configuracao);
        $disponibilidade = new DisponibilidadeDeInteligencia(
            $provedor,
            $configuracoes,
            $this->createMock(AnaliseDeInteligenciaRepository::class),
            $this->createMock(PermissionChecker::class),
            $plataforma,
        );

        return new DisponibilidadeDaIaNoAvisoDoPush($disponibilidade, $configuracoes);
    }

    private function sut(bool $plataforma = true): AlternarAvisoDoPushUseCase
    {
        return new AlternarAvisoDoPushUseCase($this->avisos, $this->ia($plataforma), new MockClock('2026-10-19 10:00:00'));
    }

    private function ligarIaNoEscritorio(): void
    {
        $this->configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $this->configuracao->atualizar(true, 50, 500, true, $this->usuario);
    }

    #[TestDox('Primeira alteração: cria a linha dos padrões, desliga só o responsável, grava quem mexeu e devolve o e-mail travado')]
    public function testPrimeiraAlteracaoCriaLinha(): void
    {
        $this->avisos->method('daPastaDoTenant')->willReturn(null);
        $gravada = null;
        $this->avisos->expects(self::once())->method('salvar')
            ->willReturnCallback(static function (AvisoDoPushDaPasta $linha, bool $flush) use (&$gravada): void {
                self::assertTrue($flush);
                $gravada = $linha;
            });

        $cartao = $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Responsavel, $this->usuario, $this->tenant);

        self::assertInstanceOf(AvisoDoPushDaPasta::class, $gravada);
        self::assertSame($this->usuario, $gravada->getAtualizadoPor());
        self::assertSame($this->pasta, $gravada->getPasta());
        self::assertSame([false, true, false], array_map(static fn ($i): bool => $i->ligado, $cartao->itens));
        self::assertTrue($cartao->itens[1]->travado, 'e-mail trava sem o responsável');
    }

    #[TestDox('Plataforma sem IA: a chave da IA trava com "IA não configurada nesta instalação." mesmo com o escritório ligado')]
    public function testPlataformaSemIa(): void
    {
        $this->ligarIaNoEscritorio();
        $this->avisos->method('daPastaDoTenant')->willReturn(null);

        $cartao = (new MontarAvisosDoPushUseCase($this->avisos, $this->ia(false)))->executar($this->pasta, $this->tenant);

        self::assertTrue($cartao->itens[2]->travado);
        self::assertSame('IA não configurada nesta instalação.', $cartao->itens[2]->dica);
    }

    #[TestDox('E-mail com o aviso ao responsável desligado: 422 com a dica e nada gravado')]
    public function testEmailSemResponsavel(): void
    {
        $linha = new AvisoDoPushDaPasta($this->tenant, $this->pasta, null, new \DateTimeImmutable());
        $linha->alternar(ChaveDoAvisoDoPush::Responsavel, $this->usuario, new \DateTimeImmutable());
        $this->avisos->method('daPastaDoTenant')->willReturn($linha);
        $this->avisos->expects(self::never())->method('salvar');

        $this->expectException(ChaveDoAvisoTravadaException::class);
        $this->expectExceptionMessage('Ligue o aviso ao responsável para receber por e-mail.');

        $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Email, $this->usuario, $this->tenant);
    }

    #[TestDox('IA desligada no escritório: 422 com o motivo real; ligada no escritório, a chave liga')]
    public function testIa(): void
    {
        $this->avisos->method('daPastaDoTenant')->willReturn(null);

        try {
            $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Ia, $this->usuario, $this->tenant);
            self::fail('deveria recusar');
        } catch (ChaveDoAvisoTravadaException $e) {
            self::assertSame('BlueJus IA desligada neste escritório. Peça ao administrador.', $e->getMessage());
        }

        $this->ligarIaNoEscritorio();
        $cartao = $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Ia, $this->usuario, $this->tenant);
        self::assertTrue($cartao->itens[2]->ligado);
        self::assertFalse($cartao->itens[2]->travado);
    }

    #[TestDox('Pasta de outro escritório: recusa antes de ler ou gravar')]
    public function testOutroEscritorio(): void
    {
        $this->avisos->expects(self::never())->method('daPastaDoTenant');
        $this->avisos->expects(self::never())->method('salvar');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Responsavel, $this->usuario, new Tenant());
    }

    #[TestDox('Montar: sem linha, os padrões; de outro escritório, não consulta a linha')]
    public function testMontar(): void
    {
        $this->avisos->expects(self::once())->method('daPastaDoTenant')->willReturn(null);
        $montar = new MontarAvisosDoPushUseCase($this->avisos, $this->ia());

        self::assertSame([true, true, false], array_map(static fn ($i): bool => $i->ligado, $montar->executar($this->pasta, $this->tenant)->itens));
        self::assertSame([true, true, false], array_map(static fn ($i): bool => $i->ligado, $montar->executar($this->pasta, new Tenant())->itens));
    }
}
