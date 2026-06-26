<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tenant\DTO\DemitirFuncionarioInput;
use App\Tenant\UseCase\DemitirFuncionarioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C1 — DemitirFuncionarioUseCase limpa/transfere responsabilidades via bulk DQL (Pasta/Chamado) e
 * SQL nativo (tarefa_responsaveis/evento_participante), que ESCAPAM do TenantFilter. O teste roda
 * com o filtro DESLIGADO (pior caso: super-admin sem tenant na sessão / CLI) e prova que o escopo
 * EXPLÍCITO por $input->tenant protege as responsabilidades do funcionário nos demais escritórios.
 * Vetor: funcionário multi-tenant (vínculo ativo em A e B), responsável por pasta/chamado/tarefa/
 * evento nos dois — demitir de A não pode tocar nada de B. em->clear() força o re-find a ler do
 * banco (bulk DQL e SQL nativo não atualizam a identity map).
 */
#[CoversClass(DemitirFuncionarioUseCase::class)]
final class DemitirFuncionarioIsolamentoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DemitirFuncionarioUseCase $useCase;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->useCase = static::getContainer()->get(DemitirFuncionarioUseCase::class);
    }

    #[TestDox('Demitir sem substituto zera as responsabilidades do tenant demitido e não toca as de outro tenant')]
    public function testRemoverNaoCruzaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $executor    = $this->criarUser();
        $funcionario = $this->criarUser();
        $this->vincular($funcionario, $tenantA);
        $this->vincular($funcionario, $tenantB);

        $pastaA   = $this->criarPasta($tenantA, $funcionario);
        $pastaB   = $this->criarPasta($tenantB, $funcionario);
        $chamadoA = $this->criarChamado($tenantA, $funcionario);
        $chamadoB = $this->criarChamado($tenantB, $funcionario);
        $tarefaA  = $this->criarTarefa($pastaA, $funcionario);
        $tarefaB  = $this->criarTarefa($pastaB, $funcionario);
        $eventoA  = $this->criarEvento($tenantA, $funcionario);
        $eventoB  = $this->criarEvento($tenantB, $funcionario);

        $funcId   = (int) $funcionario->getId();
        $ids      = $this->capturarIds($pastaA, $pastaB, $chamadoA, $chamadoB, $tarefaA, $tarefaB, $eventoA, $eventoB);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenantA));

        $this->em->clear();

        // Tenant A: tudo zerado
        self::assertNull($this->em->find(Pasta::class, $ids['pastaA'])->getResponsavel(), 'pasta de A deveria perder o responsável');
        self::assertNull($this->em->find(Chamado::class, $ids['chamadoA'])->getResponsavel(), 'chamado de A deveria perder o responsável');
        self::assertSame([], $this->ids($this->em->find(Tarefa::class, $ids['tarefaA'])->getResponsaveis()), 'tarefa de A deveria perder o responsável');
        self::assertSame([], $this->ids($this->em->find(Evento::class, $ids['eventoA'])->getParticipantes()), 'evento de A deveria perder o participante');

        // Tenant B: intacto
        self::assertSame($funcId, $this->em->find(Pasta::class, $ids['pastaB'])->getResponsavel()?->getId(), 'pasta de B NÃO deveria ser tocada');
        self::assertSame($funcId, $this->em->find(Chamado::class, $ids['chamadoB'])->getResponsavel()?->getId(), 'chamado de B NÃO deveria ser tocado');
        self::assertSame([$funcId], $this->ids($this->em->find(Tarefa::class, $ids['tarefaB'])->getResponsaveis()), 'tarefa de B NÃO deveria ser tocada');
        self::assertSame([$funcId], $this->ids($this->em->find(Evento::class, $ids['eventoB'])->getParticipantes()), 'evento de B NÃO deveria ser tocado');
    }

    #[TestDox('Demitir com substituto transfere as responsabilidades do tenant demitido e não toca as de outro tenant')]
    public function testTransferirNaoCruzaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $executor    = $this->criarUser();
        $funcionario = $this->criarUser();
        $substituto  = $this->criarUser();
        $this->vincular($funcionario, $tenantA);
        $this->vincular($funcionario, $tenantB);
        $this->vincular($substituto, $tenantA);

        $pastaA   = $this->criarPasta($tenantA, $funcionario);
        $pastaB   = $this->criarPasta($tenantB, $funcionario);
        $chamadoA = $this->criarChamado($tenantA, $funcionario);
        $chamadoB = $this->criarChamado($tenantB, $funcionario);
        $tarefaA  = $this->criarTarefa($pastaA, $funcionario);
        $tarefaB  = $this->criarTarefa($pastaB, $funcionario);
        $eventoA  = $this->criarEvento($tenantA, $funcionario);
        $eventoB  = $this->criarEvento($tenantB, $funcionario);

        $funcId   = (int) $funcionario->getId();
        $subId    = (int) $substituto->getId();
        $ids      = $this->capturarIds($pastaA, $pastaB, $chamadoA, $chamadoB, $tarefaA, $tarefaB, $eventoA, $eventoB);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenantA, $substituto));

        $this->em->clear();

        // Tenant A: transferido ao substituto
        self::assertSame($subId, $this->em->find(Pasta::class, $ids['pastaA'])->getResponsavel()?->getId(), 'pasta de A deveria ir para o substituto');
        self::assertSame($subId, $this->em->find(Chamado::class, $ids['chamadoA'])->getResponsavel()?->getId(), 'chamado de A deveria ir para o substituto');
        self::assertSame([$subId], $this->ids($this->em->find(Tarefa::class, $ids['tarefaA'])->getResponsaveis()), 'tarefa de A deveria ir para o substituto');
        self::assertSame([$subId], $this->ids($this->em->find(Evento::class, $ids['eventoA'])->getParticipantes()), 'evento de A deveria ir para o substituto');

        // Tenant B: intacto (continua com o funcionário)
        self::assertSame($funcId, $this->em->find(Pasta::class, $ids['pastaB'])->getResponsavel()?->getId(), 'pasta de B NÃO deveria ser tocada');
        self::assertSame($funcId, $this->em->find(Chamado::class, $ids['chamadoB'])->getResponsavel()?->getId(), 'chamado de B NÃO deveria ser tocado');
        self::assertSame([$funcId], $this->ids($this->em->find(Tarefa::class, $ids['tarefaB'])->getResponsaveis()), 'tarefa de B NÃO deveria ser tocada');
        self::assertSame([$funcId], $this->ids($this->em->find(Evento::class, $ids['eventoB'])->getParticipantes()), 'evento de B NÃO deveria ser tocado');
    }

    #[TestDox('Transferir para substituto que já é responsável pela mesma tarefa/evento não estoura PK e deixa o substituto único')]
    public function testTransferirComSubstitutoJaResponsavelNaoDuplica(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $executor    = $this->criarUser();
        $funcionario = $this->criarUser();
        $substituto  = $this->criarUser();
        $this->vincular($funcionario, $tenantA);
        $this->vincular($funcionario, $tenantB);
        $this->vincular($substituto, $tenantA);

        $pastaA  = $this->criarPasta($tenantA, $funcionario);
        $pastaB  = $this->criarPasta($tenantB, $funcionario);
        $tarefaA = $this->criarTarefa($pastaA, $funcionario);
        $tarefaB = $this->criarTarefa($pastaB, $funcionario);
        $eventoA = $this->criarEvento($tenantA, $funcionario);

        // Substituto JÁ é responsável pela mesma tarefa/evento de A: o INSERT da transferência
        // colidiria na PK (tarefa_id,user_id)/(evento_id,user_id) sem o NOT IN anti-duplicata.
        $tarefaA->addResponsavel($substituto);
        $eventoA->addParticipante($substituto);
        $this->em->flush();

        $funcId  = (int) $funcionario->getId();
        $subId   = (int) $substituto->getId();
        $tarefaAId = (int) $tarefaA->getId();
        $tarefaBId = (int) $tarefaB->getId();
        $eventoAId = (int) $eventoA->getId();

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenantA, $substituto));

        $this->em->clear();

        // A: substituto continua, sem duplicata; funcionário sai
        self::assertSame([$subId], $this->ids($this->em->find(Tarefa::class, $tarefaAId)->getResponsaveis()), 'tarefa de A deveria ter só o substituto, sem duplicata');
        self::assertSame([$subId], $this->ids($this->em->find(Evento::class, $eventoAId)->getParticipantes()), 'evento de A deveria ter só o substituto, sem duplicata');

        // B: funcionário intacto
        self::assertSame([$funcId], $this->ids($this->em->find(Tarefa::class, $tarefaBId)->getResponsaveis()), 'tarefa de B NÃO deveria ser tocada');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return array<string,int>
     */
    private function capturarIds(
        Pasta $pastaA, Pasta $pastaB,
        Chamado $chamadoA, Chamado $chamadoB,
        Tarefa $tarefaA, Tarefa $tarefaB,
        Evento $eventoA, Evento $eventoB,
    ): array {
        return [
            'pastaA'   => (int) $pastaA->getId(),
            'pastaB'   => (int) $pastaB->getId(),
            'chamadoA' => (int) $chamadoA->getId(),
            'chamadoB' => (int) $chamadoB->getId(),
            'tarefaA'  => (int) $tarefaA->getId(),
            'tarefaB'  => (int) $tarefaB->getId(),
            'eventoA'  => (int) $eventoA->getId(),
            'eventoB'  => (int) $eventoB->getId(),
        ];
    }

    /**
     * @param iterable<User> $users
     * @return list<int>
     */
    private function ids(iterable $users): array
    {
        $out = [];
        foreach ($users as $u) {
            $out[] = (int) $u->getId();
        }
        sort($out);

        return $out;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DEMITIR ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('demitir_' . uniqid() . '@test.com');
        $user->setFullName('User Demitir');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $this->em->persist(new UserTenant($user, $tenant));
        $this->em->flush();
    }

    private function criarPasta(Tenant $tenant, User $responsavel): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('DEM-' . (++$this->seq) . '-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setResponsavel($responsavel);
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    private function criarChamado(Tenant $tenant, User $responsavel): Chamado
    {
        $chamado = new Chamado();
        $chamado->setTitulo('Chamado ' . uniqid());
        $chamado->setDescricao('Descrição');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($responsavel);
        $chamado->setResponsavel($responsavel);
        $this->em->persist($chamado);
        $this->em->flush();

        return $chamado;
    }

    private function criarTarefa(Pasta $pasta, User $responsavel): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo('Tarefa ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($pasta->getTenant());
        $tarefa->addResponsavel($responsavel);
        $this->em->persist($tarefa);
        $this->em->flush();

        return $tarefa;
    }

    private function criarEvento(Tenant $tenant, User $participante): Evento
    {
        $evento = new Evento();
        $evento->setTitulo('Evento ' . uniqid());
        $evento->setDataInicio(new \DateTimeImmutable('2026-03-10 08:00:00'));
        $evento->setDataFim(new \DateTimeImmutable('2026-03-10 09:00:00'));
        $evento->setCriador($participante);
        $evento->setTenant($tenant);
        $evento->addParticipante($participante);
        $this->em->persist($evento);
        $this->em->flush();

        return $evento;
    }
}
