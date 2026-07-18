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

        $html = (string) $client->request('GET', '/cobrancas/objetos/' . $objetoId)
            ->html();
        self::assertResponseIsSuccessful();

        // Abas + âncora (pasta-arquivos.js e os redirects #secao-divida dependem destes ids)
        foreach (['id="objetoTabs"', 'id="tab-cobranca"', 'id="tab-documentos"', 'id="tab-historico"',
                  'id="documentos-tab"', 'id="secao-divida"'] as $gancho) {
            self::assertStringContainsString($gancho, $html, "Sumiu o gancho: {$gancho}");
        }

        // Seleção da dívida
        foreach (['id="barraSelecaoDivida"', 'jp-check', 'data-selecao-qtd', 'data-selecao-total',
                  'data-acao="acordar-selecionadas"', 'data-acao="limpar-selecao"'] as $gancho) {
            self::assertStringContainsString($gancho, $html, "Sumiu o gancho: {$gancho}");
        }

        // Modais que o JS abre/rehidrata (ids fixos)
        foreach (['id="modalRegistrarPagamento"', 'id="modalCriarAcordo"', 'id="modalEditarObrigacao"',
                  'id="modalExcluirObrigacao"', 'id="modalConcluirAcao"'] as $gancho) {
            self::assertStringContainsString($gancho, $html, "Sumiu o gancho: {$gancho}");
        }
    }
}
