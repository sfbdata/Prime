<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\DefinirLimiteDeArmazenamentoInput;
use App\Contribua\DTO\DefinirLimiteDeUsuariosInput;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\UseCase\DefinirLimiteDeArmazenamentoUseCase;
use App\Contribua\UseCase\DefinirLimiteDeUsuariosUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DefinirLimiteDeArmazenamentoUseCase::class)]
#[CoversClass(DefinirLimiteDeUsuariosUseCase::class)]
#[CoversClass(EspacoDoEscritorio::class)]
final class DefinirLimitesDoEscritorioUseCaseTest extends TestCase
{
    private EspacoDoEscritorio $espaco;
    private EspacoDoEscritorioRepository&MockObject $espacos;

    protected function setUp(): void
    {
        $this->espaco = new EspacoDoEscritorio(new Tenant(), new Plano('free', 'Free', 10, 0, 'Free', 1));
        $this->espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $this->espacos->method('doTenantOuNovo')->willReturn($this->espaco);
    }

    #[TestDox('Master define 30 GB com justificativa: grava e salva')]
    public function testDefineLimiteDeArmazenamento(): void
    {
        $this->espacos->expects(self::once())->method('salvar')->with($this->espaco, true);

        $this->armazenamento(usados: 24 * RetratoDoEspaco::BYTES_POR_GB)
            ->executar(new DefinirLimiteDeArmazenamentoInput(7, 30, false, 'contratou ampliação'), null);

        self::assertSame(30, $this->espaco->getCotaGb());
        self::assertFalse($this->espaco->isSemLimite());
        self::assertSame('contratou ampliação', $this->espaco->getJustificativa());
    }

    #[TestDox('Limite abaixo do uso é recusado com o texto do desenho e nada é salvo')]
    public function testRecusaLimiteAbaixoDoUso(): void
    {
        $this->espacos->expects(self::never())->method('salvar');

        $this->expectExceptionMessage('O novo limite é menor que o volume em uso (24,3 GB). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.');

        $this->armazenamento(usados: (int) (24.29 * RetratoDoEspaco::BYTES_POR_GB))
            ->executar(new DefinirLimiteDeArmazenamentoInput(7, 20, false, 'reduzir'), null);
    }

    #[TestDox('Sem limite apaga o GB próprio e não confere o uso')]
    public function testSemLimite(): void
    {
        $this->armazenamento(usados: 999 * RetratoDoEspaco::BYTES_POR_GB)
            ->executar(new DefinirLimiteDeArmazenamentoInput(7, 40, true, 'escritório do dono'), null);

        self::assertTrue($this->espaco->isSemLimite());
        self::assertNull($this->espaco->getCotaGb());
    }

    #[TestDox('Justificativa em branco é recusada (vai para a auditoria)')]
    public function testJustificativaObrigatoria(): void
    {
        $this->espacos->expects(self::never())->method('salvar');
        $this->expectExceptionMessage('A justificativa é obrigatória e fica na auditoria.');

        $this->armazenamento(usados: 0)->executar(new DefinirLimiteDeArmazenamentoInput(7, 30, false, '   '), null);
    }

    #[TestDox('Limite de usuários abaixo dos já cadastrados é recusado')]
    public function testLimiteDeUsuariosAbaixoDosCadastrados(): void
    {
        $this->espacos->expects(self::never())->method('salvar');
        $this->expectExceptionMessage('Menor que os usuários já cadastrados.');

        $this->usuarios(cadastrados: 6)->executar(new DefinirLimiteDeUsuariosInput(7, 5, 'enxugar'), null);
    }

    #[TestDox('Limite de usuários igual aos cadastrados é aceito; nulo volta ao do plano')]
    public function testLimiteDeUsuarios(): void
    {
        $this->usuarios(cadastrados: 5)->executar(new DefinirLimiteDeUsuariosInput(7, 5, 'novo estagiário'), null);
        self::assertSame(5, $this->espaco->getLimiteUsuarios());

        $this->usuarios(cadastrados: 5)->executar(new DefinirLimiteDeUsuariosInput(7, null, 'volta ao plano'), null);
        self::assertNull($this->espaco->getLimiteUsuarios());
    }

    private function armazenamento(int $usados): DefinirLimiteDeArmazenamentoUseCase
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn($usados);

        return new DefinirLimiteDeArmazenamentoUseCase($this->espacos, $this->createStub(PlanoRepository::class), $consumo, $this->situacao());
    }

    private function usuarios(int $cadastrados): DefinirLimiteDeUsuariosUseCase
    {
        $usuarios = $this->createStub(UsuariosDoEscritorioRepository::class);
        $usuarios->method('quantos')->willReturn($cadastrados);

        return new DefinirLimiteDeUsuariosUseCase($this->espacos, $this->createStub(PlanoRepository::class), $usuarios, $this->situacao());
    }

    private function situacao(): SituacaoDoEspaco
    {
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn(new Plano('free', 'Free', 10, 0, 'Free', 1));
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());

        return new SituacaoDoEspaco($this->createStub(ConsumoDeArmazenamentoRepository::class), $this->espacos, $planos, $configuracoes, $this->createStub(BonusDeEspacoRepository::class), new MockClock());
    }
}
