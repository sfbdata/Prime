<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\UseCase\ListarContasPagantesUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Controller\PainelMasterDadosController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Filtro "Pagantes" de "Contas e escritórios" (Painel do Master): cada conta da lista leva `pagante` (plano, valor por
 * mês, até quando vale e se está em carência), lido de TODOS os escritórios pela VisaoDoMaster; quem não paga leva
 * null. O 403 de quem não é Master na mesma rota está no CodigoDaContaNoPainelTest.
 */
#[CoversClass(PainelMasterDadosController::class)]
#[CoversClass(ListarContasPagantesUseCase::class)]
#[CoversClass(AssinaturaDeEspacoRepository::class)]
final class ContasPagantesNoPainelTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('ativa e em carência entram com plano, valor e "vale até"; cancelada, aguardando e sem assinatura ficam null')]
    public function testPagantesNaListaDeContas(): void
    {
        $plano = $this->plano();
        $ativa = $this->conta();
        $carencia = $this->conta();
        $cancelada = $this->conta();
        $aguardando = $this->conta();
        $semAssinatura = $this->conta();
        $ate = new \DateTimeImmutable('+20 days');
        $this->assinatura($ativa, $plano, $ate);
        $this->assinatura($carencia, $plano, $ate)->aplicar(new SituacaoNoGateway('sub_c', SituacaoDaAssinatura::Inadimplente, null));
        $this->assinatura($cancelada, $plano, $ate)->cancelar();
        $this->em->persist(new AssinaturaDeEspaco($aguardando, $plano, 'falso', 2990, null));
        $this->em->flush();
        $this->entrarComoMaster();

        $contas = $this->contas();

        self::assertSame(
            ['plano' => 'Pagante de teste', 'gb' => 30, 'valorCentavos' => 2990, 'valeAte' => $ate->format('Y-m-d'), 'carencia' => false],
            $contas[$ativa->getId()]['pagante'],
        );
        self::assertTrue($contas[$carencia->getId()]['pagante']['carencia']);
        self::assertNull($contas[$cancelada->getId()]['pagante']);
        self::assertNull($contas[$aguardando->getId()]['pagante']);
        self::assertNull($contas[$semAssinatura->getId()]['pagante']);
    }

    private function plano(): Plano
    {
        $plano = new Plano(substr('pg' . uniqid(), 0, 30), 'Pagante de teste', 30, 2990, 'Plano do teste de pagantes', 90);
        $this->em->persist($plano);
        $this->em->flush();

        return $plano;
    }

    private function conta(): Tenant
    {
        $tenant = (new Tenant())->setName('Conta pagante ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function assinatura(Tenant $tenant, Plano $plano, \DateTimeImmutable $pagaAte): AssinaturaDeEspaco
    {
        $assinatura = new AssinaturaDeEspaco($tenant, $plano, 'falso', 2990, null);
        $assinatura->aplicar(new SituacaoNoGateway('sub_' . uniqid(), SituacaoDaAssinatura::Ativa, $pagaAte));
        $this->em->persist($assinatura);

        return $assinatura;
    }

    /** @return array<int, array<string, mixed>> */
    private function contas(): array
    {
        $this->client->request('GET', '/master/dados/contas');
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);

        return array_column($json['contas'], null, 'id');
    }

    private function entrarComoMaster(): void
    {
        $master = new User();
        $master->setEmail('pagante_' . uniqid() . '@teste.com');
        $master->setFullName('Master do teste de pagantes');
        $master->setPassword('x');
        $master->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $master->setIsActive(true);
        $this->em->persist($master);
        $this->em->flush();
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);
    }
}
