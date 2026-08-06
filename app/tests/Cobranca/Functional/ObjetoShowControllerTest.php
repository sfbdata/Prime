<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\DTO\AcordoOutput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\LiquidacaoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 2 do ajuste 2: a página unificada do objeto (`cobranca_objeto_show`). Prova que abrir o objeto
 * renderiza o corpo da cobrança, que o acesso é tenant-safe (404 cross-tenant), e que o deep-link antigo
 * do caso redireciona para o objeto.
 *
 * Ajuste 10: a página foi reorganizada pelo TRABALHO, não pelas tabelas — 3 abas (Cobrança · Documentos ·
 * Histórico), a pessoa cobrada virou card (deixou de ser aba) e a dívida é uma lista só.
 */
#[CoversClass(ObjetoController::class)]
#[CoversClass(CasoController::class)]
final class ObjetoShowControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Objeto show: renderiza identificação, abas e os vínculos da pessoa')]
    public function testShowRenderizaPaginaUnificada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoa' => $caso->getPessoaCobradaAtual(),
            'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $objeto->getIdentificacao(), $html);
        self::assertStringContainsString('Dívida em aberto', $html);
        self::assertStringContainsString('Histórico', $html);
        self::assertStringContainsString('Proprietário', $html);
    }

    #[TestDox('SPEC UX §6.2: a barra de atividades reúne as 5 áreas de conteúdo e as ações, e a pessoa cobrada segue card')]
    public function testBarraDeAtividadesReuneConteudoEAcoes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // "Ficha completa" só existe com o vínculo da pessoa cobrada resolvido (é dele que sai o id da
        // pessoa). Sem semear o vínculo a barra nasce com uma opção a menos e a contagem exata abaixo
        // mediria um cenário incompleto, não a barra da SPEC.
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $caso->getObjeto(),
            'pessoa' => $caso->getPessoaCobradaAtual(),
            'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();

        // Contagem EXATA, não só "existe". Depois da consolidação em "Responsáveis" a barra tinha 6 áreas
        // de conteúdo + 1 ação (Registrar contato) + "Mais ações" = 8 itens; com o cabeçalho redesenhado
        // (spec cabecalho-responsaveis §1.4) a ação MUDOU DE LUGAR para a barra de ações do cabeçalho, e
        // sobram 6 áreas + "Mais ações" = 7. Sem travar o número, um item duplicado a mais — ou um que
        // deveria ter saído e ficou — passaria despercebido.
        self::assertCount(7, $crawler->filter('#objetoTabs > li'), 'a barra tem 6 opções de conteúdo + Mais ações');
        self::assertCount(7, $crawler->filter('#objetoTabs > li > .nav-link'), 'cada item da barra é um nav-link');
        self::assertCount(3, $crawler->filter('#objetoTabs .cob-mais .dropdown-item'), 'o dropdown repete só as menos frequentes');
        self::assertCount(3, $crawler->filter('#objetoTabs > li.cob-item-extra'), 'as 3 que recolhem abaixo de 1200px');

        // As 6 ÁREAS DE CONTEÚDO. Dívida e Honorários seguem opções DIFERENTES, e nenhuma aba de
        // "encargos" foi criada.
        foreach (['cobranca', 'documentos', 'historico', 'responsaveis', 'divida', 'honorarios'] as $aba) {
            self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-' . $aba . '"]', "sumiu a opção {$aba} da barra");
            self::assertSelectorExists('#tab-' . $aba, "sumiu o painel da opção {$aba}");
        }
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#tab-encargos"]', 'a SPEC proíbe aba separada de encargos');

        // Registrar contato mudou de lugar: saiu da barra e passou a ser o primeiro botão da barra de
        // ações do cabeçalho (spec §1.4). O gatilho e o modal são os mesmos — só o lugar mudou. As duas
        // asserções andam JUNTAS de propósito: sozinha, a de baixo aceitaria o botão nos DOIS lugares.
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#modalRegistrarTentativa"]', 'Registrar contato tinha de sair da barra');
        self::assertSelectorExists('.content-header .cob-cab-acoes [data-bs-target="#modalRegistrarTentativa"]', 'Registrar contato tinha de aparecer nas ações do cabeçalho');

        // "Trocar responsável" e "Envolvidos" foram CONSOLIDADOS na aba Responsáveis: não podem mais
        // existir como item solto da barra, senão a consolidação não aconteceu de fato.
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#modalAlterarPessoa"]', 'Trocar responsável tinha de sair da barra');
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#vinculosObjeto"]', 'Envolvidos tinha de sair da barra');
        self::assertSelectorNotExists('#vinculosObjeto', 'o collapse de envolvidos do card lateral não existe mais');

        // O card lateral da pessoa e o cartão de próxima ação saíram; a próxima ação virou faixa dentro
        // da aba Cobrança (a função continua, o cartão não).
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#tab-pessoas"]');
        self::assertSelectorNotExists('.jp-pessoa-card', 'o card lateral da pessoa foi removido');
        self::assertSelectorNotExists('.cob-rail', 'o trilho direito foi removido');
        self::assertSelectorExists('#tab-cobranca .cob-proxima-faixa', 'a próxima ação tem de continuar visível, compacta, na aba Cobrança');
        // A subnav do módulo voltou (B3): esta página era a única que a perdia.
        self::assertSelectorExists('.cobranca-subnav');
        // E marca CARTEIRAS: o objeto se chega por Carteira→Objeto (ajuste 2, decisão G). Sem travar o
        // item ativo, a subnav podia voltar apontando para lugar nenhum e o teste acima não notaria.
        $ativo = $crawler->filter('.cobranca-subnav .cobranca-subnav-item.active');
        self::assertCount(1, $ativo, 'exatamente um item da subnav fica ativo');
        self::assertStringContainsString('Carteiras', $ativo->text());
    }

    #[TestDox('Ajuste 10: a aba Cobrança abre por padrão — o cadastro deixou de ser a primeira coisa')]
    public function testAbaCobrancaAbrePorPadrao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            '#tab-cobranca',
            $crawler->filter('#objetoTabs .nav-link.active')->attr('data-bs-target'),
        );
        self::assertSelectorExists('#tab-cobranca.show.active');
    }

    #[TestDox('Ajuste 10: a dívida mostra quanto FALTA quando há pagamento parcial')]
    public function testADividaMostraOQuantoFaltaQuandoHaPagamentoParcial(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 40000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 40000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // 1.200,00 devidos - 400,00 recebidos = faltam 800,00 (T1: `restante()`).
        self::assertStringContainsString('800,00', $crawler->filter('.jp-obr-restante')->text());
        self::assertStringContainsString('400,00', $crawler->filter('.jp-obr-sub')->text());
    }

    #[TestDox('Ajuste 10: a dívida declara a ordem (mais antiga primeiro) e mostra a data em coluna')]
    public function testDividaTemEixoTemporalEDeclaraAOrdem(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela antiga',
            'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-03-10'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // O FIFO abate a mais antiga primeiro: a lista é uma fila e diz isso (spec §4.7).
        self::assertStringContainsString(
            'Da mais antiga para a mais nova',
            $crawler->filter('#secao-divida .jp-ordem')->text(),
        );
        self::assertStringContainsString('10/03/2026', $crawler->filter('.jp-obr-data-dia')->text());
        self::assertStringContainsString('há ', $crawler->filter('.jp-obr-data-rel')->text());
    }

    #[TestDox('Caso encerrado: some o que muta a cobrança, mas o cadastro de envolvidos continua corrigível')]
    public function testCasoEncerradoEsconderApenasOQueOServidorBloqueia(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Trocar a pessoa cobrada é mutação da cobrança: sai de cena num caso encerrado.
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalAlterarPessoa"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalRegistrarObrigacao"]'));
        // Já o CADASTRO de envolvidos não depende do caso estar aberto — os UseCases de vínculo não olham
        // o encerramento, e a UI não pode ser mais restritiva que o servidor (era assim antes do Ajuste 10).
        self::assertGreaterThan(0, $crawler->filter('[data-bs-target="#modalVincularPessoaObjeto"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-bs-target="#modalFichaPessoa"]')->count());
    }

    #[TestDox('Ajuste 10: sem movimentação financeira o extrato não oferece registrar pagamento')]
    public function testSemCapacidadeFinanceiraNaoOfereceRegistrarPagamento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // `gerenciar` e `movimentacao_financeira` são capacidades SEPARADAS (SPEC §22).
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalRegistrarPagamento"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalRegistrarLiquidacao"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-bs-target="#modalRegistrarObrigacao"]')->count());
    }

    #[TestDox('Ajuste 10 B2: o botão Documentos vive dentro de um .nav-tabs com irmãos — contrato do clear da flag')]
    public function testBotaoDocumentosViveDentroDeNavTabsComIrmaos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // O `pasta-arquivos.js` é COMPARTILHADO com `pasta/show` e resolve o container das abas subindo do
        // próprio botão (`docTabBtn.closest('.nav-tabs')`) — o id do `<ul>` difere entre as duas páginas
        // (`#objetoTabs` aqui, `#pastaTabs` lá). Se o botão sair do `.nav-tabs`, o `closest` devolve null,
        // nenhum listener de limpeza é registrado e a aba Documentos volta a grudar (spec §2.1).
        self::assertCount(1, $crawler->filter('ul.nav-tabs #documentos-tab'), 'o botão tem de estar dentro do .nav-tabs');
        // Os IRMÃOS são quem limpa a flag ao serem escolhidos: sem eles no mesmo container, nada limpa.
        self::assertGreaterThan(
            1,
            $crawler->filter('#objetoTabs [data-bs-toggle="tab"]')->count(),
            'o container precisa ter outras abas — são elas que limpam a flag',
        );
    }

    #[TestDox('Ajuste 10 B1: o modal de pagamento abre com a data de hoje, como o de contato já fazia')]
    public function testModalDePagamentoAbreComADataDeHoje(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Pagamento se registra no dia em que entrou: o caso comum é "hoje" e o gestor só corrige a
        // exceção. O modal de contato já nascia preenchido (`MontadorModaisCaso::deMutacao`); o de
        // pagamento obrigava a digitar a data toda vez.
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $crawler->filter('#modalRegistrarPagamento input[type=date]')->attr('value'),
        );

        // Só o REGISTRO nasce preenchido: corrigir mexe num pagamento que já tem data própria e liquidar
        // não é pagamento — sugerir "hoje" ali convidaria a sobrescrever a data real. A rede negativa
        // protege a assimetria contra uma regressão futura no MontadorModaisCaso.
        self::assertNull(
            $crawler->filter('#modalCorrigirPagamento input[type=date]')->attr('value'),
            'Corrigir pagamento não pode sugerir "hoje": o pagamento já tem data própria.',
        );
        self::assertNull(
            $crawler->filter('#modalRegistrarLiquidacao input[type=date]')->attr('value'),
            'Registrar liquidação não é pagamento e não deve nascer com data.',
        );
    }

    #[TestDox('Melhoria UX: o modal de criar acordo abre com a data do acordo em hoje')]
    public function testModalDeAcordoAbreComADataDeHoje(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // O acordo se lavra "hoje" no caso comum; obrigar a digitar a data toda vez é atrito. Espelha o
        // modal de contato/pagamento (`MontadorModaisCaso::deMutacao`). O "1º vencimento" (input só-JS do
        // gerador) nasce em hoje+1mês no cliente — não testável aqui, coberto por smoke.
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $crawler->filter('#modalCriarAcordo input[name$="[dataAcordo]"]')->attr('value'),
        );
    }

    #[TestDox('Objeto show cross-tenant: 404')]
    public function testShowCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Ajuste 10 fix F5: o vínculo encerrado mostra o motivo do encerramento, escapado')]
    public function testVinculoEncerradoMostraOMotivoDoEncerramento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoa' => $caso->getPessoaCobradaAtual(),
            'tipoVinculo' => TipoVinculo::Representante,
            'dataFim' => new \DateTimeImmutable('-1 day'),
            'motivoEncerramento' => 'Fiança <quitada> & liberada',
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        // O vínculo encerrado deixou o card lateral e passou para a aba Responsáveis — no destaque do
        // topo quando a pessoa segue sendo a cobrada atual (encerrar o vínculo não troca quem se cobra),
        // ou no accordion quando não é. O motivo continua exibido nos dois casos, e escapado.
        $linha = $crawler->filter('#tab-responsaveis')->text();
        self::assertStringContainsString('Fiança <quitada> & liberada', $linha);
        // Além do motivo, o ESTADO tem de continuar marcado — sem isto o assert acima passaria mesmo
        // que a aba deixasse de distinguir vínculo ativo de encerrado.
        self::assertStringContainsString('encerrado', mb_strtolower($linha), 'o vínculo encerrado precisa aparecer marcado como tal');
        // Confere que o motivo foi escapado no HTML bruto (nunca `|raw`).
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Fiança &lt;quitada&gt; &amp; liberada', $html);
    }

    #[TestDox('Ajuste 10 fix F2: o extrato funde pagamentos e liquidações do mais recente para o mais antigo')]
    public function testExtratoDeMovimentosOrdenaDoMaisRecenteParaOMaisAntigo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Datas distintas e conhecidas: mais antigo → mais novo é 10/01, 05/02, 20/03.
        PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'data' => new \DateTimeImmutable('2026-01-10'),
            'valorDivida' => 10000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ]);
        LiquidacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'data' => new \DateTimeImmutable('2026-02-05'),
            'descricaoBem' => 'Veículo dado em pagamento',
            'valorAtribuidoBem' => 20000, 'valorReconhecido' => 20000,
        ]);
        PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'data' => new \DateTimeImmutable('2026-03-20'),
            'valorDivida' => 30000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Do mais recente para o mais antigo',
            $crawler->filter('#secao-movimentos .jp-ordem')->text(),
        );

        $valores = $crawler->filter('#secao-movimentos .jp-mov .jp-mov-valor')
            ->each(static fn ($node) => trim($node->text()));

        self::assertCount(3, $valores);
        // Ordem esperada: 20/03 (300,00) → 05/02 (200,00) → 10/01 (100,00).
        self::assertStringContainsString('300,00', $valores[0]);
        self::assertStringContainsString('200,00', $valores[1]);
        self::assertStringContainsString('100,00', $valores[2]);
    }

    #[TestDox('Ajuste 10 fix F1: alerta de ação atrasada oferece "Concluir" apontando para o modal')]
    public function testAlertaDeAcaoAtrasadaOfereceBotaoConcluir(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ProximaAcaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Ligar para o devedor',
            'prazo' => new \DateTimeImmutable('-3 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('.jp-alerta [data-bs-target="#modalConcluirAcao"]');
        self::assertGreaterThan(0, $botao->count(), 'o alerta de ação atrasada deve trazer o botão Concluir');
        self::assertStringContainsString('Concluir', $botao->text());
    }

    #[TestDox('Ajuste 10 fix F4: obrigação avulsa quitada com vencimento passado não é marcada como atrasada')]
    public function testObrigacaoQuitadaComVencimentoPassadoNaoFicaAtrasada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial quitada', 'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-10 days'),
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 50000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 50000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Vencida no passado, mas quitada: não é "atrasada" — quem já pagou não fica em vermelho.
        self::assertCount(0, $crawler->filter('.jp-obr-data-rel.is-atrasado'));
        // R5 (03/08): a quitada não fica mais na fila de cobrança com um chip "Paga" — ela DESCE para a
        // seção "Já pago". O assert mudou de lugar junto com a linha; o que ele guarda é o mesmo.
        self::assertCount(0, $crawler->filter('#secao-divida .jp-obr'), 'a fila de cobrança fica vazia');
        self::assertCount(1, $crawler->filter('#secao-ja-pago .jp-pagas-linha'));
        self::assertStringContainsString(
            'Cota condominial quitada',
            $crawler->filter('#secao-ja-pago .jp-pagas-linha')->text(),
        );
    }

    #[TestDox('Ajuste 10 T5: "Receber" pré-preenche o BRUTO (dívida + honorários), não o restante da obrigação')]
    public function testReceberPrePreencheOBrutoComHonorariosAcrescidos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $caso = $this->casoComHonorarios($tenant, FormaHonorarios::AcrescidoDivida, '10.00');
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // R$ 1.320,00: o BRUTO que `ratearPagamento` devolve como R$ 1.200,00 de dívida + R$ 120,00 de
        // honorários. Pré-preencher o restante (120000) rateia para 109091 e a obrigação NÃO quita —
        // sobram R$ 109,09 e o gestor não entende por quê (spec §5.1).
        self::assertSame('132000', $crawler->filter('.jp-obr[data-bruto-centavos]')->attr('data-bruto-centavos'));
    }

    #[TestDox('Ajuste 10 T5: o prefill mira o RESTANTE — o que já foi recebido não é cobrado de novo')]
    public function testOBrutoSugeridoDescontaOQueJaFoiRecebido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $caso = $this->casoComHonorarios($tenant, FormaHonorarios::AcrescidoDivida, '10.00');
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 40000, 'valorEncargos' => 0, 'valorHonorarios' => 4000,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 40000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Alvo D = restante = 120000 − 40000 = 80000 → bruto = 88000 (R$ 880,00). Se o gross-up mirasse o
        // valor cheio da obrigação, viria 132000 e o gestor cobraria de novo o que já entrou.
        self::assertSame('88000', $crawler->filter('.jp-obr[data-bruto-centavos]')->attr('data-bruto-centavos'));
    }

    #[TestDox('Ajuste 10 T5: sem honorário percentual o prefill é o próprio restante (sem gross-up)')]
    public function testFormaSemPercentualPrePreencheORestanteSemGrossUp(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // `semearGrafo` já nasce `SemPercentual` — a forma em que o devedor paga só a dívida.
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // `brutoParaRecuperar` espelha `ratearPagamento`: sem percentual, bruto == dívida.
        self::assertSame('120000', $crawler->filter('.jp-obr[data-bruto-centavos]')->attr('data-bruto-centavos'));
    }

    #[TestDox('Ajuste 10 T5 (INV-U1): parcela de acordo vigente não oferece checkbox nem "Acordar"')]
    public function testParcelaDeAcordoVigenteNaoOfereceAcordar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo,
        ])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.jp-acordo .jp-obr')->count(), 'a parcela tem de estar na tela');
        // INV-U1 / INV-I: acordo sobre acordo duplica dívida no saldo ao romper o de baixo (ajuste 9).
        self::assertCount(0, $crawler->filter('.jp-acordo .jp-check'), 'parcela não pode ter checkbox');
        self::assertCount(0, $crawler->filter('.jp-acordo [data-acao="acordar"]'), 'parcela não pode ter Acordar');
        // Mas RECEBER continua: pagar parcela de acordo vigente é o fluxo normal (ela é exigível).
        self::assertGreaterThan(0, $crawler->filter('.jp-acordo [data-acao="receber"]')->count());
    }

    #[TestDox('Parcela de acordo rompido não aparece na seção de dívida (nem oferece Receber/Acordar)')]
    public function testParcelaDeAcordoRompidoSaiDaSecaoDeDivida(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Rompido,
        ])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela de acordo rompido',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Desde 01/08 a parcela de acordo desfeito sai da seção: ela está fora do exigível
        // (`doCasoExigiveis`) e a seção mostra o que compõe o saldo. Fica acessível pelo acordo, em
        // "Acordos encerrados".
        self::assertStringNotContainsString(
            'Parcela de acordo rompido',
            $crawler->filter('#secao-divida')->text(),
            'a parcela desfeita não pertence à seção de dívida em aberto',
        );
        // E, fora da lista, nenhuma das duas ações sobra para ela: "Receber" apontaria para um alvo que o
        // form de pagamento não conhece, e "Acordar" só vale para dívida original exigível (INV-I).
        self::assertCount(0, $crawler->filter('[data-acao="receber"]'));
        self::assertCount(0, $crawler->filter('[data-acao="acordar"]'));
    }

    #[TestDox('Acordo cujas parcelas foram TODAS assumidas aparece como "Substituído pelo acordo #N"')]
    public function testAcordoTotalmenteAssumidoMostraOSucessor(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        [$velho, $novo] = $this->acordoAssumidoPorOutro($tenant, $caso, parcelasQueFicam: 0);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $encerrados = $crawler->filter('#secao-acordos-encerrados')->text();
        self::assertStringContainsString('Acordo #' . $velho->getId(), $encerrados);
        self::assertStringContainsString('Substituído pelo acordo #' . $novo->getId(), $encerrados);
        // O selo de estado ("Ativo") é substituído pelo de sucessão: era ele que fazia o acordo já
        // assumido parecer vigente, que é exatamente o que o dono pediu para parar de acontecer.
        self::assertStringNotContainsString(
            'Ativo',
            $crawler->filter('#secao-acordos-encerrados .jp-mov')->first()->text(),
            'o acordo já assumido não pode continuar se anunciando como vigente',
        );
    }

    #[TestDox('Acordo PARCIALMENTE renegociado continua vigente: nada de "Substituído"')]
    public function testAcordoParcialmenteRenegociadoContinuaVigente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // A forma dos 6 casos reais (163, 244, 255, 306, 332, 61): o sucessor levou uma parcela e o
        // acordo antigo continua devendo as outras. No 348 o sucessor levou 1 de 40.
        [$velho] = $this->acordoAssumidoPorOutro($tenant, $caso, parcelasQueFicam: 1);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Substituído pelo acordo', $crawler->text());
        self::assertStringContainsString(
            'Parcela que sobrou',
            $crawler->filter('#secao-divida')->text(),
            'a parcela viva do acordo parcialmente renegociado continua sendo cobrada',
        );
        self::assertGreaterThan(0, $crawler->filter('#secao-divida .jp-acordo')->count(), 'o acordo antigo continua virando grupo na seção Dívida');

        // ⚠️ O assert de RENDERIZAÇÃO acima não basta, e quase ficou sozinho: um
        // `substituidoPeloAcordoId` errado aqui ficaria invisível se o template mudasse, e mentiria no
        // DTO para quem for usá-lo depois. A derivação é conferida na fonte.
        self::assertNull(
            $this->acordoNoDetalhe($caso, (int) $velho->getId())->substituidoPeloAcordoId,
            'acordo com parcela em aberto que sobrou não foi substituído: ele ainda cobra',
        );
    }

    #[TestDox('Acordo que só ficou com parcelas PAGAS também se anuncia substituído — na seção Dívida')]
    public function testAcordoComSobraApenasPagaSeAnunciaSubstituido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // A forma MAIS COMUM no dado real (29 acordos, contra 8 sem sobra nenhuma): o devedor pagou
        // algumas parcelas e renegociou o resto. A parcela paga não é substituída, então o acordo
        // continua virando grupo na seção Dívida — e sem o selo ele diria "Ativo" no meio da dívida.
        [$velho, $novo] = $this->acordoAssumidoPorOutro($tenant, $caso, parcelasQueFicam: 0, parcelasPagasQueFicam: 1);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            $novo->getId(),
            $this->acordoNoDetalhe($caso, (int) $velho->getId())->substituidoPeloAcordoId,
            'parcela PAGA que sobrou não impede o acordo de estar substituído — ele não cobra mais nada',
        );
        $grupo = $crawler->filter('#grupoAcordo' . $velho->getId());
        self::assertCount(1, $grupo, 'o acordo continua virando grupo: a parcela paga dele não sumiu da tela');
        self::assertStringContainsString('Substituído pelo acordo #' . $novo->getId(), $grupo->text());
    }

    /** O `AcordoOutput` de um acordo, como o UseCase o monta — a derivação antes de virar HTML. */
    private function acordoNoDetalhe(CasoCobranca $caso, int $acordoId): AcordoOutput
    {
        $detalhe = static::getContainer()->get(MontarDetalheCasoUseCase::class)->executar($caso);

        foreach ($detalhe->acordos as $acordo) {
            if ($acordo->id === $acordoId) {
                return $acordo;
            }
        }

        self::fail(sprintf('acordo %d não está na lista do detalhe do caso', $acordoId));
    }

    /**
     * O estado que o importador passou a criar: o acordo NOVO assume parcelas do ANTIGO. A parcela
     * assumida fica com `acordoOrigem` (o antigo) e `acordoSubstituto` (o novo) ao mesmo tempo.
     *
     * @return array{0: Acordo, 1: Acordo}
     */
    private function acordoAssumidoPorOutro(Tenant $tenant, CasoCobranca $caso, int $parcelasQueFicam, int $parcelasPagasQueFicam = 0): array
    {
        $velho = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $novo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela assumida',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'acordoOrigem' => $velho, 'acordoSubstituto' => $novo,
        ]);

        for ($i = 0; $i < $parcelasQueFicam; ++$i) {
            ObrigacaoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela que sobrou',
                'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $velho,
            ]);
        }

        for ($i = 0; $i < $parcelasPagasQueFicam; ++$i) {
            $paga = ObrigacaoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela paga que sobrou',
                'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $velho,
            ])->_real();
            $pagamento = PagamentoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 30000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
            ])->_real();
            AlocacaoPagamentoFactory::createOne([
                'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $paga, 'valor' => 30000,
            ]);
        }

        // O acordo novo precisa de parcela viva para virar grupo — senão ele mesmo cairia em
        // "Acordos encerrados" e o teste não distinguiria nada.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela do acordo novo',
            'valorOriginal' => 55000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $novo,
        ]);

        return [$velho, $novo];
    }

    #[TestDox('Ajuste 10 T5: sem movimentação financeira o "Receber" some, mas "Acordar" continua')]
    public function testSemPermissaoFinanceiraOReceberSome(): void
    {
        $client = static::createClient();
        // Capacidades SEPARADAS (SPEC §22): só `gerenciar`, sem a financeira.
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-acao="receber"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-acao="acordar"]')->count());
    }

    #[TestDox('Ajuste 10 T5: sem dívida acordável, "Novo acordo" nasce desabilitado em vez de abrir vazio')]
    public function testSemDividaAcordavelOBotaoNovoAcordoNasceDesabilitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        // Só parcela de acordo vigente: nada é acordável (INV-I barra acordo sobre acordo).
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo,
        ])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('#secao-divida [data-acao="novo-acordo"]');
        self::assertCount(1, $botao);
        self::assertNotNull($botao->attr('disabled'), 'sem acordável, o botão não pode revelar o vazio depois do clique');
    }

    #[TestDox('Ajuste 10 T5: havendo dívida original, "Novo acordo" fica habilitado')]
    public function testComDividaAcordavelOBotaoNovoAcordoFicaHabilitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('#secao-divida [data-acao="novo-acordo"]');
        self::assertCount(1, $botao);
        self::assertNull($botao->attr('disabled'));
    }

    /**
     * Caso do tenant com a política de honorários da CARTEIRA fixada (a forma decide se há gross-up
     * no prefill). #9-T2: a fonte AO VIVO é a carteira via objeto — não mais o snapshot do caso.
     */
    private function casoComHonorarios(Tenant $tenant, FormaHonorarios $forma, ?string $percentual): CasoCobranca
    {
        [, $caso] = $this->semearGrafo($tenant, [], [
            'formaHonorarios' => $forma,
            'percentualHonorarios' => $percentual,
        ]);

        return $caso;
    }

    #[TestDox('Deep-link do caso redireciona (302) para a página do objeto (Fatia 5)')]
    public function testCasoShowRedirecionaParaObjeto(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas/casos/' . $caso->getId());

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
    }

    // ── Ajuste pós-taxa #3 (dono): esconder os gatilhos de "Registrar pagamento"/"Registrar liquidação",
    // mantendo "Receber". Só UI — spec `docs/specs/cobranca-esconder-botoes-pagamento-liquidacao.md`.
    // #modalRegistrarPagamento é o MESMO modal que "Receber" abre (event.relatedTarget decide FIFO×manual
    // em show.html.twig), por isso continua no DOM; #modalRegistrarLiquidacao também continua — é o alvo
    // do reabrir-com-erro (`data-modal-erro`) da rota, que a spec mantém acessível (ver
    // `LiquidacaoMutacaoControllerTest::testRegistrarLiquidacaoInvalidaReabreModalComErroEPreservaODigitado`).
    // Por isso a asserção mira o BOTÃO-gatilho, não o texto bruto da página (que o título/submit do modal
    // reaproveitado ainda carregam, de propósito).

    #[TestDox('Ajuste pós-taxa #3: os gatilhos de Registrar pagamento/liquidação somem; Receber continua')]
    public function testEsconderGatilhosDeRegistrarPagamentoELiquidacaoMasReceberContinua(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // O bloco de ações da seção "O que já entrou" ficou vazio: os dois botões genéricos saíram.
        self::assertCount(
            0,
            $crawler->filter('#secao-movimentos .jp-secao-acoes button'),
            'os botões "Registrar pagamento"/"Registrar liquidação" têm de sumir da seção de movimentos',
        );
        // Nenhum gatilho clicável aponta mais para o modal de liquidação em NENHUMA parte da página.
        self::assertCount(
            0,
            $crawler->filter('[data-bs-toggle="modal"][data-bs-target="#modalRegistrarLiquidacao"]'),
            'não pode sobrar gatilho para o modal de liquidação',
        );
        // "Receber" (por obrigação) continua — mesmo modal de pagamento, disparado com data-acao=receber.
        $receber = $crawler->filter('[data-acao="receber"][data-bs-target="#modalRegistrarPagamento"]');
        self::assertGreaterThan(0, $receber->count(), '"Receber" por obrigação continua oferecendo o pagamento');
        self::assertStringContainsString('Receber', $receber->text());
        // Os MODAIS seguem no DOM (motor intacto, revertível): o de pagamento por ser reaproveitado pelo
        // "Receber"; o de liquidação por ainda ser o alvo do reabrir-com-erro da rota, que segue acessível.
        self::assertSelectorExists('#modalRegistrarPagamento');
        self::assertSelectorExists('#modalRegistrarLiquidacao');
    }

    // ── Encargos separados (spec "encargos configuráveis em cascata" §11) ──────────────────────────
    // A linha da obrigação deixou de ter uma coluna "Valor" e passou a ter as do relatório da
    // contabilidade: Original · Juros · Multa · Correção · Honorários · Total.

    #[TestDox('F4: a linha mostra as colunas do PDF com o split real da contabilidade (Apêndice A)')]
    public function testALinhaMostraAsColunasDeEncargosSeparados(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Config TOPLIFE I na CARTEIRA (T1: fonte ao vivo do meio da cascata via Objeto — juros 1%
        // a.m., multa 2%, honorários 20%, carência 30). No modelo AO VIVO os encargos NÃO são
        // materializados na fixture: a tela os calcula de (vencimento → hoje). Principal R$ 170,00
        // com 240 dias de atraso reproduz, AO CENTAVO, a linha real do Apêndice A da spec: juros
        // 13,60 · multa 3,40 · correção 0,00 · honorários 37,40. Vencimento relativo a hoje (−240
        // dias) para o cálculo ser determinístico em qualquer dia de execução.
        [, $caso] = $this->semearGrafo($tenant, [], [
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'carenciaHonorariosDias' => 30,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Boleto TOPLIFE', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => (new \DateTimeImmutable('today'))->modify('-240 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('#secao-divida .jp-obr');
        self::assertCount(1, $linha);
        self::assertStringContainsString('170,00', $linha->filter('.col-original')->text());
        self::assertStringContainsString('13,60', $linha->filter('.col-juros')->text());
        self::assertStringContainsString('3,40', $linha->filter('.col-multa')->text());
        self::assertStringContainsString('0,00', $linha->filter('.col-correcao')->text());
        self::assertStringContainsString('37,40', $linha->filter('.col-honorarios')->text());
        // Total = o do RELATÓRIO da contabilidade: 170,00 + 13,60 + 3,40 + 0,00 + 37,40 = 224,40.
        // É o número da prova real do Apêndice A ("Tot 224,40") e o que esta linha existe para
        // reproduzir — as seis colunas na tela têm de fechar a soma, senão não dá para conferir
        // contra o PDF. NÃO é o exigível (18700), que exclui honorários por INV-E2 e segue sendo o
        // que alimenta saldo/FIFO/acordo, sem aparecer nesta coluna.
        self::assertStringContainsString('224,40', $linha->filter('.col-total')->text());
        self::assertSame(
            22440,
            17000 + 1360 + 340 + 0 + 3740,
            'as seis colunas exibidas têm de fechar a soma do relatório',
        );
    }

    #[TestDox('F4 (encargos na linha): o cabeçalho nomeia as colunas compactas e a faixa rotula cada encargo')]
    public function testOCabecalhoNomeiaAsColunasCompactasEAFaixaRotulaCadaEncargo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Março/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // A linha é COMPACTA, então o cabeçalho nomeia só as colunas fixas — o detalhamento dos encargos
        // vive na faixa sempre visível de cada linha, não em colunas próprias.
        $cabecalho = $crawler->filter('#secao-divida .jp-lista-head');
        self::assertCount(1, $cabecalho);
        $textoCabecalho = $cabecalho->text();
        foreach (['Venceu em', 'O que é', 'Total'] as $coluna) {
            self::assertStringContainsString($coluna, $textoCabecalho, "o cabeçalho precisa nomear a coluna {$coluna}");
        }
        // Cada número de dinheiro é ROTULADO onde ele aparece — na FAIXA sempre visível da linha (o dono
        // quer ver cada encargo sem expandir; não há mais chevron/painel). O "Total" fica na célula própria
        // (col-total), fora da faixa.
        $faixa = $crawler->filter('#secao-divida .jp-obr .jp-obr-encargos');
        self::assertCount(1, $faixa);
        $textoFaixa = $faixa->text();
        foreach (['Original', 'Juros', 'Multa', 'Correção', 'Honorários'] as $encargo) {
            self::assertStringContainsString($encargo, $textoFaixa, "a faixa precisa rotular o encargo {$encargo}");
        }
    }

    #[TestDox('F4 (INV-E4): obrigação congelada avisa que os encargos não crescem mais; a normal não avisa')]
    public function testObrigacaoCongeladaMostraOIndicadorEANaoCongeladaNao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $congelada = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Veio do relatório da contabilidade', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-01-10'),
        ])->_real();
        $congelada->definirEncargos(1360, 340, 0, 3740, new \DateTimeImmutable('2026-02-01'));
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-02-01 10:00:00'));

        // No modelo AO VIVO a obrigação não-congelada é recalculada na leitura para HOJE — o "atualizado
        // em" acompanha o relógio, então não é mais um valor semeado (era o antigo cron).
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Calculada ao vivo', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-03-10'),
        ]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#secao-divida .jp-obr'), 'as duas obrigações estão na tela');
        // Só a congelada carrega o sinal — se as duas o mostrassem, ele não diria nada.
        $indicador = $crawler->filter('#secao-divida .jp-obr-congelado');
        self::assertCount(1, $indicador);
        self::assertStringContainsString('Encargos congelados em 01/02/2026', (string) $indicador->attr('title'));
        self::assertStringContainsString('não são recalculados automaticamente', (string) $indicador->attr('title'));
        // A não-congelada diz quando foi a última atualização, no próprio Total — AO VIVO, com a data de
        // hoje. Casa por PADRÃO de data (não pela data exata de execução) para não flakar na virada da
        // meia-noite entre montar o esperado e renderizar o request.
        $totais = $crawler->filter('#secao-divida .jp-obr .col-total')->each(
            static fn ($node) => (string) $node->attr('title'),
        );
        $temAtualizado = array_filter(
            $totais,
            static fn (string $t): bool => 1 === preg_match('#^Encargos atualizados em \d{2}/\d{2}/\d{4}\.$#', $t),
        );
        self::assertNotEmpty($temAtualizado, 'a não-congelada mostra "Encargos atualizados em <data>" no Total');
    }

    #[TestDox('F4 (encargos na linha): avulsa e parcela mostram a faixa de encargos; a substituída é histórico simples')]
    public function testAvulsaEParcelaMostramAFaixaEaSubstituidaEhSimples(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo,
        ])->_real();
        // 1) avulsa · 2) parcela do acordo vigente · 3) obrigação que o acordo substituiu
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Avulsa',
            'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Trocada pelo acordo',
            'valorOriginal' => 60000, 'encargosReconhecidos' => 0, 'acordoSubstituto' => $acordo,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('#secao-divida .jp-obr'), 'as três variantes de linha estão na tela');
        // A dívida VIVA — avulsa e parcela de acordo — mostra a faixa de encargos na própria linha. A
        // substituída é histórico (já dentro do collapse do acordo que a trocou), então fica SIMPLES: só o
        // Total, sem a faixa (não repete o split de quem já saiu do total em aberto).
        self::assertCount(2, $crawler->filter('#secao-divida .jp-obr-encargos'), 'avulsa e parcela têm a faixa de encargos');
        self::assertCount(1, $crawler->filter('#secao-divida .jp-obr.is-substituida'), 'a substituída está na tela');
        self::assertCount(0, $crawler->filter('#secao-divida .jp-obr.is-substituida .jp-obr-encargos'), 'a substituída NÃO mostra a faixa');
        // Os encargos rotulados do relatório vivem na faixa — presentes nas duas variantes vivas.
        foreach (['.col-original', '.col-juros', '.col-multa', '.col-correcao', '.col-honorarios'] as $coluna) {
            self::assertCount(2, $crawler->filter('#secao-divida .jp-obr-encargos ' . $coluna), "faltou {$coluna} nas faixas");
        }
    }

    #[TestDox('F4: o menu Editar leva o split de encargos (contrato dos data-* lido pelo JS do modal)')]
    public function testOMenuEditarCarregaOSplitDeEncargosNosDataAttributes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Config TOPLIFE I na CARTEIRA (T1): no modelo AO VIVO os encargos vêm de (vencimento → hoje).
        // P=170,00 com 240 dias de atraso → juros 1360 · multa 340 · correção 0 (soma 1700), ao vivo.
        [, $caso] = $this->semearGrafo($tenant, [], [
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'carenciaHonorariosDias' => 30,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Boleto TOPLIFE', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => (new \DateTimeImmutable('today'))->modify('-240 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#secao-divida [data-bs-target="#modalEditarObrigacao"]');
        self::assertCount(1, $item);
        // O `data-encargos-centavos` (a SOMA, INV-E1) permanece por compatibilidade e os três do split
        // entram ao lado: é o contrato que o JS do modal de edição reidrata.
        // 1360+340+0: a soma vem da coluna-sombra `encargos_reconhecidos`, que a entidade sincroniza
        // no `PreUpdate` (F1). Falhar AQUI aponta para a sombra, não para o template.
        self::assertSame('1700', $item->attr('data-encargos-centavos'), 'a soma continua publicada');
        self::assertSame('1360', $item->attr('data-juros-centavos'));
        self::assertSame('340', $item->attr('data-multa-centavos'));
        self::assertSame('0', $item->attr('data-correcao-centavos'));
    }

    // ── Taxa por-obrigação (Task 9): editar submete %/R$, o servidor grava a taxa e o saldo/linha ──
    // refletem AO VIVO (Task 4 overlay + Task 5/7 conversor). Vencimento RELATIVO a hoje (-240 dias):
    // `EditarObrigacaoUseCase` grava `hoje` como `new \DateTimeImmutable('today')` direto (sem relógio
    // injetável nesse UseCase) — fixar a DIFERENÇA de dias, e não a data absoluta, é o que sustenta o
    // determinismo independente do dia em que a suíte roda (mesmo padrão já usado no teste F4 acima).

    #[TestDox('Task 9: editar com modoJuros=percent grava a taxa própria e o saldo cresce proporcionalmente')]
    public function testEditarComOverrideDeJurosPercentualRefleteNoSaldoDoCaso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Caso herda 1% a.m. (100 bp) de juros da CARTEIRA (T1) — só juros configurado (multa/
        // correção seguem 0, herdadas da carteira neutra do semearGrafo nos demais campos).
        [, $caso] = $this->semearGrafo($tenant, [], ['taxaJurosMensalBp' => 100]);
        $vencimento = (new \DateTimeImmutable('today'))->modify('-240 days');
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Boleto com taxa própria', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => $vencimento,
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        // Baseline herdado (P=170,00, 1% a.m., 240 dias) = R$13,60 — o MESMO número já provado no
        // Apêndice A por `testALinhaMostraAsColunasDeEncargosSeparados` acima (paridade ao centavo).
        self::assertStringContainsString('13,60', $crawler->filter('#secao-divida .jp-obr .col-juros')->text());
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        // `jurosBp` é o campo TaxaBpType — texto do PERCENTUAL em pt-BR ("2,00"), NÃO bp cru; o
        // `TaxaBpParaTextoTransformer` converte para 200 bp no servidor (2,00% = 200 bp).
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Boleto com taxa própria',
                'valorOriginal' => '170,00',
                'vencimentoOriginal' => $vencimento->format('Y-m-d'),
                'modoJuros' => 'percent',
                'jurosBp' => '2,00',
                'motivo' => 'Ajuste de taxa própria (Task 9)',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Obrigacao::class)->find($obrigacaoId);
        self::assertNotNull($fresh);
        self::assertSame(200, $fresh->getTaxaJurosMensalBp(), 'a taxa própria (2%) foi gravada na obrigação — override, não mais herdando');
        self::assertFalse($fresh->encargosCongelados(), 'ao vivo (D6): editar não congela a obrigação');
        // 2% é o DOBRO EXATO de 1% (linear em bp, mesmos P/dias): R$13,60 × 2 = R$27,20.
        self::assertSame(2720, $fresh->getJuros(), 'o juros materializado no servidor dobrou com a taxa própria');

        // Ponta-a-ponta: uma NOVA leitura da página (o saldo é recalculado AO VIVO, Task 4) mostra a
        // taxa própria refletida — não só no banco, mas no que o gestor vê.
        $crawlerDepois = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('27,20', $crawlerDepois->filter('#secao-divida .jp-obr .col-juros')->text());
        // Total do caso (cabeçalho): 170,00 + 27,20 de juros (multa/correção seguem 0, herdadas da
        // carteira neutra) = 197,20. O valor já andou pela tela: hero → linha fina `.cob-resumo` →
        // card `Total vencido` (2026-07-27, quando a linha fina saiu e os cards passaram a somar o
        // vencido). A obrigação deste teste venceu há meses, então ela entra no card.
        self::assertStringContainsString(
            '197,20',
            $crawlerDepois->filter('.cob-cab-card[data-card="total"]')->text(),
        );
    }

    // ── FIX crítico (Task 9): rehidratação do override de taxa no modal de Editar ──
    // Bug: abrir "Editar" sempre nascia com os 4 encargos em "herda", mesmo quando a obrigação já tinha
    // um override próprio — o JS não conhecia a taxa crua atual. Qualquer submissão (mesmo só corrigir a
    // descrição) reenviava `modo=herda`, e o `EditarObrigacaoUseCase` (que sempre DERIVA os 4 overrides
    // do que o Form recebeu, sem um 4º modo "não mexi") apagava o override em silêncio — dinheiro
    // revertendo pra herança sem o gestor pedir. O fix: `ObrigacaoOutput` expõe a taxa crua, a linha
    // publica em `data-taxa-*-bp`, e o JS do modal a rehidrata (seta `%` + dispara `input`, que seta
    // `modo=percent` e deriva o R$ de preview) — os dois testes abaixo provam o CONTRATO do lado do
    // servidor (o que o JS envia de volta), não o JS em si (PHPUnit não roda JS — ver relatório).

    #[TestDox('FIX Task 9: editar só a descrição, reenviando o override de multa como o JS rehidratado enviaria, preserva a taxa própria')]
    public function testEditarReenviandoOOverrideRehidratadoPreservaATaxaPropria(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $vencimento = (new \DateTimeImmutable('today'))->modify('-30 days');
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Boleto com multa própria', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => $vencimento,
            'taxaMultaBp' => 200, // override próprio de 2% de multa, já gravado ANTES desta edição
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        // Corrige SÓ a descrição — mas reenvia `modoMulta=percent`/`multaBp=2,00`, exatamente como o JS
        // rehidratado (FIX) enviaria ao abrir o modal e ler `data-taxa-multa-bp="200"` da linha.
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Boleto com multa própria (corrigido)',
                'valorOriginal' => '170,00',
                'vencimentoOriginal' => $vencimento->format('Y-m-d'),
                'modoMulta' => 'percent',
                'multaBp' => '2,00',
                'motivo' => 'Corrige só a descrição (FIX Task 9)',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Obrigacao::class)->find($obrigacaoId);
        self::assertNotNull($fresh);
        self::assertSame('Boleto com multa própria (corrigido)', $fresh->getDescricao());
        self::assertSame(200, $fresh->getTaxaMultaBp(), 'o override de multa (2%) sobreviveu à correção de descrição — não reverteu a "herda"');
    }

    #[TestDox('FIX Task 9: editar com o override explicitamente limpo (modo=herda) volta a taxa a null')]
    public function testEditarComOverrideLimpoVoltaATaxaParaHerda(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $vencimento = (new \DateTimeImmutable('today'))->modify('-30 days');
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Boleto com multa própria', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => $vencimento,
            'taxaMultaBp' => 200, // override próprio — o gestor vai CLICAR em "limpar" (voltar a herdar)
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        // `.jp-taxa-limpar` seta `%`/`R$` vazios e `modo='herda'` explicitamente — é o que este POST espelha.
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Boleto com multa própria',
                'valorOriginal' => '170,00',
                'vencimentoOriginal' => $vencimento->format('Y-m-d'),
                'modoMulta' => 'herda',
                'multaBp' => '',
                'motivo' => 'Volta a herdar a multa do caso (FIX Task 9)',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Obrigacao::class)->find($obrigacaoId);
        self::assertNotNull($fresh);
        self::assertNull($fresh->getTaxaMultaBp(), 'o override foi limpo de propósito — volta a herdar do caso');
    }
}
