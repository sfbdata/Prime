<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanBoard;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A passagem do bastão do Kanban usa SQL nativo (kanban_board_participante) e UPDATE direto
 * (kanban_board.criado_por_id), que ESCAPAM do TenantFilter. O teste prova que o escopo
 * EXPLÍCITO por tenant_id protege o quadro do outro escritório: remover no A não pode tocar
 * nada do B, mesmo com a mesma pessoa criando/participando dos dois quadros.
 */
#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class RemoverColaboradorIsolamentoTest extends JusPrimeWebTestCase
{
    #[TestDox('remover no escritorio A nao toca no quadro de Kanban do escritorio B')]
    public function testNaoTocaNoOutroEscritorio(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenantA = new Tenant(); $tenantA->setName('A ' . uniqid());
        $tenantB = new Tenant(); $tenantB->setName('B ' . uniqid());
        $em->persist($tenantA); $em->persist($tenantB);

        $pessoa = new User();
        $pessoa->setEmail('multi_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa em dois escritórios');
        $em->persist($pessoa);

        $admin = new User();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin A');
        $em->persist($admin);

        $em->persist(new UserTenant($pessoa, $tenantA));
        $em->persist(new UserTenant($pessoa, $tenantB));
        $em->persist(new UserTenant($admin, $tenantA));

        // Um quadro em CADA escritório, ambos criados pela mesma pessoa. KanbanBoard exige
        // nome/tenant/criadoPor no construtor — não tem setTenant/setCriadoPor.
        $boardA = new KanbanBoard('Quadro do A', $tenantA, $pessoa);
        $em->persist($boardA);

        $boardB = new KanbanBoard('Quadro do B', $tenantB, $pessoa);
        $boardB->adicionarParticipante($pessoa);
        $em->persist($boardB);
        $em->flush();

        // O UseCase ainda não tem consumidor em produção (controller vem em tarefa
        // posterior) — o compilador do container de teste remove/inlina serviço privado
        // sem consumidor, então buscá-lo direto por `getContainer()->get()` falha com
        // ServiceNotFoundException. Suas duas dependências (EM e o repositório) são
        // públicas o bastante para sair do container; montamos o UseCase à mão com elas,
        // preservando que a query rode contra a conexão/EM REAIS de teste.
        $useCase = new RemoverColaboradorDoEscritorioUseCase(
            $em,
            static::getContainer()->get(UserTenantRepository::class),
        );
        $useCase->executar(new RemoverColaboradorInput($admin, $pessoa, $tenantA));

        $em->clear();

        // O quadro do A trocou de dono...
        $recarregadoA = $em->find(KanbanBoard::class, $boardA->getId());
        self::assertSame(
            $admin->getId(),
            $recarregadoA->getCriadoPor()?->getId(),
            'o quadro do escritório A devia ter sido herdado pelo executor'
        );

        // ...e o do B não foi tocado.
        $recarregadoB = $em->find(KanbanBoard::class, $boardB->getId());
        self::assertSame(
            $pessoa->getId(),
            $recarregadoB->getCriadoPor()?->getId(),
            'o criador do quadro do escritório B não podia ter mudado'
        );
        self::assertCount(1, $recarregadoB->getParticipantes(), 'o participante do B foi apagado');
    }
}
