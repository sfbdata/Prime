<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\FeriadoController;
use App\Ponto\Entity\Feriado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A página antiga "Feriados e Folgas" mostra a abrangência de cada feriado (spec `docs/specs/ponto-desenho-0811.md`,
 * E6): "Todas as sedes" ou o nome da lotação. Só os feriados do escritório da sessão.
 */
#[CoversClass(FeriadoController::class)]
#[Group('ponto')]
final class FeriadoAbrangenciaNaPaginaAntigaTest extends JusPrimeWebTestCase
{
    #[TestDox('A lista mostra a coluna "Abrangência": "Todas as sedes" ou o nome da lotação; o de outro escritório não aparece')]
    public function testListaMostraAAbrangencia(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->escritorio('Tenant Abrangência');
        $outro = $this->escritorio('Outro Abrangência');
        $maceio = (new Lotacao())->setNome('MACEIÓ/AL')->setTenant($tenant);
        $deLa = (new Lotacao())->setNome('RECIFE/PE')->setTenant($outro);
        $em->persist($maceio);
        $em->persist($deLa);
        $this->feriado($tenant, 'Natal', '2026-12-25', null);
        $this->feriado($tenant, 'Emancipação de Alagoas', '2026-09-16', $maceio);
        $this->feriado($outro, 'Feriado de lá', '2026-03-06', $deLa);
        $this->logarComTenant($client, $this->admin($tenant), $tenant);
        $em->clear();

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados');

        self::assertResponseIsSuccessful();
        $cabecalho = $crawler->filter('table.table > thead > tr > th');
        self::assertSame(['Data', 'Nome', 'Recorrência', 'Abrangência', 'Ações'], array_map('trim', $cabecalho->each(static fn ($th): string => $th->text())));
        $abrangencias = [];
        foreach ($crawler->filter('table.table > tbody > tr') as $linha) {
            $celulas = $linha->getElementsByTagName('td');
            $abrangencias[trim((string) $celulas->item(1)?->textContent)] = trim((string) $celulas->item(3)?->textContent);
        }
        self::assertSame(['Emancipação de Alagoas' => 'MACEIÓ/AL', 'Natal' => 'Todas as sedes'], $abrangencias);
    }

    #[TestDox('Sem feriado, a linha de vazio ocupa as cinco colunas')]
    public function testListaVazia(): void
    {
        $client = static::createClient();
        $tenant = $this->escritorio('Tenant Abrangência vazio');
        $this->logarComTenant($client, $this->admin($tenant), $tenant);

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.table > tbody > tr > td[colspan="5"]'));
    }

    private function escritorio(string $nome): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName($nome . ' ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function admin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('feriado_abr_' . uniqid() . '@test.com');
        $user->setFullName('Admin Abrangência');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function feriado(Tenant $tenant, string $nome, string $dia, ?Lotacao $lotacao): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Feriado())->setNome($nome)->setData(new \DateTime($dia))->setRecorrente(true)->setTenant($tenant)->setLotacao($lotacao));
        $em->flush();
    }
}
