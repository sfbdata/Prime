<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\ProcessosDaOabController;
use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\ProcessoAcompanhado;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ProcessosDaOabController::class)]
#[Group('djen')]
final class ProcessosDaOabControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO_A = '07022222220268070001';
    private const NUMERO_B = '07033333320268070002';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarProcesso(Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->em()->getReference(Tenant::class, $tenant->getId()));
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setOrgaoJulgador('1ª Vara Cível');
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: Tenant} */
    private function entrar(): array
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        return [$client, $tenant];
    }

    #[Test]
    public function listaOsProcessosAcompanhadosNoArranjoDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.pp-abas a.pp-aba.is-ativa[data-pp-aba="processos"]'));
        self::assertCount(5, $crawler->filter('.pp-pagina > .pp-kpis > a.pp-kpi'));
        self::assertCount(1, $crawler->filter('.pp-pagina > section[data-pp-inclusao] > form'));
        self::assertCount(2, $crawler->filter('.pp-pagina > .pp-subabas > a.pp-subaba'));
        self::assertCount(1, $crawler->filter('[data-pp-inclusao] input[data-pp-mascara-cnj]'), 'máscara CNJ do desenho');
        self::assertSame('0 | 150', trim($crawler->filter('[data-pp-inclusao] [data-pp-obs-n]')->text()), 'contador da observação do desenho');
        self::assertCount(1, $crawler->filter('.pp-tabela__corpo > .pp-linha--dado'));
        self::assertCount(1, $crawler->filter('.pp-tabela__corpo > .pp-linha--dado[data-pp-linha-proc][data-numero]'), 'a linha carrega o contexto do menu de botão direito');
        self::assertCount(1, $crawler->filter('.pp-linha--dado .pp-c-acoes > button[data-pp-vincular-proc]'), 'sem pasta, o botão Pasta abre o modal');
        self::assertCount(1, $crawler->filter('[data-pp-modal-pasta]'));
        self::assertStringContainsString('0702222-22.2026.8.07.0001', $crawler->filter('.pp-linha--dado')->text());
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="processos"] .pp-kpi__valor')->text()));
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="nunca"] .pp-kpi__valor')->text()));
        self::assertCount(0, $crawler->filter('.pp-pagina .card, .pp-pagina .btn, .pp-pagina .table'));
    }

    #[Test]
    public function incluirNumeroSemProcessoGravaEAparece(): void
    {
        [$client, $tenant] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-inclusao] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/incluir', ['_token' => $token, 'numero' => '0703333-33.2026.8.07.0002', 'email' => 'a@b.com', 'observacao' => 'Cliente Y']);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('incluído para acompanhamento', $crawler->text());
        self::assertStringContainsString('Cliente Y', $crawler->filter('.pp-linha--dado')->text());
        $linha = $this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO_B]);
        self::assertNotNull($linha);
        self::assertSame($tenant->getId(), $linha->getTenant()->getId());
        self::assertTrue($linha->isAcompanhando());
    }

    #[Test]
    public function incluirComTokenInvalidoNaoGrava(): void
    {
        [$client] = $this->entrar();

        $client->request('POST', '/push-processual/processos/incluir', ['_token' => 'errado', 'numero' => self::NUMERO_B]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertNull($this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO_B]));
    }

    #[Test]
    public function pararDeAcompanharPausaOProcessoESaiDaLista(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $processo = $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-inclusao] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/parar', ['_token' => $token, 'numeros' => [self::NUMERO_A]]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('1 processo(s) deixaram de ser acompanhados', $crawler->text());
        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
        $this->limparIdentityMap();
        self::assertFalse($this->em()->find(Processo::class, $processo->getId())->monitoramentoAtivo());
    }

    #[Test]
    public function relatorioListaNumeroDaOabQueAindaNaoEAcompanhadoEIncluiNoAcompanhamento(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $oab = $this->criarOab($tenant, '49613', 'DF');
        $publicacao = $this->criarPublicacao($tenant, '950001', self::NUMERO_B);
        $publicacao->setOabMonitorada($oab);
        $this->em()->flush();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos?sub=relatorio');

        self::assertCount(1, $crawler->filter('.pp-linha--dado'));
        self::assertStringContainsString('0703333-33.2026.8.07.0002', $crawler->filter('.pp-linha--dado')->text());
        $token = $crawler->filter('.pp-linha--dado input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/incluir-do-relatorio', ['_token' => $token, 'numeros' => [self::NUMERO_B]]);
        $client->followRedirect();
        $crawler = $client->request('GET', '/push-processual/processos?sub=relatorio');

        self::assertCount(0, $crawler->filter('.pp-linha--dado'), 'incluído, sai do Relatório');
        $crawler = $client->request('GET', '/push-processual/processos');
        self::assertCount(1, $crawler->filter('.pp-linha--dado'));
    }

    #[Test]
    public function anotacoesGravamObservacaoAreaEFase(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-inclusao] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/anotacoes', ['_token' => $token, 'numero' => self::NUMERO_A, 'observacao' => 'Cliente Z', 'area' => 'Cível', 'fase' => 'Recurso protocolado', 'email' => '']);
        $crawler = $client->followRedirect();

        $linha = $crawler->filter('.pp-linha--dado')->text();
        self::assertStringContainsString('Cliente Z', $linha);
        self::assertStringContainsString('Cível', $linha);
        self::assertStringContainsString('Recurso protocolado', $linha);
        self::assertStringContainsString('Recursal', $linha, 'o grupo da fase aparece sob o número');
        $crawler = $client->request('GET', '/push-processual/processos', ['area' => 'Família']);
        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
        self::assertCount(1, $crawler->filter('[data-pp-limpar]'));
    }

    #[Test]
    public function exportaCsvComOsFiltros(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        ob_start();
        $client->request('GET', '/push-processual/processos/exportar');
        $csv = ob_get_clean() . (string) $client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('0702222-22.2026.8.07.0001', (string) $csv);
    }

    #[Test]
    public function nadaVazaEntreEscritorios(): void
    {
        $client = static::createClient();
        $outro = $this->criarTenant();
        $this->criarProcesso($outro, self::NUMERO_A);
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos');

        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
    }

    #[Test]
    public function semOModuloProcessosVaiParaAInicial(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['modules.djen.view']), $tenant);

        $client->request('GET', '/push-processual/processos');

        self::assertResponseRedirects();
    }

    #[Test]
    public function excluirProcessoTiraDaListaDaPastaEDoRelatorioMasMantemAsPublicacoes(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $processo = $this->criarProcesso($tenant, self::NUMERO_A);
        $pasta = new \App\Pasta\Entity\Pasta();
        $pasta->setNup('NUP-EX1');
        $pasta->setNomeCliente('MARIA');
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();
        $pasta->vincularProcesso($processo);
        $oab = $this->criarOab($tenant, '49613', 'DF');
        $publicacao = $this->criarPublicacao($tenant, '950010', self::NUMERO_A);
        $publicacao->setOabMonitorada($oab);
        $this->em()->flush();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos');
        self::assertCount(1, $crawler->filter('.pp-linha--dado'));
        $token = $crawler->filter('[data-pp-excluir-form] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/excluir', ['_token' => $token, 'numero' => self::NUMERO_A]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('Processo excluído.', $crawler->text());
        self::assertCount(0, $crawler->filter('.pp-linha--dado'), 'saiu dos acompanhados');
        $crawler = $client->request('GET', '/push-processual/processos?sub=relatorio');
        self::assertCount(0, $crawler->filter('.pp-linha--dado'), 'não volta ao Relatório');
        $crawler = $client->request('GET', '/push-processual');
        self::assertCount(1, $crawler->filter('[data-pp-linha]'), 'a publicação continua no Push');
        $this->limparIdentityMap();
        self::assertFalse($this->em()->find(\App\Pasta\Entity\Pasta::class, $pasta->getId())->temProcesso($this->em()->find(Processo::class, $processo->getId())), 'saiu da pasta');
    }

    #[Test]
    public function incluirDeNovoDesfazOExcluir(): void
    {
        [$client, $tenant] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-excluir-form] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/excluir', ['_token' => $token, 'numero' => self::NUMERO_B]);
        $client->followRedirect();
        $this->limparIdentityMap();
        $linha = $this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO_B]);
        self::assertNotNull($linha?->getExcluidoEm());

        $client->request('POST', '/push-processual/processos/incluir', ['_token' => $token, 'numero' => self::NUMERO_B]);
        $client->followRedirect();
        $this->limparIdentityMap();
        $linha = $this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO_B]);
        self::assertNull($linha->getExcluidoEm());
        self::assertTrue($linha->isAcompanhando());
    }

    #[Test]
    public function excluirRecusaNumeroIncompleto(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-excluir-form] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/excluir', ['_token' => $token, 'numero' => '123']);

        self::assertStringContainsString('20 dígitos', $client->followRedirect()->text());
    }

    #[Test]
    public function modalVincularProcessoEstaNasDuasAbas(): void
    {
        [$client] = $this->entrar();

        foreach (['/push-processual', '/push-processual/processos'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertCount(1, $crawler->filter('[data-pp-vp][hidden]'), $url);
            self::assertCount(1, $crawler->filter('button[data-push-cadastrar-processo]'), $url);
            self::assertCount(0, $crawler->filter('[data-pp-vp] .modal, [data-pp-vp] .select2'), 'componentes do desenho, não do sistema');
        }
    }

    #[Test]
    public function buscaOsProcessosJaCadastradosPorNumero(): void
    {
        [$client, $tenant] = $this->entrar();
        $this->criarProcesso($tenant, self::NUMERO_A);

        $client->request('GET', '/push-processual/processos/cadastrados?q=0702222');

        self::assertResponseIsSuccessful();
        $itens = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $itens);
        self::assertSame(self::NUMERO_A, $itens[0]['numero']);
    }

    #[Test]
    public function incluiProcessoJaCadastradoNoMonitoramento(): void
    {
        [$client, $tenant] = $this->entrar();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-vp-form-busca] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/incluir-cadastrado', ['_token' => $token, 'numero' => self::NUMERO_A]);

        self::assertStringContainsString('vinculado', $client->followRedirect()->text());
    }

    #[Test]
    public function incluirCadastradoSemSelecaoAvisa(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-vp-form-busca] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/incluir-cadastrado', ['_token' => $token, 'numero' => '']);

        self::assertStringContainsString('Selecione um processo da lista.', $client->followRedirect()->text());
    }

    #[Test]
    public function cadastraProcessoNovoEIncluiNoAcompanhamento(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-vp-form-novo] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/cadastrar', [
            '_token' => $token,
            'numeroProcesso' => '0704444-44.2026.8.07.0001',
            'orgaoJulgador' => '2ª Vara Cível',
            'classeProcessual' => 'Procedimento Comum',
            'assuntoProcessual' => 'Indenização',
            'partes' => [['tipo' => 'PARTE', 'nome' => 'Maria', 'documento' => '', 'papel' => 'Polo ativo']],
        ]);

        self::assertStringContainsString('cadastrado e incluído', $client->followRedirect()->text());
        $lista = $client->request('GET', '/push-processual/processos');
        self::assertCount(1, $lista->filter('[data-pp-linha-proc][data-numero="07044444420268070001"]'));
    }

    #[Test]
    public function cadastrarSemCamposObrigatoriosMostraErro(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-vp-form-novo] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/cadastrar', ['_token' => $token, 'numeroProcesso' => '0704444-44.2026.8.07.0001']);

        $client->followRedirect();
        $this->limparIdentityMap();
        self::assertNull($this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => '07044444420268070001']));
    }

    #[Test]
    public function botaoBuscarApontaParaARotaDaAbaEComToken(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');

        self::assertCount(1, $crawler->filter('.pp-topo form[data-pp-buscar][action="/push-processual/processos/buscar"] input[name="_token"]'));
    }

    #[Test]
    public function buscarSemOabAtivaAvisaEVaiParaAsOabs(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-buscar] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/buscar', ['_token' => $token]);

        self::assertResponseRedirects('/push-processual/oabs');
        self::assertStringContainsString('Cadastre ao menos uma OAB para buscar os processos.', $client->followRedirect()->text());
    }

    #[Test]
    public function buscarComOabConsultaHistoricoEInformaOsProcessosEncontrados(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $this->criarOab($tenant, '67228', 'PR');
        $consultas = [];
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(
            static function (ConsultaComunicacoesQuery $q) use (&$consultas): RespostaComunicacoes {
                $consultas[] = $q;

                return new RespostaComunicacoes(1, [[
                    'id' => 990001,
                    'numero_processo' => self::NUMERO_A,
                    'siglaTribunal' => 'TJDFT',
                    'tipoComunicacao' => 'Intimação',
                    'data_disponibilizacao' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                ]]);
            }
        );
        static::getContainer()->set(DjenClient::class, $mock);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-buscar] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/buscar', ['_token' => $token]);

        self::assertResponseRedirects('/push-processual/processos');
        self::assertMatchesRegularExpression('#Busca concluída: [1-9]\d* processos encontrados para 67228/PR\.#', $client->followRedirect()->text());
        self::assertNotEmpty($consultas);
        self::assertSame((new \DateTimeImmutable('today -89 days'))->format('Y-m-d'), $consultas[0]->dataDisponibilizacaoInicio->format('Y-m-d'));
    }

    #[Test]
    public function buscarComTokenInvalidoNaoConsulta(): void
    {
        [$client] = $this->entrar();

        $client->request('POST', '/push-processual/processos/buscar', ['_token' => 'x']);

        self::assertStringContainsString('Token de segurança inválido.', $client->followRedirect()->text());
    }

    private function publicar(Tenant $tenant, string $numero, string $orgao, string $dataIso, bool $lida, ?int $prazo, ?string $cumpridoEm = null): void
    {
        $pub = $this->criarPublicacao($tenant, 'ac_' . $numero . $dataIso, $numero);
        $pub = $this->em()->find(PublicacaoDjen::class, $pub->getId());
        $pub->setNomeOrgao($orgao);
        $pub->setDataDisponibilizacao(new \DateTimeImmutable($dataIso));
        $pub->setLida($lida);
        $pub->definirPrazoDias($prazo);
        if ($cumpridoEm !== null) {
            $pub->marcarPrazoCumprido(new \DateTimeImmutable($cumpridoEm));
        }
        $this->em()->flush();
    }

    #[Test]
    public function acervoComecaPedindoUmaJurisdicaoEContaOsOrgaos(): void
    {
        [$client, $tenant] = $this->entrar();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->criarProcesso($tenant, self::NUMERO_B);

        $crawler = $client->request('GET', '/push-processual/processos?sub=acervo');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.pp-subabas > a.pp-subaba.is-ativa[href*="sub=acervo"]'));
        self::assertCount(1, $crawler->filter('.pp-acv-layout > aside.pp-acv'));
        self::assertCount(6, $crawler->filter('.pp-acv > .pp-acv__grupos > a.pp-acv__grupo'));
        self::assertSame('Selecione uma jurisdição', trim($crawler->filter('.pp-acv-layout__lista > .pp-acv-vazio')->text()));
        self::assertCount(0, $crawler->filter('[data-pp-tabela]'), 'sem recorte a tabela some');
        self::assertCount(0, $crawler->filter('[data-pp-filtros]'), 'a busca do acervo é a da coluna lateral');
    }

    #[Test]
    public function acervoAgrupaPelaSituacaoDoPrazoEFiltraPorOrgao(): void
    {
        [$client, $tenant] = $this->entrar();
        $hoje = new \DateTimeImmutable('today');
        // A: publicada hoje, não lida, 15 dias úteis => pendente de ciência. B: lida há 60 dias, 5 dias => prazo findou.
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->criarProcesso($tenant, self::NUMERO_B);
        $this->publicar($tenant, self::NUMERO_A, '1ª Vara Cível', $hoje->format('Y-m-d'), false, 15);
        $this->publicar($tenant, self::NUMERO_B, '2ª Vara Cível', $hoje->modify('-60 days')->format('Y-m-d'), true, 5);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos?sub=acervo&grupo=pr');
        $grupos = $crawler->filter('.pp-acv__grupo b')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['1', '1', '0', '1', '0', '0'], $grupos, 'pr, pend, cien, fin, sem, resp');
        self::assertSame(['1ª Vara Cível'], $crawler->filter('.pp-acv__orgao span')->each(static fn ($n) => trim($n->text())), 'só os pendentes do grupo aberto');

        $crawler = $client->request('GET', '/push-processual/processos?sub=acervo&grupo=fin&orgao=' . rawurlencode('2ª Vara Cível'));
        self::assertCount(1, $crawler->filter('[data-pp-linha-proc]'));
        self::assertSame(self::NUMERO_B, $crawler->filter('[data-pp-linha-proc]')->attr('data-numero'));
    }

    #[Test]
    public function acervoSoListaRespondidosDosUltimos10Dias(): void
    {
        [$client, $tenant] = $this->entrar();
        $hoje = new \DateTimeImmutable('today');
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->criarProcesso($tenant, self::NUMERO_B);
        $this->publicar($tenant, self::NUMERO_A, '1ª Vara Cível', $hoje->modify('-20 days')->format('Y-m-d'), true, 5, $hoje->modify('-9 days')->format('Y-m-d'));
        $this->publicar($tenant, self::NUMERO_B, '1ª Vara Cível', $hoje->modify('-40 days')->format('Y-m-d'), true, 5, $hoje->modify('-11 days')->format('Y-m-d'));
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos?sub=acervo&grupo=resp&orgao=' . rawurlencode('1ª Vara Cível'));

        self::assertSame([self::NUMERO_A], $crawler->filter('[data-pp-linha-proc]')->each(static fn ($n) => $n->attr('data-numero')));
    }

    #[Test]
    public function acervoBuscaPorNumeroEProcessoSemPublicacaoFicaSemPrazo(): void
    {
        [$client, $tenant] = $this->entrar();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->criarProcesso($tenant, self::NUMERO_B);

        $crawler = $client->request('GET', '/push-processual/processos?sub=acervo&grupo=sem&q=0703333');

        self::assertSame([self::NUMERO_B], $crawler->filter('[data-pp-linha-proc]')->each(static fn ($n) => $n->attr('data-numero')));
    }
}
