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
 * ⚠️ O par mais perigoso desta tela: `totalAtualizadoEmAberto` (card **Total atualizado**) é o valor
 * BRUTO das obrigações em aberto; `saldoExigivel` (linha fina, **Total em aberto**) é o LÍQUIDO de
 * pagamentos, e é ele que governa o encerramento. Os dois convivem de propósito (§1.2), e
 * `testTotalAtualizadoNaoEOSaldoExigivel` existe para que trocar um pelo outro no Twig quebre.
 */
#[CoversClass(ObjetoController::class)]
final class CabecalhoObjetoShowTest extends CobrancaWebTestCase
{
    #[TestDox('§1.1: a trilha tem os DOIS níveis (Carteira › Unidade), com o título e o badge de status')]
    public function testTrilhaTituloEStatus(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $this->renomear($caso->getObjeto(), 'Apto 302');

        $crawler = $this->abrir($client, $caso->getObjeto()->getId());
        $cabecalho = $crawler->filter('.content-header .cob-cab');

        $trilha = $cabecalho->filter('.cob-cab-trilha');
        self::assertCount(1, $trilha, 'a trilha tem de existir');
        self::assertStringContainsString('Carteira ' . $carteira->getNome(), $trilha->text());
        self::assertStringContainsString('Unidade Apto 302', $trilha->text());
        self::assertSame(
            '/cobrancas/carteiras/' . $carteira->getId(),
            $trilha->filter('a')->attr('href'),
            'o primeiro nível da trilha é o caminho de volta para a carteira',
        );

        self::assertSame('Apto 302', trim($cabecalho->filter('h1')->text()), 'o título é a identificação da unidade');
        self::assertStringContainsString('Ativo', $cabecalho->filter('h1 + .badge')->text(), 'o badge de status fica ao lado do título');
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

    #[TestDox('§1.2: os quatro cards aparecem e o Total atualizado é a soma exata dos três acima')]
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

        $cabecalho = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab');

        $principal = $this->valorDoCard($cabecalho, 'principal');
        $encargos = $this->valorDoCard($cabecalho, 'encargos');
        $honorarios = $this->valorDoCard($cabecalho, 'honorarios');
        $total = $this->valorDoCard($cabecalho, 'total');

        self::assertSame(100000, $principal, 'o card Principal é a soma dos valores originais em aberto');
        // Encargos e honorários são calculados AO VIVO pela cascata: cravar o número aqui duplicaria a
        // fórmula dentro do teste. O que se afirma é que eles CHEGARAM (não-zero) e que a soma fecha.
        self::assertGreaterThan(0, $encargos, 'a carteira cobra juros e multa — o card não pode estar zerado');
        self::assertGreaterThan(0, $honorarios, 'a carteira cobra honorários — o card não pode estar zerado');
        self::assertSame(
            $principal + $encargos + $honorarios,
            $total,
            'o card Total atualizado tem de ser a soma dos três, centavo a centavo',
        );

        self::assertMatchesRegularExpression(
            '/atualizado em \d{2}\/\d{2}\/\d{4}/',
            $cabecalho->filter('.cob-cab-card[data-card="total"] .cob-cab-card-nota')->text(),
            'o Total atualizado diz de quando é o número',
        );
    }

    #[TestDox('§1.2: o card Total atualizado é o BRUTO — não é o saldo exigível da linha fina')]
    public function testTotalAtualizadoNaoEOSaldoExigivel(): void
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
        // saldo exigível já desconta o que entrou.
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 40000, 'valorHonorarios' => 0,
        ]);
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 40000,
        ]);

        $cabecalho = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab');

        $total = $this->valorDoCard($cabecalho, 'total');
        $emAberto = $this->centavos($cabecalho->filter('.cob-resumo-item')->eq(0)->filter('.cob-resumo-valor')->text());

        self::assertSame(100000, $total, 'o card Total atualizado é o bruto: não abate pagamento');
        self::assertSame(60000, $emAberto, 'o Total em aberto é o saldo exigível: 1.000,00 menos os 400,00 pagos');
        self::assertNotSame($total, $emAberto, 'os dois números são contas diferentes e ficam na tela de propósito');
        self::assertStringContainsString('Total em aberto', $cabecalho->filter('.cob-resumo-item')->eq(0)->text());
        self::assertStringContainsString('Total vencido', $cabecalho->filter('.cob-resumo-item')->eq(1)->text());
    }

    #[TestDox('§1.3: a caixa de prescrição mostra os dias restantes, o aviso de estimativa e o Ver competência')]
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

        $caixa = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab-lateral .cob-presc');

        self::assertCount(1, $caixa, 'com obrigação em aberto a caixa tem de aparecer');
        self::assertSame('critica', $caixa->attr('data-severidade'), 'faltando ~60 dias, a faixa é crítica');
        self::assertMatchesRegularExpression('/Faltam \d+ dias/', $caixa->filter('.cob-presc-destaque')->text());
        self::assertStringContainsString(
            'Competência antiga',
            $caixa->filter('.cob-presc-detalhe')->text(),
            'a caixa aponta a competência MAIS ANTIGA em aberto, não a recente',
        );

        // O aviso de estimativa é OBRIGATÓRIO (§1.3): o sistema conta dias, não emite parecer jurídico.
        $aviso = $caixa->filter('.cob-presc-aviso')->text();
        self::assertStringContainsString('Estimativa', $aviso);
        self::assertStringContainsString('interrupção', $aviso, 'o aviso tem de dizer o que a conta NÃO considera');

        $link = $caixa->filter('.cob-presc-link');
        self::assertSame('divida', $link->attr('data-abrir-aba'), 'Ver competência abre a aba Dívida pelo mecanismo já existente');
        self::assertSame('#secao-divida', $link->attr('href'));
    }

    #[TestDox('§1.3: com o prazo vencido a caixa diz "Prazo esgotado em", não dias negativos')]
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
            '/Prazo esgotado em \d{2}\/\d{2}\/\d{4}/',
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

        $meio = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab-nav');
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

    #[TestDox('§1: as duas colunas do cabeçalho só se separam a partir de 992px (col-lg)')]
    public function testDuasColunasSoAPartirDe992px(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $cabecalho = $this->abrir($client, $caso->getObjeto()->getId())->filter('.cob-cab');

        // `col-12 col-lg-*`: uma coluna abaixo de 992px, duas a partir dali. É a regra da §1, e ela vive
        // na classe — sem isso o cabeçalho nasce em duas colunas espremidas no celular.
        foreach (['cob-cab-identidade', 'cob-cab-lateral'] as $coluna) {
            $classe = (string) $cabecalho->filter('.' . $coluna)->attr('class');
            self::assertStringContainsString('col-12', $classe, "{$coluna} tem de ocupar a largura toda no estreito");
            self::assertMatchesRegularExpression('/\bcol-lg-\d+\b/', $classe, "{$coluna} tem de separar só no lg");
        }
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

    private function valorDoCard(Crawler $cabecalho, string $card): int
    {
        $val = $cabecalho->filter('.cob-cab-card[data-card="' . $card . '"] .cob-cab-card-val');
        self::assertCount(1, $val, "não achei o card \"{$card}\" no cabeçalho");

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
