<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * C6-PMC-03 — no cartão Clientes da aba Dados, as ações que escrevem no vínculo (o "+" com a
 * busca inline, a estrela vazia de "tornar principal" e a lixeira de "desvincular") só aparecem
 * para quem o servidor deixaria usá-las: as três rotas pedem EDIT na pasta e a lápide recusa
 * todo POST. Mesma regra do PAS-049 (cartão de processos). A estrela CHEIA do principal fica:
 * é leitura, não ação. O "abrir ficha" também fica.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaClientesAcoesPermissaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NENHUMA = ['vincular' => 0, 'busca' => 0, 'tornarPrincipal' => 0, 'desvincular' => 0];
    private const TODAS   = ['vincular' => 1, 'busca' => 1, 'tornarPrincipal' => 1, 'desvincular' => 2];

    private function novoPf(Tenant $tenant, string $nome, string $cpf): ClientePF
    {
        $c = new ClientePF();
        $c->setEmail('pmc03' . uniqid() . '@test.com');
        $c->setCep('80000-000');
        $c->setEndereco('Rua Um, 1');
        $c->setCidade('Curitiba');
        $c->setEstado('PR');
        $c->setTenant($tenant);
        $c->setNomeCompleto($nome);
        $c->setCpf($cpf);
        $c->setRg('12.345.678-9');
        $c->setRgOrgaoExpedidor('SSP');

        return $c;
    }

    /** Pasta com dois clientes: o primeiro vinculado vira o principal (Pasta::addCliente). */
    private function cenario(Tenant $tenant): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        foreach ([
            $this->novoPf($tenant, 'Ana Principal', '111.111.111-11'),
            $this->novoPf($tenant, 'Bruno Segundo', '222.222.222-22'),
        ] as $c) {
            $this->em()->persist($c);
            $pasta->addCliente($c);
        }
        $this->em()->flush();

        return $pasta;
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $secao = $crawler->filter('section.ps-clientes[data-trilho="clientes"]');
        self::assertCount(1, $secao);
        self::assertCount(2, $secao->filter('#clientesList .cliente-linha'), 'as linhas continuam para todos');

        return $secao;
    }

    /** @return array<string, int> contagem de cada ação no cartão */
    private function acoes(Crawler $secao): array
    {
        return [
            'vincular'        => $secao->filter('.ps-card-cab > button.js-ps-vinc-abrir')->count(),
            'busca'           => $secao->filter('section > #psVincCliente.ps-vinc')->count(),
            'tornarPrincipal' => $secao->filter('.js-cliente-acoes > form.js-ajax-cliente-principal')->count(),
            'desvincular'     => $secao->filter('.js-cliente-acoes > form.js-ajax-desvincular-cliente')->count(),
        ];
    }

    /** O que é leitura fica para todos: a estrela cheia do principal e o "abrir ficha". */
    private function assertLeituraFica(Crawler $secao): void
    {
        self::assertCount(1, $secao->filter('.cliente-principal .js-cliente-acoes > .js-cliente-principal-estrela'));
        self::assertCount(2, $secao->filter('.js-cliente-acoes > a.cliente-abrir'));
    }

    #[TestDox('Só leitura da pasta: sem "+", sem busca, sem estrela de trocar e sem lixeira')]
    public function testLeitorNaoVe(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->cenario($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);

        $this->logarComTenant($client, $leitor, $tenant);
        $secao = $this->abrir($client, $pasta);

        self::assertSame(self::NENHUMA, $this->acoes($secao));
        $this->assertLeituraFica($secao);
    }

    #[TestDox('Lápide: nenhuma ação de escrita no cartão Clientes, nem para o administrador')]
    public function testLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->cenario($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $secao = $this->abrir($client, $pasta);

        self::assertSame(self::NENHUMA, $this->acoes($secao));
        $this->assertLeituraFica($secao);
    }

    #[TestDox('Com EDIT na pasta (papel comum): "+", busca, estrela de trocar e as duas lixeiras')]
    public function testEditorVe(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->cenario($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);

        $this->logarComTenant($client, $editor, $tenant);
        $secao = $this->abrir($client, $pasta);

        self::assertSame(self::TODAS, $this->acoes($secao));
        $this->assertLeituraFica($secao);
    }
}
