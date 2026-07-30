<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use App\Ponto\UseCase\EditarHorasPagasUseCase;
use App\Ponto\UseCase\ExcluirHorasPagasUseCase;
use App\Ponto\UseCase\LancarHorasPagasUseCase;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LancarHorasPagasUseCase::class)]
#[CoversClass(EditarHorasPagasUseCase::class)]
#[CoversClass(ExcluirHorasPagasUseCase::class)]
#[CoversClass(LancamentoHorasPagasInput::class)]
final class LancarHorasPagasUseCaseTest extends TestCase
{
    #[TestDox('descontar 100h30 vira -6030 minutos')]
    public function testOperacaoDescontarProduzMinutosNegativos(): void
    {
        $input = $this->input(operacao: 'descontar', horas: 100, minutos: 30);

        self::assertSame(-6030, $input->minutosComSinal());
    }

    #[TestDox('acrescentar 8h vira +480 minutos')]
    public function testOperacaoAcrescentarProduzMinutosPositivos(): void
    {
        $input = $this->input(operacao: 'acrescentar', horas: 8, minutos: 0);

        self::assertSame(480, $input->minutosComSinal());
    }

    #[TestDox('quantidade zero e recusada')]
    public function testQuantidadeZeroRecusada(): void
    {
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Informe uma quantidade de horas maior que zero.');

        ($this->useCase())($this->input(horas: 0, minutos: 0), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('motivo so com espacos e recusado')]
    public function testMotivoVazioRecusado(): void
    {
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Informe o motivo do lançamento.');

        ($this->useCase())($this->input(motivo: '   '), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('competencia futura e recusada')]
    public function testCompetenciaFuturaRecusada(): void
    {
        $proximoMes = (new \DateTimeImmutable('first day of next month'));

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('A competência não pode ser futura.');

        ($this->useCase())(
            $this->input(ano: (int) $proximoMes->format('Y'), mes: (int) $proximoMes->format('n')),
            $this->user(2),
            $this->user(1),
            $this->tenant(1),
        );
    }

    #[TestDox('operacao fora de descontar/acrescentar e recusada')]
    public function testOperacaoInvalidaRecusada(): void
    {
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Operação inválida.');

        ($this->useCase())($this->input(operacao: 'desconto'), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('competencia do mes corrente e permitida')]
    public function testCompetenciaMesCorrentePermitida(): void
    {
        $mesAtual = new \DateTimeImmutable('first day of this month');

        $lancamento = ($this->useCase())(
            $this->input(ano: (int) $mesAtual->format('Y'), mes: (int) $mesAtual->format('n')),
            $this->user(2),
            $this->user(1),
            $this->tenant(1),
        );

        self::assertInstanceOf(LancamentoHorasPagas::class, $lancamento);
    }

    #[TestDox('colaborador sem vinculo ativo com o tenant e recusado')]
    public function testColaboradorSemVinculoRecusado(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $useCase = new LancarHorasPagasUseCase($em, $this->userTenantRepository(false));

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Colaborador não pertence a este escritório.');

        $useCase($this->input(), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('ninguem lanca horas pagas para si mesmo')]
    public function testAutoLancamentoRecusado(): void
    {
        $mesmo = $this->user(7);

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Você não pode lançar horas pagas para si mesmo.');

        ($this->useCase())($this->input(), $mesmo, $mesmo, $this->tenant(1));
    }

    #[TestDox('super-admin tambem nao lanca para si mesmo')]
    public function testAutoLancamentoRecusadoTambemParaSuperAdmin(): void
    {
        $mesmo = $this->user(7, ['ROLE_SUPER_ADMIN']);

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Você não pode lançar horas pagas para si mesmo.');

        ($this->useCase())($this->input(), $mesmo, $mesmo, $this->tenant(1));
    }

    #[TestDox('lancamento valido persiste com autoria e minutos com sinal')]
    public function testLancamentoValidoPersiste(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $autor       = $this->user(1);
        $colaborador = $this->user(2);
        $tenant      = $this->tenant(1);

        $lancamento = (new LancarHorasPagasUseCase($em, $this->userTenantRepository()))(
            $this->input(operacao: 'descontar', horas: 100, minutos: 0, motivo: 'Pago na folha de agosto'),
            $colaborador,
            $autor,
            $tenant,
        );

        self::assertSame(-6000, $lancamento->getMinutos());
        self::assertSame('Pago na folha de agosto', $lancamento->getMotivo());
        self::assertSame($colaborador, $lancamento->getUser());
        self::assertSame($autor, $lancamento->getCriadoPor());
        self::assertSame($tenant, $lancamento->getTenant());
        self::assertNotNull($lancamento->getCriadoEm());
    }

    #[TestDox('editar lancamento de outro tenant e recusado')]
    public function testEditarLancamentoDeOutroTenantRecusado(): void
    {
        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(9));
        $lancamento->setUser($this->user(2));

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Lançamento não pertence a este escritório.');

        (new EditarHorasPagasUseCase($this->createStub(EntityManagerInterface::class)))(
            $lancamento,
            $this->input(),
            $this->user(1),
            $this->tenant(1),
        );
    }

    #[TestDox('editar o proprio lancamento recebido e recusado')]
    public function testEditarLancamentoDoProprioAutorRecusado(): void
    {
        $mesmo = $this->user(7);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(1));
        $lancamento->setUser($mesmo);

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Você não pode lançar horas pagas para si mesmo.');

        (new EditarHorasPagasUseCase($this->createStub(EntityManagerInterface::class)))(
            $lancamento,
            $this->input(),
            $mesmo,
            $this->tenant(1),
        );
    }

    #[TestDox('excluir lancamento de outro tenant e recusado e nao remove nada')]
    public function testExcluirLancamentoDeOutroTenantRecusado(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(9));
        $lancamento->setUser($this->user(2));

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Lançamento não pertence a este escritório.');

        (new ExcluirHorasPagasUseCase($em))($lancamento, $this->user(1), $this->tenant(1));
    }

    private function useCase(): LancarHorasPagasUseCase
    {
        return new LancarHorasPagasUseCase(
            $this->createStub(EntityManagerInterface::class),
            $this->userTenantRepository(),
        );
    }

    /**
     * Stub do vínculo user_tenant. Por padrão simula colaborador com vínculo ativo — só os testes
     * que exercitam especificamente a guarda de posse (`testColaboradorSemVinculoRecusado`) pedem
     * `false` explicitamente.
     */
    private function userTenantRepository(bool $vinculoAtivo = true): UserTenantRepository
    {
        $repositorio = $this->createStub(UserTenantRepository::class);
        $repositorio->method('existeVinculoAtivo')->willReturn($vinculoAtivo);

        return $repositorio;
    }

    private function input(
        int $ano = 2026,
        int $mes = 1,
        string $operacao = 'descontar',
        int $horas = 8,
        int $minutos = 0,
        string $motivo = 'motivo de teste',
    ): LancamentoHorasPagasInput {
        $input = new LancamentoHorasPagasInput();
        $input->ano      = $ano;
        $input->mes      = $mes;
        $input->operacao = $operacao;
        $input->horas    = $horas;
        $input->minutos  = $minutos;
        $input->motivo   = $motivo;

        return $input;
    }

    /**
     * O id do User é privado e sem setter; a identidade que os UseCases comparam vem de getId().
     */
    private function user(int $id, array $roles = []): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}
