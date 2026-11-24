<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\Twig\FerramentasNoPerfilExtension;
use App\Produto\UseCase\ListarFerramentasDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * O cartão "Ferramentas do escritório" no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9, PR 8b):
 * só o dono vê; o estado de cada ferramenta segue os produtos do escritório, a liberação de hoje do Voto e da I.A.,
 * a trava do Master (D16) e a chave global.
 */
#[CoversClass(ListarFerramentasDoEscritorioUseCase::class)]
#[CoversClass(FerramentasNoPerfilExtension::class)]
final class FerramentasNoPerfilTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;
    use ComFerramentasNoPerfil;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('escritório de hoje (sem linhas): as ferramentas aparecem ligadas; o Voto, sem liberação, desligado; a I.A. sem a plataforma, indisponível e sem botão')]
    public function testEscritorioDeHoje(): void
    {
        $tenant = $this->escritorio();
        $this->chaveDoVoto(true);
        $cartao = $this->cartaoDo($tenant);

        self::assertCount(8, $cartao->filter('.mp-fe-lista > .mp-fe-item'));
        foreach (['expediente', 'cobrancas', 'calculo', 'kanban', 'assinatura', 'ponto'] as $produto) {
            self::assertSame('ligada', $this->estado($cartao, $produto), $produto);
            self::assertSame('0', $this->botao($cartao, $produto)->attr('data-ligar'), $produto);
        }
        self::assertSame('desligada', $this->estado($cartao, 'voto'));
        self::assertSame('1', $this->botao($cartao, 'voto')->attr('data-ligar'));
        self::assertSame('indisponivel', $this->estado($cartao, 'ia'), 'no teste a plataforma da I.A. não está configurada');
        self::assertCount(0, $this->botao($cartao, 'ia'));
    }

    #[TestDox('Voto liberado pelo Master aparece ligado; produto desligado pelo dono aparece desligado, com o botão Ligar')]
    public function testLiberadoEDesligado(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Voto);
        $this->liberarOVoto($tenant);
        $cartao = $this->cartaoDo($tenant);

        self::assertSame('ligada', $this->estado($cartao, 'voto'));
        self::assertSame('desligada', $this->estado($cartao, 'kanban'));
        self::assertSame('1', $this->botao($cartao, 'kanban')->attr('data-ligar'));
        self::assertStringEndsWith('/perfil/ferramentas/kanban', (string) $this->botao($cartao, 'kanban')->attr('data-url'));
    }

    #[TestDox('chave global do Voto desligada: "Indisponível no momento", sem botão, mesmo liberado no escritório')]
    public function testChaveGlobalDesligada(): void
    {
        $tenant = $this->escritorio();
        $this->liberarOVoto($tenant);
        $this->chaveDoVoto(false);
        $cartao = $this->cartaoDo($tenant);

        self::assertSame('indisponivel', $this->estado($cartao, 'voto'));
        self::assertCount(0, $this->botao($cartao, 'voto'));
    }

    #[TestDox('travada pelo Master (D16): "Desligada pelo BlueJus", sem botão')]
    public function testTravadaPeloMaster(): void
    {
        $tenant = $this->escritorio();
        $this->mexer($tenant, Produto::Kanban, OrigemDaContratacao::Master, false);
        $cartao = $this->cartaoDo($tenant);

        self::assertSame('travada', $this->estado($cartao, 'kanban'));
        self::assertCount(0, $this->botao($cartao, 'kanban'));
        self::assertSame('ligada', $this->estado($cartao, 'expediente'));
    }

    #[TestDox('`completo` ativa (posta pelo Master): as ferramentas aparecem ligadas, como no escritório sem linhas')]
    public function testCompletoAtiva(): void
    {
        $tenant = $this->escritorio(Produto::Completo);
        $cartao = $this->cartaoDo($tenant);

        self::assertSame('ligada', $this->estado($cartao, 'kanban'));
        self::assertSame('ligada', $this->estado($cartao, 'expediente'));
    }

    #[TestDox('desligada pelo dono: "Desligada" com o botão Ligar, nunca a trava do Master; o Master religando destrava')]
    public function testDonoDesligouEMasterReligou(): void
    {
        $tenant = $this->escritorio(Produto::Expediente, Produto::Kanban, Produto::Ponto);
        $this->mexer($tenant, Produto::Kanban, OrigemDaContratacao::Dono, false);
        $cartao = $this->cartaoDo($tenant);
        self::assertSame('desligada', $this->estado($cartao, 'kanban'));
        self::assertSame('1', $this->botao($cartao, 'kanban')->attr('data-ligar'));

        $this->mexer($tenant, Produto::Ponto, OrigemDaContratacao::Master, false);
        $this->mexer($tenant, Produto::Ponto, OrigemDaContratacao::Master, true);
        self::assertSame('ligada', $this->estado($this->cartaoDo($tenant), 'ponto'), 'a última linha do Ponto é a religada pelo Master');
    }

    #[TestDox('o cartão é do escritório da sessão: o dono de A, entrando em B (onde não é o dono), não o vê')]
    public function testEscritorioDaSessao(): void
    {
        $a = $this->escritorio();
        $b = $this->escritorio();
        $pessoa = $this->primario($a, $b);
        $this->em()->find(Tenant::class, $a->getId())?->setCriadoPor($pessoa);
        $this->em()->flush();

        $this->logarComTenant($this->client, $pessoa, $b);
        self::assertCount(0, $this->client->request('GET', '/perfil')->filter('#mpFerramentas'));

        $this->logarComTenant($this->client, $pessoa, $a);
        self::assertCount(1, $this->client->request('GET', '/perfil')->filter('#mpFerramentas'));
    }

    #[TestDox('escritório sem dono gravado (sem criador): ninguém vê o cartão')]
    public function testSemDono(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->primario($tenant), $tenant);

        self::assertCount(0, $this->client->request('GET', '/perfil')->filter('#mpFerramentas'));
    }

    #[TestDox('quem não é o dono (outro primário do escritório) não vê o cartão')]
    public function testSoODono(): void
    {
        $tenant = $this->escritorio();
        $this->dono($tenant);
        $this->logarComTenant($this->client, $this->primario($tenant), $tenant);

        $pagina = $this->client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $pagina->filter('#mpFerramentas'));
    }

    #[TestDox('depois de ligar (?ferramenta_ligada=1): aviso de que os perfis próprios não ganham acesso, com o link para Perfis (regra 11)')]
    public function testAvisoDosPerfis(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $pagina = $this->client->request('GET', '/perfil?ferramenta_ligada=1');

        $aviso = $pagina->filter('#mpFerramentas > [data-mp-fe-ligou]');
        self::assertCount(1, $aviso);
        self::assertSame(
            static::getContainer()->get('router')->generate('app_tenant_role_index', ['tenantId' => $tenant->getId()]),
            $aviso->filter('a')->attr('href'),
        );
        self::assertCount(0, $this->client->request('GET', '/perfil')->filter('[data-mp-fe-ligou]'), 'sem o parâmetro, sem aviso');
    }

    /** A chave global `voto` do Master (linha em `recurso_estado`; sem linha, o recurso está ativo). */
    private function chaveDoVoto(bool $ligada): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null === $estado) {
            $estado = new RecursoEstado('voto');
            $this->em()->persist($estado);
        }
        if ($ligada) {
            $estado->reativar(null, new \DateTimeImmutable());
        } else {
            $estado->desativar(null, null, null, new \DateTimeImmutable());
        }
        $this->em()->flush();
    }

    private function mexer(Tenant $tenant, Produto $produto, OrigemDaContratacao $origem, bool $ligar): void
    {
        $servico = static::getContainer()->get(ContratacoesDoEscritorio::class);
        $real = $this->em()->find(Tenant::class, $tenant->getId());
        self::assertNotNull($real);
        $servico->sobTrava($real, fn () => $ligar
            ? $servico->incluir($real, $produto, $origem, null, new \DateTimeImmutable())
            : $servico->excluir($real, $produto, $origem, null, new \DateTimeImmutable()));
    }

    private function cartaoDo(Tenant $tenant): Crawler
    {
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);
        $pagina = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        $cartao = $pagina->filter('#mpFerramentas');
        self::assertCount(1, $cartao, 'o dono vê o cartão');

        return $cartao;
    }

    private function item(Crawler $cartao, string $produto): Crawler
    {
        return $cartao->filter('.mp-fe-lista > .mp-fe-item[data-produto="' . $produto . '"]');
    }

    private function estado(Crawler $cartao, string $produto): string
    {
        $selo = $this->item($cartao, $produto)->filter('.mp-bk-sit');
        foreach (['ligada', 'desligada', 'travada', 'indisponivel'] as $estado) {
            if (str_contains((string) $selo->attr('class'), 'mp-fe-sit-' . $estado)) {
                return $estado;
            }
        }
        self::fail('selo sem estado: ' . $produto);
    }

    private function botao(Crawler $cartao, string $produto): Crawler
    {
        return $this->item($cartao, $produto)->filter('[data-mp-fe-alternar]');
    }
}
