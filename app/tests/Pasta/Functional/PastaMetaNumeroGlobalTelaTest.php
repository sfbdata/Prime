<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Aba Metas da pasta: o selo "Nº 0001" do desenho 02 (dc L1559) logo depois do título, com a dica literal
 * (dc L6682). É o número da meta no ESCRITÓRIO (Tarefa::getNumero), nunca o id. Meta antiga, sem número
 * até o app:tarefa:numerar-metas rodar, não mostra selo nenhum.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaMetaNumeroGlobalTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const SELO = '.ps-metas-lista > article.ps-meta[data-meta-id="%d"] > .ps-meta-abrir > .ps-meta-corpo > .ps-meta-titulo > .ps-meta-titulo-tx > span.meta-num-global';

    private function criarMeta(Pasta $pasta, User $user, string $titulo, ?int $numero): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($user);
        if ($numero !== null) {
            $meta->numerar($numero);
        }
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    #[TestDox('O selo "Nº 0012" vem depois do título, dentro do texto do título, com a dica do desenho')]
    public function testSeloDepoisDoTituloComADica(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarMeta($pasta, $user, 'Primeira', 11);
        $segunda = $this->criarMeta($pasta, $user, 'Protocolar recurso', 12);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $selo = $crawler->filter(sprintf(self::SELO, $segunda->getId()));
        self::assertCount(1, $selo, 'o selo é filho direto do texto do título');
        self::assertSame('Nº 0012', trim($selo->text()));
        self::assertSame('Meta 2 desta pasta · nº 12 na sequência geral do sistema', $selo->attr('title'));

        // Depois do título, não antes: o texto do título vem primeiro e o selo é o último filho.
        $titulo = $crawler->filter(sprintf('.ps-metas-lista > article.ps-meta[data-meta-id="%d"] .ps-meta-titulo > .ps-meta-titulo-tx', $segunda->getId()));
        self::assertStringStartsWith('Protocolar recurso', $titulo->text());
        self::assertSame('meta-num-global', $titulo->children()->last()->attr('class'));

        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href$="/css/meta-numero-global.css"]'), 'o estilo do selo é carregado');
    }

    #[TestDox('Meta sem número: nenhum selo, nem "Nº 0000"')]
    public function testSemNumeroNaoHaSelo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $antiga = $this->criarMeta($pasta, $user, 'Meta antiga', null);
        $nova   = $this->criarMeta($pasta, $user, 'Meta nova', 1);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter(sprintf('.ps-metas-lista > article.ps-meta[data-meta-id="%d"] .meta-num-global', $antiga->getId())));
        self::assertSame('Meta antiga', trim($crawler->filter(sprintf('.ps-metas-lista > article.ps-meta[data-meta-id="%d"] .ps-meta-titulo-tx', $antiga->getId()))->text()));
        self::assertCount(1, $crawler->filter(sprintf(self::SELO, $nova->getId())));
        self::assertStringNotContainsString('Nº 0000', (string) $client->getResponse()->getContent());
    }
}
