<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\PublicacaoPrazoController;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(PublicacaoPrazoController::class)]
final class PrazoDaPublicacaoTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07022222222222222222';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function token(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        return $client->request('GET', '/push-processual')->filter('[data-pp-lote] input[name="_token"]')->attr('value');
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: int, 2: string, 3: Tenant} */
    private function cenario(bool $vinculada): array
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '980001', self::NUMERO);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->token($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');
        if ($vinculada) {
            $pasta = new Pasta();
            $pasta->setNup('NUP-P1');
            $pasta->setNomeCliente('MARIA');
            $pasta->setTenant($this->em()->getReference(Tenant::class, $tenant->getId()));
            $this->em()->persist($pasta);
            $this->em()->flush();
            $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => $token, 'pasta_id' => $pasta->getId(), 'numero' => self::NUMERO]);
        }

        return [$client, $id, $token, $tenant];
    }

    #[Test]
    public function semPrazoEscolhidoNadaEPresumido(): void
    {
        [$client, $id] = $this->cenario(false);

        $lista = $client->request('GET', '/push-processual');
        self::assertCount(0, $lista->filter('.pp-selo--prazo'));

        $detalhe = $client->request('GET', "/push-processual/publicacoes/$id/detalhe");
        self::assertCount(0, $detalhe->filter('.pp-det__dia--on'));
        self::assertStringContainsString('Sem prazo definido', $detalhe->text());
    }

    #[Test]
    public function abrirODetalheMarcaComoLida(): void
    {
        [$client, $id] = $this->cenario(false);
        self::assertSame('0', $client->request('GET', '/push-processual')->filter('[data-pp-linha]')->attr('data-lida'));

        $client->request('GET', "/push-processual/publicacoes/$id/detalhe");

        self::assertSame('1', $client->request('GET', '/push-processual')->filter('[data-pp-linha]')->attr('data-lida'));
    }

    #[Test]
    public function escolherDiasMostraOSeloEOIntervalo(): void
    {
        [$client, $id, $token] = $this->cenario(false);

        $client->request('POST', "/push-processual/publicacoes/$id/prazo", ['_token' => $token, 'dias' => '15', 'voltar' => '/push-processual']);
        $lista = $client->followRedirect();

        self::assertCount(1, $lista->filter('.pp-selo--prazo'));
        self::assertStringContainsString('Vencido', $lista->filter('.pp-selo--prazo')->text());
        $detalhe = $client->request('GET', "/push-processual/publicacoes/$id/detalhe");
        self::assertSame('15 dias', trim($detalhe->filter('.pp-det__dia--on')->text()));
        self::assertStringContainsString('dias úteis, sem fins de semana', $detalhe->text());
    }

    #[Test]
    public function avulsaNaoCriaMetaEOBotaoVemDesabilitado(): void
    {
        [$client, $id, $token] = $this->cenario(false);
        $client->request('POST', "/push-processual/publicacoes/$id/prazo", ['_token' => $token, 'dias' => '10']);

        $detalhe = $client->request('GET', "/push-processual/publicacoes/$id/detalhe");
        self::assertSame('Vincule uma pasta para criar a meta', $detalhe->filter('button.pp-det__btn-meta[disabled]')->attr('title'));

        $client->request('POST', "/push-processual/publicacoes/$id/meta", ['_token' => $token]);
        self::assertStringContainsString('Vincule uma pasta', $client->followRedirect()->text());
    }

    #[Test]
    public function criarMetaGravaATarefaNaPastaUmaVezSo(): void
    {
        [$client, $id, $token, $tenant] = $this->cenario(true);
        $client->request('POST', "/push-processual/publicacoes/$id/prazo", ['_token' => $token, 'dias' => '30']);

        $client->request('POST', "/push-processual/publicacoes/$id/meta", ['_token' => $token, 'voltar' => '/push-processual']);
        $lista = $client->followRedirect();

        self::assertStringContainsString('Meta criada com prazo até', $lista->text());
        self::assertCount(1, $lista->filter('.pp-meta-criada'));
        $this->limparIdentityMap();
        $tarefas = $this->em()->getRepository(Tarefa::class)->findBy(['tenant' => $tenant]);
        self::assertCount(1, $tarefas);
        self::assertStringStartsWith('Manifestação: intimação de 06/07/2026', $tarefas[0]->getTitulo());

        $client->request('POST', "/push-processual/publicacoes/$id/meta", ['_token' => $token]);
        self::assertStringContainsString('já tem uma meta', $client->followRedirect()->text());
        $this->limparIdentityMap();
        self::assertCount(1, $this->em()->getRepository(Tarefa::class)->findBy(['tenant' => $tenant]));
    }

    #[Test]
    public function publicacaoDeOutroEscritorioNaoAbre(): void
    {
        [$client] = $this->cenario(false);
        $alheia = $this->criarPublicacao($this->criarTenant(), '980099', '07033333333333333333');

        $client->request('GET', '/push-processual/publicacoes/' . $alheia->getId() . '/detalhe');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function marcarCumpridoMostraOSeloVerde(): void
    {
        [$client, $id, $token] = $this->cenario(false);
        $client->request('POST', "/push-processual/publicacoes/$id/prazo", ['_token' => $token, 'dias' => '10']);

        $client->request('POST', "/push-processual/publicacoes/$id/cumprido", ['_token' => $token, 'cumprido' => '1', 'voltar' => '/push-processual']);
        $lista = $client->followRedirect();

        self::assertCount(1, $lista->filter('.pp-selo--cumprido'));
        $this->limparIdentityMap();
        self::assertNotNull($this->em()->getRepository(PublicacaoDjen::class)->find($id)->getPrazoCumpridoEm());
    }
}
