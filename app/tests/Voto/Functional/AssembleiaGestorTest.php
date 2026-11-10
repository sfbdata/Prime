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
use App\Tests\Factory\Voto\OpcaoDaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\Controller\MesaDaAssembleiaController;
use App\Voto\Controller\PautasDaAssembleiaController;
use App\Voto\Controller\UnidadesDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\Service\LeituraDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Gestor da assembleia de condomínio pela tela (spec docs/specs/voto-assembleia-condominio.md, seção 1, Gestor):
 * as travas de acesso iguais às de /voto, a posse por escritório (404), o fluxo criar, pautas, unidades, publicar,
 * abrir, encerrar, resultado e ata, e a regra de sigilo: com a pauta aberta, nenhuma tela mostra parcial de votos.
 *
 * Os votos são simulados como a cédula grava (contrato, seção 2): a participação da unidade e a soma no contador da
 * opção por OpcaoDaPautaRepository::somarVoto.
 */
#[CoversClass(AssembleiaController::class)]
#[CoversClass(PautasDaAssembleiaController::class)]
#[CoversClass(MesaDaAssembleiaController::class)]
#[CoversClass(UnidadesDaAssembleiaController::class)]
#[CoversClass(GuardaDaEquipeDoVoto::class)]
#[CoversClass(LeituraDaAssembleia::class)]
#[CoversClass(AvisosDoVoto::class)]
final class AssembleiaGestorTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Voto ' . uniqid()])->_real();
        $this->ligar();
    }

    #[TestDox('Escritório não liberado: as telas e as ações da assembleia recebem 403, como /voto')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();

        $this->client->request('GET', '/voto/assembleias/nova');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/voto/assembleias/nova', ['titulo' => 'AGO', 'peso' => 'fracao']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/pautas');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->contarAssembleias(), 'nada foi criado');
    }

    #[TestDox('Assembleia de outro escritório: telas e ações dão 404, como um id que não existe')]
    public function testAssembleiaDeOutroEscritorioDa404(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->create()->_real();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $alheia])->_real();
        $a = $alheia->getId();
        $p = $pauta->getId();

        foreach (['/editar', '/pautas', '/unidades', '/ata', '/pautas/' . $p . '/assistir'] as $tela) {
            $this->client->request('GET', '/voto/assembleias/' . $a . $tela);
            self::assertResponseStatusCodeSame(404, $tela);
        }
        $token = $this->tokenValido('voto_assembleia_' . $a);
        $u = $unidade->getId();
        $acoes = [
            '/publicar', '/pautas/' . $p . '/abrir', '/pautas/' . $p . '/encerrar', '/pautas/' . $p . '/prazo',
            '/pautas', '/pautas/' . $p . '/editar', '/unidades/' . $u . '/novo-link', '/unidades/importar',
            '/unidades/enviar-links', '/unidades/' . $u . '/reenviar', '/pautas/' . $p . '/remover', '/unidades/' . $u . '/remover',
        ];
        foreach ($acoes as $acao) {
            $this->client->request('POST', '/voto/assembleias/' . $a . $acao, [
                '_token' => $token, 'lista' => '999;Z', 'minutos' => '5', 'titulo' => 'Pauta invasora',
                'opcao_numero' => ['1', '2'], 'opcao_rotulo' => ['Sim', 'Não'],
            ]);
            self::assertResponseStatusCodeSame(404, $acao);
            self::assertEmailCount(0, null, $acao);
        }
        // Pelo banco: o filtro de tenant da sessão esconderia as linhas do outro escritório do EntityManager.
        $banco = $this->em->getConnection();
        $pautaNoBanco = $banco->fetchAssociative('SELECT estado, titulo, prazo_ate FROM voto.assembleia_pauta WHERE id = ?', [$p]);
        self::assertSame('aguardando', $pautaNoBanco['estado'] ?? null, 'a pauta alheia não foi aberta nem removida');
        self::assertSame($pauta->getTitulo(), $pautaNoBanco['titulo'], 'a pauta alheia não foi editada');
        self::assertNull($pautaNoBanco['prazo_ate'], 'nem ganhou prazo');
        self::assertSame(1, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_pauta WHERE assembleia_id = ?', [$a]), 'nenhuma pauta criada');
        self::assertSame($unidade->getSemente(), $banco->fetchOne('SELECT semente FROM voto.assembleia_unidade WHERE id = ?', [$u]), 'o link alheio não mudou');
        self::assertNull($banco->fetchOne('SELECT link_enviado_em FROM voto.assembleia_unidade WHERE id = ?', [$u]), 'nenhum link enviado');
        self::assertSame(1, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$a]), 'nada importado nem removido');
    }

    #[TestDox('Fluxo do gestor: criar, pautas, unidades, publicar, abrir, votar, encerrar, resultado por fração e ata')]
    public function testFluxoDoGestor(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());

        // Painel vazio e criação
        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertResponseIsSuccessful();
        self::assertSame('Nenhuma assembleia criada.', trim($painel->filter('.voto-tela > .voto-painel[data-aba="ges"] .voto-vazio')->text()));
        self::assertCount(0, $painel->filter('.voto-demonstracao'), 'a assembleia é votação real: sem a faixa de demonstração');
        $form = $this->client->request('GET', '/voto/assembleias/nova')->selectButton('Continuar')->form(['titulo' => 'AGO Residencial Jardim 2026', 'peso' => 'fracao']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(1, preg_match('#/voto/assembleias/(\d+)/pautas$#', (string) $this->client->getResponse()->headers->get('Location'), $m));
        $id = (int) $m[1];

        // Pautas pelos atalhos (pauta padrão 1 Sim, 2 Não, mais a abstenção)
        $pautas = $this->client->followRedirect();
        $this->client->submit($pautas->filter('.voto-atalhos button[value="Aprovação de contas"]')->form());
        $pautas = $this->client->followRedirect();
        $this->client->submit($pautas->filter('.voto-atalhos button[value="Obra ou benfeitoria"]')->form());
        $pautas = $this->client->followRedirect();
        self::assertSame(['Aprovação de contas', 'Obra ou benfeitoria'], $pautas->filter('.voto-pauta-bloco input[name="titulo"]')->each(static fn (Crawler $c): string => (string) $c->attr('value')));

        // Unidades: 3 aptas, 1 não apta, 1 inválida, 1 duplicada
        $unidades = $this->client->request('GET', '/voto/assembleias/' . $id . '/unidades');
        self::assertSame('Lista obrigatória nesta votação.', trim($unidades->filter('.voto-estado')->text()));
        $this->client->submit($unidades->selectButton('Importar lista')->form(['lista' => implode("\n", [
            'unidade;bloco;situacao;fracao;responsavel;email',
            '101;A;;0,50;Ana Souza;ana@exemplo.com',
            '102;A;quite;0,20;Bruno Lima;',
            '103;B;;0,20;;',
            '104;B;inadimplente;0,10;;',
            ';C;;0,1;;',
            '101;A;;0,5;;',
        ])]));
        $unidades = $this->client->followRedirect();
        self::assertSame('4 registros importados. Inválidas: 1. Duplicadas: 1.', trim($unidades->filter('.voto-toast')->text()));
        self::assertSame(['Linha 6: Linha sem a unidade.', 'Duplicadas: linhas 7.'], $unidades->filter('.voto-relatorio > div')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('3 aptos e 1 não aptos importados.', trim($unidades->filter('.voto-estado')->text()));
        self::assertCount(0, $unidades->filter('[data-voto-copiar]'), 'os links só aparecem depois de publicar');

        // Publicar
        $this->client->submit($unidades->selectButton('Publicar votação')->form());
        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $id);
        $painel = $this->client->followRedirect();
        self::assertSame('Votação publicada. A configuração ficou bloqueada.', trim($painel->filter('.voto-toast')->text()));
        $cartao = $painel->filter('#assembleia-' . $id);
        self::assertSame('Publicada', trim($cartao->filter('.vH .vChip')->text()));
        self::assertCount(2, $cartao->filter('.voto-pauta[data-situacao="publicada"] form[data-mesa="abrir"]'));
        $links = $this->client->request('GET', '/voto/assembleias/' . $id . '/unidades');
        self::assertCount(3 * 2, $links->filter('[data-voto-copiar]'), 'Copiar link e Copiar mensagem por unidade apta');
        self::assertStringContainsString('/votar/', (string) $links->filter('[data-voto-copiar]')->first()->attr('data-voto-copiar'));

        // Abrir a 1ª pauta por 5 minutos e votar: unidades 2 x 1 para o Não, fração 0,5 x 0,4 para o Sim
        [$contas, $obra] = $this->pautasDa($id);
        $painel = $this->client->request('GET', '/voto?aba=ges');
        $this->client->submit($painel->filter('#pauta-' . $contas . ' form[data-mesa="abrir"] button[value="minutos"]')->form(['convocacao' => '1', 'minutos' => '5']));
        $painel = $this->client->followRedirect();
        self::assertSame('Votação aberta pela mesa.', trim($painel->filter('.voto-toast')->text()));
        $cron = $painel->filter('#pauta-' . $contas . ' .voto-cron');
        self::assertGreaterThan(290, (int) $cron->attr('data-voto-prazo'));
        self::assertStringStartsWith('Encerra em 0', trim($cron->text()));
        $this->votar($contas, ['101' => 1, '102' => 2, '103' => 2]);

        // Pauta aberta: participação sim, parcial de votos nunca (painel, resultados, acompanhar e assistir)
        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertSame('Participação 3 de 3 aptos (100%). Conduzida pela mesa.', trim($painel->filter('#pauta-' . $contas . ' .voto-participacao')->text()));
        self::assertCount(0, $painel->filter('.voto-decisao'));
        $resultados = $this->client->request('GET', '/voto?aba=tra');
        self::assertCount(0, $resultados->filter('.voto-resultado'));
        $acompanhar = $this->client->request('GET', '/voto?aba=aco');
        self::assertSame(['Aprovação de contas', 'Obra ou benfeitoria'], $acompanhar->filter('.voto-aco-linha .voto-aco-nome > b')->each(static fn (Crawler $c): string => $c->text()), 'abertas primeiro');
        self::assertSame('AGO Residencial Jardim 2026 · 3 de 3 votaram', trim($acompanhar->filter('.voto-aco-linha .voto-aco-det')->first()->text()));
        $assistir = $this->client->request('GET', '/voto/assembleias/' . $id . '/pautas/' . $contas . '/assistir');
        self::assertResponseIsSuccessful();
        self::assertSame(['3', '3', '100%'], $assistir->filter('.g strong')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Quórum atingido: 50%', $assistir->filter('p.ok')->first()->text());
        self::assertStringNotContainsString('Sim', $assistir->filter('body')->text());
        self::assertStringNotContainsString('Não', $assistir->filter('body')->text());

        // Encerrar sem motivo: "Determinação da mesa"; resultado por fração ideal
        $this->client->submit($painel->filter('#pauta-' . $contas . ' form[data-mesa="encerrar"]')->form());
        $painel = $this->client->followRedirect();
        self::assertSame('Votação encerrada.', trim($painel->filter('.voto-toast')->text()));
        self::assertSame('Resultado: 1 Sim', trim($painel->filter('#pauta-' . $contas . ' .voto-decisao.voto-decisao-ok')->text()));
        $resultados = $this->client->request('GET', '/voto?aba=tra');
        $cartao = $resultados->filter('.voto-tela > .voto-painel[data-aba="tra"] > #assembleia-' . $id);
        self::assertSame('Aprovação de contas: 1 Sim', trim($cartao->filter('.voto-resultado-faixa')->text()));
        self::assertSame(['1 un · 0,5', '2 un · 0,4'], $cartao->filter('.voto-resultado-valor')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('Abstenções 0 · Nulos 0', trim($cartao->filter('.voto-resultado-abst')->text()));

        // 2ª pauta sem prazo: só uma unidade vota (0,2 de 0,9) e o quórum de 50% não é atingido
        $this->client->submit($painel->filter('#pauta-' . $obra . ' form[data-mesa="abrir"] button[value="sem"]')->form(['convocacao' => '1']));
        $painel = $this->client->followRedirect();
        self::assertSame('Aberta, sem prazo definido', trim($painel->filter('#pauta-' . $obra . ' .voto-cron')->text()));
        $this->votar($obra, ['102' => 1]);
        $this->client->submit($painel->filter('#pauta-' . $obra . ' form[data-mesa="encerrar"]')->form(['motivo' => 'Pauta esgotada pela mesa']));
        $painel = $this->client->followRedirect();
        self::assertSame('Quórum não atingido', trim($painel->filter('#pauta-' . $obra . ' .voto-decisao')->text()));

        // Ata
        $ata = $this->client->request('GET', '/voto/assembleias/' . $id . '/ata');
        self::assertResponseIsSuccessful();
        self::assertSame('RELATÓRIO CONSOLIDADO DA VOTAÇÃO', $ata->filter('h1')->text());
        $texto = $ata->filter('body')->text();
        self::assertStringContainsString('Aprovação de contas: 3 de 3 aptos votaram (100%).', $texto);
        self::assertStringContainsString('Resultado de Aprovação de contas: 1 Sim. Quórum: atingido.', $texto);
        self::assertStringContainsString('Resultado de Obra ou benfeitoria: Quórum não atingido. Quórum: não atingido.', $texto);
        self::assertStringContainsString('Pela mesa, 1ª convocação', $texto);
        self::assertStringContainsString('Determinação da mesa', $texto);
        self::assertStringContainsString('Pauta esgotada pela mesa', $texto);
        self::assertStringContainsString('Quórum: 100,00% (exigido 50%)', $texto);
        self::assertStringContainsString('Não indica em quem cada pessoa votou.', $texto);
        $campos = $this->camposDaAta($ata, $contas);
        self::assertSame('Síndica Teste', $campos['Aberta por'] ?? null);
        self::assertSame('Síndica Teste', $campos['Encerrada por'] ?? null);
        self::assertSame('Determinação da mesa', $campos['Motivo registrado'] ?? null);
        $votantes = $ata->filter('#pauta-' . $contas . ' table')->last()->filter('tbody tr')->each(static fn (Crawler $c): string => trim($c->filter('td')->first()->text()));
        self::assertSame(['A · 101', 'A · 102', 'B · 103'], $votantes);
        self::assertSame(['Presidência da assembleia', 'Secretaria da assembleia'], $ata->filter('.voto-ata-assinaturas > div')->each(static fn (Crawler $c): string => trim($c->text())));
    }

    #[TestDox('Um voto por unidade: empate é empate (sem decisão), e a abstenção conta para o quórum, não para as opções')]
    public function testEmpateEAbstencaoPorUnidade(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGE Bloco Sul'])->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'titulo' => 'Pintura da fachada'])->encerrada()->create()->_real();
        OpcaoDaPautaFactory::new(['pauta' => $pauta, 'numero' => 1, 'rotulo' => 'Sim'])->comVotos(2, '2.000000')->create();
        OpcaoDaPautaFactory::new(['pauta' => $pauta, 'numero' => 2, 'rotulo' => 'Não'])->comVotos(2, '2.000000')->create();
        OpcaoDaPautaFactory::new(['pauta' => $pauta])->abstencao()->comVotos(1, '1.000000')->create();
        foreach (range(1, 6) as $n) {
            $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '20' . $n])->_real();
            if ($n <= 5) {
                $this->em->persist(new ParticipacaoNaPauta($unidade, $pauta));
            }
        }
        $this->em->flush();

        $resultados = $this->client->request('GET', '/voto?aba=tra');

        $cartao = $resultados->filter('#assembleia-' . $assembleia->getId());
        self::assertSame('Pintura da fachada: Empate', trim($cartao->filter('.voto-resultado-faixa')->text()));
        self::assertSame(['2', '2'], $cartao->filter('.voto-resultado-valor')->each(static fn (Crawler $c): string => trim($c->text())), 'sem peso: só as unidades');
        self::assertSame('Abstenções 1 · Nulos 0', trim($cartao->filter('.voto-resultado-abst')->text()));
        self::assertSame('Empate', trim($cartao->filter('.voto-decisao')->text()));
        self::assertCount(0, $cartao->filter('.voto-decisao-ok'));
        self::assertSame(['5', '5'], $cartao->filter('.vKpi b')->each(static fn (Crawler $c): string => $c->text()));
    }

    #[TestDox('Prazo vencido: a leitura do painel encerra a pauta com o motivo "Prazo definido pela mesa"')]
    public function testPrazoVencidoEncerraNaLeitura(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->aberta(1, new \DateTimeImmutable('-1 minute'))->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia]);

        $painel = $this->client->request('GET', '/voto?aba=ges');

        self::assertSame('encerrada', $painel->filter('#pauta-' . $pauta->getId())->attr('data-situacao'));
        $this->em->clear();
        $depois = $this->em->find(PautaDaAssembleia::class, $pauta->getId());
        self::assertSame(EstadoDaPauta::Encerrada, $depois?->getEstado());
        self::assertSame(PautaDaAssembleia::MOTIVO_DO_PRAZO, $depois->getMotivoEncerramento());

        $campos = $this->camposDaAta($this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata'), (int) $pauta->getId());
        self::assertSame('Mesa', $campos['Aberta por'] ?? null);
        self::assertArrayNotHasKey('Encerrada por', $campos, 'encerrada pelo prazo: ninguém encerrou');
        self::assertSame('Prazo definido pela mesa', $campos['Motivo registrado'] ?? null);
    }

    #[TestDox('Definir prazo com o prazo já vencido: o encerramento pelo prazo fica gravado e a mesa vê a recusa em vermelho')]
    public function testDefinirPrazoComPrazoVencidoRecusa(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->aberta(1, new \DateTimeImmutable('-1 minute'))->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia]);
        $a = (int) $assembleia->getId();
        $p = (int) $pauta->getId();
        $token = $this->tokenValido('voto_assembleia_' . $a);

        $this->client->request('POST', '/voto/assembleias/' . $a . '/pautas/' . $p . '/prazo', ['_token' => $token, 'minutos' => '10', 'voltar' => 'pautas']);

        self::assertResponseRedirects('/voto/assembleias/' . $a . '/pautas#pauta-' . $p);
        // Antes de seguir o redirecionamento: a leitura da tela também encerraria pelo prazo.
        $noBanco = $this->em->getConnection()->fetchAssociative('SELECT estado, motivo_encerramento, encerrada_por_nome FROM voto.assembleia_pauta WHERE id = ?', [$p]);
        self::assertSame(['estado' => 'encerrada', 'motivo_encerramento' => PautaDaAssembleia::MOTIVO_DO_PRAZO, 'encerrada_por_nome' => null], $noBanco);
        $tela = $this->client->followRedirect();
        self::assertSame('O prazo desta votação já terminou e ela foi encerrada.', trim($tela->filter('.voto-toast.voto-toast-erro')->text()));
    }

    #[TestDox('Unidade inapta com e-mail: não recebe no lote, não entra no "faltam" e o Reenviar direto por POST é recusado')]
    public function testInaptaNaoRecebeOLink(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '301', 'email' => 'apta.voto@exemplo.com']);
        $inapta = UnidadeDaAssembleiaFactory::new()->inapta()->create(['assembleia' => $assembleia, 'identificacao' => '302', 'email' => 'inapta.voto@exemplo.com'])->_real();
        $a = (int) $assembleia->getId();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a . '/unidades');
        self::assertSame('Faltam 1.', trim($tela->filter('.voto-links .voto-acoes .voto-participacao')->text()));
        $this->client->submit($tela->selectButton('Enviar links por e-mail')->form());
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'apta.voto@exemplo.com');
        $tela = $this->client->followRedirect();
        self::assertSame('1 enviados. Falhas: 0. Faltam 0.', trim($tela->filter('.voto-toast')->text()));

        $this->client->request('POST', '/voto/assembleias/' . $a . '/unidades/' . $inapta->getId() . '/reenviar', ['_token' => $this->tokenValido('voto_assembleia_' . $a)]);
        self::assertResponseRedirects();
        self::assertEmailCount(0);
        $tela = $this->client->followRedirect();
        self::assertSame('Unidade inapta não vota e não recebe o link.', trim($tela->filter('.voto-toast.voto-toast-erro')->text()));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT link_enviado_em FROM voto.assembleia_unidade WHERE id = ?', [$inapta->getId()]));
    }

    #[TestDox('Lote de e-mail: endereço fora da RFC conta como falha daquela unidade, o lote segue e as marcas de quem recebeu ficam gravadas')]
    public function testLoteSegueComEnderecoForaDaRfc(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '401', 'email' => 'primeira.voto@exemplo.com']);
        $invalida = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '402', 'email' => 'segunda@@exemplo.com'])->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '403', 'email' => 'terceira.voto@exemplo.com']);
        $a = (int) $assembleia->getId();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a . '/unidades');
        $this->client->submit($tela->selectButton('Enviar links por e-mail')->form());

        self::assertEmailCount(2);
        $tela = $this->client->followRedirect();
        self::assertSame('2 enviados. Falhas: 1. Faltam 1.', trim($tela->filter('.voto-toast.voto-toast-erro')->text()));
        $enviadas = $this->em->getConnection()->fetchFirstColumn(
            'SELECT identificacao FROM voto.assembleia_unidade WHERE assembleia_id = ? AND link_enviado_em IS NOT NULL ORDER BY identificacao',
            [$a],
        );
        self::assertSame(['401', '403'], $enviadas);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT link_enviado_em FROM voto.assembleia_unidade WHERE id = ?', [$invalida->getId()]));
    }

    #[TestDox('Enviar links por e-mail: só publicada; cada unidade com e-mail recebe o link /votar/{token} e sai do "faltam"')]
    public function testEnviaOsLinksPorEmail(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Torre Norte'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create();
        $ana = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '301', 'responsavel' => 'Ana', 'email' => 'ana.voto@exemplo.com'])->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '302', 'email' => 'bruno.voto@exemplo.com']);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => '303', 'email' => null]);
        $token = UnidadeDaAssembleiaFactory::tokenDe($ana);

        $tela = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/unidades');
        self::assertSame('Faltam 2.', trim($tela->filter('.voto-links .voto-acoes .voto-participacao')->text()));
        $this->client->submit($tela->selectButton('Enviar links por e-mail')->form());

        self::assertEmailCount(2);
        $email = self::getMailerMessage(0);
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'ana.voto@exemplo.com');
        self::assertEmailHtmlBodyContains($email, '/votar/' . $token);
        self::assertEmailHtmlBodyContains($email, 'Olá, Ana.');
        $tela = $this->client->followRedirect();
        self::assertSame('2 enviados. Falhas: 0. Faltam 0.', trim($tela->filter('.voto-toast')->text()));
        self::assertSame('Faltam 0.', trim($tela->filter('.voto-links .voto-acoes .voto-participacao')->text()));
    }

    #[TestDox('Publicar sem unidade apta é recusado com a mensagem e a assembleia segue em rascunho')]
    public function testPublicarSemUnidadeRecusa(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        $this->client->submit($painel->filter('#assembleia-' . $assembleia->getId())->selectButton('Publicar')->form());
        $painel = $this->client->followRedirect();

        self::assertSame('Inclua ao menos uma unidade apta a votar.', trim($painel->filter('.voto-toast.voto-toast-erro')->text()));
        $this->em->clear();
        self::assertSame(EstadoDaAssembleia::Rascunho, $this->em->find(Assembleia::class, $assembleia->getId())?->getEstado());
    }

    #[TestDox('Tela das pautas: Nº só com algarismo, cada caixa marcada para o salvar sem recarregar e o texto de falha')]
    public function testTelaDasPautasPreparaOSalvarSemRecarregar(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/pautas');

        self::assertResponseIsSuccessful();
        $caixa = $tela->filter('.voto-tela .voto-assistente[data-voto-pautas]');
        self::assertCount(1, $caixa);
        self::assertSame('Não foi possível salvar. Confira a conexão e tente de novo.', $caixa->attr('data-voto-falha'));
        self::assertSame('Corrija a pauta ou repita o clique para seguir sem salvá-la.', $caixa->attr('data-voto-repetir'));
        self::assertSame('Pauta incluída.', $caixa->attr('data-voto-incluida'));
        $erro = $caixa->filter('.voto-assistente-corpo > .voto-erro[data-voto-erro][role="alert"]');
        self::assertCount(1, $erro, 'o erro da etapa (edErro do desenho) mora no corpo do assistente');
        self::assertNotNull($erro->attr('hidden'), 'começa escondido');
        self::assertCount(1, $caixa->filter('.voto-assistente-corpo > .voto-erro[data-voto-erro] + .voto-rodape'), 'logo acima do rodapé, como no desenho');
        self::assertCount(1, $caixa->filter('.voto-assistente-corpo > .voto-pauta-bloco > form#pauta-' . $pauta->getId() . '[data-voto-pauta-form="existente"]'), 'a pauta que já existe grava por fetch');
        self::assertCount(1, $caixa->filter('.voto-assistente-corpo > form#pauta-nova[data-voto-pauta-form="nova"]'), 'a pauta nova é gravada antes de sair');
        $numeros = $caixa->filter('form[data-voto-pauta-form] .voto-opcao > input[name="opcao_numero[]"]');
        self::assertCount(4, $numeros, '1 Sim e 2 Não na pauta e na pauta nova');
        self::assertSame(4, $caixa->filter('input[name="opcao_numero[]"][data-voto-numero][inputmode="numeric"][maxlength="1"]')->count(), 'todo Nº só aceita um algarismo');
    }

    #[TestDox('Salvar a pauta por fetch responde JSON, grava e não deixa toast para a próxima tela')]
    public function testSalvarPautaPorFetchRespondeJson(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-' . $pauta->getId() . ' input[name="_token"]')->attr('value');

        $this->client->xmlHttpRequest('POST', $url . '/' . $pauta->getId() . '/editar', [
            '_token' => $token, 'titulo' => 'Pintura da fachada', 'descricao' => '',
            'opcao_numero' => ['2', '1', '3'], 'opcao_rotulo' => ['Não', 'Sim', 'Adiar'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true, 'mensagem' => 'Pauta salva.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame('Pintura da fachada', $this->em->getConnection()->fetchOne('SELECT titulo FROM voto.assembleia_pauta WHERE id = ?', [$pauta->getId()]));
        self::assertSame([[0, 'Abstenção'], [1, 'Sim'], [2, 'Não'], [3, 'Adiar']], $this->opcoesNoBanco((int) $pauta->getId()));
        $tela = $this->client->request('GET', $url);
        self::assertCount(0, $tela->filter('.voto-toast'), 'o aviso foi no JSON, não na sessão');
    }

    #[TestDox('Nº com letra é recusado: por fetch, 422 com a mensagem; no envio comum, toast de erro; nada muda')]
    public function testNumeroComLetraERecusado(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-' . $pauta->getId() . ' input[name="_token"]')->attr('value');
        $dados = ['_token' => $token, 'titulo' => 'Pintura da fachada', 'opcao_numero' => ['a', '2'], 'opcao_rotulo' => ['Sim', 'Não']];

        $this->client->xmlHttpRequest('POST', $url . '/' . $pauta->getId() . '/editar', $dados);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['ok' => false, 'mensagem' => 'Número a deve ter 1 dígito(s).'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('POST', $url . '/' . $pauta->getId() . '/editar', $dados);
        self::assertResponseRedirects($url . '#pauta-' . $pauta->getId());
        $tela = $this->client->followRedirect();
        self::assertSame('Número a deve ter 1 dígito(s).', trim($tela->filter('.voto-toast.voto-toast-erro')->text()));

        $this->client->xmlHttpRequest('POST', $url, ['_token' => $token, 'titulo' => 'Obra', 'opcao_numero' => ['x'], 'opcao_rotulo' => ['Sim']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Aprovação das contas', $this->em->getConnection()->fetchOne('SELECT titulo FROM voto.assembleia_pauta WHERE id = ?', [$pauta->getId()]));
        self::assertSame([[0, 'Abstenção'], [1, 'Sim'], [2, 'Não']], $this->opcoesNoBanco((int) $pauta->getId()));
        self::assertSame([(int) $pauta->getId()], $this->pautasDa((int) $assembleia->getId()), 'a pauta com letra não foi criada');
    }

    #[TestDox('Incluir a pauta por fetch (gravada antes de sair da tela) responde JSON e cria a pauta')]
    public function testIncluirPautaPorFetchRespondeJson(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-nova input[name="_token"]')->attr('value');

        $this->client->xmlHttpRequest('POST', $url, ['_token' => $token, 'titulo' => 'Obra na garagem', 'opcao_numero' => ['1', '2'], 'opcao_rotulo' => ['Sim', 'Não']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true, 'mensagem' => 'Pauta incluída.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $pautas = $this->pautasDa((int) $assembleia->getId());
        self::assertCount(1, $pautas);
        self::assertSame([[0, 'Abstenção'], [1, 'Sim'], [2, 'Não']], $this->opcoesNoBanco($pautas[0]));
    }

    #[TestDox('Remover a pauta por fetch responde JSON e remove só ela')]
    public function testRemoverPautaPorFetchRespondeJson(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $fica = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'ordem' => 1])->comOpcoesPadrao()->create()->_real();
        $sai = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'ordem' => 2, 'titulo' => 'Obra'])->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-nova input[name="_token"]')->attr('value');

        $this->client->xmlHttpRequest('POST', $url . '/' . $sai->getId() . '/remover', ['_token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true, 'mensagem' => 'Pauta removida.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([(int) $fica->getId()], $this->pautasDa((int) $assembleia->getId()));
    }

    #[TestDox('Pauta que não existe mais (ou de outro escritório) no salvar por fetch: 404 em JSON, sem mexer na alheia')]
    public function testSalvarPorFetchPautaInexistenteDa404EmJson(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $alheia = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-nova input[name="_token"]')->attr('value');
        $dados = ['_token' => $token, 'titulo' => 'Invasora', 'opcao_numero' => ['1'], 'opcao_rotulo' => ['Sim']];
        $esperado = ['ok' => false, 'mensagem' => 'Esta pauta não existe mais. Recarregue a página.'];

        foreach ([999999999, (int) $alheia->getId()] as $pautaId) {
            $this->client->xmlHttpRequest('POST', $url . '/' . $pautaId . '/editar', $dados);
            self::assertResponseStatusCodeSame(404);
            self::assertSame($esperado, json_decode((string) $this->client->getResponse()->getContent(), true));
        }
        self::assertSame('Aprovação das contas', $this->em->getConnection()->fetchOne('SELECT titulo FROM voto.assembleia_pauta WHERE id = ?', [$alheia->getId()]));
    }

    #[TestDox('Sessão expirada no salvar por fetch: 403 com o aviso (a tela tenta de novo, não descarta), e a pauta não muda')]
    public function testSalvarPorFetchComTokenVencido(): void
    {
        $this->liberar();
        $this->entrar($this->usuario());
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();

        $this->client->xmlHttpRequest('POST', '/voto/assembleias/' . $assembleia->getId() . '/pautas/' . $pauta->getId() . '/editar', [
            '_token' => 'vencido', 'titulo' => 'Outra', 'opcao_numero' => ['1'], 'opcao_rotulo' => ['Sim'],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['ok' => false, 'mensagem' => 'A sessão expirou. Recarregue a página e tente de novo.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame('Aprovação das contas', $this->em->getConnection()->fetchOne('SELECT titulo FROM voto.assembleia_pauta WHERE id = ?', [$pauta->getId()]));
    }

    /** @return list<array{int, string}> número e nome de cada opção da pauta, pelo número */
    private function opcoesNoBanco(int $pautaId): array
    {
        return array_map(
            static fn (array $l): array => [(int) $l['numero'], (string) $l['rotulo']],
            $this->em->getConnection()->fetchAllAssociative('SELECT numero, rotulo FROM voto.assembleia_opcao WHERE pauta_id = ? ORDER BY numero', [$pautaId]),
        );
    }

    /**
     * Grava os votos como a cédula grava: a participação da unidade e a soma no contador da opção.
     *
     * @param array<string, int> $votos identificação da unidade => número da opção
     */
    private function votar(int $pautaId, array $votos): void
    {
        $pauta = $this->em->find(PautaDaAssembleia::class, $pautaId);
        self::assertNotNull($pauta);
        $opcoes = static::getContainer()->get(OpcaoDaPautaRepository::class);
        foreach ($votos as $identificacao => $numero) {
            $unidade = $this->em->getRepository(UnidadeDaAssembleia::class)->findOneBy(['assembleia' => $pauta->getAssembleia(), 'identificacao' => (string) $identificacao]);
            self::assertNotNull($unidade);
            $opcao = array_values(array_filter($pauta->getOpcoes()->toArray(), static fn (OpcaoDaPauta $o): bool => $o->getNumero() === $numero))[0];
            $this->em->persist(new ParticipacaoNaPauta($unidade, $pauta));
            $opcoes->somarVoto($opcao, $unidade->pesoDoVoto());
        }
        $this->em->flush();
    }

    /**
     * Os campos "rótulo: valor" da seção da pauta na ata.
     *
     * @return array<string, string>
     */
    private function camposDaAta(Crawler $ata, int $pautaId): array
    {
        $campos = [];
        $ata->filter('#pauta-' . $pautaId . ' dl.voto-ata-campos > div')->each(static function (Crawler $c) use (&$campos): void {
            $campos[trim($c->filter('dt')->text())] = trim($c->filter('dd')->text());
        });

        return $campos;
    }

    /** @return list<int> ids das pautas pela ordem */
    private function pautasDa(int $assembleiaId): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM voto.assembleia_pauta WHERE assembleia_id = ? ORDER BY ordem',
            [$assembleiaId],
        ));
    }

    private function contarAssembleias(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    /**
     * Um token CSRF válido para o id pedido, gravado na sessão do navegador como o SessionTokenStorage grava: a tela
     * nunca entrega o token de uma assembleia alheia, e sem ele a ação pararia no CSRF antes da guarda de posse.
     * Abre a aba Votar, que não lê as assembleias (a leitura encerraria antes da ação a pauta de prazo vencido).
     */
    private function tokenValido(string $id): string
    {
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function liberar(): void
    {
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function ligar(): void
    {
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    /** Perfil de sistema do escritório (passa pelo bypass do PermissionChecker, como em VotoTelaTest). */
    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
