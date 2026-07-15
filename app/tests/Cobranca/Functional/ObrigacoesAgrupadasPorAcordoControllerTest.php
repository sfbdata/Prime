<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Enum\StatusAcordo;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Aba Obrigações agrupada por acordo (Ajuste 8): o acordo VIGENTE vira uma linha-resumo que expande
 * as parcelas e leva ao detalhe; as obrigações substituídas saem da lista solta (vivem no detalhe do
 * acordo). Parcela de acordo DESFEITO é histórico e continua na lista solta.
 */
#[CoversClass(ObjetoController::class)]
final class ObrigacoesAgrupadasPorAcordoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Acordo vigente vira linha-resumo com as parcelas dentro e link para o detalhe')]
    public function testAcordoVigenteViraGrupoComParcelas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2', 'valorOriginal' => 20000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        // A original substituída deve SAIR da lista solta (fica no detalhe do acordo).
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Divida original antiga', 'valorOriginal' => 60000, 'encargosReconhecidos' => 0, 'acordoSubstituto' => $acordo]);
        // Uma obrigação sem acordo nenhum: segue na lista solta.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Cobranca avulsa', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $aba = $crawler->filter('#tab-obrigacoes');

        // Linha-resumo do acordo: qtd de parcelas, quantas substituiu e o total derivado (300+200).
        $resumo = $aba->filter('.card-header')->text();
        self::assertStringContainsString('2 parcela(s)', $resumo);
        self::assertStringContainsString('substituiu 1 obrigação(ões)', $resumo);
        self::assertStringContainsString('500,00', $resumo);
        // Link para o detalhe do acordo (item 7).
        self::assertCount(1, $aba->filter('a[href="/cobrancas/acordos/' . $acordo->getId() . '"]'));

        // As parcelas vivem DENTRO do grupo colapsável, não soltas.
        $grupo = $aba->filter('#grupoAcordo' . $acordo->getId())->text();
        self::assertStringContainsString('Parcela 1/2', $grupo);
        self::assertStringContainsString('Parcela 2/2', $grupo);

        $texto = $aba->text();
        self::assertStringContainsString('Cobranca avulsa', $texto, 'obrigação sem acordo segue na lista');
        self::assertStringNotContainsString('Divida original antiga', $texto, 'substituída sai da lista solta');
    }

    #[TestDox('Parcela de acordo desfeito NÃO vira grupo: volta para a lista solta (histórico)')]
    public function testAcordoDesfeitoNaoAgrupaEParcelaVoltaParaLista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Cancelado])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela de acordo desfeito', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        // Substituída por acordo NÃO vigente: voltou ao saldo → tem de aparecer na lista.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Original restaurada', 'valorOriginal' => 60000, 'encargosReconhecidos' => 0, 'acordoSubstituto' => $acordo]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $aba = $crawler->filter('#tab-obrigacoes');

        self::assertCount(0, $aba->filter('#grupoAcordo' . $acordo->getId()), 'acordo desfeito não vira grupo');
        $texto = $aba->text();
        self::assertStringContainsString('Parcela de acordo desfeito', $texto);
        self::assertStringContainsString('Original restaurada', $texto, 'a original voltou ao saldo → volta à lista');
    }

    #[TestDox('Acordo-sobre-acordo: parcela de A já substituída por B vigente sai do grupo e do total de A')]
    public function testParcelaSubstituidaPorOutroAcordoNaoInflaOGrupo(): void
    {
        // Cenário real e suportado (spec do ajuste 7 §13): B substitui parcelas de A ainda vigente.
        // A parcela substituída está FORA do exigível → não pode contar no grupo de A, senão o resumo
        // contradiz o saldo derivado e ela é contada duas vezes (em A e no "substituiu N" de B).
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $acordoA = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoB = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        // Parcela viva de A.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela viva de A', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordoA]);
        // Parcela de A que B já substituiu: fora do saldo.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela morta de A', 'valorOriginal' => 90000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordoA, 'acordoSubstituto' => $acordoB]);
        // Parcela viva de B (a dívida agora corre por aqui).
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela viva de B', 'valorOriginal' => 90000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordoB]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $aba = $crawler->filter('#tab-obrigacoes');

        // Grupo de A: só a parcela viva (300,00) — a morta não entra nem soma.
        $grupoA = $aba->filter('#grupoAcordo' . $acordoA->getId())->text();
        self::assertStringContainsString('Parcela viva de A', $grupoA);
        self::assertStringNotContainsString('Parcela morta de A', $grupoA);

        $texto = $aba->text();
        self::assertStringNotContainsString('Parcela morta de A', $texto, 'substituída sai da aba inteira');
        // O resumo de A fecha em 300,00 (e não em 1.200,00, que seria o total inflado).
        self::assertStringContainsString('300,00', $texto);
        self::assertStringNotContainsString('1.200,00', $texto);
    }

    #[TestDox('Acordo vigente sem parcela viva não vira grupo')]
    public function testAcordoVigenteSemParcelasNaoViraGrupo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Cobranca avulsa', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#tab-obrigacoes #grupoAcordo' . $acordo->getId()));
        self::assertStringContainsString('Cobranca avulsa', $crawler->filter('#tab-obrigacoes')->text());
    }

    #[TestDox('Tudo dentro de acordo: a lista solta explica em vez de dizer que não há obrigação')]
    public function testTudoNoAcordoMostraAvisoProprio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $texto = $crawler->filter('#tab-obrigacoes')->text();
        self::assertStringContainsString('Todas as obrigações desta cobrança estão nos acordos acima.', $texto);
        self::assertStringNotContainsString('Nenhuma obrigação registrada.', $texto);
    }

    #[TestDox('Objeto sem obrigação nenhuma: mantém o aviso de lista vazia')]
    public function testSemObrigacoesMantemAviso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma obrigação registrada.', $crawler->filter('#tab-obrigacoes')->text());
    }
}
