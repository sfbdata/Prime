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
 * A dívida agrupada por acordo (Ajuste 8): o acordo VIGENTE vira um bloco com as suas parcelas — que são
 * a dívida agora — e leva ao detalhe. Parcela de acordo DESFEITO é histórico e continua na lista solta.
 *
 * Ajuste 10 (redesenho): a aba "Obrigações" virou a seção `#secao-divida` da aba Cobrança. A lista solta é
 * a `.jp-lista` direta da seção; cada acordo vigente é um `#grupoAcordo{id}` abaixo dela. As obrigações que
 * um acordo substituiu deixaram de sumir da tela: ficam RECOLHIDAS dentro do grupo do acordo que as trocou
 * (`.jp-obr.is-substituida`), fora do total em aberto.
 */
#[CoversClass(ObjetoController::class)]
final class ObrigacoesAgrupadasPorAcordoControllerTest extends CobrancaWebTestCase
{
    /** A lista solta: o que NÃO está em acordo vigente (a `.jp-lista` direta da seção da dívida). */
    private const LISTA_SOLTA = '#secao-divida > .jp-lista';

    #[TestDox('Acordo vigente vira bloco com as parcelas dentro e link para o detalhe')]
    public function testAcordoVigenteViraGrupoComParcelas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2', 'valorOriginal' => 20000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        // A original substituída deve SAIR da lista solta (fica recolhida no grupo do acordo — Ajuste 10).
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Divida original antiga', 'valorOriginal' => 60000, 'encargosReconhecidos' => 0, 'acordoSubstituto' => $acordo]);
        // Uma obrigação sem acordo nenhum: segue na lista solta.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Cobranca avulsa', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $divida = $crawler->filter('#secao-divida');

        // Cabeçalho do acordo: qtd de parcelas, quantas substituiu e o total derivado (300+200).
        $resumo = $divida->filter('.jp-acordo-head')->text();
        self::assertStringContainsString('2 parcela(s)', $resumo);
        self::assertStringContainsString('substituiu 1 obrigação(ões)', $resumo);
        self::assertStringContainsString('500,00', $resumo);
        // Link para o detalhe do acordo (item 7).
        self::assertCount(1, $divida->filter('a[href="/cobrancas/acordos/' . $acordo->getId() . '"]'));

        // As parcelas vivem DENTRO do bloco do acordo, não soltas (`text()` lê só o 1º nó → junta todos).
        $parcelas = implode(' | ', $divida
            ->filter('#grupoAcordo' . $acordo->getId() . ' .jp-obr:not(.is-substituida)')
            ->each(static fn ($linha) => $linha->text()));
        self::assertStringContainsString('Parcela 1/2', $parcelas);
        self::assertStringContainsString('Parcela 2/2', $parcelas);

        self::assertStringContainsString('Cobranca avulsa', $divida->filter(self::LISTA_SOLTA)->text(), 'obrigação sem acordo segue na lista');
        self::assertStringNotContainsString('Divida original antiga', $divida->filter(self::LISTA_SOLTA)->text(), 'substituída sai da lista solta');

        // Ajuste 10: mas não SOME — fica recolhida no acordo que a trocou, com a explicação.
        $substituidas = $divida->filter('#substituidasAcordo' . $acordo->getId())->text();
        self::assertStringContainsString('Divida original antiga', $substituidas);
        self::assertStringContainsString('volta ao total se o acordo for rompido', $substituidas);
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
        $divida = $crawler->filter('#secao-divida');

        self::assertCount(0, $divida->filter('#grupoAcordo' . $acordo->getId()), 'acordo desfeito não vira grupo');
        $solta = $divida->filter(self::LISTA_SOLTA)->text();
        self::assertStringContainsString('Parcela de acordo desfeito', $solta);
        self::assertStringContainsString('Original restaurada', $solta, 'a original voltou ao saldo → volta à lista');
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
        $divida = $crawler->filter('#secao-divida');

        // Grupo de A: só a parcela viva (300,00) — a morta não entra nem soma.
        $grupoA = $divida->filter('#grupoAcordo' . $acordoA->getId());
        self::assertStringContainsString('Parcela viva de A', $grupoA->text());
        self::assertStringNotContainsString('Parcela morta de A', $grupoA->text());
        // O resumo de A fecha em 300,00 (e não em 1.200,00, que seria o total inflado).
        $resumoA = $grupoA->filter('.jp-acordo-head')->text();
        self::assertStringContainsString('300,00', $resumoA);
        self::assertStringNotContainsString('1.200,00', $resumoA);

        self::assertStringNotContainsString('Parcela morta de A', $divida->filter(self::LISTA_SOLTA)->text(), 'substituída não volta para a lista solta');
        // Ajuste 10: a morta não some — reaparece recolhida sob B, que foi quem a substituiu.
        self::assertStringContainsString('Parcela morta de A', $divida->filter('#substituidasAcordo' . $acordoB->getId())->text());
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
        self::assertCount(0, $crawler->filter('#secao-divida #grupoAcordo' . $acordo->getId()));
        self::assertStringContainsString('Cobranca avulsa', $crawler->filter(self::LISTA_SOLTA)->text());
        // Sem grupo que o represente, o acordo não some da página: cai em "Acordos encerrados".
        self::assertStringContainsString('Acordo #' . $acordo->getId(), $crawler->filter('#secao-acordos-encerrados')->text());
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
        $texto = $crawler->filter('#secao-divida')->text();
        self::assertStringContainsString('Todas as obrigações desta cobrança estão nos acordos abaixo.', $texto);
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
        self::assertStringContainsString('Nenhuma obrigação registrada.', $crawler->filter('#secao-divida')->text());
    }
}
