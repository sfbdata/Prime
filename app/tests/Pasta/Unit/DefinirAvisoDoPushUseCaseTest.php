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
use App\Pasta\UseCase\DefinirAvisoDoPushUseCase;
use App\Pasta\UseCase\MontarAvisosDoPushUseCase;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\PlataformaDeIaNoTesteTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * As chaves do "Avisar quando chegar" (spec push-avisar-quando-chegar §2): o POST manda o estado
 * DESEJADO; pedir o que já vale não grava; a primeira gravação cria a linha pelo repositório
 * (idempotente); ligar o e-mail sem o responsável ou a IA indisponível (no escritório ou para o
 * responsável) é recusado com a dica; desligar sempre passa; outro escritório não é tocado.
 */
#[CoversClass(DefinirAvisoDoPushUseCase::class)]
#[CoversClass(MontarAvisosDoPushUseCase::class)]
#[CoversClass(DisponibilidadeDaIaNoAvisoDoPush::class)]
final class DefinirAvisoDoPushUseCaseTest extends TestCase
{
    use PlataformaDeIaNoTesteTrait;

    private AvisoDoPushDaPastaRepository&MockObject $avisos;
    private PermissionChecker&MockObject $permissoes;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $usuario;
    private ?ConfiguracaoDeInteligencia $configuracao = null;
    private ?AvisoDoPushDaPasta $linha = null;

    protected function setUp(): void
    {
        $this->avisos = $this->createMock(AvisoDoPushDaPastaRepository::class);
        $this->avisos->method('daPastaDoTenant')->willReturnCallback(fn (): ?AvisoDoPushDaPasta => $this->linha);
        $this->avisos->method('garantirDaPasta')->willReturnCallback(fn (): AvisoDoPushDaPasta => $this->linha ??= new AvisoDoPushDaPasta($this->tenant, $this->pasta, $this->usuario, new \DateTimeImmutable()));
        $this->permissoes = $this->createMock(PermissionChecker::class);
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
        $disponibilidade = new DisponibilidadeDeInteligencia($provedor, $configuracoes, $this->createMock(AnaliseDeInteligenciaRepository::class), $this->permissoes, $plataforma, $this->plataformaDeIa(), new \App\Inteligencia\Service\CustoDaIa('us'));

        return new DisponibilidadeDaIaNoAvisoDoPush($disponibilidade, $this->permissoes);
    }

    private function sut(bool $plataforma = true): DefinirAvisoDoPushUseCase
    {
        return new DefinirAvisoDoPushUseCase($this->avisos, $this->ia($plataforma), new MockClock('2026-10-19 10:00:00'));
    }

    private function ligarIaNoEscritorio(): void
    {
        $this->configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $this->configuracao->liberarPeloMaster();
        $this->configuracao->atualizar(true, 50, 500, true, $this->usuario);
    }

    /** @return list<bool> */
    private static function ligadas(\App\Pasta\DTO\AvisosDoPushOutput $cartao): array
    {
        return array_map(static fn ($i): bool => $i->ligado, $cartao->itens);
    }

    #[TestDox('Primeira gravação: cria a linha pelo repositório, desliga só o responsável, grava quem mexeu e devolve o e-mail travado')]
    public function testPrimeiraGravacao(): void
    {
        $this->avisos->expects(self::once())->method('salvar')->with(self::isInstanceOf(AvisoDoPushDaPasta::class), true);

        $cartao = $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Responsavel, false, $this->usuario, $this->tenant);

        self::assertSame([false, true, false], self::ligadas($cartao));
        self::assertTrue($cartao->itens[1]->travado, 'e-mail trava sem o responsável');
        self::assertSame($this->usuario, $this->linha?->getAtualizadoPor());
    }

    #[TestDox('Estado desejado: pedir o que já vale (duas vezes "desligar") não grava e devolve o mesmo cartão')]
    public function testEstadoDesejadoIdempotente(): void
    {
        $this->avisos->expects(self::once())->method('salvar');

        $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Responsavel, false, $this->usuario, $this->tenant);
        $segunda = $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Responsavel, false, $this->usuario, $this->tenant);
        $padrao = $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Email, true, $this->usuario, $this->tenant);

        self::assertSame([false, true, false], self::ligadas($segunda));
        self::assertSame([false, true, false], self::ligadas($padrao));
    }

    #[TestDox('Ligar o e-mail com o aviso ao responsável desligado: 422 com a dica; desligar o e-mail passa')]
    public function testEmailSemResponsavel(): void
    {
        $this->linha = new AvisoDoPushDaPasta($this->tenant, $this->pasta, null, new \DateTimeImmutable());
        $this->linha->definir(ChaveDoAvisoDoPush::Responsavel, false, $this->usuario, new \DateTimeImmutable());
        $this->linha->definir(ChaveDoAvisoDoPush::Email, false, $this->usuario, new \DateTimeImmutable());

        try {
            $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Email, true, $this->usuario, $this->tenant);
            self::fail('deveria recusar');
        } catch (ChaveDoAvisoTravadaException $e) {
            self::assertSame('Ligue o aviso ao responsável para receber por e-mail.', $e->getMessage());
        }

        $this->linha->definir(ChaveDoAvisoDoPush::Email, true, $this->usuario, new \DateTimeImmutable());
        self::assertFalse($this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Email, false, $this->usuario, $this->tenant)->itens[1]->ligado);
    }

    #[TestDox('IA não liberada para o escritório (sem configuração): ligar dá 422 com o motivo real; ligada no escritório, a chave liga')]
    public function testIaNoEscritorio(): void
    {
        try {
            $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Ia, true, $this->usuario, $this->tenant);
            self::fail('deveria recusar');
        } catch (ChaveDoAvisoTravadaException $e) {
            self::assertSame('A BlueJus IA ainda não foi liberada para este escritório.', $e->getMessage());
        }

        $this->ligarIaNoEscritorio();
        $cartao = $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Ia, true, $this->usuario, $this->tenant);
        self::assertTrue($cartao->itens[2]->ligado);
        self::assertFalse($cartao->itens[2]->travado);
    }

    #[TestDox('Responsável da pasta sem modules.inteligencia.view: a IA trava com "O responsável da pasta não tem acesso à BlueJus IA."')]
    public function testResponsavelSemIa(): void
    {
        $this->ligarIaNoEscritorio();
        $responsavel = new User();
        $this->pasta->setResponsavel($responsavel);
        $this->permissoes->method('canAccessModule')->willReturnCallback(fn (User $u): bool => $u !== $responsavel);

        $cartao = (new MontarAvisosDoPushUseCase($this->avisos, $this->ia()))->executar($this->pasta, $this->tenant);

        self::assertTrue($cartao->itens[2]->travado);
        self::assertSame('O responsável da pasta não tem acesso à BlueJus IA.', $cartao->itens[2]->dica);
        $this->expectException(ChaveDoAvisoTravadaException::class);
        $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Ia, true, $this->usuario, $this->tenant);
    }

    #[TestDox('Plataforma sem IA: a chave da IA trava com "IA não configurada nesta instalação." mesmo com o escritório ligado')]
    public function testPlataformaSemIa(): void
    {
        $this->ligarIaNoEscritorio();

        $cartao = (new MontarAvisosDoPushUseCase($this->avisos, $this->ia(false)))->executar($this->pasta, $this->tenant);

        self::assertTrue($cartao->itens[2]->travado);
        self::assertSame('IA não configurada nesta instalação.', $cartao->itens[2]->dica);
    }

    #[TestDox('Pasta de outro escritório: recusa antes de ler ou gravar')]
    public function testOutroEscritorio(): void
    {
        $this->avisos->expects(self::never())->method('garantirDaPasta');
        $this->avisos->expects(self::never())->method('salvar');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->sut()->executar($this->pasta, ChaveDoAvisoDoPush::Responsavel, false, $this->usuario, new Tenant());
    }

    #[TestDox('Montar: sem linha, os padrões; de outro escritório, não consulta a linha')]
    public function testMontar(): void
    {
        $montar = new MontarAvisosDoPushUseCase($this->avisos, $this->ia());

        self::assertSame([true, true, false], self::ligadas($montar->executar($this->pasta, $this->tenant)));
        self::assertSame([true, true, false], self::ligadas($montar->executar($this->pasta, new Tenant())));
    }
}
