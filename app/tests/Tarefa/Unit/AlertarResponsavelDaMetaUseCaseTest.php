<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\Exception\AlertaDeMetaRecusadoException;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AlertarResponsavelDaMetaUseCase::class)]
final class AlertarResponsavelDaMetaUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-05 14:00:00';

    private EntityManagerInterface&MockObject $em;
    private NotificacaoService&MockObject $notificacaoService;
    private NotificacaoRepository&MockObject $notificacaoRepository;
    private UserTenantRepository&MockObject $userTenantRepository;
    private AlertarResponsavelDaMetaUseCase $useCase;
    private Tenant $tenant;
    private User $remetente;
    private User $responsavel;
    private Tarefa $tarefa;

    protected function setUp(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->notificacaoService    = $this->createMock(NotificacaoService::class);
        $this->notificacaoRepository = $this->createMock(NotificacaoRepository::class);
        $this->userTenantRepository  = $this->createMock(UserTenantRepository::class);

        $this->useCase = new AlertarResponsavelDaMetaUseCase(
            $this->em,
            $this->notificacaoService,
            $this->notificacaoRepository,
            $this->userTenantRepository,
            // MockClock com string assume UTC; o resto do teste (e o NativeClock de produção) usa o
            // fuso padrão do PHP — sem isto o relógio fica 3h à frente e o teste depende da hora.
            new MockClock(new \DateTimeImmutable(self::AGORA)),
        );

        $this->tenant      = new Tenant();
        $this->remetente   = $this->usuario(10, 'Ana Autora');
        $this->responsavel = $this->usuario(20, 'Bruno Responsável');

        $pasta = new Pasta();
        $pasta->setNup('1234');
        $pasta->setTenant($this->tenant);

        $this->tarefa = new Tarefa();
        $this->tarefa->setTitulo('Protocolar recurso');
        $this->tarefa->setDescricao('...');
        $this->tarefa->setPasta($pasta);
        $this->tarefa->setTenant($this->tenant);
        $this->tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $this->tarefa->setPrazo(new \DateTimeImmutable('2026-10-02'));
        $this->tarefa->addResponsavel($this->responsavel);
        $this->tarefa->addResponsavel($this->remetente);
    }

    private function usuario(int $id, string $nome): User
    {
        $user = (new User())->setEmail('u' . $id . '@test.com');
        $user->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function alertaAnterior(string $quando): Notificacao
    {
        $n = new Notificacao();
        (new \ReflectionProperty(Notificacao::class, 'criadaEm'))->setValue($n, new \DateTimeImmutable($quando));

        return $n;
    }

    private function vinculoAtivo(bool $ativo = true): void
    {
        $this->userTenantRepository->method('existeVinculoAtivo')->willReturn($ativo);
    }

    private function recusa(int $destinatarioId, string $trecho): void
    {
        $this->notificacaoService->expects(self::never())->method('criar');
        $this->em->expects(self::never())->method('flush');

        try {
            $this->useCase->executar($this->tarefa, $this->remetente, $this->tenant, $destinatarioId);
            self::fail('deveria recusar');
        } catch (AlertaDeMetaRecusadoException $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
        }
    }

    #[TestDox('alerta o responsável escolhido: uma notificação do tipo próprio, ligada à meta, no escritório da meta')]
    public function testAlerta(): void
    {
        $this->vinculoAtivo();
        $this->notificacaoRepository->expects(self::once())->method('findOneBy')
            ->with(
                ['tarefa' => $this->tarefa, 'usuario' => $this->responsavel, 'tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO],
                ['criadaEm' => 'DESC'],
            )
            ->willReturn(null);
        $this->notificacaoService->expects(self::once())->method('criar')
            ->with(
                $this->responsavel,
                $this->tenant,
                AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO,
                'Alerta para verificar a meta',
                'Ana Autora pediu que você verifique a meta "Protocolar recurso" (pasta 1234) · 3 dias em atraso.',
                $this->tarefa,
            )
            ->willReturn(new Notificacao());
        $this->em->expects(self::once())->method('flush');

        self::assertSame($this->responsavel, $this->useCase->executar($this->tarefa, $this->remetente, $this->tenant, 20));
    }

    #[TestDox('meta com prazo no futuro: a mensagem diz quando vence')]
    public function testMensagemComPrazoFuturo(): void
    {
        $this->vinculoAtivo();
        $this->tarefa->setPrazo(new \DateTimeImmutable('2026-10-12'));
        $this->notificacaoService->expects(self::once())->method('criar')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(),
                'Ana Autora pediu que você verifique a meta "Protocolar recurso" (pasta 1234) · vence 12/10/2026.')
            ->willReturn(new Notificacao());

        $this->useCase->executar($this->tarefa, $this->remetente, $this->tenant, 20);
    }

    #[TestDox('meta concluída não recebe alerta')]
    public function testConcluidaRecusa(): void
    {
        $this->vinculoAtivo();
        $this->tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);

        $this->recusa(20, 'concluída');
    }

    #[TestDox('quem não é responsável da meta não pode ser alertado')]
    public function testNaoResponsavelRecusa(): void
    {
        $this->vinculoAtivo();

        $this->recusa(99, 'responsável desta meta');
    }

    #[TestDox('responsável sem vínculo ativo no escritório não é alertado')]
    public function testSemVinculoAtivoRecusa(): void
    {
        $this->vinculoAtivo(false);

        $this->recusa(20, 'responsável desta meta');
    }

    #[TestDox('ninguém alerta a si mesmo')]
    public function testASiMesmoRecusa(): void
    {
        $this->vinculoAtivo();

        $this->recusa(10, 'a si mesmo');
    }

    #[TestDox('alerta repetido dentro de 1 hora é recusado, dizendo a hora do anterior')]
    public function testRepetidoDentroDaHoraRecusa(): void
    {
        $this->vinculoAtivo();
        $this->notificacaoRepository->method('findOneBy')->willReturn($this->alertaAnterior('2026-10-05 13:30:00'));

        $this->recusa(20, 'às 13:30');
    }

    #[TestDox('passada 1 hora do alerta anterior, alerta de novo')]
    public function testDepoisDaHoraAlerta(): void
    {
        $this->vinculoAtivo();
        $this->notificacaoRepository->method('findOneBy')->willReturn($this->alertaAnterior('2026-10-05 12:59:00'));
        $this->notificacaoService->expects(self::once())->method('criar')->willReturn(new Notificacao());
        $this->em->expects(self::once())->method('flush');

        $this->useCase->executar($this->tarefa, $this->remetente, $this->tenant, 20);
    }

    #[TestDox('meta de outro escritório: recusa sem notificar')]
    public function testOutroEscritorio(): void
    {
        $this->notificacaoService->expects(self::never())->method('criar');
        $this->expectException(\LogicException::class);

        $this->useCase->executar($this->tarefa, $this->remetente, new Tenant(), 20);
    }
}
