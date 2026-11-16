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
use App\Voto\Controller\RelatorioDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Repository\OpcaoDaPautaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Janela "Relatório" e resumos para divulgar (desenho 18, F3-2): guarda e posse como os vizinhos (403 sem liberação, 404
 * de outro escritório ou de rascunho), PNG e PDF gerados na hora só da pauta encerrada e apurada, sem dado pessoal, e a
 * janela do cartão com as três opções do desenho.
 */
#[CoversClass(RelatorioDaAssembleiaController::class)]
final class RelatorioDaAssembleiaControllerTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Telão ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    #[TestDox('Sem liberação do escritório: a imagem e o PDF dão 403, como os vizinhos')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();

        foreach (['resumo.png', 'resumo.pdf'] as $arquivo) {
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/relatorio/' . $arquivo);
            self::assertResponseStatusCodeSame(403, $arquivo);
        }
    }

    #[TestDox('Outro escritório e assembleia em rascunho: 404 na imagem e no PDF')]
    public function testOutroEscritorioERascunhoDao404(): void
    {
        $this->liberar();
        $this->entrar();
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->encerrada()->create();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $rascunho])->comOpcoesPadrao()->create();

        foreach ([$alheia, $rascunho] as $a) {
            foreach (['resumo.png', 'resumo.pdf'] as $arquivo) {
                $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/relatorio/' . $arquivo);
                self::assertResponseStatusCodeSame(404, $arquivo);
            }
        }
    }

    #[TestDox('Imagem: PNG gerado na hora, como download, sem gravar arquivo')]
    public function testImagem(): void
    {
        [$a] = $this->cenario();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/relatorio/resumo.png');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString('attachment; filename="resultado-assembleia-' . $a->getId() . '.png"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $conteudo = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\x89PNG", $conteudo);
        $tamanho = getimagesizefromstring($conteudo);
        self::assertSame(1000, $tamanho[0] ?? null);
    }

    #[TestDox('PDF: application/pdf como download')]
    public function testPdf(): void
    {
        [$a] = $this->cenario();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/relatorio/resumo.pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Sem pauta encerrada e apurada: volta ao painel com o aviso, sem gerar arquivo')]
    public function testSemPautaEncerradaVoltaAoPainelComAviso(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Pauta aguardando'])->comOpcoesPadrao()->create();

        foreach (['resumo.png', 'resumo.pdf'] as $arquivo) {
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/relatorio/' . $arquivo);
            self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $a->getId());
            $painel = $this->client->followRedirect();
            self::assertStringContainsString('Ainda não há votação apurada para divulgar.', $painel->filter('[data-voto-toasts]')->text());
        }
    }

    #[TestDox('Cartão: o botão Relatório consolidado abre a janela do desenho com três opções por filho direto e o aviso')]
    public function testJanelaNoCartao(): void
    {
        [$a] = $this->cenario();
        $id = $a->getId();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertResponseIsSuccessful();
        $cartao = $painel->filter('#assembleia-' . $id);
        self::assertSame('Relatório consolidado', trim($cartao->filter('.voto-acoes > button.vB.vP[data-voto-janela="#janela-relatorio-' . $id . '"]')->text()));
        $janela = $cartao->filter('.voto-acoes > .vModAbrir[hidden][data-voto-janela-raiz]#janela-relatorio-' . $id . ' > .vModC[role="dialog"]');
        self::assertCount(1, $janela);
        self::assertStringContainsString('Relatório: ', trim($janela->filter('.vH > span')->first()->text()));
        $corpo = $janela->filter('.vModC > .vModCorpo');
        $opcoes = $corpo->filter('.vModCorpo > a.vRelOp');
        self::assertSame(
            ['Completo, para registro em cartório', 'Resumo para divulgar nos grupos (imagem)', 'Resumo para divulgar (PDF)'],
            $opcoes->filter('span > b')->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertSame(['bi-bank', 'bi-whatsapp', 'bi-file-earmark-pdf'], $opcoes->filter('i')->each(static fn (Crawler $c): string => (string) preg_replace('/^bi /', '', (string) $c->attr('class'))));
        self::assertSame(
            ['/voto/assembleias/' . $id . '/ata', '/voto/assembleias/' . $id . '/relatorio/resumo.png', '/voto/assembleias/' . $id . '/relatorio/resumo.pdf'],
            $opcoes->each(static fn (Crawler $c): string => (string) $c->attr('href')),
        );
        self::assertSame(
            ['Relatório completo gerado.', 'Imagem do resultado gerada para divulgar.', 'Resumo em PDF gerado.'],
            $opcoes->each(static fn (Crawler $c): string => (string) $c->attr('data-voto-sucesso')),
        );
        self::assertSame('O completo contém dados pessoais: entregue só à comissão, à auditoria e ao cartório.', trim($corpo->filter('.vModCorpo > .vAviso')->text()));
    }

    #[TestDox('Rascunho: o cartão não mostra a janela do relatório')]
    public function testRascunhoSemJanela(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertCount(1, $painel->filter('#assembleia-' . $a->getId()));
        self::assertCount(0, $painel->filter('#janela-relatorio-' . $a->getId()));
    }

    /**
     * Assembleia publicada com 3 unidades aptas e 3 pautas: aguardando, aberta (2 de 3 votaram, prazo de 5 min) e
     * encerrada (3 votaram Sim).
     *
     * @return array{0: Assembleia, 1: int, 2: int, 3: int}
     */
    private function cenario(): array
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        $unidades = [];
        foreach (['T101', 'T102', 'T103'] as $identificacao) {
            $unidades[] = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => $identificacao, 'responsavel' => 'Responsável ' . $identificacao, 'email' => strtolower($identificacao) . '@telao.test'])->_real();
        }
        $p1 = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 1, 'titulo' => 'Pauta aguardando'])->comOpcoesPadrao()->create()->_real();
        $p2 = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 2, 'titulo' => 'Pauta aberta'])->comOpcoesPadrao()->aberta(1, new \DateTimeImmutable('+5 minutes'))->create()->_real();
        $p3 = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 3, 'titulo' => 'Pauta encerrada'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $this->em->refresh($a);
        $this->votar($p2, [$unidades[0], $unidades[1]]);
        $this->votar($p3, $unidades);

        return [$a, (int) $p1->getId(), (int) $p2->getId(), (int) $p3->getId()];
    }

    /** Voto 1 (Sim) para cada unidade, como a cédula grava: participação e contador da opção. */
    private function votar(PautaDaAssembleia $pauta, array $unidades): void
    {
        $opcoes = static::getContainer()->get(OpcaoDaPautaRepository::class);
        $sim = array_values(array_filter($pauta->getOpcoes()->toArray(), static fn (OpcaoDaPauta $o): bool => 1 === $o->getNumero()));
        foreach ($unidades as $unidade) {
            $this->em->persist(new ParticipacaoNaPauta($unidade, $pauta));
            $opcoes->somarVotos($sim, $unidade->pesoDoVoto());
        }
        $this->em->flush();
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
