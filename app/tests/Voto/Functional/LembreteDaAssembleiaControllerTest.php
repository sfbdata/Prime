<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\LembreteDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\ParticipacaoNaPauta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Lembrar quem não votou" (desenho 18, F3-4): guarda e posse como os vizinhos (403 sem liberação, 404 de outro
 * escritório ou rascunho), janela do desenho por filho direto, vazio, CSV e a entrada no menu do cartão do Painel.
 */
#[CoversClass(LembreteDaAssembleiaController::class)]
final class LembreteDaAssembleiaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Lembrete ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    #[TestDox('Sem liberação do escritório: a janela e o CSV dão 403')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete/lista.csv');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Outro escritório e rascunho: 404 na janela e no CSV')]
    public function testOutroEscritorioERascunhoDao404(): void
    {
        $this->liberar();
        $this->entrar();
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();

        foreach ([$alheia, $rascunho] as $a) {
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete');
            self::assertResponseStatusCodeSame(404);
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete/lista.csv');
            self::assertResponseStatusCodeSame(404);
        }
    }

    #[TestDox('Janela do desenho: título, resumo, botões do topo, uma linha por apto sem voto e o rodapé')]
    public function testConteudoDaJanela(): void
    {
        [$a] = $this->cenario();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('div.vModAbrir[data-voto-janela-raiz]:not([hidden])'));
        $c = $tela->filter('div.vModAbrir[data-voto-janela-raiz] > .vModC[role="dialog"]');
        self::assertCount(1, $c);
        self::assertStringContainsString('width:min(620px,100%)', (string) $c->attr('style'));
        self::assertSame('Lembrar quem não votou', trim($c->children('.vH')->children('span')->first()->text()));
        self::assertCount(1, $c->children('.vH')->children('i.bi-whatsapp'));
        self::assertSame('Fechar', trim($c->children('.vH')->children('button[data-voto-janela-fechar]')->text()));
        $topo = $c->children('.vLin.voto-lembrar-topo');
        self::assertStringContainsString('2 apto(s) ainda não votaram; 1 com telefone na lista.', $topo->children('.voto-lembrar-resumo')->text());
        self::assertSame(['Copiar mensagem'], $topo->children('button.vB')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertStringContainsString('Lembrete: a votação está aberta. Votação: AGO do Lembrete. Acesse pelo link', (string) $topo->children('button.vB')->attr('data-voto-copiar'));
        $baixar = $topo->children('a.vB');
        self::assertSame('Baixar lista', trim($baixar->text()));
        self::assertStringEndsWith('/voto/assembleias/' . $a->getId() . '/lembrete/lista.csv', (string) $baixar->attr('href'));

        $linhas = $c->children('.voto-lembrar-lista')->children('.vLin');
        self::assertCount(2, $linhas, 'só os aptos que não votaram (a unidade que votou e a inapta ficam fora)');
        $primeira = $linhas->eq(0);
        self::assertSame('Ana Souza', trim($primeira->children('.voto-lembrar-quem')->children('b')->text()));
        self::assertSame('L101 · A', trim($primeira->children('.voto-lembrar-quem')->children('div')->text()));
        $enviar = $primeira->children('a.vB[target="_blank"]');
        self::assertSame('Enviar lembrete', trim($enviar->text()));
        $href = (string) $enviar->attr('href');
        self::assertStringStartsWith('https://wa.me/5561999990001?text=', $href);
        $texto = rawurldecode(substr($href, \strlen('https://wa.me/5561999990001?text=')));
        self::assertStringStartsWith('Olá, Ana. Lembrete: a votação está aberta e você ainda não votou. Votação: AGO do Lembrete. Acesse pelo link ', $texto);
        self::assertStringContainsString('/voto/v/', $texto);
        self::assertStringContainsString('Não precisa ter conta no BlueJus. Código de conferência: ', $texto);
        $segunda = $linhas->eq(1);
        self::assertSame('Sem nome na lista', trim($segunda->children('.voto-lembrar-quem')->children('b')->text()));
        self::assertSame('Sem telefone na lista', trim($segunda->children('.voto-lembrar-sem-tel')->text()));
        self::assertCount(0, $segunda->filter('a.vB'));
        self::assertSame('Cada botão abre o WhatsApp com a mensagem pronta; o envio é feito por você. A lista não mostra quem votou em quem.', trim($c->children('.vLin.voto-lembrar-rodape')->text()));
        self::assertStringNotContainsString('Votou', $tela->text(), 'quem votou não aparece');
        self::assertStringNotContainsString('Inapta', $tela->text());
    }

    #[TestDox('Todos votaram: "Todos os aptos já votaram." e nenhuma linha')]
    public function testVazio(): void
    {
        [$a, $unidades, $pauta] = $this->cenario();
        foreach ($unidades as $u) {
            if ($u->isApta() && 'Votou' !== $u->getResponsavel()) {
                $this->em->persist(new ParticipacaoNaPauta($u, $pauta));
            }
        }
        $this->em->flush();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete');
        self::assertResponseIsSuccessful();
        self::assertSame('Todos os aptos já votaram.', trim($tela->filter('.vModC > .voto-lembrar-topo > .voto-lembrar-resumo')->getNode(0)->lastChild->textContent));
        self::assertCount(0, $tela->filter('.voto-lembrar-lista > .vLin'));
    }

    #[TestDox('CSV: cabeçalho e uma linha por apto que não votou, com anexo e sem cache')]
    public function testCsv(): void
    {
        [$a] = $this->cenario();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/lembrete/lista.csv');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(
            "nome;identificacao;grupo;telefone\n\"Ana Souza\";\"L101\";\"A\";\"61999990001\"\n\"\";\"L102\";\"A\";\"\"\n",
            $this->client->getResponse()->getContent(),
        );
    }

    #[TestDox('Painel: o item "Lembrar quem não votou" (bi-whatsapp) vem depois do telão e antes de Duplicar; rascunho e sem aptos não têm')]
    public function testEntradaNoMenuDoCartao(): void
    {
        [$a] = $this->cenario();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        $semAptos = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $semAptos])->comOpcoesPadrao()->create();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertResponseIsSuccessful();
        $cartao = $painel->filter('#assembleia-' . $a->getId());
        $itens = $cartao->filter('.vH .vKm > a, .vH .vKm button')->each(static fn (Crawler $c): string => trim($c->text()));
        $posicao = array_search('Lembrar quem não votou', $itens, true);
        self::assertNotFalse($posicao);
        self::assertSame(array_search('Conduzir no telão (modo assembleia)', $itens, true) + 1, $posicao);
        self::assertSame(array_search('Duplicar votação', $itens, true) - 1, $posicao);
        $item = $cartao->filter('.vKm > a')->reduce(static fn (Crawler $c): bool => 'Lembrar quem não votou' === trim($c->text()));
        self::assertCount(1, $item->filter('i.bi-whatsapp'));
        self::assertStringEndsWith('/voto/assembleias/' . $a->getId() . '/lembrete', (string) $item->attr('href'));

        self::assertStringNotContainsString('Lembrar quem não votou', $painel->filter('#assembleia-' . $rascunho->getId())->text());
        self::assertStringNotContainsString('Lembrar quem não votou', $painel->filter('#assembleia-' . $semAptos->getId())->text());
    }

    /**
     * Publicada com pauta aberta e 4 unidades: L101 apta com telefone (Ana Souza), L102 apta sem nome e sem telefone,
     * L103 apta que votou, L104 inapta.
     *
     * @return array{0: Assembleia, 1: list<\App\Voto\Entity\UnidadeDaAssembleia>, 2: \App\Voto\Entity\PautaDaAssembleia}
     */
    private function cenario(): array
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO do Lembrete'])->publicada()->create()->_real();
        $u = [
            UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => 'L101', 'responsavel' => 'Ana Souza', 'telefone' => '61999990001'])->_real(),
            UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => 'L102', 'responsavel' => null])->_real(),
            UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => 'L103', 'responsavel' => 'Votou', 'telefone' => '61999990003'])->_real(),
            UnidadeDaAssembleiaFactory::new(['assembleia' => $a, 'identificacao' => 'L104', 'responsavel' => 'Inapta', 'telefone' => '61999990004'])->inapta()->create()->_real(),
        ];
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 1, 'titulo' => 'Contas'])->comOpcoesPadrao()->aberta(1, new \DateTimeImmutable('+5 minutes'))->create()->_real();
        $this->em->refresh($a);
        $this->em->persist(new ParticipacaoNaPauta($u[2], $pauta));
        $this->em->flush();

        return [$a, $u, $pauta];
    }

    private function liberar(): void
    {
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function entrar(): void
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
