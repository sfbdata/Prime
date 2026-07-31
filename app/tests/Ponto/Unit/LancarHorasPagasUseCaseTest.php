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

    #[TestDox('motivo com menos de 3 caracteres uteis e recusado')]
    public function testMotivoCurtoRecusado(): void
    {
        // Spec §3: mínimo de 3 caracteres DEPOIS do trim. "  x  " tem 5 caracteres brutos e um só
        // útil — contar sem trim deixaria passar. O motivo é a única defesa documental de um
        // lançamento que altera verba trabalhista.
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('O motivo precisa ter ao menos 3 caracteres.');

        ($this->useCase())($this->input(motivo: '  x  '), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('motivo com exatamente 3 caracteres uteis e aceito (limite inclusivo)')]
    public function testMotivoComTresCaracteresAceito(): void
    {
        $lancamento = ($this->useCase())(
            $this->input(motivo: ' abc '),
            $this->user(2),
            $this->user(1),
            $this->tenant(1),
        );

        self::assertSame('abc', $lancamento->getMotivo(), 'o limite é inclusivo e o motivo é gravado sem os espaços');
    }

    #[TestDox('quantidade de horas absurda e recusada com mensagem, nao com 500 do banco')]
    public function testHorasAcimaDoTetoDeSanidadeRecusadas(): void
    {
        // NÃO é teto de negócio (o dono recusou um): é guarda contra estouro. 100.000.001 horas
        // viram 6.000.000.060 minutos, acima do `integer` do Postgres — sem esta recusa o admin
        // levava um 500 no INSERT.
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('A quantidade de horas não pode passar de 100000.');

        ($this->useCase())($this->input(horas: 100000001), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('quantidade de horas no teto de sanidade ainda e aceita (limite inclusivo)')]
    public function testHorasNoTetoDeSanidadeAceitas(): void
    {
        $lancamento = ($this->useCase())(
            $this->input(operacao: 'descontar', horas: 100000, minutos: 0),
            $this->user(2),
            $this->user(1),
            $this->tenant(1),
        );

        self::assertSame(-6000000, $lancamento->getMinutos(), 'o teto é inclusivo e cabe folgado no integer do Postgres');
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

    /**
     * Auto-lançamento é PERMITIDO desde 2026-07-31, por decisão do dono. A trava anterior recusava
     * `colaborador === autor` mesmo para super-admin. Este teste existe para que reintroduzir a trava
     * não passe despercebido — as demais guardas (tenant, vínculo, validação do input) seguem valendo.
     */
    #[TestDox('admin lanca horas pagas para si mesmo')]
    public function testAutoLancamentoPermitido(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $mesmo  = $this->user(7);
        $tenant = $this->tenant(1);

        $lancamento = (new LancarHorasPagasUseCase($em, $this->userTenantRepository()))(
            $this->input(operacao: 'acrescentar', horas: 2, minutos: 0, motivo: 'Ajuste do proprio banco'),
            $mesmo,
            $mesmo,
            $tenant,
        );

        self::assertSame(120, $lancamento->getMinutos());
        self::assertSame($mesmo, $lancamento->getUser());
        self::assertSame($mesmo, $lancamento->getCriadoPor(), 'beneficiado e autor podem ser a mesma pessoa');
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

    #[TestDox('editar o proprio lancamento recebido e permitido')]
    public function testEditarLancamentoDoProprioAutorPermitido(): void
    {
        $mesmo = $this->user(7);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(1));
        $lancamento->setUser($mesmo);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new EditarHorasPagasUseCase($em))(
            $lancamento,
            $this->input(operacao: 'acrescentar', horas: 3, minutos: 0),
            $mesmo,
            $this->tenant(1),
        );

        self::assertSame(180, $lancamento->getMinutos(), 'a edição do próprio lançamento tem de valer');
        self::assertSame($mesmo, $lancamento->getAtualizadoPor());
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
