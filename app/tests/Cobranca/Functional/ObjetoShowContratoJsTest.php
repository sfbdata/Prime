<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Ajuste 11 (redesign): trava o CONTRATO de marcação que o JS de show.html.twig depende.
 * O redesign é reveste-primeiro: nenhum destes ganchos pode sumir. Se algum sumir, ESTE teste falha.
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoShowContratoJsTest extends CobrancaWebTestCase
{
    #[TestDox('"Ver na dívida" leva à ABA da dívida: carrega o gancho que o JS usa para ativá-la antes de rolar')]
    public function testVerNaDividaCarregaOGanchoDeAbertura(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Obrigação VENCIDA: é o que faz nascer o alerta que traz o botão.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota vencida', 'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-30 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $botao = $crawler->filter('.jp-alerta-acoes a[href="#secao-divida"]');
        self::assertCount(1, $botao, 'o alerta de vencida tem de oferecer "Ver na dívida"');

        // O alvo do link mora DENTRO do pane da aba Dívida, que nasce oculto: sem este gancho o clique
        // não sai do lugar, porque não se rola até elemento invisível.
        self::assertSame('divida', $botao->attr('data-abrir-aba'), 'sem o gancho o botão vira clique morto');
        self::assertSelectorExists('#tab-divida #secao-divida', 'o alvo da rolagem tem de estar dentro do pane da aba');
        self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-divida"]', 'o JS ativa a aba por este gatilho');
    }

    #[TestDox('A página do objeto mantém todos os ganchos de id/data-* que o JS usa')]
    public function testGanchosDeJsPresentes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        // A barra de seleção (`#barraSelecaoDivida`) só nasce no HTML quando há dívida original
        // acordável (INV-I) — sem obrigação avulsa, o gancho nem é renderizado.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial', 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ]);

        // `#modalConcluirAcao` (`_acoes_modais.html.twig`) só nasce no HTML quando há uma próxima
        // ação PENDENTE no caso — sem ela, o gancho nem é renderizado.
        ProximaAcaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Ligar para o devedor',
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertResponseIsSuccessful();

        // Todos os ganchos são checados via SELETOR CSS contra o DOM parseado (assertSelectorExists),
        // nunca por substring do HTML bruto: o `<script>` incondicional de show.html.twig referencia
        // os MESMOS literais (ex.: `querySelector('[data-selecao-qtd]')`) — uma checagem por substring
        // continuaria verde mesmo se o restyle apagasse o gancho só do MARKUP, deixando o script morto
        // sem detectar nada (falso-positivo).

        // Abas + âncora (pasta-arquivos.js e os redirects #secao-divida dependem destes ids)
        foreach (['#objetoTabs', '#tab-cobranca', '#tab-documentos', '#tab-historico',
                  '#documentos-tab', '#secao-divida'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }

        // Seleção da dívida
        foreach (['#barraSelecaoDivida', '#secao-divida .jp-check', '[data-selecao-qtd]',
                  '[data-selecao-total]', '[data-acao="acordar-selecionadas"]',
                  '[data-acao="limpar-selecao"]'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }

        // Modais que o JS abre/rehidrata (ids fixos)
        foreach (['#modalRegistrarPagamento', '#modalCriarAcordo', '#modalEditarObrigacao',
                  '#modalRegistrarObrigacao', '#modalExcluirObrigacao', '#modalConcluirAcao'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }

        // Taxa por-obrigação (Task 9, espelho R$↔%): cada encargo é um bloco `.jp-encargo[data-encargo=…]`
        // com um TRIO de campos do Form (Task 8) — "%" (`jp-taxa-pct`), "R$" (`jp-taxa-reais`) e o modo
        // hidden (`jp-taxa-modo`) que diz ao servidor qual dos dois foi editado por último. Diferente do
        // F4/Ajuste-11 antigo, os DOIS (% e R$) são campos reais SUBMETIDOS — não há mais um "% auxiliar
        // sem name" (o servidor recalcula a %/taxa a partir de qualquer um deles, Task 5/7). Se qualquer
        // gancho sumir, o par se desfaz em silêncio: o gestor digitaria algo que não vira taxa nenhuma.
        // Vale nos DOIS modais.
        foreach (['#modalEditarObrigacao', '#modalRegistrarObrigacao'] as $modal) {
            self::assertSelectorExists($modal . ' .jp-encargos-wrap', "Sumiu o wrapper de encargos em {$modal}");

            foreach (['juros', 'multa', 'correcao'] as $encargo) {
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"][data-base="valorOriginal"]',
                    "Sumiu o bloco de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-pct',
                    "Sumiu o input de % de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-reais',
                    "Sumiu o campo em R$ de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-modo',
                    "Sumiu o modo (hidden) de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-herda',
                    "Sumiu o indicador \"herda do caso\" de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-limpar',
                    "Sumiu a ação de limpar (voltar a herdar) de {$encargo} em {$modal}",
                );
            }
            // A base do espelho: sem ela o JS não deriva o R$ de juros/multa/correção a partir do %.
            self::assertSelectorExists($modal . ' input[name$="[valorOriginal]"]', "Sumiu a base do % em {$modal}");

            // Ajuste 2 (Fatia B), preservado na taxa por-obrigação: o honorário é o 4º bloco, mas com base
            // COMPOSTA (`data-base="composta"`) — o JS soma valorOriginal + as R$ dos outros três. Se o
            // marcador `composta` sumir, o espelho leria o honorário sobre a base errada, em silêncio e
            // sobre dinheiro (risco 2 da spec).
            self::assertSelectorExists(
                $modal . ' .jp-encargo[data-encargo="honorarios"][data-base="composta"]',
                "Sumiu o bloco de honorários com base composta em {$modal}",
            );
            self::assertSelectorExists(
                $modal . ' .jp-encargo[data-encargo="honorarios"] .jp-taxa-pct',
                "Sumiu o input de % de honorários em {$modal}",
            );
            self::assertSelectorExists(
                $modal . ' .jp-encargo[data-encargo="honorarios"] .jp-taxa-reais',
                "Sumiu o campo em R$ de honorários em {$modal}",
            );
        }

        // O `modo` é o discriminador de qual campo venceu (herda|percent|reais) — tem de ser hidden, nunca
        // um campo visível/editável direto: quem o seta é o JS (ou o default 'herda' do Form), nunca a
        // digitação do usuário.
        self::assertSelectorExists('input[type="hidden"].jp-taxa-modo', 'o modo do encargo tem de ser hidden');
        self::assertSelectorNotExists('.jp-taxa-modo:not([type="hidden"])', 'o modo do encargo não pode ser um campo visível');

        // FIX crítico (Task 9): a linha publica a taxa CRUA de override de cada encargo (bp; vazio =
        // herda) no gatilho de "Editar" — sem isto, o modal não tem como reidratar o override ATUAL da
        // obrigação ao abrir, e qualquer submissão (mesmo só corrigir a descrição) apaga o override em
        // silêncio (o servidor sempre DERIVA os 4 overrides do que o Form recebe, nunca preserva por
        // omissão). DOM parseado, mesmo padrão do resto do arquivo — nunca substring do HTML bruto.
        foreach (['juros', 'multa', 'correcao', 'honorarios'] as $encargo) {
            self::assertSelectorExists(
                '#secao-divida [data-bs-target="#modalEditarObrigacao"][data-taxa-' . $encargo . '-bp]',
                "Sumiu o gancho data-taxa-{$encargo}-bp no gatilho de Editar da linha",
            );
        }

        // O handler de abertura do modal (`show.bs.modal` de `#modalEditarObrigacao`) precisa LER esses
        // ganchos para rehidratar — aqui SIM checamos o texto do `<script>` (não há seletor CSS para
        // "referenciado dentro de uma função JS"): o literal abaixo é o `getAttribute` que lê o data-*
        // acima, ao vivo, um por encargo (`'data-taxa-' + campo + '-bp'`). Se o handler for reescrito sem
        // este acesso, o gancho markup continua existindo mas morto — este teste é o único que pegaria
        // essa regressão (PHPUnit não executa JS; ver relatório da Task 9, seção FIX).
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            "botao.getAttribute('data-taxa-' + campo + '-bp')",
            $html,
            'O handler de abertura do modal de Editar parou de ler o data-taxa-*-bp por encargo (rehidratação morta)',
        );

        // A "Próxima ação" saiu do cartão do trilho e virou faixa compacta no topo da aba Cobrança —
        // o gancho visual, não só o modal que ela abre. O trilho não existe mais.
        self::assertSelectorExists('#tab-cobranca .cob-proxima-faixa', 'Sumiu a faixa Próxima ação da aba Cobrança');
        self::assertSelectorExists('.cob-proxima-faixa [data-bs-target="#modalDefinirAcao"], .cob-proxima-faixa [data-bs-target="#modalConcluirAcao"]', 'a faixa perdeu o gatilho de definir/concluir');

        // SPEC UX §6.1 (2026-07-26): "Encerrar cobrança" saiu do cartão do trilho e virou botão do
        // CABEÇALHO, junto do status que ele muda. Neste cenário há Obrigação com restante > 0 →
        // saldoExigivel > 0 → `caso.prontoParaEncerrar` é false → renderiza DESABILITADO, ensinando a
        // condição em vez de sumir. Cobre o ramo desabilitado; o ramo habilitado (saldo zerado) e
        // Judicializar (módulo pastas) exigem cenário/tenant extra — ver FOLLOW-UP no relatório.
        self::assertSelectorExists('.content-header .cob-acao-encerrar.is-disabled', 'Sumiu o botão desabilitado de Encerrar cobrança no cabeçalho');
    }
}
