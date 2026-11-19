<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\LinkDoAviso;
use App\Pasta\Twig\AcessoRestritoExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 3 da spec acesso-restrito-e-compartilhar (pendência da 2b, "aviso de meta sem link no sino"): as telas de
 * metas (`tarefa_*`) são "Fora do seu acesso" para o restrito, então o aviso de meta leva à aba Metas da pasta, já na
 * meta, e o aviso de meta sem meta própria fica sem link. Quem não é restrito continua indo à tela da meta.
 */
#[CoversClass(LinkDoAviso::class)]
#[CoversClass(AcessoRestritoExtension::class)]
final class LinkDoAvisoNoAcessoRestritoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $tenant;
    private User $primario;
    private User $restrito;
    private User $comum;
    private Pasta $pasta;
    private Tarefa $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->restrito = $this->criarColaborador($this->tenant, 'Rita Restrita', self::PERMISSOES);
        $this->comum    = $this->criarColaborador($this->tenant, 'Carlos Comum', self::PERMISSOES);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->restrito, 'tenant' => $this->tenant]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Prospectador');

        $this->pasta = $this->criarPasta($this->tenant, 'CLIENTE DA RITA');
        $this->pasta->setResponsavel($this->restrito);
        $this->meta = new Tarefa();
        $this->meta->setTitulo('Protocolar a inicial');
        $this->meta->setDescricao('Descrição');
        $this->meta->setPasta($this->pasta);
        $this->meta->setTenant($this->tenant);
        $this->meta->setCriadoPor($this->primario);
        $this->em()->persist($this->meta);
        $this->em()->flush();
    }

    #[TestDox('restrito: aviso de meta leva à aba Metas da pasta; sem meta própria, fica sem link; o resto não muda')]
    public function testRestrito(): void
    {
        foreach ([$this->restrito, $this->comum] as $pessoa) {
            $this->aviso($pessoa, 'Meta sem link', null, $this->meta);
            $this->aviso($pessoa, 'Meta com link', '/tarefas/' . $this->meta->getId(), $this->meta);
            $this->aviso($pessoa, 'Metas redistribuídas', '/tarefas/minhas', null);
            $this->aviso($pessoa, 'Mensagem na pasta', '/pasta/' . $this->pasta->getId() . '#pasta-msg-1', null);
        }
        $naPasta = '/pasta/' . $this->pasta->getId() . '?meta=' . $this->meta->getId() . '#tarefas';

        $this->entrar($this->restrito, $this->tenant);
        self::assertEquals([
            'Meta sem link'        => $naPasta,
            'Meta com link'        => $naPasta,
            'Metas redistribuídas' => LinkDoAviso::SEM_LINK,
            'Mensagem na pasta'    => '/pasta/' . $this->pasta->getId() . '#pasta-msg-1',
        ], $this->linksDoSino());
        self::assertEquals($this->linksDoSino(), $this->linksDoVerTodas());

        $this->entrar($this->comum, $this->tenant);
        self::assertEquals([
            'Meta sem link'        => '/tarefas/' . $this->meta->getId(),
            'Meta com link'        => '/tarefas/' . $this->meta->getId(),
            'Metas redistribuídas' => '/tarefas/minhas',
            'Mensagem na pasta'    => '/pasta/' . $this->pasta->getId() . '#pasta-msg-1',
        ], $this->linksDoSino());
        self::assertEquals($this->linksDoSino(), $this->linksDoVerTodas());
    }

    /** @return array<string, string> título => link, no dropdown do sino */
    private function linksDoSino(): array
    {
        $this->client->request('GET', '/notificacoes/lista-dropdown');
        self::assertResponseIsSuccessful();
        $html = (string) (json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['html'] ?? '');
        $crawler = new Crawler('<div>' . $html . '</div>');

        return $this->porTitulo($crawler->filter('a[href]'));
    }

    /** @return array<string, string> título => link, na página "Ver todas" */
    private function linksDoVerTodas(): array
    {
        $crawler = $this->client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();

        return $this->porTitulo($crawler->filter('a.notif-main'));
    }

    /** @return array<string, string> */
    private function porTitulo(Crawler $links): array
    {
        $mapa = [];
        foreach ($links as $a) {
            $titulo = trim((string) ($a instanceof \DOMElement ? $a->getElementsByTagName('h6')->item(0)?->textContent ?? $a->textContent : ''));
            foreach (['Meta sem link', 'Meta com link', 'Metas redistribuídas', 'Mensagem na pasta'] as $esperado) {
                if (str_contains($titulo, $esperado) && $a instanceof \DOMElement) {
                    $mapa[$esperado] = $a->getAttribute('href');
                }
            }
        }

        return $mapa;
    }

    private function aviso(User $para, string $titulo, ?string $url, ?Tarefa $meta): void
    {
        $n = new Notificacao();
        $n->setUsuario($para);
        $n->setTenant($this->tenant);
        $n->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $n->setTitulo($titulo);
        $n->setMensagem('Texto');
        $n->setUrl($url);
        $n->setTarefa($meta);
        $this->em()->persist($n);
        $this->em()->flush();
    }
}
