<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\ContribuaController;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Twig\AvisoDeEspacoExtension;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Master\UseCase\AlterarConvenioAtivoUseCase;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(ContribuaController::class)]
#[CoversClass(AvisoDeEspacoExtension::class)]
final class ContribuaControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $primario;
    private User $secundario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->secundario = $this->pessoa('secundario', $this->escritorio, false);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
        // A campanha (BlueJus Black) ligada acrescenta faixa, FAQ e painel; os testes dela ficam no CampanhaTelaTest. A da migration nasce desligada.
        $this->em->getConnection()->executeStatement('UPDATE contribua_campanha SET ligada = false');
        $this->em->persist(new Sugestao($this->outro, null, 'Pessoa do B', TipoDeSugestao::Falta, 'Sugestão que só o B e o Master veem'));
        $this->em->flush();
    }

    #[TestDox('A página abre com os textos do desenho e o PIX do escritório')]
    public function testPaginaAbre(): void
    {
        $this->liberarOpcoes($this->escritorio);
        $html = $this->abrir($this->primario);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('BlueJus, o escritório digital', $html);
        self::assertStringContainsString('RECOMENDADO PARA VOCÊ', $html);
        self::assertStringContainsString('Quero +10 GB', $html);
        self::assertStringContainsString('PIX válido · conta Nº ' . str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT), $html);
        self::assertStringContainsString('Já contribuí, registrar meu apoio', $html);
        self::assertStringContainsString('Quem somos e contribua', $html, 'título na barra azul');
        self::assertStringNotContainsString('Marcar atendida', $html);
    }

    #[TestDox('Indique e ganhe no topo: saldo, prêmio, cupom BJ- do link curto, copiar, compartilhar e contadores; dica "ganhe saldo"')]
    public function testIndiqueEGanhe(): void
    {
        $html = $this->abrir($this->primario);

        self::assertSelectorCount(1, '.ct-pagina > section#indique.ct-indique');
        self::assertSelectorCount(0, '#indique.ct-indique--off', 'ligado: sem a marca de desligado');
        self::assertSelectorNotExists('#indique [data-indique-master]', 'só o Master vê a faixa da chave');
        self::assertSelectorTextContains('.ct-indique > .ct-indique-saldo .ct-indique-rotulo', 'SEU SALDO DE RECOMPENSA');
        self::assertSelectorTextSame('.ct-indique-valor', 'R$ 0,00');
        self::assertSelectorTextContains('.ct-indique-situacao', 'Libera com 10 escritórios ativos com pastas · você tem 0');
        self::assertSelectorTextContains('.ct-indique-titulo', 'Indique o BlueJus e ganhe R$ 10 por escritório');
        self::assertMatchesRegularExpression('#data-cupom>BJ-[A-HJ-NP-Z2-9]{8}<#', $html);
        self::assertSelectorTextContains('.ct-indique-cupom > [data-copiar-cupom]', 'Copiar cupom');
        self::assertSelectorTextContains('.ct-indique-cupom > .ct-indique-compartilhar[data-ind-abrir]', 'Compartilhar');
        self::assertSelectorTextContains('.ct-indique-contadores', '0 convite(s) enviado(s)');
        self::assertSelectorTextContains('.ct-indique-contadores', '0 aguardando confirmação');
        self::assertSelectorTextContains('.ct-indique-contadores', '0 conta(s) aberta(s)');
        self::assertSelectorExists('.bj-share[data-tip="Indique o BlueJus e ganhe saldo"]');
        // VS 03.11.26: o texto saiu de baixo do título e virou balão no próprio título, com a validade de 3 meses.
        self::assertSelectorCount(0, '.ct-indique-texto');
        $titulo = $this->client->getCrawler()->filter('.ct-indique-titulo');
        self::assertStringContainsString('você ganha crédito quando o escritório indicado começar a usar o BlueJus. O crédito expira em 3 meses.', (string) $titulo->attr('data-tip'));
        self::assertSame($titulo->attr('data-tip'), $titulo->attr('title'));
        self::assertStringNotContainsString('O saldo fica guardado', $html);
        self::assertStringNotContainsString('nunca expira', $html);
        self::assertStringNotContainsString('—', $this->client->getCrawler()->filter('#indique')->text());
    }

    #[TestDox('Com o programa de recompensa desligado no Master, o cartão do saldo some')]
    public function testIndiqueSomeComProgramaDesligado(): void
    {
        $programa = $this->em->find(ProgramaDeRecompensa::class, ProgramaDeRecompensa::ID_UNICO) ?? new ProgramaDeRecompensa();
        $programa->alterarRegras(false, 1000, 10, 0, 50, true);
        $this->em->persist($programa);
        $this->em->flush();

        $this->abrir($this->primario);

        self::assertSelectorNotExists('#indique');
        self::assertSelectorExists('.ct-pagina');
    }

    #[TestDox('A ordem das seções é a do desenho de 14 e 15/11, todas filhas diretas de .ct-pagina')]
    public function testOrdemDasSecoes(): void
    {
        $this->abrir($this->primario);

        $ids = $this->client->getCrawler()->filter('.ct-pagina > section, .ct-pagina > div.ct-numeros')->each(
            static fn ($no): string => (string) ($no->attr('id') ?: ($no->attr('class') ?? '')),
        );
        self::assertSame(
            ['ct-cartao ct-apresentacao', 'convenio', 'faq', 'quem', 'espaco', 'indique', 'ct-numeros', 'sug', 'apoie', 'ct-cartao ct-suporte'],
            $ids,
        );
        foreach (['convenio', 'faq', 'quem', 'espaco', 'indique', 'sug', 'apoie'] as $id) {
            self::assertSelectorCount(1, '.ct-pagina > section#' . $id);
        }
        self::assertSelectorNotExists('.ct-pagina > section[data-cc-integracao]', 'a integração bancária é só do Master');
    }

    #[TestDox('Integração bancária (só Master) fica por último, depois do Suporte')]
    public function testIntegracaoBancariaPorUltimoParaOMaster(): void
    {
        $this->abrir($this->master);

        self::assertSelectorCount(1, '.ct-pagina > section.ct-suporte ~ section[data-cc-integracao]:last-of-type');
    }

    #[TestDox('Apresentação: selo GRATUITO, atalhos novos e a linha "Sem mensalidade..." recolhida')]
    public function testApresentacao(): void
    {
        $html = $this->abrir($this->primario);

        self::assertSelectorTextContains('.ct-apresentacao .ct-apresentacao-linha > .ct-selo-gratuito', 'GRATUITO');
        self::assertSelectorCount(1, '.ct-selo-gratuito > i.bi-gift-fill');
        $atalhos = $this->client->getCrawler()->filter('.ct-apresentacao > .ct-atalhos > a')->each(static fn ($a): array => [$a->attr('href'), trim($a->text())]);
        self::assertSame([['#convenio', 'Por que é gratuito'], ['#faq', 'Dúvidas'], ['#espaco', 'Meu espaço'], ['#sug', 'Sugestões'], ['#apoie', 'Apoiar']], $atalhos);
        self::assertStringNotContainsString('Apoiar o sistema', $html);
        self::assertStringNotContainsString('Comprar espaço', $html);
        self::assertSelectorCount(1, '.ct-apresentacao > button.ct-gratis-botao[data-gratis][aria-expanded="false"]');
        self::assertSelectorTextContains('.ct-gratis-botao', 'Sem mensalidade, sem cartão e com todos os recursos liberados');
        self::assertSelectorTextContains('.ct-gratis-botao', 'Ver detalhes');
        self::assertSelectorExists('.ct-apresentacao > #gratis-corpo[hidden]');
        self::assertSelectorCount(4, '#gratis-corpo > .ct-gratis-item');
        foreach (['Sem mensalidade', 'Todos os recursos liberados', 'Sem cartão de crédito', '10 GB para começar', 'Prazos, processos, documentos, metas, equipe, assinatura, ponto e voto.', 'Espaço extra só se o escritório precisar, como serviço à parte.'] as $texto) {
            self::assertStringContainsString($texto, $html);
        }
    }

    #[TestDox('Faixa Convênio OAB: textos, link do WhatsApp com o endereço do sistema e "Ver benefícios" abre o modal, sem botão de copiar')]
    public function testFaixaConvenio(): void
    {
        $this->abrir($this->primario);

        self::assertSelectorTextContains('#convenio b', 'Convênio OAB: a parceria que mantém o BlueJus gratuito');
        self::assertSelectorTextContains('#convenio', 'A OAB e a tecnologia a serviço da advocacia. Os convênios com a OAB, a CAA e as subseções ajudam a manter o BlueJus gratuito para os advogados.');
        self::assertSelectorCount(1, '#convenio > .ct-convenio-logo > img[alt="OAB"]');
        $whats = $this->client->getCrawler()->filter('#convenio a.ct-convenio-whats');
        self::assertCount(1, $whats);
        self::assertSame('Recomendar à minha subseção', trim($whats->text()));
        self::assertSame('_blank', $whats->attr('target'));
        $href = (string) $whats->attr('href');
        self::assertStringStartsWith('https://wa.me/?text=', $href);
        $mensagem = rawurldecode(substr($href, strlen('https://wa.me/?text=')));
        self::assertStringStartsWith('Olá! Sou advogado(a) e uso o BlueJus, sistema gratuito de gestão', $mensagem);
        self::assertStringContainsString('Conheça: http', $mensagem, 'o endereço do sistema vem da requisição, não fixo');
        self::assertStringNotContainsString('www.bluejus.com.br', $mensagem);
        self::assertSelectorCount(1, '#convenio button.ct-convenio-ver[data-abrir-oab]');
        self::assertSelectorTextContains('#convenio button[data-abrir-oab]', 'Ver benefícios');
        self::assertSelectorNotExists('#convenio button[data-copiar-mensagem]');
        self::assertSelectorCount(2, '#convenio a, #convenio button', 'só dois botões: recomendar e ver benefícios');
    }

    #[TestDox('Perguntas frequentes: cinco <details> com as perguntas e respostas do desenho')]
    public function testPerguntasFrequentes(): void
    {
        $html = $this->abrir($this->primario);

        self::assertSelectorTextContains('#faq > .ct-faq-cabeca > b', 'Gratuito de verdade: perguntas frequentes');
        self::assertSelectorCount(5, '#faq .ct-faq-corpo > details.ct-faq');
        self::assertSelectorNotExists('#faq details[open]');
        $perguntas = $this->client->getCrawler()->filter('#faq details.ct-faq > summary')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['O BlueJus é gratuito mesmo?', 'Por que é gratuito?', 'Existe alguma coisa paga?', 'E se o meu espaço acabar?', 'Preciso contribuir para continuar usando?'], $perguntas);
        foreach ([
            'Sim. Para usar o sistema não há mensalidade, período de teste nem cartão de crédito. Todos os recursos ficam liberados para o escritório.',
            'O BlueJus nasceu para aproximar a tecnologia da advocacia. Os convênios com a OAB, a CAA e as subseções, e as contribuições voluntárias de quem quiser apoiar, ajudam a manter o sistema.',
            'Só o espaço extra de armazenamento, para quem passar dos 10 GB e quiser ampliar. É opcional e só aparece quando o escritório solicita ou chega perto do limite.',
            'Nada é apagado. Consultar e baixar arquivos continua liberado; apenas novos envios ficam pausados até liberar espaço.',
            'Não. A contribuição por PIX é voluntária e não muda nada no seu acesso.',
        ] as $resposta) {
            self::assertStringContainsString($resposta, $html);
        }
    }

    #[TestDox('Apoie começa recolhido, com o título novo, e o conteúdo (PIX, favorecido) segue no corpo')]
    public function testApoieRecolhivel(): void
    {
        $html = $this->abrir($this->primario);

        self::assertSelectorCount(1, '#apoie > button.ct-apoie-botao[data-apoie-botao][aria-expanded="false"][aria-controls="apoie-corpo"]');
        self::assertSelectorTextContains('#apoie > .ct-apoie-botao', 'Quer apoiar o BlueJus?');
        self::assertSelectorTextContains('#apoie > .ct-apoie-botao', 'contribuição voluntária por PIX, de qualquer valor. O sistema é gratuito para todos.');
        self::assertSelectorTextContains('#apoie > .ct-apoie-botao', 'Ver como apoiar');
        self::assertSelectorExists('#apoie > #apoie-corpo.ct-apoie[hidden]');
        self::assertSelectorExists('#apoie-corpo [data-copiar-codigo]');
        self::assertStringNotContainsString('Prefere apoiar sem mudar de plano?', $html);
    }

    #[TestDox('Motivo de não apoiar: "Prefiro apoiar de outra forma" no lugar de "Prefiro comprar espaço"')]
    public function testMotivoNovoDeNaoApoiar(): void
    {
        $html = $this->abrir($this->primario);

        self::assertSelectorExists('[data-motivo-opcao="Prefiro apoiar de outra forma"]');
        self::assertSelectorNotExists('[data-motivo-opcao="Prefiro comprar espaço"]');
        self::assertStringContainsString('Prefiro apoiar de outra forma', $html);

        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);
        self::assertTrue($this->post('/contribua/motivo', ['motivo' => 'Prefiro apoiar de outra forma'])['ok']);
        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM contribua_motivo_de_nao_apoiar WHERE tenant_id = :t', $this->escritorio));
    }

    #[TestDox('Chave "indique" desligada: o usuário comum não vê o painel, o botão da barra nem o item "Indicar" do menu')]
    public function testIndiqueDesligadoSomeParaUsuarioComum(): void
    {
        $this->desligarIndique();

        $html = $this->abrir($this->primario);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#indique');
        self::assertSelectorNotExists('.bj-share');
        self::assertSelectorNotExists('[data-bj-share]');
        self::assertSelectorNotExists('.mu-menu > .mu-grupo > button[data-ind-abrir]');
        self::assertStringNotContainsString('SEU SALDO DE RECOMPENSA', $html);
        self::assertSelectorExists('.ct-pagina > section#sug', 'o resto da página segue');
    }

    #[TestDox('Chave "indique" desligada: o Master ainda vê tudo, com o painel marcado e a faixa "Só Master" com a chave')]
    public function testIndiqueDesligadoParaOMaster(): void
    {
        $this->desligarIndique();

        $this->abrir($this->master);

        self::assertSelectorCount(1, '.ct-pagina > section#indique.ct-indique.ct-indique--off');
        self::assertSelectorExists('.bj-share[data-ind-abrir]');
        self::assertSelectorExists('.mu-menu > .mu-grupo > button[data-ind-abrir]');
        self::assertSelectorTextContains('#indique > .ct-indique-master > b', 'Só Master:');
        self::assertSelectorTextContains('#indique > .ct-indique-master', 'Indique e ganhe está desativado: os usuários não veem este painel nem o botão de indicação.');
        $chave = $this->client->getCrawler()->filter('#indique > .ct-indique-master > button[role="switch"]');
        self::assertCount(1, $chave);
        self::assertSame('false', $chave->attr('aria-checked'));
        self::assertSame('Mostrar Indique e ganhe para os usuários', $chave->attr('aria-label'));
        self::assertSame('/master/recursos/indique', $chave->attr('data-url'));
        self::assertNotEmpty($chave->attr('data-token'));
        self::assertSame('Desativado', trim($chave->filter('[data-indique-rotulo]')->text()));
    }

    #[TestDox('Chave "indique" ligada (padrão): o Master vê a faixa com a chave ligada; o usuário comum não vê a faixa')]
    public function testIndiqueLigadoMostraAFaixaSoParaOMaster(): void
    {
        $this->abrir($this->master);

        self::assertSelectorCount(0, '#indique.ct-indique--off');
        self::assertSelectorTextContains('#indique > .ct-indique-master', 'Indique e ganhe está visível para todos os usuários.');
        self::assertSame('true', $this->client->getCrawler()->filter('#indique [role="switch"]')->attr('aria-checked'));
        self::assertSame('Ativado', trim($this->client->getCrawler()->filter('#indique [data-indique-rotulo]')->text()));

        $this->abrir($this->primario);
        self::assertSelectorNotExists('#indique .ct-indique-master');
        self::assertSelectorExists('.mu-menu > .mu-grupo > button[data-ind-abrir]');
    }

    #[TestDox('A chave do painel liga e desliga pela rota do Master: usuário comum recebe 403; o Master grava "Desativado pelo Master"')]
    public function testChaveDoIndiquePelaRotaDoMaster(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/master/recursos/indique', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_recursos'], '{"ativo":false,"motivo":"Desativado pelo Master"}');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->client->request('POST', '/master/recursos/indique', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_recursos'], '{"ativo":false,"motivo":"Desativado pelo Master"}');
        self::assertResponseIsSuccessful();
        $resposta = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($resposta['ativo']);
        self::assertSame('Desativado pelo Master', $resposta['motivo']);

        $this->abrir($this->primario);
        self::assertSelectorNotExists('#indique');
    }

    #[TestDox('O pacote Free mostra o selo do convênio OAB, que abre o modal; os pagos mostram "+ Espaço"')]
    public function testConvenioOabEMaisEspaco(): void
    {
        $this->liberarOpcoes($this->escritorio);
        $this->abrir($this->primario);

        self::assertSelectorCount(1, '.ct-pacote > .ct-oab-selo[data-abrir-oab]');
        self::assertSelectorTextContains('.ct-oab-selo', 'CONVÊNIO OAB');
        self::assertSelectorExists('[data-modal-oab][hidden] [role="dialog"]');
        self::assertSelectorTextContains('[data-modal-oab]', 'Parabéns! Você tem o BlueJus completo, sem pagar nada.');
        self::assertSelectorCount(8, '[data-modal-oab] .ct-oab-beneficio');
        self::assertSelectorExists('.ct-pacote > .ct-nuvem');
        self::assertSelectorNotExists('.ct-pacote--atual > .ct-nuvem');
    }

    #[TestDox('Convênio OAB desligado pelo Master (M2.1): somem a seção, o atalho, o selo do Free e o modal; os pacotes continuam')]
    public function testConvenioDesligadoSomeDoContribua(): void
    {
        $this->liberarOpcoes($this->escritorio);
        static::getContainer()->get(AlterarConvenioAtivoUseCase::class)->executar(false, $this->master);

        $this->abrir($this->primario);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#convenio');
        self::assertSelectorNotExists('.ct-atalhos a[href="#convenio"]');
        self::assertSelectorNotExists('.ct-oab-selo');
        self::assertSelectorNotExists('[data-modal-oab]');
        self::assertSelectorNotExists('[data-abrir-oab]');
        self::assertSelectorExists('.ct-pacote > .ct-nuvem', 'os planos pagos seguem à venda');

        static::getContainer()->get(AlterarConvenioAtivoUseCase::class)->executar(true, $this->master);
        $this->abrir($this->primario);

        self::assertSelectorExists('#convenio');
        self::assertSelectorExists('.ct-atalhos a[href="#convenio"]');
        self::assertSelectorCount(1, '.ct-pacote > .ct-oab-selo[data-abrir-oab]');
        self::assertSelectorExists('[data-modal-oab]');
    }

    #[TestDox('O escritório não vê as sugestões de outro; o Master vê todas e pode atender')]
    public function testIsolamentoDasSugestoes(): void
    {
        self::assertStringNotContainsString('Sugestão que só o B e o Master veem', $this->abrir($this->primario));

        $doMaster = $this->abrir($this->master);
        self::assertStringContainsString('Sugestão que só o B e o Master veem', $doMaster);
        self::assertStringContainsString('Marcar atendida', $doMaster);
    }

    #[TestDox('Sugestão curta é recusada; a válida é gravada no escritório e avisa o Master')]
    public function testEnviarSugestao(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        $curta = $this->post('/contribua/sugestao', ['tipo' => 'sug', 'texto' => 'curta']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escreva um pouco mais para entendermos a sua sugestão.', $curta['erro']);

        $ok = $this->post('/contribua/sugestao', ['tipo' => 'prob', 'texto' => 'O filtro some quando volto para a lista']);
        self::assertTrue($ok['ok']);
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM contribua_sugestao WHERE tenant_id = :t AND tipo = 'prob'", $this->escritorio));
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM notificacao WHERE usuario_id = {$this->master->getId()} AND tipo = 'contribua.sugestao' AND tenant_id = :t", $this->escritorio));
    }

    #[TestDox('Atender sugestão: 403 para quem não é Master; o Master atende a de outro escritório')]
    public function testAtender(): void
    {
        $id = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM contribua_sugestao WHERE tenant_id = :t', ['t' => $this->outro->getId()]);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', "/contribua/sugestao/{$id}/atender", ['_token' => self::TOKEN, 'resposta' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->client->request('POST', "/contribua/sugestao/{$id}/atender", ['_token' => self::TOKEN, 'resposta' => 'Entrou na versão 2.4']);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['atendida' => true, 'resposta' => 'Entrou na versão 2.4'],
            static::getContainer()->get(Connection::class)->fetchAssociative('SELECT atendida, resposta FROM contribua_sugestao WHERE id = :id', ['id' => $id]),
        );
    }

    #[TestDox('Atender: o secundário também recebe 403')]
    public function testAtenderSecundario(): void
    {
        $id = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM contribua_sugestao WHERE tenant_id = :t', ['t' => $this->outro->getId()]);
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        $this->client->request('POST', "/contribua/sugestao/{$id}/atender", ['_token' => self::TOKEN]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Sugestão longa com nomes longos: grava uma vez e o aviso ao Master cabe no título')]
    public function testSugestaoLonga(): void
    {
        $this->escritorio->setName(str_repeat('Escritório Grande ', 3));
        $this->secundario->setFullName(str_repeat('Nome Comprido ', 3));
        $this->em->flush();
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        self::assertTrue($this->post('/contribua/sugestao', ['tipo' => 'sug', 'texto' => str_repeat('Texto longo de sugestão. ', 20)])['ok']);

        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM contribua_sugestao WHERE tenant_id = :t', $this->escritorio));
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM notificacao WHERE tipo = 'contribua.sugestao' AND tenant_id = :t AND char_length(titulo) <= 255", $this->escritorio));
    }

    #[TestDox('Números isolados: o escritório conta só os próprios apoios; o Master conta todos')]
    public function testNumerosIsolados(): void
    {
        $this->em->persist(new \App\Contribua\Entity\Apoio($this->outro, null, 2000));
        $this->em->flush();

        self::assertMatchesRegularExpression('#APOIOS REGISTRADOS</div><b>0</b>#', $this->abrir($this->primario));
        self::assertMatchesRegularExpression('#APOIOS REGISTRADOS</div><b>[1-9]\d*</b>#', $this->abrir($this->master));
    }

    #[TestDox('Apoio: um por mês; o segundo é recusado com o texto do desenho')]
    public function testApoioUmPorMes(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        self::assertTrue($this->post('/contribua/apoio', ['centavos' => 5000])['ok']);
        $segundo = $this->post('/contribua/apoio', ['centavos' => 5000]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Seu apoio deste mês já está registrado. Obrigado!', $segundo['erro']);
        self::assertStringContainsString('Obrigado! Apoio registrado este mês', $this->abrir($this->primario));
    }

    #[TestDox('Motivo anônimo: gravado só com o escritório e a pergunta some')]
    public function testMotivo(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);

        self::assertTrue($this->post('/contribua/motivo', ['motivo' => 'Sem orçamento agora'])['ok']);

        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM contribua_motivo_de_nao_apoiar WHERE tenant_id = :t', $this->escritorio));
        self::assertStringNotContainsString('Ainda não é o momento de contribuir?', $this->abrir($this->secundario));
    }

    #[TestDox('Pedir espaço: só o primário, e só plano maior que o atual')]
    public function testPedirEspaco(): void
    {
        $this->liberarOpcoes($this->escritorio);
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);
        $this->post('/contribua/espaco/pedido', ['plano' => 'essencial']);
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertSame('Pedido registrado. Avisaremos quando a assinatura por cartão for liberada.', $this->post('/contribua/espaco/pedido', ['plano' => 'essencial'])['mensagem']);
        $this->post('/contribua/espaco/pedido', ['plano' => 'free']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Ação sem o token CSRF é recusada')]
    public function testCsrf(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/contribua/apoio', ['_token' => 'errado', 'centavos' => 2000]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t', $this->escritorio));
    }

    #[TestDox('PIX para outro valor vem válido e com o txid da conta')]
    public function testPixPorValor(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/contribua/pix', ['_token' => self::TOKEN, 'valor' => '50']);

        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($dados['valido']);
        self::assertStringContainsString('540550.00', $dados['codigo']);
        self::assertStringContainsString('BJ' . str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT), $dados['codigo']);
    }

    #[TestDox('Aviso de espaço a partir de 90%: barra para o primário nas outras telas, nunca para o secundário nem na Contribua')]
    public function testAvisoDeEspaco(): void
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn((int) (0.93 * 10 * RetratoDoEspaco::BYTES_POR_GB));
        static::getContainer()->set(ConsumoDeArmazenamentoRepository::class, $consumo);

        $doPrimario = $this->abrir($this->primario, '/novidades');
        self::assertStringContainsString('Seu espaço está 93% ocupado', $doPrimario);
        self::assertStringContainsString('9,3 GB de 10 GB. Faltam 0,7 GB. Se precisar, solicite mais espaço.', $this->client->getCrawler()->filter('[data-aviso-espaco]')->text());
        self::assertStringNotContainsString('Seu espaço está 93% ocupado', $this->abrir($this->secundario, '/novidades'));
        self::assertStringNotContainsString('Seu espaço está 93% ocupado', $this->abrir($this->primario), 'a Contribua já mostra o mesmo conteúdo');
    }

    #[TestDox('Menu do avatar tem o item Contribua · PIX')]
    public function testItemDoMenu(): void
    {
        $html = $this->abrir($this->secundario);

        self::assertMatchesRegularExpression('#href="/contribua"[^>]*class="dropdown-item mu-item"[\s\S]*?Contribua[\s\S]*?PIX#', $html);
    }

    private function desligarIndique(): void
    {
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'indique']) ?? new RecursoEstado('indique');
        $estado->desativar('Desativado pelo Master', null, null, new \DateTimeImmutable());
        $this->em->persist($estado);
        $this->em->flush();
    }

    /** D2: o pedido de plano só passa com as opções liberadas (a trava tem teste próprio em PedidoDeEspacoControllerTest). */
    private function liberarOpcoes(Tenant $escritorio): void
    {
        $espaco = new EspacoDoEscritorio($escritorio, static::getContainer()->get(PlanoRepository::class)->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());
        $this->em->persist($espaco);
        $this->em->flush();
    }

    private function abrir(User $quem, string $url = '/contribua'): string
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $campos): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function contar(string $sql, Tenant $tenant): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne($sql, ['t' => $tenant->getId()]);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório CONTRIBUA ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, bool $primario, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid());
        $papel->setIsSystem($primario);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
