<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\DjenController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O `PublicacaoDjenListaItem` é compartilhado entre a lista do MÓDULO Push Processual e a aba Push
 * da pasta. O FPN-012 deu ao DTO o `textoResumo` (título do cartão da pasta); este teste segura o
 * outro lado: a lista do módulo continua com a coluna Órgão e sem teor nenhum na página.
 */
#[CoversClass(DjenController::class)]
#[Group('djen')]
final class PushProcessualListaIntactaTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    #[TestDox('A lista do módulo segue mostrando o órgão e não traz o teor da publicação')]
    public function testListaDoModuloSemTeorEComOrgao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $pub    = $this->criarPublicacao($tenant, '930001', '50636766220224047000');
        $pub->setNomeOrgao('2ª Vara Federal de Teste');
        $pub->setTexto('<p>TEOR QUE NAO PODE IR PARA A LISTA DO MODULO</p>');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('TEOR QUE NAO PODE IR PARA A LISTA DO MODULO', $html);
        self::assertStringContainsString('2ª Vara Federal de Teste', $crawler->filter('[data-pp-tabela]')->text());
    }
}
