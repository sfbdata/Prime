<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\UltimosAlertasDasMetas;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A consulta do sino "Alertado: Nome às HH:MM", sem request — e portanto com o
 * TenantFilter DESLIGADO: o isolamento provado aqui é o da própria consulta
 * (notificação e meta do escritório informado, meta desta pasta), não o do filtro.
 */
#[CoversClass(UltimosAlertasDasMetas::class)]
#[Group('pasta')]
final class UltimosAlertasDasMetasTest extends KernelTestCase
{
    use Factories;

    private UltimosAlertasDasMetas $servico;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->servico = static::getContainer()->get(UltimosAlertasDasMetas::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function alerta(Tarefa $meta, User $destinatario, Tenant $tenant, string $em, string $tipo = AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO): void
    {
        $n = new Notificacao();
        $n->setUsuario($destinatario);
        $n->setTenant($tenant);
        $n->setTipo($tipo);
        $n->setTitulo('Alerta para verificar a meta');
        $n->setTarefa($meta);
        $this->em()->persist($n);
        $this->em()->flush();

        // `criadaEm` nasce no construtor e não tem setter: o teste fixa o instante.
        $this->em()->getConnection()->executeStatement(
            'UPDATE notificacao SET criada_em = :em WHERE id = :id',
            ['em' => $em, 'id' => $n->getId()],
        );
    }

    #[TestDox('devolve o ÚLTIMO alerta de cada meta da pasta, com o nome do destinatário')]
    public function testUltimoAlertaPorMeta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $metaA  = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant])->_real();
        $metaB  = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant])->_real();
        $semAlerta = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant])->_real();
        $bruno  = UserFactory::createOne(['fullName' => 'Bruno Lima'])->_real();
        $carla  = UserFactory::createOne(['fullName' => 'Carla Dias'])->_real();

        $this->alerta($metaA, $bruno, $tenant, '2026-10-01 09:00:00');
        $this->alerta($metaA, $carla, $tenant, '2026-10-02 16:20:00');
        $this->alerta($metaB, $bruno, $tenant, '2026-09-30 11:11:00');

        $alertas = $this->servico->daPasta($pasta, $tenant);

        self::assertEqualsCanonicalizing([$metaA->getId(), $metaB->getId()], array_keys($alertas));
        self::assertArrayNotHasKey((int) $semAlerta->getId(), $alertas);
        self::assertSame('Carla Dias', $alertas[$metaA->getId()]->nome, 'o mais recente vence');
        self::assertSame('2026-10-02 16:20', $alertas[$metaA->getId()]->em->format('Y-m-d H:i'));
        self::assertSame('Bruno Lima', $alertas[$metaB->getId()]->nome);
    }

    #[TestDox('só o tipo gravado pelo sino conta: outras notificações da meta não acendem o sino')]
    public function testSoOTipoDoSino(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $meta   = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant])->_real();
        $bruno  = UserFactory::createOne(['fullName' => 'Bruno Lima'])->_real();

        $this->alerta($meta, $bruno, $tenant, '2026-10-02 16:20:00', Notificacao::TIPO_TAREFA_CRIADA);
        $this->alerta($meta, $bruno, $tenant, '2026-10-02 16:30:00', Notificacao::TIPO_TAREFA_CONCLUIDA);

        self::assertSame([], $this->servico->daPasta($pasta, $tenant), 'resultado vazio também é resposta');
    }

    #[TestDox('isolamento: alerta de meta de OUTRA pasta não entra; notificação de OUTRO escritório não entra, nem para meta desta pasta')]
    public function testIsolamento(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pasta   = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $outra   = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $meta    = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenantA])->_real();
        $metaDaOutra = TarefaFactory::createOne(['pasta' => $outra, 'tenant' => $tenantA])->_real();
        $bruno   = UserFactory::createOne(['fullName' => 'Bruno Lima'])->_real();

        $this->alerta($metaDaOutra, $bruno, $tenantA, '2026-10-02 16:20:00');
        $this->alerta($meta, $bruno, $tenantB, '2026-10-03 10:00:00');

        self::assertSame([], $this->servico->daPasta($pasta, $tenantA), 'nem a meta irmã nem a notificação de B acendem o sino');
        self::assertSame([], $this->servico->daPasta($pasta, $tenantB), 'a pasta de A pedida com o escritório B não devolve nada');

        $this->alerta($meta, $bruno, $tenantA, '2026-10-01 08:00:00');
        $alertas = $this->servico->daPasta($pasta, $tenantA);
        self::assertSame([(int) $meta->getId()], array_keys($alertas));
        self::assertSame('2026-10-01 08:00', $alertas[$meta->getId()]->em->format('Y-m-d H:i'), 'a de B, mais nova, continua de fora');
    }
}
