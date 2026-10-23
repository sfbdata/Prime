<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\PainelMasterDadosController;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\MapaDeAtuacao;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Seta de tendência do mapa com as saídas da equipe (spec perfis-1.2-planos-e-limites §5, C6): saldo de entradas
 * menos saídas por estado, contra o banco. O banco de teste pode ter dados de outros testes: a tendência é medida
 * como DIFERENÇA antes/depois do cenário, e as linhas do repositório pelos escritórios criados aqui.
 */
#[CoversClass(PainelMasterRepository::class)]
#[CoversClass(ConsultarVisaoGeralUseCase::class)]
#[CoversClass(MapaDeAtuacao::class)]
#[CoversClass(PainelMasterDadosController::class)]
final class TendenciaComSaidasTest extends JusPrimeWebTestCase
{
    private const ROTAS = ['/master/dados/visao-geral', '/master/dados/mapa'];

    #[TestDox('saidasRecentes: recorte de 60 dias (o dia 61 fica fora) e escritório na quarentena fora')]
    public function testRecorteDoRepositorio(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $t = $this->tenant($em, 'TO');
        $q = $this->tenant($em, 'TO');
        $q->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $comOab = $this->usuario($em, 'PI');
        $em->flush();

        $this->saida($t, null, true, '-10 days');
        $this->saida($t, $comOab, false, '-45 days');
        $this->saida($t, null, false, '-59 days -23 hours');
        $this->saida($t, null, true, '-61 days');
        $this->saida($q, null, true, '-5 days');

        $agora = new \DateTimeImmutable();
        $linhas = static::getContainer()->get(PainelMasterRepository::class)->saidasRecentes($agora->modify('-60 days'), $agora->modify('+1 second'));
        $minhas = array_values(array_filter($linhas, static fn (array $l): bool => in_array($l['tenantId'], [$t->getId(), $q->getId()], true)));

        self::assertCount(3, $minhas, 'dia 61 e quarentena fora');
        self::assertSame([$t->getId()], array_values(array_unique(array_column($minhas, 'tenantId'))));
        self::assertSame([true, false, false], array_reverse(array_column($minhas, 'eraPrimario')), 'ordem por saiu_em');
        $daOab = array_values(array_filter($minhas, static fn (array $l): bool => $l['userId'] !== null));
        self::assertCount(1, $daOab);
        self::assertSame($comOab->getId(), $daOab[0]['userId']);
        self::assertSame('PI', $daOab[0]['oabUf']);
        self::assertSame('TO', $daOab[0]['estado']);
        self::assertSame(['saiuEm', 'eraPrimario', 'tenantId', 'userId', 'oabUf', 'estado'], array_keys($daOab[0]), 'sem nome, e-mail nem CPF');
    }

    #[TestDox('JSON da visão geral e do mapa: tendência líquida (entradas menos saídas) em "Geral" e "Só primários"')]
    public function testTendenciaLiquidaNoJson(): void
    {
        $client = $this->clienteMaster();
        $antes = $this->tendencias($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $t = $this->tenant($em, 'TO');
        $outro = $this->tenant($em, 'AC');
        $q = $this->tenant($em, 'TO');
        $q->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $colaborador = $this->papel($em, $t, false);

        $entrante = $this->usuario($em, null);
        $em->persist((new UserTenant($entrante, $t))->setTenantRole($colaborador));
        $saiuDeVez = $this->usuario($em, null);
        $segueAtivo = $this->usuario($em, null);
        $em->persist((new UserTenant($segueAtivo, $outro))->setTenantRole($this->papel($em, $outro, true)));
        $em->flush();

        $this->saida($t, null, true, '-10 days');         // geral e prim: ag -1
        $this->saida($t, null, false, '-20 days');        // geral: ag -1
        $this->saida($t, $saiuDeVez, false, '-45 days');  // geral: an -1
        $this->saida($t, null, true, '-61 days');         // fora da janela
        $this->saida($q, null, true, '-5 days');          // quarentena: fora
        $this->saida($t, $segueAtivo, true, '-3 days');   // segue ativo em outro escritório: não conta

        $depois = $this->tendencias($client);
        foreach (self::ROTAS as $rota) {
            $delta = static fn (string $modo, string $janela): int => $depois[$rota][$modo][$janela] - $antes[$rota][$modo][$janela];
            self::assertSame(-1, $delta('geral', 'ag'), $rota . ': +1 entrada, -2 saídas');
            self::assertSame(-1, $delta('geral', 'an'), $rota);
            self::assertSame(-1, $delta('prim', 'ag'), $rota . ': só a saída de primário; a entrada não é primária');
            self::assertSame(0, $delta('prim', 'an'), $rota);
        }
    }

    /**
     * Tendência de TO nas duas rotas, nos dois modos (ausente vale zero, como no painel.js).
     *
     * @return array<string, array<string, array{ag: int, an: int}>>
     */
    private function tendencias(KernelBrowser $client): array
    {
        $saida = [];
        foreach (self::ROTAS as $rota) {
            $client->request('GET', $rota);
            self::assertResponseIsSuccessful();
            $json = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $mapa = $rota === '/master/dados/mapa' ? $json : $json['mapa'];
            foreach (['geral', 'prim'] as $modo) {
                $saida[$rota][$modo] = $mapa['pessoas'][$modo]['tend']['TO'] ?? ['ag' => 0, 'an' => 0];
            }
        }

        return $saida;
    }

    private function saida(Tenant $tenant, ?User $usuario, bool $primario, string $quando): void
    {
        static::getContainer()->get(Connection::class)->insert('saida_da_equipe', [
            'tenant_id'    => $tenant->getId(),
            'user_id'      => $usuario?->getId(),
            'motivo'       => 'removido',
            'era_primario' => $primario ? 'true' : 'false',
            'saiu_em'      => (new \DateTimeImmutable($quando))->format('Y-m-d H:i:s'),
        ]);
    }

    private function tenant(EntityManagerInterface $em, string $estado): Tenant
    {
        $t = (new Tenant())->setName('Escritório Tendência ' . uniqid())->setEstado($estado);
        $em->persist($t);

        return $t;
    }

    private function papel(EntityManagerInterface $em, Tenant $tenant, bool $sistema): TenantRole
    {
        $r = (new TenantRole())->setTenant($tenant)->setName($sistema ? 'Administrador do Escritório' : 'Colaborador ' . uniqid())->setIsSystem($sistema);
        $em->persist($r);

        return $r;
    }

    private function usuario(EntityManagerInterface $em, ?string $oabUf): User
    {
        $u = new User();
        $u->setEmail('tendencia_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário Tendência');
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setOabUf($oabUf);
        $em->persist($u);

        return $u;
    }

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $master = $this->usuario($em, null);
        $master->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $em->flush();
        $client->loginUser($master);
        $this->marcarTermosAceitos($client);

        return $client;
    }
}
