<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\UseCase\DefinirLimiteDeArmazenamentoUseCase;
use App\Contribua\UseCase\DefinirLimiteDeUsuariosUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\LimiteDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Master\UseCase\DefinirLimiteDaContaUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Guardas do lado do Master antes de chegar à Contribua (spec perfis-1.2-planos-e-limites P4). O caminho
 * feliz e as recusas da Contribua estão em PlanosELimitesControllerTest (functional).
 */
#[CoversClass(DefinirLimiteDaContaUseCase::class)]
final class DefinirLimiteDaContaUseCaseTest extends TestCase
{
    private ContasDoSistemaRepository&MockObject $contas;
    private EspacoDoEscritorioRepository&MockObject $espacos;
    private DefinirLimiteDaContaUseCase $useCase;

    protected function setUp(): void
    {
        $this->contas = $this->createMock(ContasDoSistemaRepository::class);
        $this->espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $planos = $this->createMock(PlanoRepository::class);
        $situacao = new SituacaoDoEspaco($this->createMock(ConsumoDeArmazenamentoRepository::class), $this->espacos, $planos, $this->createMock(ConfiguracaoDoEspacoRepository::class));

        $this->useCase = new DefinirLimiteDaContaUseCase(
            $this->contas,
            new RegraDosMasters($this->contas),
            new EscopoDeAuditoriaDePlataforma(),
            new DefinirLimiteDeArmazenamentoUseCase($this->espacos, $planos, $this->createMock(ConsumoDeArmazenamentoRepository::class), $situacao),
            new DefinirLimiteDeUsuariosUseCase($this->espacos, $planos, $this->createMock(UsuariosDoEscritorioRepository::class), $situacao),
        );
    }

    #[TestDox('quem não é Master é recusado antes de carregar a conta')]
    public function testSoMaster(): void
    {
        $this->contas->expects($this->never())->method('buscar');
        $this->expectException(OperacaoDeContaRecusada::class);

        $this->useCase->executar($this->input((new User())->setRoles(['ROLE_USER']), LimiteDaContaInput::ARMAZENAMENTO, 20));
    }

    #[TestDox('conta inexistente: ContaNaoEncontrada (404)')]
    public function testContaInexistente(): void
    {
        $this->contas->method('buscar')->willReturn(null);
        $this->expectException(ContaNaoEncontrada::class);

        $this->useCase->executar($this->input($this->master(), LimiteDaContaInput::ARMAZENAMENTO, 20));
    }

    #[TestDox('conta em quarentena: recusada, nada é gravado')]
    public function testQuarentena(): void
    {
        $this->contas->method('buscar')->willReturn((new Tenant())->setExcluidoEm(new \DateTimeImmutable()));
        $this->espacos->expects($this->never())->method('salvar');
        $this->expectExceptionMessage('Esta conta foi excluída. Reative a conta antes de mudar o limite.');

        $this->useCase->executar($this->input($this->master(), LimiteDaContaInput::USUARIOS, 20));
    }

    #[TestDox('valor vazio: mensagem do campo (GB ou usuários), nada é gravado')]
    public function testValorVazio(): void
    {
        $this->contas->method('buscar')->willReturn(new Tenant());
        $this->espacos->expects($this->never())->method('salvar');

        foreach ([LimiteDaContaInput::ARMAZENAMENTO => 'Informe um limite em GB maior que zero.', LimiteDaContaInput::USUARIOS => 'Informe um limite de usuários maior que zero.'] as $tipo => $mensagem) {
            try {
                $this->useCase->executar($this->input($this->master(), $tipo, null));
                self::fail('devia recusar');
            } catch (\DomainException $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
    }

    private function master(): User
    {
        return (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function input(User $ator, string $tipo, ?int $valor): LimiteDaContaInput
    {
        return new LimiteDaContaInput($ator, 7, $tipo, $valor, false, 'motivo');
    }
}
