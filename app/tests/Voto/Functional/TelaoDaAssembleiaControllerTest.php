<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
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
use App\Voto\Controller\TelaoDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Repository\OpcaoDaPautaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Telão (modo assembleia, desenho 18, F3-1): guarda e posse como os vizinhos (403 sem liberação, 404 de outro
 * escritório ou de rascunho), arranjo do desenho por filho direto, botões da mesa por estado, resultado só com a
 * pauta encerrada, JSON sem dado pessoal e a entrada pelo cartão do Painel.
 */
#[CoversClass(TelaoDaAssembleiaController::class)]
final class TelaoDaAssembleiaControllerTest extends JusPrimeWebTestCase
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

    #[TestDox('Sem liberação do escritório: o telão e o JSON dão 403, como os vizinhos')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao/dados');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Outro escritório e assembleia em rascunho: 404 no telão e no JSON')]
    public function testOutroEscritorioERascunhoDao404(): void
    {
        $this->liberar();
        $this->entrar();
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->create();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $rascunho])->comOpcoesPadrao()->create();

        foreach ([$alheia, $rascunho] as $a) {
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao');
            self::assertResponseStatusCodeSame(404);
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao/dados');
            self::assertResponseStatusCodeSame(404);
        }
    }

    #[TestDox('Arranjo do desenho: barra, grade com ordem do dia e QR, painel da pauta selecionada e botões por estado')]
    public function testArranjoEBotoesPorEstado(): void
    {
        [$a, $aguardando, $aberta, $encerrada] = $this->cenario();
        $id = $a->getId();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $id . '/telao');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('.vAo[role="dialog"] > .vAoTop > img'));
        self::assertCount(1, $tela->filter('.vAo > .vAoTop > .vAoHora'));
        self::assertSame(['Tela cheia', 'Sair'], $tela->filter('.vAo > .vAoTop > .vAoB')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(1, $tela->filter('.vAo > .vAoG > aside.vAoL'));
        self::assertSame('ORDEM DO DIA', trim($tela->filter('.vAoG > .vAoL > .vAoRotulo')->text()));
        $itens = $tela->filter('.vAoG > .vAoL > .vAoI');
        self::assertCount(3, $itens);
        self::assertSame(['1', '2', '3'], $itens->filter('.vAoN')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Publicada', 'Votação aberta', 'Encerrada'], $itens->filter('.vChip')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(1, $tela->filter('.vAoG > .vAoL > .vAoI.vAoOn'), 'uma só destacada');
        self::assertStringContainsString('Votação aberta', $tela->filter('.vAoG > .vAoL > .vAoOn')->text(), 'abre na pauta aberta');
        $qr = $tela->filter('.vAoL > .vAoQr');
        self::assertSame('Aponte a câmera para votar', trim($qr->filter('b')->text()));
        self::assertMatchesRegularExpression('/^Código de conferência [A-Z0-9]{4}-[A-Z0-9]{4}$/', trim($qr->filter('span')->text()));
        self::assertStringContainsString('/divulgacao/arquivo/png', (string) $qr->filter('img')->attr('src'));
        self::assertCount(3, $tela->filter('.vAoG > .vAoM'));
        self::assertCount(2, $tela->filter('.vAoG > .vAoM[hidden]'), 'só o painel da pauta em foco aparece');

        $painelAberta = $tela->filter('.vAoM[data-telao-pauta="' . $aberta . '"]');
        self::assertFalse($painelAberta->getNode(0)->hasAttribute('hidden'));
        self::assertSame(['2', '3', '66,7%'], [$painelAberta->filter('[data-telao-votantes]')->text(), $painelAberta->filter('[data-telao-aptos]')->text(), $painelAberta->filter('[data-telao-percentual]')->text()]);
        self::assertSame('de participação', trim($painelAberta->filter('.vAoNums > div')->eq(2)->filter('small')->text()));
        self::assertCount(1, $painelAberta->filter('.vAoBar > u'));
        self::assertCount(1, $painelAberta->filter('.vAoBar > em:not([hidden])'));
        self::assertSame('Quórum 50%: atingido', trim($painelAberta->filter('[data-telao-quorum]')->text()));
        self::assertStringStartsWith('Encerra em 0', trim($painelAberta->filter('[data-telao-cron]')->text()));
        self::assertCount(0, $painelAberta->filter('.vAoRes'), 'aberta: nunca resultado nem parcial');
        self::assertStringNotContainsString('Sim', $painelAberta->text());
        $mesa = $painelAberta->filter('.vAoMesa');
        self::assertSame(['Encerrar votação'], $mesa->filter('button.vAoD[data-telao-pedir-encerrar]')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(0, $mesa->filter('[data-voto-janela^="#janela-abrir"]'), 'aberta: sem Abrir');
        self::assertSame(['Suspender'], $mesa->filter('button.vAoSuspender[data-voto-janela="#janela-ciclo-' . $aberta . '-sus"]')->each(static fn (Crawler $c): string => trim($c->text())), 'aberta: Suspender abre a janela do ciclo');
        $form = $mesa->filter('form[data-telao-encerrar]');
        self::assertStringEndsWith('/voto/assembleias/' . $id . '/pautas/' . $aberta . '/encerrar', (string) $form->attr('action'));
        self::assertSame('telao', $form->filter('input[name="voltar"]')->attr('value'));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        self::assertSame('Encerrar agora? Depois de encerrada não aceita mais votos.', trim($form->filter('[data-telao-pergunta] > b')->text()));
        self::assertSame(['Continuar votação', 'Confirmar encerramento'], $form->filter('[data-telao-pergunta] > button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('Próxima: Pauta encerrada', trim($mesa->filter('a.vAoProx')->text()));

        $painelAguardando = $tela->filter('.vAoM[data-telao-pauta="' . $aguardando . '"]');
        self::assertSame('Aguardando abertura pela mesa', trim($painelAguardando->filter('.vAoLinha > span')->text()));
        self::assertSame('Abrir votação', trim($painelAguardando->filter('.vAoMesa > button.vAoP[data-voto-janela]')->text()));
        $janela = $painelAguardando->filter('.vAoMesa .vModAbrir[hidden] form');
        self::assertCount(1, $janela);
        self::assertSame('telao', $janela->filter('input[name="voltar"]')->attr('value'));
        self::assertCount(0, $painelAguardando->filter('[data-telao-pedir-encerrar]'));

        $painelEncerrada = $tela->filter('.vAoM[data-telao-pauta="' . $encerrada . '"]');
        self::assertCount(4, $painelEncerrada->filter('.vAoRes .vAoIt'), 'Sim, Não, Abstenções e Nulos');
    }

    #[TestDox('Pauta encerrada e apurada: barras de resultado, sem botão de abrir nem de encerrar, com Ir para a apuração')]
    public function testResultadoSoDepoisDeEncerrada(): void
    {
        [$a, , , $encerrada] = $this->cenario();
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao?pauta=' . $encerrada);
        $painel = $tela->filter('.vAoM[data-telao-pauta="' . $encerrada . '"]');
        self::assertFalse($painel->getNode(0)->hasAttribute('hidden'), 'o parâmetro escolhe a pauta em foco');
        $res = $painel->filter('.vAoRes');
        self::assertCount(1, $res);
        self::assertCount(1, $res->filter('.vAoResTopo > .vAoDecisao'));
        self::assertSame('Aprovada', trim($res->filter('.vAoDecisao')->text()), 'frase do resTx do desenho, pelo partial _texto_do_resultado');
        self::assertSame(['1 Sim', '2 Não', 'Abstenções', 'Nulos'], $res->filter('.vAoIt > span')->each(static fn (Crawler $c): string => (string) preg_replace('/[\s\x{a0}]+/u', ' ', trim($c->text()))));
        self::assertSame(['3', '0', '0', '0'], $res->filter('.vAoIt > b')->each(static fn (Crawler $c): string => $c->text()));
        self::assertStringContainsString('width:100%', (string) $res->filter('.vAoIt > div > u')->first()->attr('style'));
        $mesa = $painel->filter('.vAoMesa');
        self::assertCount(0, $mesa->filter('[data-voto-janela], [data-telao-pedir-encerrar]'));
        self::assertStringContainsString('aba=tra', (string) $mesa->filter('a.vAoP')->attr('href'));
        self::assertSame('Ir para a apuração', trim($mesa->filter('a.vAoP')->text()));
        self::assertCount(0, $painel->filter('.vAoLinha [data-telao-cron]'));
    }

    #[TestDox('Peso por fração: o terceiro número diz "das frações"')]
    public function testPesoPorFracao(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->porFracao()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $a])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'fracao' => '0.500000']);

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao');
        self::assertResponseIsSuccessful();
        self::assertSame('das frações', trim($tela->filter('.vAoNums > div')->eq(2)->filter('small')->text()));
    }

    #[TestDox('JSON: só contagens, peso e quórum, sem nome, e-mail nem unidade; igual ao que a tela mostrou')]
    public function testJsonSemDadoPessoal(): void
    {
        [$a, $aguardando, $aberta, $encerrada] = $this->cenario();
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/telao/dados');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $bruto = (string) $this->client->getResponse()->getContent();
        $json = json_decode($bruto, true, 512, \JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('/^\d\d:\d\d:\d\d$/', $json['hora']);
        self::assertSame([$aguardando, $aberta, $encerrada], array_column($json['pautas'], 'id'));
        $porId = array_column($json['pautas'], null, 'id');
        self::assertSame(['id', 'situacao', 'ciclo', 'votantes', 'aptos', 'percentual', 'largura', 'quorumMarca', 'quorumTexto', 'segundos'], array_keys($porId[$aberta]));
        self::assertSame('aberta', $porId[$aberta]['situacao']);
        self::assertSame(2, $porId[$aberta]['votantes']);
        self::assertSame(3, $porId[$aberta]['aptos']);
        self::assertSame('66,7%', $porId[$aberta]['percentual']);
        self::assertSame('50', $porId[$aberta]['quorumMarca']);
        self::assertSame('Quórum 50%: atingido', $porId[$aberta]['quorumTexto']);
        self::assertGreaterThan(280, $porId[$aberta]['segundos']);
        self::assertSame('publicada', $porId[$aguardando]['situacao']);
        self::assertNull($porId[$aguardando]['quorumMarca']);
        self::assertSame('', $porId[$aguardando]['quorumTexto']);
        self::assertNull($porId[$aguardando]['segundos']);

        foreach ($this->em->getRepository(UnidadeDaAssembleia::class)->findBy(['assembleia' => $a]) as $unidade) {
            self::assertStringNotContainsString((string) $unidade->getEmail(), $bruto);
            self::assertStringNotContainsString((string) $unidade->getResponsavel(), $bruto);
            self::assertStringNotContainsString($unidade->getIdentificacao(), $bruto);
        }
        self::assertStringNotContainsString('Sim', $bruto, 'nunca parcial de votos nem opção');
    }

    #[TestDox('Mesa pelo telão: abrir e encerrar voltam ao telão na mesma pauta')]
    public function testMesaVoltaAoTelao(): void
    {
        [$a, $aguardando, $aberta] = $this->cenario();
        $id = $a->getId();
        // Aba Votar: não lê as assembleias, então a leitura não encerra a pauta antes da ação (mesmo truque do gestor).
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/voto_assembleia_' . $id, 'token-de-teste');
        $sessao->save();
        $token = 'token-de-teste';

        $this->client->request('POST', '/voto/assembleias/' . $id . '/pautas/' . $aberta . '/encerrar', ['_token' => $token, 'voltar' => 'telao', 'motivo' => 'Determinação da mesa']);
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/telao?pauta=' . $aberta);
        $this->client->request('POST', '/voto/assembleias/' . $id . '/pautas/' . $aguardando . '/abrir', ['_token' => $token, 'voltar' => 'telao', 'convocacao' => '1', 'duracao' => 'sem']);
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/telao?pauta=' . $aguardando);
        $tela = $this->client->followRedirect();
        self::assertSame('Votação aberta pela mesa.', trim($tela->filter('.voto-toast')->last()->text()));
        self::assertSame('Aberta, sem prazo definido', trim($tela->filter('.vAoM[data-telao-pauta="' . $aguardando . '"] [data-telao-cron]')->text()));
    }

    #[TestDox('Painel: o item "Conduzir no telão (modo assembleia)" entra no menu antes de Duplicar e o botão visível só com a assembleia publicada')]
    public function testEntradaNoCartaoDoPainel(): void
    {
        [$a] = $this->cenario();
        $id = $a->getId();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertResponseIsSuccessful();
        $cartao = $painel->filter('#assembleia-' . $id);
        $itens = $cartao->filter('.vH .vKm > a, .vH .vKm button')->each(static fn (Crawler $c): string => trim($c->text()));
        $posicao = array_search('Conduzir no telão (modo assembleia)', $itens, true);
        self::assertNotFalse($posicao);
        self::assertLessThan(array_search('Duplicar votação', $itens, true), $posicao);
        self::assertGreaterThan(array_search('Link e QR Code', $itens, true), $posicao);
        $item = $cartao->filter('.vKm > a')->reduce(static fn (Crawler $c): bool => str_contains($c->text(), 'Conduzir'));
        self::assertCount(1, $item->filter('i.bi-display'));
        self::assertStringEndsWith('/voto/assembleias/' . $id . '/telao', (string) $item->attr('href'));
        $botao = $cartao->filter('.voto-acoes > a.vB')->reduce(static fn (Crawler $c): bool => 'Conduzir no telão' === trim($c->text()));
        self::assertCount(1, $botao);
        self::assertCount(1, $botao->filter('i.bi-display'));
        self::assertStringEndsWith('/voto/assembleias/' . $id . '/telao', (string) $botao->attr('href'));

        $semTelao = $painel->filter('#assembleia-' . $rascunho->getId());
        self::assertStringNotContainsString('Conduzir', $semTelao->text(), 'rascunho não tem telão');
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
