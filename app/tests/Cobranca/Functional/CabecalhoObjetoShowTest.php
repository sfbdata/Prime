<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Cabeçalho redesenhado do `cobranca_objeto_show` (spec `cobranca-objeto-show-cabecalho-responsaveis`
 * §1, Etapa 5). Prova o que a PÁGINA renderiza — a aritmética dos totais e as faixas de prescrição já
 * têm prova própria nos unitários do `MontarDetalheCasoUseCase` e da `CalculadoraPrescricao`.
 *
 * O que se afirma aqui é a LIGAÇÃO: que cada número do DTO chegou ao lugar certo do HTML. É justamente
 * onde um cabeçalho erra em silêncio — trocando um campo por outro de nome parecido, com a tela
 * continuando bonita e o gestor conferindo o número errado.
 *
 * ⚠️ Os quatro cards somam o VENCIDO e no BRUTO (§1.2, revisto em 2026-07-27). São dois recortes que
 * se erram em silêncio, e cada um tem teste próprio para que errá-lo quebre:
 *  - **vencido** — a obrigação que ainda vai vencer fica fora (`testCardsSomamSoOVencido`);
 *  - **bruto** — pagamento parcial NÃO reduz os cards; quem é líquido é o `saldoExigivel`, que desde
 *    2026-07-27 não aparece mais na tela, só governa o encerramento (`testCardsSaoBrutosENaoOSaldo`).
 */
#[CoversClass(ObjetoController::class)]
final class CabecalhoObjetoShowTest extends CobrancaWebTestCase
{
    #[TestDox('Redesenho 1a: a trilha tem TRÊS níveis (Cobranças / Carteira / Unidade), com título e badge')]
    public function testTrilhaTituloEStatus(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $this->renomear($caso->getObjeto(), 'Apto 302');

        $crawler = $this->abrir($client, $caso->getObjeto()->getId());
        $painel = $crawler->filter('.content-header .cob-cab-painel');

        // A trilha ganhou o nível do módulo (`Cobranças`) e mudou de lugar: mora na linha do topo do
        // painel, ao lado do botão de voltar e das setas — não mais dentro da coluna da identidade.
        $trilha = $painel->filter('.cob-cab-topo > .cob-cab-trilha');
        self::assertCount(1, $trilha, 'a trilha tem de existir, na linha do topo do painel');
        self::assertStringContainsString('Cobranças', $trilha->text());
        self::assertStringContainsString($carteira->getNome(), $trilha->text());
        self::assertStringContainsString('Unidade Apto 302', $trilha->text());

        $links = $trilha->filter('a')->each(static fn ($a) => $a->attr('href'));
        self::assertSame(
            ['/cobrancas', '/cobrancas/carteiras/' . $carteira->getId()],
            $links,
            'os dois níveis navegáveis são o módulo e a carteira; a unidade é o nível atual',
        );

        // O botão de voltar leva ao MESMO lugar do segundo nível da trilha — duplicação deliberada do
        // desenho: o botão é o alvo grande de "sair daqui", a trilha é orientação.
        self::assertSame(
            '/cobrancas/carteiras/' . $carteira->getId(),
            $painel->filter('.cob-cab-voltar')->attr('href'),
        );

        $identidade = $painel->filter('.cob-cab-identidade');
        self::assertSame('Apto 302', trim($identidade->filter('h1')->text()), 'o título é a identificação da unidade');
        // O selo de estado subiu para a linha do sobretítulo `Unidade`, ACIMA do `h1` (desenho); antes
        // era irmão imediato dele (`h1 + .badge`). Ancoro no bloco que os contém e na ordem entre eles.
        self::assertStringContainsString('Ativo', $identidade->filter('.badge')->text(), 'o badge de status abre o bloco de identidade');
        self::assertStringContainsString(
            'Unidade',
            $identidade->filter('.cob-cab-sobretitulo')->text(),
            'o sobretítulo diz o que é a coisa; o h1 diz qual é',
        );
    }

    #[TestDox('§1.1: a linha meta traz a descrição e a contagem de obrigações em aberto, com plural certo')]
    public function testLinhaMetaComDescricaoEContagem(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->renomear($caso->getObjeto(), 'Apto 302', 'Bloco B');

        // Duas em aberto e uma QUITADA: a contagem tem de ignorar a quitada, como a aba Dívida ignora.
        foreach (['Competência 01', 'Competência 02'] as $descricao) {
            ObrigacaoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'descricao' => $descricao,
                'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
                'vencimentoOriginal' => new \DateTimeImmutable('-100 days'),
            ]);
        }
        $this->quitar($tenant, $caso, 'Competência 00', 30000);

        $meta = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab-meta');

        self::assertStringContainsString('Bloco B', $meta->text(), 'a descrição do objeto entra na linha meta');
        self::assertSame('2 obrigações em aberto', trim($meta->filter('[data-meta="obrigacoes-em-aberto"]')->text()));
        // `Matrícula` da maquete NÃO entra (§1.1 / §6): o sistema tem `referenciaExterna`, que é genérica.
        self::assertStringNotContainsStringIgnoringCase('Matrícula', $meta->text());
    }

    #[TestDox('§1.1: com uma única obrigação em aberto a linha meta fala no singular')]
    public function testLinhaMetaNoSingular(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência única',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-100 days'),
        ]);

        $meta = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab-meta');

        self::assertSame('1 obrigação em aberto', trim($meta->filter('[data-meta="obrigacoes-em-aberto"]')->text()));
    }

    #[TestDox('§1.2: os quatro cards aparecem e o Total vencido é a soma exata dos três acima')]
    public function testOsQuatroCardsSomamEntreSi(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], self::carteiraComEncargosEHonorarios());

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência 01',
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
        ]);

        // Âncora no PAINEL, não em `.cob-cab`: desde o redesenho 1a a linha de identidade e a faixa de
        // dinheiro são irmãs dentro dele, e o dinheiro deixou de estar dentro de `.cob-cab`.
        $cabecalho = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab-painel');

        $principal = $this->valorDoCard($cabecalho, 'principal');
        $encargos = $this->valorDoCard($cabecalho, 'encargos');
        $honorarios = $this->valorDoCard($cabecalho, 'honorarios');
        $total = $this->valorDoCard($cabecalho, 'total');

        self::assertSame(100000, $principal, 'o card Principal é a soma dos valores originais vencidos');
        // Encargos e honorários são calculados AO VIVO pela cascata: cravar o número aqui duplicaria a
        // fórmula dentro do teste. O que se afirma é que eles CHEGARAM (não-zero) e que a soma fecha.
        self::assertGreaterThan(0, $encargos, 'a carteira cobra juros e multa — o card não pode estar zerado');
        self::assertGreaterThan(0, $honorarios, 'a carteira cobra honorários — o card não pode estar zerado');
        self::assertSame(
            $principal + $encargos + $honorarios,
            $total,
            'o card Total vencido tem de ser a soma dos três, centavo a centavo',
        );

        // O "atualizado em" saiu de dentro do card e virou a nota ao lado do herói (desenho): mesma
        // informação, mesmo dado (`totaisAtualizadosEm`), outro lugar.
        self::assertMatchesRegularExpression(
            '/atualizado em \d{2}\/\d{2}\/\d{4}/',
            $cabecalho->filter('.cob-heroi .cob-heroi-quando')->text(),
            'o Total vencido diz de quando é o número',
        );
    }

    #[TestDox('§1.2: os cards somam SÓ o vencido — a obrigação a vencer fica de fora')]
    public function testCardsSomamSoOVencido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Carteira NEUTRA (o default da factory): sem encargo nem honorário o número é determinístico,
        // e o que sobra na diferença é exatamente o recorte do vencido.
        [, $caso] = $this->semearGrafo($tenant);

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência vencida',
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
        ]);
        // A VENCER: está em aberto, aparece na aba Dívida e conta na linha meta — mas não é cobrança
        // de hoje, e desde 2026-07-27 o cabeçalho não a soma. Trocar o recorte de volta para "em
        // aberto" faz o Principal virar 1.700,00 e este teste quebrar.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência futura',
            'valorOriginal' => 70000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('+30 days'),
        ]);

        $crawler = $this->abrir($client, $caso->getObjeto()->getId());
        $cabecalho = $crawler->filter('.cob-cab-painel');

        self::assertSame(100000, $this->valorDoCard($cabecalho, 'principal'), 'só a vencida entra no Principal');
        self::assertSame(100000, $this->valorDoCard($cabecalho, 'total'), 'e o Total vencido segue a mesma régua');
        self::assertStringContainsString(
            'Total vencido',
            $cabecalho->filter('.cob-heroi-rotulo')->text(),
            'o número herói diz que é do vencido — o rótulo é o que explica o recorte ao gestor',
        );

        // A linha meta continua contando o que está EM ABERTO (as duas): são perguntas diferentes, e a
        // §1.1 não mudou. Se um dia mudar, que seja por decisão, não por arrasto do recorte dos cards.
        self::assertSame(
            '2 obrigações em aberto',
            trim($crawler->filter('.cob-cab-meta [data-meta="obrigacoes-em-aberto"]')->text()),
        );
    }

    #[TestDox('§1.2: os cards são BRUTOS (pagamento parcial não os reduz) e a linha fina saiu da tela')]
    public function testCardsSaoBrutosENaoOSaldo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Carteira NEUTRA (o default da factory): sem encargo nem honorário, os números ficam
        // determinísticos e a diferença entre bruto e líquido é exatamente o pagamento.
        [, $caso] = $this->semearGrafo($tenant);

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência 01',
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
        ]);

        // Pagamento PARCIAL: a obrigação continua em aberto (entra nos cards pelo valor cheio), mas o
        // saldo exigível — que já não aparece na tela — desconta o que entrou.
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 40000, 'valorHonorarios' => 0,
        ]);
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 40000,
        ]);

        // Âncora no PAINEL, não em `.cob-cab`: desde o redesenho 1a a linha de identidade e a faixa de
        // dinheiro são irmãs dentro dele, e o dinheiro deixou de estar dentro de `.cob-cab`.
        $cabecalho = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab-painel');

        self::assertSame(
            100000,
            $this->valorDoCard($cabecalho, 'total'),
            'o card Total vencido é o bruto: não abate pagamento. Ligar o saldo exigível (60.000) aqui quebra',
        );

        // A linha fina `Total em aberto` / `Total vencido` saiu do cabeçalho em 2026-07-27 (decisão do
        // dono): o vencido virou o card, e o saldo exigível deixou de ser exibido — ele segue vivo no
        // `prontoParaEncerrar` e no tooltip do `Encerrar cobrança`, que têm testes próprios.
        // O texto é conferido na coluna da identidade (onde a linha morava) e não no cabeçalho inteiro:
        // o tooltip do `Encerrar cobrança` desabilitado fala em "total em aberto" de propósito.
        self::assertCount(0, $cabecalho->filter('.cob-resumo'), 'a linha fina não volta por descuido');
        self::assertStringNotContainsString('Total em aberto', $cabecalho->filter('.cob-cab-identidade')->text());
    }

    #[TestDox('§1.3: a caixa de prescrição mostra os dias restantes, a competência mais antiga e o Ver competência')]
    public function testCaixaDePrescricao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // A MAIS ANTIGA em aberto é quem manda: vencida há 5 anos menos 60 dias, sobram ~60 dias de
        // prazo — dentro da faixa crítica (≤ 90), longe da de atenção (≤ 180).
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência antiga',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-5 years +60 days'),
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência recente',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-30 days'),
        ]);

        // A caixa saiu da coluna `.cob-cab-lateral` (que deixou de existir) e virou bloco na coluna de
        // CONTEXTO da faixa do cabeçalho, ao lado do dinheiro.
        $caixa = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cabecalho-contexto > .cob-presc');

        self::assertCount(1, $caixa, 'com obrigação em aberto a caixa tem de aparecer');
        self::assertSame('critica', $caixa->attr('data-severidade'), 'faltando ~60 dias, a faixa é crítica');
        // A frase do destaque é o conteúdo da caixa, não enfeite: ela sozinha diz o que está em jogo
        // (risco) e em quanto tempo. Era "Faltam N dias" até 2026-07-27, quando o dono pediu a forma
        // da maquete — trocar a frase de volta sem trocar esta linha deixaria a caixa muda.
        self::assertMatchesRegularExpression(
            '/Risco de prescrição em \d+ dias/',
            $caixa->filter('.cob-presc-destaque')->text(),
        );
        self::assertStringContainsString(
            'Competência antiga',
            $caixa->filter('.cob-presc-detalhe')->text(),
            'a caixa aponta a competência MAIS ANTIGA em aberto, não a recente',
        );

        // A ressalva "Estimativa — não considera interrupção nem suspensão do prazo" SAIU a pedido do
        // dono (2026-07-27). A caixa ficou com destaque, detalhe e link, e é isso que se afirma aqui —
        // a asserção existe para que o texto não volte por acidente, e sim por decisão.
        self::assertCount(0, $caixa->filter('.cob-presc-aviso'));
        self::assertStringNotContainsString('Estimativa', $caixa->text());

        $link = $caixa->filter('.cob-presc-link');
        self::assertSame('divida', $link->attr('data-abrir-aba'), 'Ver competência abre a aba Dívida pelo mecanismo já existente');
        self::assertSame('#secao-divida', $link->attr('href'));
    }

    #[TestDox('§1.3: com o prazo vencido a caixa diz "Prazo de ajuizamento esgotado em", não dias negativos')]
    public function testPrescricaoEsgotada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência prescrita',
            'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-6 years'),
        ]);

        $caixa = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-presc');

        self::assertSame('esgotada', $caixa->attr('data-severidade'));
        self::assertMatchesRegularExpression(
            '/Prazo de ajuizamento esgotado em \d{2}\/\d{2}\/\d{4}/',
            $caixa->filter('.cob-presc-destaque')->text(),
        );
        self::assertStringNotContainsString('Faltam', $caixa->filter('.cob-presc-destaque')->text());
    }

    #[TestDox('§1.3: sem obrigação em aberto a caixa de prescrição não é renderizada')]
    public function testSemObrigacaoEmAbertoNaoHaCaixaDePrescricao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Só uma QUITADA: não há o que prescrever, e uma caixa vazia seria alarme sem causa.
        $this->quitar($tenant, $caso, 'Competência paga', 30000);

        $crawler = $this->abrir($client, $caso->getObjeto()->getId());

        self::assertCount(0, $crawler->filter('.cob-presc'), 'sem obrigação em aberto a caixa nem nasce');
        self::assertCount(1, $crawler->filter('.cob-cab-acoes'), 'as ações continuam, mesmo sem a caixa');
    }

    #[TestDox('§1.4: Simular acordo e Planilha atualizada entram DESABILITADOS, com tooltip explicando')]
    public function testAcoesSemFuncaoEntramDesabilitadasComTooltip(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acoes = $this->abrir($client, $caso->getObjeto()->getId())->filter('.content-header .cob-cab-acoes');

        foreach (['simular-acordo', 'planilha-atualizada'] as $acao) {
            $botao = $acoes->filter('[data-acao="' . $acao . '"]');
            self::assertCount(1, $botao, "sumiu o botão {$acao}");
            self::assertNotNull($botao->attr('disabled'), "o botão {$acao} tem de nascer desabilitado — ele não existe no sistema");

            // Tooltip do Bootstrap NÃO dispara em elemento `disabled`: por isso o título mora no `<span>`
            // que embrulha o botão. Sem essa asserção o tooltip podia migrar para o botão e morrer calado.
            $embrulho = $acoes->filterXPath(
                '//span[@data-bs-toggle="tooltip"][.//button[@data-acao="' . $acao . '"]]',
            );
            self::assertCount(1, $embrulho, "o botão {$acao} precisa do span com o tooltip por fora");
            self::assertNotSame('', (string) $embrulho->attr('title'), "o tooltip de {$acao} não pode ser vazio");
        }

        // Os três pontinhos da maquete não entraram: não há o que colocar neles (§6).
        self::assertCount(0, $acoes->filter('.dropdown-toggle'));
    }

    #[TestDox('§1.5: as setas apontam para os vizinhos da carteira e ficam desabilitadas nas pontas')]
    public function testSetasDeNavegacaoEntreUnidades(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);

        // Ordem `identificacao ASC`: 100 · 200 (o do meio, o desta página) · 300.
        $this->renomear($caso->getObjeto(), 'Unidade 200');
        $primeiro = $this->objetoComCaso($tenant, $carteira, 'Unidade 100');
        $ultimo = $this->objetoComCaso($tenant, $carteira, 'Unidade 300');

        // ⚠️ QUARTA posição das setas. Elas nasceram ao lado do título, foram para o topo da coluna da
        // direita, e em 2026-07-27 saíram do painel por decisão do dono. O desenho aprovado de 28/08
        // ("Objeto 1A") as traz de VOLTA para dentro do painel, na linha da trilha, encostadas à
        // direita — e é o desenho que manda. Ancoro na linha da trilha E afirmo que elas continuam
        // sendo UMA só na página: sem a segunda asserção o teste passaria com as setas duplicadas
        // dentro e fora, que é como uma migração meio-feita costuma terminar.
        $pagina = $this->abrir($client, $caso->getObjeto()->getId());
        self::assertCount(
            1,
            $pagina->filter('.cob-cab-painel .cob-cab-topo > .cob-cab-nav'),
            'as setas ficam na linha da trilha, dentro do painel',
        );
        self::assertCount(1, $pagina->filter('.cob-cab-nav'), 'e existem uma única vez na página');

        $meio = $pagina->filter('.cob-cab-nav');
        self::assertSame(
            '/cobrancas/objetos/' . $primeiro->getId(),
            $meio->filter('[data-nav="anterior"]')->attr('href'),
            'a seta ‹ leva à unidade anterior por identificação',
        );
        self::assertSame(
            '/cobrancas/objetos/' . $ultimo->getId(),
            $meio->filter('[data-nav="proximo"]')->attr('href'),
            'a seta › leva à próxima unidade por identificação',
        );

        $naPrimeira = $this->abrir($client, $primeiro->getId())->filter('.cob-cab-nav');
        self::assertNull($naPrimeira->filter('[data-nav="anterior"]')->attr('href'), 'na primeira unidade a seta ‹ não navega');
        self::assertStringContainsString('disabled', (string) $naPrimeira->filter('[data-nav="anterior"]')->attr('class'));
        self::assertNotNull($naPrimeira->filter('[data-nav="proximo"]')->attr('href'), 'mas a seta › continua viva');

        $naUltima = $this->abrir($client, $ultimo->getId())->filter('.cob-cab-nav');
        self::assertNull($naUltima->filter('[data-nav="proximo"]')->attr('href'), 'na última unidade a seta › não navega');
        self::assertStringContainsString('disabled', (string) $naUltima->filter('[data-nav="proximo"]')->attr('class'));
        self::assertNotNull($naUltima->filter('[data-nav="anterior"]')->attr('href'), 'mas a seta ‹ continua viva');
    }

    #[TestDox('Redesenho 1a: dinheiro e contexto são as DUAS colunas da faixa `.cob-cabecalho`')]
    public function testFaixaTemDinheiroEContextoLadoALado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $pagina = $this->abrir($client, $caso->getObjeto()->getId());

        // As duas colunas deixaram de ser `col-12 col-lg-*` do Bootstrap e viraram uma grade CSS
        // própria (`.cob-cabecalho`), porque elas precisam da MESMA ALTURA para a divisória vertical
        // entre elas ir de ponta a ponta — coisa que a `.row` não dá. O ponto de quebra (991.98px)
        // agora vive só na folha, e teste de PHPUnit não enxerga CSS.
        //
        // O que dá para provar aqui é o ARRANJO, com combinador de FILHO DIRETO: os dois painéis são
        // filhos da mesma faixa, e não "existem em algum lugar da página" — que era verdade mesmo com
        // o layout errado (a lição do `CarteiraArranjoTelaTest`).
        $faixa = $pagina->filter('.cob-cab-painel > .cob-cabecalho');
        self::assertCount(1, $faixa, 'a faixa de dinheiro + contexto tem de existir dentro do painel');
        self::assertCount(1, $faixa->filter('.cob-cabecalho > .cob-cabecalho-dinheiro'), 'a coluna do dinheiro é filha direta da faixa');
        self::assertCount(1, $faixa->filter('.cob-cabecalho > .cob-cabecalho-contexto'), 'a coluna de contexto é filha direta da faixa');

        // A `.cob-cab-lateral` foi embora junto com a `.row`: se ela voltar, é sinal de que alguém
        // remontou o cabeçalho antigo por cima deste.
        self::assertCount(0, $pagina->filter('.cob-cab-lateral'), 'a coluna lateral da grade antiga não volta por descuido');
    }

    // ── apoio ────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function carteiraComEncargosEHonorarios(): array
    {
        return [
            'taxaJurosMensalBp' => 100,      // 1% a.m.
            'taxaMultaBp' => 200,            // 2%
            'baseMulta' => BaseEncargo::Principal,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'baseHonorarios' => BaseEncargo::Principal,
            'carenciaHonorariosDias' => 0,
        ];
    }

    private function abrir(KernelBrowser $client, int $objetoId): Crawler
    {
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function renomear(ObjetoCobranca $objeto, string $identificacao, ?string $descricao = null): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $objeto->setIdentificacao($identificacao);
        if ($descricao !== null) {
            $objeto->setDescricao($descricao);
        }
        $em->flush();
    }

    /** Vizinho de carteira com caso próprio — só assim a página dele abre. */
    private function objetoComCaso(Tenant $tenant, Carteira $carteira, string $identificacao): ObjetoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => $identificacao,
        ])->_real();

        CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
        ]);

        return $objeto;
    }

    /** Obrigação coberta por alocação: fica FORA do conjunto "em aberto". */
    private function quitar(Tenant $tenant, \App\Cobranca\Entity\CasoCobranca $caso, string $descricao, int $valor): void
    {
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => $descricao,
            'valorOriginal' => $valor, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-200 days'),
        ]);
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => $valor, 'valorHonorarios' => 0,
        ]);
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 999999,
        ]);
    }

    /**
     * Os quatro números do cabeçalho. Desde o redesenho 1a eles não são mais quatro cards iguais — o
     * total é o HERÓI (`.cob-heroi-valor`) e os outros três são apoios (`.cob-apoio-valor`) —, mas o
     * gancho `data-card` continua no elemento que carrega o número, de propósito: é o que permite ao
     * teste conferir a identidade entre eles sem depender da hierarquia visual, que o desenho pode
     * mudar de novo.
     */
    private function valorDoCard(Crawler $cabecalho, string $card): int
    {
        $val = $cabecalho->filter('[data-card="' . $card . '"]');
        self::assertCount(1, $val, "não achei o valor \"{$card}\" no cabeçalho");

        return $this->centavos($val->text());
    }

    /** "R$ 1.234,56" → 123456. Sem float no caminho: teste de dinheiro não tem erro de ponto flutuante. */
    private function centavos(string $texto): int
    {
        $digitos = preg_replace('/\D/', '', $texto) ?? '';
        self::assertNotSame('', $digitos, "Valor monetário ilegível: \"{$texto}\"");

        return (int) $digitos;
    }
}
