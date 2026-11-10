<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Ponto\Controller\ConfiguracoesDoPontoController;
use App\Ponto\Controller\FuncaoDoPontoController;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\GestaoDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E7 "Liga e desliga" do Ponto (spec docs/specs/ponto-desenho-0811.md, P67, P95 e P96): a tela `ponto` no catálogo do
 * Master, a função `ponto-geo` nas duas camadas (Global do Master e Escritório) e o efeito dela na batida. O cartão do
 * `ponto-comprovante` (E15) na mesma seção: a ordem do desenho, a chave do escritório e a trava do Master.
 *
 * O arranjo da seção é conferido por filho direto (`>`). O CSRF é previsível (`TOKEN_<id>`).
 */
#[CoversClass(FuncaoDoPontoController::class)]
#[CoversClass(ConfiguracoesDoPontoController::class)]
#[CoversClass(PontoController::class)]
#[CoversClass(ConfiguracaoDoPontoRepository::class)]
#[Group('ponto')]
final class LigaEDesligaDoPontoTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const FUNCOES = '.pe-config > .pe-config-funcoes[data-config-funcoes]';
    private const LINHA = self::FUNCOES . ' > .pe-config-funcao[data-config-funcao="ponto-geo"]';
    private const COMPROVANTE = self::FUNCOES . ' > .pe-config-funcao[data-config-funcao="ponto-comprovante"]';
    private const TRAVA = 'Desativado pelo Master para todos os escritórios.';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $primario;
    private ?Crawler $painel = null;

    protected function setUp(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 5) {
            self::markTestSkipped('Nos primeiros 5 minutos do dia as batidas "de hoje" cairiam em ontem.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Liga');
        $this->primario = $this->pessoa('primario');
        $this->vincularPrimario($this->primario, $this->escritorio);
    }

    #[TestDox('Tela `ponto` desligada: quem não é Master recebe "em preparação" (503) na /ponto e na batida; as três rotas globais respondem; o menu tira o item')]
    public function testTelaDesligadaBarraQuemNaoEMaster(): void
    {
        $this->desligar('ponto');
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->entrar($colaborador);

        $this->client->request('GET', '/ponto/');
        self::assertResponseStatusCodeSame(503);
        $this->client->request('POST', '/ponto/batida', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], content: '{"tipo":"entrada"}');
        self::assertResponseStatusCodeSame(503);
        self::assertArrayHasKey('erro', $this->json());
        self::assertSame(0, $this->batidas($colaborador), 'nada gravou');

        foreach (['/ponto/estado-do-dia', '/ponto/alerta-horario', '/ponto/pendencia-do-dia'] as $rota) {
            $this->client->request('GET', $rota, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful($rota . ' fica aberta com a tela desligada');
        }

        $this->entrar($this->primario);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(503, 'a chave do escritório cai com a tela');
        $tela = $this->client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tela->filter('.pje-menu-list a[href="/ponto/"]'), 'o item do Ponto some do menu');
    }

    #[TestDox('Tela `ponto` desligada: o Master entra na /ponto e vê a faixa vermelha e o item marcado no menu')]
    public function testMasterVeATelaDesligada(): void
    {
        $this->desligar('ponto');
        $master = $this->master();

        $this->entrar($master);
        $tela = $this->client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        self::assertSame('Esta tela está desativada para os usuários. Só o Master está vendo.', $tela->filter('[data-recurso-faixa].bj-recurso-faixa')->text());
        self::assertCount(1, $tela->filter('.pje-menu-list > li.pje-menu-item.bj-recurso-off > a[href="/ponto/"]'));
    }

    #[TestDox('A seção das funções aparece para o perfil com admin.ponto.manage, o primário e o Master; o Master vê também a chave Global')]
    public function testSecaoParaQuemAdministraOPonto(): void
    {
        $perfil = $this->pessoa('perfil');
        $this->vincular($perfil, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::SEDES], false);
        $master = $this->master();

        foreach ([$perfil, $this->primario] as $quem) {
            $linha = $this->linha($quem);
            self::assertCount(1, $linha);
            self::assertSame(
                'Usuário primário: define como o ponto funciona no seu escritório, dentro do que o Master liberou.',
                trim($this->painel->filter('.pe-config > .pe-config-funcoes > .pe-config-ajuda')->text()),
            );
            self::assertSame(['pe-config-icone', 'pe-config-texto', 'pe-config-chave', 'pe-config-trava', 'pe-config-erro'], $this->filhos($linha));
            self::assertCount(0, $linha->filter('[data-config-global]'), 'sem a chave Global');
        }

        $linha = $this->linha($master);
        self::assertSame(
            'Master: a chave Global libera ou bloqueia a função para todos os escritórios. Com a função liberada, cada usuário primário decide o uso no seu escritório.',
            trim($this->painel->filter('.pe-config > .pe-config-funcoes > .pe-config-ajuda')->text()),
        );
        self::assertSame(['pe-config-icone', 'pe-config-texto', 'pe-config-chave', 'pe-config-chave', 'pe-config-trava', 'pe-config-erro'], $this->filhos($linha));
        self::assertCount(1, $linha->filter('.pe-config-funcao > i.pe-config-icone.bi.bi-geo-alt:first-child[aria-hidden="true"]'));
        self::assertSame('Geolocalização', $linha->filter('.pe-config-funcao > .pe-config-texto > b')->text());
        self::assertSame('Exige que o registro seja feito dentro do raio de uma sede.', $linha->filter('.pe-config-funcao > .pe-config-texto > b + span')->text());
        $global = $linha->filter('.pe-config-funcao > .pe-config-texto + .pe-config-chave > span:first-child + button.pe-config-switch[role="switch"]');
        self::assertSame('Global', $linha->filter('.pe-config-funcao > .pe-config-texto + .pe-config-chave > span:first-child')->text());
        self::assertSame(['true', 'Geolocalização: liberar para todos', '/master/recursos/ponto-geo'], [$global->attr('aria-checked'), $global->attr('aria-label'), $global->attr('data-config-global')]);
        self::assertNotEmpty($global->attr('data-token'), 'o token do Master vai junto da chave Global');
        $escritorio = $linha->filter('.pe-config-funcao > .pe-config-chave + .pe-config-chave > span:first-child + button.pe-config-switch[role="switch"]');
        self::assertSame('Escritório', $linha->filter('.pe-config-funcao > .pe-config-chave + .pe-config-chave > span:first-child')->text());
        self::assertSame(['true', 'Geolocalização: usar no escritório', '/ponto/configuracoes/funcoes/ponto-geo', '1', null], [$escritorio->attr('aria-checked'), $escritorio->attr('aria-label'), $escritorio->attr('data-config-escritorio'), $escritorio->attr('data-ligada'), $escritorio->attr('disabled')]);
        self::assertCount(1, $linha->filter('.pe-config-funcao > span.pe-config-trava[hidden]'), 'chave nova: nasce ligada e sem trava');
        self::assertSame(self::TRAVA, $linha->filter('.pe-config-funcao > span.pe-config-trava')->text());
    }

    #[TestDox('Quem só cuida dos feriados vê a aba sem a seção das funções; o colaborador recebe 403 no painel e na gravação')]
    public function testSemASecao(): void
    {
        $feriados = $this->pessoa('feriados');
        $this->vincular($feriados, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->entrar($feriados);
        $painel = $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $painel->filter('[data-config-funcoes]'));
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(403);

        $this->entrar($colaborador);
        $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Só quem administra o ponto do escritório pode mudar esta configuração.', $this->json()['erro']);
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('O primário desliga e religa a geolocalização do escritório: a linha nasce no primeiro salvamento, a mudança vai para o audit_log e para a trilha do Ponto ("Configuração do escritório")')]
    public function testPrimarioDesligaEReliga(): void
    {
        $this->entrar($this->primario);

        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseIsSuccessful();
        self::assertSame(['chave' => 'ponto-geo', 'global' => true, 'escritorio' => false], $this->json());
        $linha = $this->configuracao($this->escritorio);
        self::assertSame(['ponto-geo' => false], $linha['chaves']);
        self::assertSame($this->primario->getId(), $linha['atualizado_por_id']);
        self::assertSame([150, 120, 6, true], [$linha['raio_padrao_metros'], $linha['banco_limite_horas'], $linha['banco_prazo_meses'], $linha['permite_trabalho_externo']]);
        $trilha = $this->emSemFiltro()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDoPonto::class, 'tenantId' => $this->escritorio->getId()]);
        self::assertCount(1, $trilha, 'a primeira mudança vai para a trilha do escritório (a linha nasce vazia, por SQL)');

        $tela = $this->linha($this->primario);
        self::assertSame(['false', '0'], [$tela->filter('[data-config-escritorio]')->attr('aria-checked'), $tela->filter('[data-config-escritorio]')->attr('data-ligada')]);

        $this->entrar($this->primario);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":true}');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['escritorio']);
        self::assertSame(['ponto-geo' => true], $this->configuracao($this->escritorio)['chaves']);
        self::assertCount(2, $this->emSemFiltro()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDoPonto::class, 'tenantId' => $this->escritorio->getId()]));

        // A trilha do Ponto (E9), com o texto do desenho (dc L761) e o primário como autor.
        $eventos = $this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT acao, autor_id, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->escritorio->getId()],
        );
        self::assertSame(['configuracao_do_escritorio', 'configuracao_do_escritorio'], array_column($eventos, 'acao'));
        self::assertSame([$this->primario->getId(), $this->primario->getId()], array_map('intval', array_column($eventos, 'autor_id')));
        self::assertSame(
            ['Geolocalização desativado', 'Geolocalização ativado'],
            array_map(static fn (array $e): string => DetalheDaTrilhaDoPonto::texto($e['acao'], json_decode((string) $e['detalhe'], true)), $eventos),
        );
    }

    #[TestDox('A trilha do Ponto que falha desfaz a mudança da chave: mesma transação, nada gravado')]
    public function testTrilhaQueFalhaNaoMudaAChave(): void
    {
        $this->entrar($this->primario);
        $container = static::getContainer();
        $falsa = new class ($container->get(ManagerRegistry::class)) extends TrilhaDoPontoRepository {
            public int $chamadas = 0;

            public function inserir(array $linha): void
            {
                ++$this->chamadas;

                throw new \RuntimeException('Falha simulada da trilha.');
            }
        };
        $container->set(TrilhaDoPontoRepository::class, $falsa);

        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $falsa->chamadas, 'o erro veio da trilha simulada, depois de gravar a chave, e não de antes');
        $linha = $this->configuracao($this->escritorio);
        self::assertTrue($linha === null || !\array_key_exists('ponto-geo', $linha['chaves']), 'a chave voltou com a transação');
        self::assertSame(0, (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_auditoria WHERE tenant_id = ?', [$this->escritorio->getId()]));
    }

    #[TestDox('Sem linha, o repositório cria a do escritório com os padrões e a trava (SELECT ... FOR UPDATE); de novo, devolve a mesma, sem duplicar nem sobrescrever')]
    public function testLinhaGarantidaETravadaSemDuplicar(): void
    {
        $repositorio = static::getContainer()->get(ConfiguracaoDoPontoRepository::class);
        self::assertFalse($this->tabelaTravadaPorMim(), 'controle: nada travado antes');

        $criada = $repositorio->emTransacao(fn (): ConfiguracaoDoPonto => $repositorio->garantidaETravada($this->escritorio, new \DateTimeImmutable('2026-11-09 10:00:00')));

        self::assertTrue($this->tabelaTravadaPorMim(), 'a linha garantida sai travada');
        $linha = $this->configuracao($this->escritorio);
        self::assertSame(
            [$criada->getId(), [], 150, 120, 6, true, null, '2026-11-09 10:00:00'],
            [$linha['id'], $linha['chaves'], $linha['raio_padrao_metros'], $linha['banco_limite_horas'], $linha['banco_prazo_meses'], $linha['permite_trabalho_externo'], $linha['atualizado_por_id'], $linha['atualizado_em']],
            'os padrões da entidade e da migration; mapa vazio (tudo ligado, P96)',
        );

        $denovo = $repositorio->emTransacao(fn (): ConfiguracaoDoPonto => $repositorio->garantidaETravada($this->escritorio, new \DateTimeImmutable('2026-11-09 11:00:00')));

        self::assertSame($criada->getId(), $denovo->getId());
        self::assertSame(1, $this->linhasDe($this->escritorio), 'a segunda chamada não duplica');
        self::assertSame('2026-11-09 10:00:00', $this->configuracao($this->escritorio)['atualizado_em'], 'nem sobrescreve a linha que existe');
    }

    #[TestDox('Corrida do primeiro salvamento: a linha que outro pedido gravou por fora do ORM não estoura a gravação (ON CONFLICT), volta relida e recebe a mudança, sem duplicar')]
    public function testLinhaGravadaPorOutroPedidoNaoEstoura(): void
    {
        $this->emSemFiltro()->getConnection()->executeStatement(
            'INSERT INTO ponto_config_escritorio (tenant_id, chaves, atualizado_em) VALUES (?, ?, LOCALTIMESTAMP(0))',
            [$this->escritorio->getId(), '{"ponto-geo":false}'],
        );
        $this->entrar($this->primario);

        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":true}');

        self::assertResponseIsSuccessful();
        self::assertSame(['chave' => 'ponto-geo', 'global' => true, 'escritorio' => true], $this->json());
        self::assertSame(1, $this->linhasDe($this->escritorio));
        $linha = $this->configuracao($this->escritorio);
        self::assertSame([['ponto-geo' => true], $this->primario->getId()], [$linha['chaves'], $linha['atualizado_por_id']], 'a mudança entrou na linha do outro pedido, relida do banco');
    }

    #[TestDox('Global desligado pelo Master trava a chave do escritório: desligada na tela, com a frase do Master, e a gravação recusada (422)')]
    public function testGlobalDesligadoTravaOEscritorio(): void
    {
        $this->desligar('ponto-geo');
        $master = $this->master();

        $linha = $this->linha($this->primario);
        $chave = $linha->filter('.pe-config-funcao > .pe-config-chave > button[data-config-escritorio]');
        self::assertSame(['false', 'disabled'], [$chave->attr('aria-checked'), $chave->attr('disabled')]);
        self::assertCount(1, $linha->filter('.pe-config-funcao > span.pe-config-trava:not([hidden])'));
        self::assertSame(self::TRAVA, $linha->filter('.pe-config-funcao > span.pe-config-trava')->text());

        $this->entrar($this->primario);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => self::TRAVA, 'global' => false], $this->json(), 'a tela trava a chave na hora com o `global: false`');
        self::assertNull($this->configuracao($this->escritorio), 'nada gravou');

        $global = $this->linha($master)->filter('[data-config-global]');
        self::assertSame('false', $global->attr('aria-checked'), 'o Master vê o Global desligado');
    }

    #[TestDox('Com o selo pronto, o cartão "Comprovante em imagem" vem antes do da Geolocalização, como o desenho (FEATS, L414 a L423)')]
    public function testCartaoDoComprovanteAntesDaGeolocalizacao(): void
    {
        $this->seloPronto();

        $cartao = $this->linha($this->primario, self::COMPROVANTE);

        self::assertSame(['ponto-comprovante', 'ponto-geo', 'ponto-alertas'], $this->funcoesNoPainel());
        self::assertCount(1, $this->painel->filter(self::COMPROVANTE . ' + .pe-config-funcao[data-config-funcao="ponto-geo"]'), 'irmãos diretos, nesta ordem');
        self::assertSame(['pe-config-icone', 'pe-config-texto', 'pe-config-chave', 'pe-config-trava', 'pe-config-erro'], $this->filhos($cartao));
        self::assertCount(1, $cartao->filter('.pe-config-funcao > i.pe-config-icone.bi.bi-receipt:first-child[aria-hidden="true"]'));
        self::assertSame('Comprovante em imagem', $cartao->filter('.pe-config-funcao > .pe-config-texto > b')->text());
        self::assertSame("Imagem em alta definição com código de validação, QR Code, marca d'água e padrões de segurança.", $cartao->filter('.pe-config-funcao > .pe-config-texto > b + span')->text());
        $chave = $cartao->filter('.pe-config-funcao > .pe-config-texto + .pe-config-chave > span:first-child + button.pe-config-switch[role="switch"]');
        self::assertSame(['true', 'Comprovante em imagem: usar no escritório', '/ponto/configuracoes/funcoes/ponto-comprovante', '1', null], [$chave->attr('aria-checked'), $chave->attr('aria-label'), $chave->attr('data-config-escritorio'), $chave->attr('data-ligada'), $chave->attr('disabled')]);
        self::assertCount(1, $cartao->filter('.pe-config-funcao > span.pe-config-trava[hidden]'), 'nasce ligado e sem trava');
    }

    #[TestDox('Sem o selo do comprovante (segredo do HMAC ausente): o cartão do comprovante não aparece, só os da Geolocalização e dos Alertas de intervalo')]
    public function testSemSeloSemOCartaoDoComprovante(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto(''));

        $this->linha($this->primario);

        self::assertSame(['ponto-geo', 'ponto-alertas'], $this->funcoesNoPainel(), 'como a aba Comprovantes, o cartão some sem o selo; os alertas (E11) ficam');
    }

    #[TestDox('O primário desliga o comprovante do escritório: a chave grava e a aba Comprovantes some da /ponto')]
    public function testPrimarioDesligaOComprovante(): void
    {
        $this->seloPronto();
        self::assertCount(1, $this->telaDoPonto()->filter('.pe-abas-barra > button#ponto-aba-comprovantes'), 'controle: ligado, a aba aparece');

        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-comprovante', server: $this->ajax(), content: '{"ligada":false}');

        self::assertResponseIsSuccessful();
        self::assertSame(['chave' => 'ponto-comprovante', 'global' => true, 'escritorio' => false], $this->json());
        self::assertSame(['ponto-comprovante' => false], $this->configuracao($this->escritorio)['chaves']);
        $chave = $this->linha($this->primario, self::COMPROVANTE)->filter('[data-config-escritorio]');
        self::assertSame(['false', '0'], [$chave->attr('aria-checked'), $chave->attr('data-ligada')]);
        self::assertCount(0, $this->telaDoPonto()->filter('#ponto-aba-comprovantes, #ponto-painel-comprovantes, [data-validar-abrir]'), 'a aba e o "Validar código" somem (desenho L447 e L757)');
    }

    #[TestDox('Global do comprovante desligado pelo Master: o cartão do escritório fica travado, com a frase do Master, e a gravação é recusada (422)')]
    public function testGlobalDoComprovanteDesligadoTrava(): void
    {
        $this->seloPronto();
        $this->desligar(FuncoesDoPonto::COMPROVANTE);
        $master = $this->master();

        $cartao = $this->linha($this->primario, self::COMPROVANTE);
        $chave = $cartao->filter('.pe-config-funcao > .pe-config-chave > button[data-config-escritorio]');
        self::assertSame(['false', 'disabled'], [$chave->attr('aria-checked'), $chave->attr('disabled')]);
        self::assertCount(1, $cartao->filter('.pe-config-funcao > span.pe-config-trava:not([hidden])'));
        self::assertSame(self::TRAVA, $cartao->filter('.pe-config-funcao > span.pe-config-trava')->text());
        self::assertCount(1, $this->painel->filter(self::LINHA . ' > span.pe-config-trava[hidden]'), 'a geolocalização não trava junto');

        $this->entrar($this->primario);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-comprovante', server: $this->ajax(), content: '{"ligada":true}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => self::TRAVA, 'global' => false], $this->json());
        self::assertNull($this->configuracao($this->escritorio), 'nada gravou');
        self::assertSame('false', $this->linha($master, self::COMPROVANTE)->filter('[data-config-global]')->attr('aria-checked'), 'o Master vê o Global desligado');
    }

    #[TestDox('Recusas do pedido: CSRF inválido (403), corpo sem `ligada` (422), função sem a camada Escritório (422)')]
    public function testRecusasDoPedido(): void
    {
        $this->entrar($this->primario);

        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":"nao"}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-facial', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Função do ponto desconhecida.'], $this->json(), 'só a trava do Master leva o `global`');
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(422, 'a tela não tem camada Escritório');
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('Isolamento: a chave de um escritório não muda o outro, e o escritório é sempre o da sessão')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $outro = $this->escritorio('Escritório Vizinho');
        $this->vincularColaborador($this->primario, $outro);   // primário aqui, colaborador lá

        $this->entrar($this->primario);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false,"tenant":' . $outro->getId() . '}');
        self::assertResponseIsSuccessful();
        self::assertSame(['ponto-geo' => false], $this->configuracao($this->escritorio)['chaves']);
        self::assertNull($this->configuracao($outro), 'o id no corpo não escolhe o escritório');

        $this->logarComTenant($this->client, $this->primario, $outro);
        $this->client->request('POST', '/ponto/configuracoes/funcoes/ponto-geo', server: $this->ajax(), content: '{"ligada":false}');
        self::assertResponseStatusCodeSame(403, 'no outro escritório ele é colaborador');
        self::assertNull($this->configuracao($outro));

        $funcoes = static::getContainer()->get(FuncoesDoPonto::class);
        self::assertTrue($funcoes->vale(FuncoesDoPonto::GEO, $outro));
    }

    #[TestDox('Geolocalização desligada no escritório: a batida sem posição entra, sem sede e sem home office; a posição que vier é descartada; o outro escritório (ligado) continua exigindo a cerca')]
    public function testBatidaComAGeolocalizacaoDesligada(): void
    {
        $outro = $this->escritorio('Escritório Com Cerca');
        $this->sede($this->escritorio);
        $this->sede($outro);
        $this->desligarNoEscritorio($this->escritorio);
        $ana = $this->pessoa('ana');
        $this->vincularColaborador($ana, $this->escritorio);
        $bia = $this->pessoa('bia');
        $this->vincularColaborador($bia, $outro);

        $this->entrar($ana);
        $resposta = $this->bater(['tipo' => 'entrada']);
        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame('Ponto registrado com sucesso!', $resposta['message']);
        self::assertArrayNotHasKey('sede', $resposta['data']);
        $registro = $this->ultimaBatida($ana);
        self::assertSame([false, null, null, null], [$registro['home_office'], $registro['sede_id'], $registro['latitude'], $registro['sede_nome_snapshot']]);

        // A posição que vier é descartada, como no home office (o escritório escolheu não usar localização), sem recusa.
        $resposta = $this->bater(['tipo' => 'repouso', 'latitude' => -23.55, 'longitude' => -46.63, 'precisaoGps' => 25.0]);
        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertArrayNotHasKey('sede', $resposta['data'], 'nem dentro do raio da sede a cerca é conferida');
        $registro = $this->ultimaBatida($ana);
        self::assertSame(
            [null, false, null, null, null, null],
            [$registro['sede_id'], $registro['home_office'], $registro['latitude'], $registro['longitude'], $registro['precisao_gps'], $registro['sede_nome_snapshot']],
        );

        $this->logarComTenant($this->client, $bia, $outro);
        self::assertSame('Geolocalização é obrigatória para registrar o ponto.', $this->bater(['tipo' => 'entrada'])['message']);
        self::assertResponseStatusCodeSame(422);
        $fora = $this->bater(['tipo' => 'entrada', 'latitude' => -10.0, 'longitude' => -40.0, 'precisaoGps' => 10.0]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Você está fora do raio permitido', $fora['foraDoRaio']['titulo'], 'a cerca do outro escritório continua valendo (E12)');
        self::assertStringStartsWith('Você está fora do raio permitido. A sede mais próxima é ', $fora['message']);
        self::assertSame(0, $this->batidas($bia));
    }

    #[TestDox('Geolocalização desligada pelo Master (Global): a batida sem posição entra em qualquer escritório; o estado do dia avisa a tela')]
    public function testBatidaComOGlobalDesligado(): void
    {
        $this->sede($this->escritorio);
        $this->desligar('ponto-geo');
        $ana = $this->pessoa('ana');
        $this->vincularColaborador($ana, $this->escritorio);
        $this->entrar($ana);

        self::assertTrue($this->bater(['tipo' => 'entrada'])['success']);
        $this->client->request('GET', '/ponto/estado-do-dia');
        $estado = $this->json();
        self::assertFalse($estado['geoLigado']);
        self::assertSame([], $estado['sedes'], 'sem cerca a avaliar');
    }

    #[TestDox('A /ponto com a geolocalização desligada não tem a caixa da localização e avisa o JS; ligada, tudo como antes')]
    public function testTelaSemACaixaDaLocalizacao(): void
    {
        $outro = $this->escritorio('Escritório Ligado');
        $this->desligarNoEscritorio($this->escritorio);
        $ana = $this->pessoa('ana');
        $this->vincularColaborador($ana, $this->escritorio);
        $this->vincularColaborador($ana, $outro);
        $corpo = '.pe-page > .pe-grade > .pe-esq > section.pe-registrar > .pe-corpo';

        $this->entrar($ana);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tela->filter('#gps-status'));
        self::assertCount(1, $tela->filter($corpo . ' > #ponto-tipo + #ponto-aviso-dia'), 'a caixa sai sem deixar buraco');
        self::assertStringContainsString('const geoLigado = false;', (string) $this->client->getResponse()->getContent());

        $this->logarComTenant($this->client, $ana, $outro);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter($corpo . ' > #ponto-tipo + #gps-status.pe-caixa'));
        self::assertStringContainsString('const geoLigado = true;', (string) $this->client->getResponse()->getContent());
    }

    /** O painel da aba Configurações de quem pede, e a linha de uma função nele (a geolocalização, sem dizer qual). */
    private function linha(User $quem, string $seletor = self::LINHA): Crawler
    {
        $this->entrar($quem);
        $this->painel = $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseIsSuccessful();

        return $this->painel->filter($seletor);
    }

    /** @return list<string> as chaves dos cartões do último painel aberto, na ordem da tela */
    private function funcoesNoPainel(): array
    {
        self::assertNotNull($this->painel);

        return $this->painel->filter(self::FUNCOES . ' > .pe-config-funcao')->each(static fn (Crawler $no): string => (string) $no->attr('data-config-funcao'));
    }

    /** A /ponto do primário, logado no escritório (a sessão segue para o pedido seguinte). */
    private function telaDoPonto(): Crawler
    {
        $this->entrar($this->primario);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** O segredo do Ponto configurado (as chaves da E9): o selo do comprovante fica pronto. */
    private function seloPronto(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto('segredo-de-teste-do-comprovante'));
    }

    /** @return list<string> a primeira classe de cada filho direto da linha */
    private function filhos(Crawler $linha): array
    {
        return $linha->children()->each(static fn (Crawler $no): string => explode(' ', (string) $no->attr('class'))[0]);
    }

    private function entrar(User $quem): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
    }

    private function master(): User
    {
        $master = $this->pessoa('master', ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->vincularPrimario($master, $this->escritorio);

        return $master;
    }

    /** @return array<string, string> */
    private function ajax(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function desligar(string $chave): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => $chave]) ?? new RecursoEstado($chave);
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    private function desligarNoEscritorio(Tenant $tenant): void
    {
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir(FuncoesDoPonto::GEO, false, $this->primario, new \DateTimeImmutable());
        $this->em()->persist($configuracao);
        $this->em()->flush();
    }

    private function linhasDe(Tenant $tenant): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_config_escritorio WHERE tenant_id = ?', [$tenant->getId()]);
    }

    /**
     * O SELECT ... FOR UPDATE deixa um RowShareLock na tabela até o fim da transação do teste (o DAMA não a fecha), e
     * só ele o pega: o INSERT pega RowExclusiveLock.
     */
    private function tabelaTravadaPorMim(): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid() AND relation = 'ponto_config_escritorio'::regclass AND mode = 'RowShareLock')",
        );
    }

    /** @return array<string, mixed>|null a linha crua, sem o filtro do escritório */
    private function configuracao(Tenant $tenant): ?array
    {
        $linha = $this->emSemFiltro()->getConnection()->fetchAssociative('SELECT * FROM ponto_config_escritorio WHERE tenant_id = ?', [$tenant->getId()]);
        if ($linha === false) {
            return null;
        }
        $linha['chaves'] = json_decode((string) $linha['chaves'], true);

        return $linha;
    }

    private function sede(Tenant $tenant): void
    {
        $sede = new Sede();
        $sede->setNome('Sede ' . uniqid());
        $sede->setLatitude('-23.55000000');
        $sede->setLongitude('-46.63000000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $this->em()->persist($sede);
        $this->em()->flush();
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function bater(array $corpo): array
    {
        $this->client->request('POST', '/ponto/batida', server: $this->ajax(), content: (string) json_encode($corpo));

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function ultimaBatida(User $quem): array
    {
        return (array) $this->emSemFiltro()->getConnection()->fetchAssociative(
            'SELECT home_office, sede_id, sede_nome_snapshot, latitude, longitude, precisao_gps FROM registro_ponto WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$quem->getId()],
        );
    }

    private function batidas(User $quem): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE user_id = ?', [$quem->getId()]);
    }
}
