<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Página da meta, itens do desenho 04B que faltavam na Fase 2a (spec `minhas-metas-esteira-2210.md`, 3.2, L2 a L5 e
 * L8): faixa "Aguardando", painel de situação, DETALHES DA META, seta e trilha, e o envio para revisão sem texto.
 * O arranjo é conferido por combinador de filho direto.
 */
#[CoversClass(TarefaController::class)]
#[Group('tarefa')]
final class PaginaDaMetaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = (new Tenant())->setCidade('Maceió')->setEstado('AL');
        $this->escritorio->setName('Escritório Página da Meta ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->criador = $this->criarPessoa('FARLEI ASSIS DA ROCHA');
        $this->responsavel = $this->criarPessoa('Rui Prado');
        $this->meta = $this->criarMeta();
    }

    #[TestDox('L5: seta de voltar às metas e trilha "Expediente / Pasta X / Meta" no cabeçalho')]
    public function testCabecalhoComSetaETrilha(): void
    {
        $c = $this->abrir($this->criador);

        self::assertCount(1, $c->filter('.content-header > .container-fluid > .meta-cabeca > a.meta-voltar[href="/tarefas/minhas"][title="Voltar às metas"] > i.bi-arrow-left'));
        self::assertCount(1, $c->filter('.meta-cabeca > a.meta-voltar + h1.meta-titulo'));
        $trilha = $c->filter('.meta-cabeca > nav.meta-trilha');
        self::assertSame('Expediente / Pasta ' . $this->meta->getPasta()->getNup() . ' / Meta', self::texto($trilha));
        self::assertCount(1, $trilha->filter('nav.meta-trilha > a[href="/expediente"]'));
        self::assertCount(1, $trilha->filter('nav.meta-trilha > a[href="/pasta/' . $this->meta->getPasta()->getId() . '"]'));
    }

    #[TestDox('L2: faixa AGUARDANDO DESPACHO DE com iniciais, nome curto e "hoje / com a demanda"; vermelha se atrasada')]
    public function testFaixaAguardandoDespacho(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        $faixa = $c->filter('.col-lg-8 > .meta-aguardando.meta-aguardando--atrasada');
        self::assertCount(1, $faixa);
        self::assertSame('AGUARDANDO DESPACHO DE', self::texto($faixa->filter('.meta-aguardando > .meta-aguardando-quem > .meta-aguardando-rotulo')));
        self::assertSame('RP', self::texto($faixa->filter('.meta-aguardando-pessoas > .meta-aguardando-pessoa > .meta-aguardando-iniciais')));
        self::assertSame('RPRui Prado', self::texto($faixa->filter('.meta-aguardando-pessoas > .meta-aguardando-pessoa')));
        self::assertSame('hoje', self::texto($faixa->filter('.meta-aguardando > .meta-aguardando-tempo > b.meta-aguardando-dias')));
        self::assertSame('com a demanda', self::texto($faixa->filter('.meta-aguardando-tempo > .meta-aguardando-desde')));
        self::assertCount(0, $faixa->filter('button'), 'o Lembrar é da Fase 2b');
    }

    #[TestDox('L2 e L8: enviar para revisão sem texto grava a mensagem do desenho e a faixa passa a aguardar o criador')]
    public function testEnviarSemTextoEFaixaDeRevisao(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => 'enviar', 'mensagem' => '']);
        self::assertResponseRedirects();

        $this->em->clear();
        $mensagens = $this->em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $id]);
        self::assertCount(1, $mensagens);
        self::assertSame('<p>Meta enviada para revisão do autor.</p>', $mensagens[0]->getMensagem());
        self::assertSame(AcaoDoDespacho::Envio, $mensagens[0]->getAcao());
        self::assertNotNull($mensagens[0]->getCodigo());

        $c = $this->client->request('GET', '/tarefas/' . $id);
        $faixa = $c->filter('.col-lg-8 > .meta-aguardando.meta-aguardando--revisao');
        self::assertSame('AGUARDANDO REVISÃO DE', self::texto($faixa->filter('.meta-aguardando-quem > .meta-aguardando-rotulo')));
        self::assertSame('FAFarlei Rocha', self::texto($faixa->filter('.meta-aguardando-pessoas > .meta-aguardando-pessoa')));
        self::assertSame('desde o envio para revisão', self::texto($faixa->filter('.meta-aguardando-tempo > .meta-aguardando-desde')));
        self::assertStringContainsString('Meta enviada para revisão do autor.', self::texto($c->filter('#despacho-' . $mensagens[0]->getId())));
        self::assertSame('Aguardando revisão', self::texto($c->filter('.col-lg-4 > .mb-3 > .meta-situacao.meta-situacao--revisao > .meta-situacao-texto > b.meta-situacao-titulo')));
    }

    #[TestDox('L3: painel de situação "Atrasada · N dia(s)" vermelho e "Etapa i/n · Nome"')]
    public function testPainelDeSituacao(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        $painel = $c->filter('.col-lg-4 > .mb-3 > .meta-situacao.meta-situacao--atrasada');
        self::assertCount(1, $painel);
        self::assertCount(1, $painel->filter('.meta-situacao > i.bi-exclamation-octagon.meta-situacao-icone + .meta-situacao-texto'));
        self::assertSame('Atrasada · 2 dia(s)', self::texto($painel->filter('.meta-situacao-texto > b.meta-situacao-titulo')));
        self::assertSame('Etapa 5/8 · Jurídico', self::texto($painel->filter('.meta-situacao-texto > b.meta-situacao-titulo + .meta-situacao-etapa')));
    }

    #[TestDox('L4: DETALHES DA META na lateral, no lugar das 4 caixas: prazo atrasado com Alterar, responsáveis, criada em e despachante')]
    public function testDetalhesDaMeta(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('-4 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        self::assertCount(0, $c->filter('.tarefa-meta-box'), 'as 4 caixas do topo saíram');
        $d = $c->filter('.col-lg-4 > .mb-3 > .meta-detalhes');
        self::assertCount(1, $d);
        self::assertSame('DETALHES DA META', self::texto($d->filter('.meta-detalhes > .meta-detalhes-cabeca')));
        $prazo = $d->filter('.meta-detalhes > .meta-detalhes-cabeca + .meta-detalhes-item.meta-detalhes-prazo.meta-detalhes-prazo--atrasada[data-bs-target="#modalEditarPrazo"]');
        self::assertCount(1, $prazo);
        self::assertSame('PRAZOAlterar', self::texto($prazo->filter('.meta-detalhes-prazo > .meta-detalhes-rotulo')));
        self::assertSame('Atrasada venceu em ' . (new \DateTimeImmutable('-4 days'))->format('d/m/Y'), self::texto($prazo->filter('.meta-detalhes-atraso > .meta-detalhes-atraso-texto')));
        self::assertSame('4 dias', self::texto($prazo->filter('.meta-detalhes-atraso > .meta-detalhes-atraso-dias')));
        $itens = $d->filter('.meta-detalhes > .meta-detalhes-item');
        self::assertCount(4, $itens);
        self::assertSame('RESPONSÁVEISAlterar Rui Prado', self::texto($itens->eq(1)));
        self::assertSame('CRIADA EM ' . (new \DateTimeImmutable())->format('d/m/Y') . '· hoje', self::texto($itens->eq(2)));
        self::assertSame('DESPACHANTE FARLEI ASSIS DA ROCHA', self::texto($itens->eq(3)));
    }

    #[TestDox('L4: no prazo, o quadro mostra só a data, sem vermelho')]
    public function testDetalhesNoPrazo(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('+5 days'));
        $this->em->flush();

        $c = $this->abrir($this->criador);

        $prazo = $c->filter('.meta-detalhes > .meta-detalhes-item.meta-detalhes-prazo');
        self::assertStringNotContainsString('meta-detalhes-prazo--atrasada', (string) $prazo->attr('class'));
        self::assertSame((new \DateTimeImmutable('+5 days'))->format('d/m/Y'), self::texto($prazo->filter('.meta-detalhes-prazo > .meta-detalhes-prazo-data')));
        self::assertSame('Pendente', self::texto($c->filter('.meta-situacao > .meta-situacao-texto > b.meta-situacao-titulo')));
    }

    private function abrir(User $quem): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertResponseIsSuccessful();

        return $c;
    }

    private static function texto(Crawler $no): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $no->text()));
    }

    private function criarPessoa(string $nome): User
    {
        $user = new User();
        $user->setEmail('pagina_meta_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($this->escritorio);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $this->em->persist($role);

        $vinculo = new UserTenant($user, $this->escritorio);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function criarMeta(): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-PAG-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->setCriadoPor($this->criador);
        $this->em->persist($pasta);

        $meta = new Tarefa();
        $meta->setTitulo('Meta da página');
        $meta->setDescricao('Descrição');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setEtapa(EtapaDaPasta::Juridico);
        $meta->setTipoDeDemanda(TipoDeDemanda::Judicial);
        $meta->setPasta($pasta);
        $meta->setTenant($this->escritorio);
        $meta->setCriadoPor($this->criador);
        $meta->addResponsavel($this->responsavel);
        $this->em->persist($meta);
        $this->em->flush();

        return $meta;
    }

    private function instalarCsrfFalso(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }
}
