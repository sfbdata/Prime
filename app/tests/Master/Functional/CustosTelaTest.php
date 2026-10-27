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
 * Arranjo da seção Custos no /master (desenho 05 VS 25.10, L260-270): filho direto, não "existe em algum lugar".
 * O que o sistema tem e o desenho não tem não volta (caixa do AdminLTE).
 */
final class CustosTelaTest extends JusPrimeWebTestCase
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

    #[TestDox('o painel traz a seção Custos: barra, 5 KPIs por script, 2 cartões na grade e a lista como filhos diretos')]
    public function testArranjo(): void
    {
        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $secao = '[data-sec-externa="custos"] .cst';
        self::assertSame(1, $crawler->filter($secao . ' > .cst-barra > .cst-mes > input[type="month"]')->count());
        self::assertSame(1, $crawler->filter($secao . ' > .cst-kpis')->count());
        self::assertSame(2, $crawler->filter($secao . ' > .cst-grade > .cst-caixa')->count());
        self::assertSame(1, $crawler->filter($secao . ' > .cst-caixa > [data-cst-lancamentos]')->count());
        self::assertSame(0, $crawler->filter('[data-sec-externa="custos"] .card, [data-sec-externa="custos"] .modal')->count(), 'sem componente do sistema');
    }

    #[TestDox('os textos do desenho estão na tela')]
    public function testTextos(): void
    {
        $this->client->request('GET', '/master');
        $html = (string) $this->client->getResponse()->getContent();

        foreach (['Custos por categoria', 'Margem por plano e projeção', 'Lançamentos do mês', 'Projeção mantém os valores do mês selecionado.', 'PLANO', 'PROJEÇÃO'] as $texto) {
            self::assertStringContainsString($texto, $html, $texto);
        }
        foreach (['Copiar do mês anterior', 'Lançar custo'] as $botao) {
            self::assertStringContainsString($botao, $html, $botao);
        }
        self::assertStringContainsString('data-url-dados="/master/dados/custos"', $html);
        self::assertStringContainsString('data-token="', $html);
    }
}
