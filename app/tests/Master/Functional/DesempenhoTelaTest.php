<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Arranjo da seção Desempenho no /master (desenho 05 VS 25.10, L271-280): filho direto, não "existe em algum lugar".
 * O que o sistema tem e o desenho não tem não volta (caixa do AdminLTE).
 */
final class DesempenhoTelaTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $master = UserFactory::createOne(['fullName' => 'Master', 'roles' => ['ROLE_SUPER_ADMIN'], 'isActive' => true])->_real();
        $conta = (new Tenant())->setName('Conta ' . uniqid())->setCriadoPor($master);
        $em->persist($conta);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $em->persist($perfil);
        $em->persist((new UserTenant($master, $conta))->setTenantRole($perfil));
        $em->flush();
        $this->logarComTenant($this->client, $master, $conta);
    }

    #[TestDox('o painel traz a seção Desempenho: barra, KPIs e os 4 cartões como filhos diretos da grade')]
    public function testArranjo(): void
    {
        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $secao = '[data-sec-externa="desemp"] .dpm';
        self::assertSame(1, $crawler->filter($secao . ' > .dpm-barra > .dpm-periodos')->count());
        self::assertSame(3, $crawler->filter($secao . ' > .dpm-barra > .dpm-periodos > .dpm-periodo')->count(), '24 horas, 7 dias, 30 dias');
        self::assertSame(1, $crawler->filter($secao . ' > .dpm-kpis')->count());
        self::assertSame(4, $crawler->filter($secao . ' > .dpm-grade > .dpm-caixa')->count());
        self::assertSame(0, $crawler->filter('[data-sec-externa="desemp"] .card, [data-sec-externa="desemp"] .modal')->count(), 'sem componente do sistema');
    }

    #[TestDox('os textos do desenho estão na tela e os botões de gravação (PR 4)')]
    public function testTextos(): void
    {
        $this->client->request('GET', '/master');
        $html = (string) $this->client->getResponse()->getContent();

        foreach (['Incidentes', 'Backups e restauração', 'Integrações', 'Erros recentes', '24 horas', '7 dias', '30 dias', 'Nenhum incidente registrado.', 'Nenhum backup registrado.', 'Nenhum erro no período.'] as $texto) {
            self::assertStringContainsString($texto, $html, $texto);
        }
        foreach (['Limpar registros', 'Abrir incidente', 'Registrar backup', 'Registrar teste'] as $botao) {
            self::assertStringContainsString($botao, $html, $botao);
        }
        self::assertStringContainsString('data-token="', $html);
    }
}
