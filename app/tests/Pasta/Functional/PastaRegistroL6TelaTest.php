<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote L6 no Registro dos expedientes (desenho 1.2.3, dc L.1300-1325 e `regsItens` L.5966-5978):
 * dica do "editado" (PAS-033), linha sem lixeira nem "· N min" (PAS-037), edição com o rodapé
 * dentro do editor (PAS-032) e a caixa de resposta sob o cartão (PAS-030/031).
 *
 * O que muda na tela roda no navegador; aqui se prova o que o Twig desenha e o script.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaRegistroL6TelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $texto, string $quando, ?PastaMensagem $respostaA = null, ?string $editadaEm = null): PastaMensagem
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($texto)
            ->setRespostaA($respostaA);
        if ($editadaEm !== null) {
            $msg->setEditadaEm(new \DateTimeImmutable($editadaEm));
        }

        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($msg, new \DateTimeImmutable($quando));

        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function html(object $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    #[TestDox('PAS-033: a dica do "editado" é "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM", no Twig e no JS')]
    public function testDicaDoEditado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg             = $this->registrar($pasta, $user, $tenant, 'Combinado', '2026-10-01 09:12:00', null, '2026-10-02 14:05:00');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $marca = $crawler->filter('#pasta-msg-' . $msg->getId() . ' > .ps-anotacao-topo > .pasta-msg-editado');
        self::assertSame('editado', trim($marca->text()));
        self::assertSame('Publicado às 09:12 · última edição 02/10/2026 às 14:05', $marca->attr('title'));
        self::assertStringNotContainsString('Editado em', $this->html($client));
        self::assertStringContainsString("marca.title = (publicado ? 'Publicado às ' + publicado + ' · ' : '') + 'última edição '", $this->html($client));
    }
}
