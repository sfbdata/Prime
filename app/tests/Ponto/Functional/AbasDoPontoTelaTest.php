<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E3 do desenho do Ponto (desenho 16 L166 a L191): o cartão de abas logo abaixo da Folha, com "Pendências" (P37) e
 * "Minhas Justificativas" (P39). Arranjo por filho direto, os rótulos das situações (P40 e D22), a confirmação antes
 * de excluir (D4) e o que saiu (o cartão antigo, os selos do Bootstrap e os textos "Pendente" e "Rejeitado").
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class AbasDoPontoTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const CARTAO = '.pe-page > .pe-grade > .pe-dir > #ponto-regiao-folha + section.pe-abas[data-screen-label="Registros e comprovantes"]';
    private const BARRA = self::CARTAO . ' > div.pe-abas-barra[role="tablist"]:first-child';
    private const LINHAS = self::CARTAO . ' > #ponto-painel-justificativas[role="tabpanel"] > div.pe-abas-rolagem > table.pe-tab:not(.pe-resp) > tbody > tr.pe-just-lin';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;
    private \DateTimeImmutable $dia;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Abas');
        $this->eu         = $this->pessoa('abas');
        $this->vincularColaborador($this->eu, $this->escritorio);
        $this->dia = new \DateTimeImmutable('first day of this month');
    }

    #[TestDox('Arranjo do desenho: abas logo abaixo da Folha, Pendências primeira e aberta, Minhas Justificativas em seguida')]
    public function testArranjoDasAbas(): void
    {
        $this->justificativa($this->eu, $this->escritorio, $this->dia, 'pendente');
        $this->justificativa($this->eu, $this->escritorio, $this->dia, 'abonado');

        $tela = $this->tela();

        self::assertCount(1, $tela->filter(self::CARTAO), 'o cartão de abas vem logo depois da Folha, na coluna da direita');
        self::assertCount(2, $tela->filter(self::BARRA . ' > button.pe-aba[role="tab"]'), 'só Pendências e Minhas Justificativas (Banco de Horas na E17)');
        $pendencias = $tela->filter(self::BARRA . ' > button#ponto-aba-pendencias[role="tab"]:first-child[aria-selected="true"][aria-controls="ponto-regiao-pendencias"]');
        self::assertCount(1, $pendencias, 'Pendências é a primeira e abre ao entrar (P37)');
        self::assertCount(1, $pendencias->filter('button > i.bi.bi-exclamation-triangle:first-child'));
        self::assertSame('Pendências', trim($pendencias->text()));
        self::assertCount(0, $pendencias->filter('button > span'), 'sem pendência, sem número');
        $justificativas = $tela->filter(self::BARRA . ' > button#ponto-aba-justificativas[role="tab"]:nth-child(2)[aria-selected="false"][tabindex="-1"]');
        self::assertCount(1, $justificativas->filter('button > i.bi.bi-calendar-check:first-child'));
        self::assertSame('Minhas Justificativas2', trim($justificativas->text()), 'o número é o das justificativas do mês');
        self::assertCount(1, $justificativas->filter('button > span.pe-aba-num:last-child'));

        $painel = $tela->filter(self::BARRA . ' + div#ponto-regiao-pendencias.pe-abas-painel[role="tabpanel"]:not([hidden])');
        self::assertCount(1, $painel, 'o painel das pendências logo abaixo das abas');
        self::assertCount(1, $painel->filter('div > div.pe-pend-texto:first-child + div#pendencias-ponto.pe-pend-lista > div.pe-abas-vazio:only-child'));
        self::assertSame('Dias dos últimos 30 em que faltou uma batida. Elas não impedem o registro do ponto: envie o registro de esquecimento para regularizar.', trim($painel->filter('.pe-pend-texto')->text()));
        self::assertSame('Nenhuma pendência de registro.', trim($painel->filter('.pe-abas-vazio')->text()));
        self::assertCount(1, $tela->filter(self::CARTAO . ' > #ponto-regiao-pendencias + div#ponto-painel-justificativas[role="tabpanel"][hidden]'));
    }

    #[TestDox('A aba pelo endereço (?aba=justificativas): abre Minhas Justificativas; a volta da exclusão leva o mês e a aba')]
    public function testAbaPeloEndereco(): void
    {
        $tela = $this->tela('?aba=justificativas');

        self::assertCount(1, $tela->filter(self::BARRA . ' > #ponto-aba-justificativas[aria-selected="true"][tabindex="0"]'));
        self::assertCount(1, $tela->filter(self::BARRA . ' > #ponto-aba-pendencias[aria-selected="false"]'));
        self::assertCount(1, $tela->filter(self::CARTAO . ' > #ponto-regiao-pendencias[hidden]'));
        self::assertCount(1, $tela->filter(self::CARTAO . ' > #ponto-painel-justificativas:not([hidden]) > div.pe-abas-vazio:only-child'));
        self::assertSame('Nenhuma justificativa enviada nesta competência.', trim($tela->filter('#ponto-painel-justificativas')->text()));
        self::assertSame(
            '/ponto/?competencia=' . $this->dia->format('Y-m') . '&aba=justificativas',
            $tela->filter(self::CARTAO)->attr('data-url-volta'),
        );
    }

    #[TestDox('Aba pedida que não está na tela (Comprovantes desligado, Banco antes da E17) ou que não existe: abre Pendências')]
    public function testAbaEscondidaOuDesconhecidaAbrePendencias(): void
    {
        foreach (['?aba=comprovantes', '?aba=banco', '?aba=qualquer'] as $consulta) {
            $tela = $this->tela($consulta);

            self::assertCount(1, $tela->filter(self::BARRA . ' > #ponto-aba-pendencias[aria-selected="true"][tabindex="0"]'), $consulta);
            self::assertCount(1, $tela->filter(self::CARTAO . ' > #ponto-regiao-pendencias:not([hidden])'), $consulta);
            self::assertCount(1, $tela->filter(self::CARTAO . ' > .pe-abas-painel:not([hidden])'), $consulta . ': um painel aberto só');
            self::assertCount(0, $tela->filter('#ponto-aba-comprovantes, #ponto-aba-banco'), $consulta);
        }
    }

    #[TestDox('Linhas da aba: só o mês e o escritório; colunas, selos e situações no masculino da aba (P40, D22)')]
    public function testLinhasDasJustificativas(): void
    {
        $analise = $this->justificativa($this->eu, $this->escritorio, $this->dia, 'pendente', ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'saida', 'horaRegistroEsquecido' => '23:59']);
        $abonada = $this->justificativa($this->eu, $this->escritorio, $this->dia, 'abonado', ['observacaoAnalise' => 'Conferido.', 'dataAnalise' => $this->dia->format('Y-m-d')]);
        $recusada = $this->justificativa($this->eu, $this->escritorio, $this->dia, 'rejeitado', ['dataAnalise' => $this->dia->format('Y-m-d')]);
        $falta = $this->justificativa($this->eu, $this->escritorio, $this->dia, 'abonado', ['tipo' => 'falta_nao_justificada']);
        $this->justificativa($this->eu, $this->escritorio, $this->dia->modify('-1 month'), 'pendente');
        $outro = $this->escritorio('Outro');
        $this->vincularColaborador($this->eu, $outro);
        $this->justificativa($this->eu, $outro, $this->dia, 'pendente');

        $tela = $this->tela('?aba=justificativas');

        self::assertCount(4, $tela->filter(self::LINHAS), 'só as do mês escolhido, só deste escritório');
        self::assertSame(
            ['Data(s)', 'Tipo', 'Status', 'Atestado', 'Obs. da Análise', 'Analisado em', ''],
            $tela->filter(self::CARTAO . ' table.pe-tab > thead > tr > th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        $linha = $this->linha($tela, (int) $analise->getId());
        self::assertSame($this->dia->format('d/m/Y'), $linha->filter('tr > td:first-child > span.pe-just-data')->text());
        self::assertSame('Esquecimento de Registro', $linha->filter('tr > td.pe-just-celtipo:nth-child(2) > span.pe-just-tipo')->text());
        self::assertSame('Saída às 23:59', $linha->filter('tr > td.pe-just-celtipo:nth-child(2) > div.pe-just-sub')->text());
        self::assertSame('Em análise', trim($linha->filter('tr > td:nth-child(3) > span.pe-just.pe-just--analise')->text()));
        self::assertCount(1, $linha->filter('tr > td:nth-child(3) > span > i.bi-hourglass-split'));
        self::assertSame(['Sem anexo', 'Aguardando análise', ''], $this->celulasDeTexto($linha));
        self::assertCount(1, $linha->filter('tr > td:last-child > button.pe-just-icone[type="button"][aria-label="Excluir justificativa"][title="Excluir enquanto não analisada"] > i.bi-trash3'));
        self::assertCount(1, $linha->filter('tr > td:last-child > button[title="Editar justificativa"] > i.bi-pencil'), 'o lápis fica (P42)');
        self::assertCount(0, $linha->filter('[data-justificativa-bloqueada]'));
        self::assertCount(0, $linha->filter('.pe-just-analise'), 'colaborador não analisa a própria');

        $linha = $this->linha($tela, (int) $abonada->getId());
        self::assertSame('Abonado', trim($linha->filter('tr > td:nth-child(3) > span.pe-just.pe-just--abonada')->text()));
        self::assertSame(['Sem anexo', 'Conferido.', $this->dia->format('d/m/Y')], $this->celulasDeTexto($linha));
        self::assertCount(1, $linha->filter('tr > td:last-child > span.pe-just-trava[data-justificativa-bloqueada][aria-label="Análise concluída"][title="Análise concluída: não pode ser alterada."] > i.bi-lock'));
        self::assertCount(0, $linha->filter('button'), 'analisada: nem lixeira nem lápis');

        $linha = $this->linha($tela, (int) $recusada->getId());
        self::assertSame('Recusado', trim($linha->filter('tr > td:nth-child(3) > span.pe-just.pe-just--recusada > i.bi-x-circle')->ancestors()->first()->text()));
        self::assertSame('Não informado', $this->celulasDeTexto($linha)[1]);

        $linha = $this->linha($tela, (int) $falta->getId());
        self::assertSame('Não abonado', trim($linha->filter('tr > td:nth-child(3) > span.pe-just.pe-just--nao-abonada')->text()), 'sistema, fica');
    }

    #[TestDox('O que saiu (P37, P39, P40) conta zero no cartão: cartões, selos e botões do Bootstrap, "Pendente", "Rejeitado", "Sem registro"')]
    public function testOQueSaiuContaZero(): void
    {
        $this->justificativa($this->eu, $this->escritorio, $this->dia, 'pendente');
        $this->justificativa($this->eu, $this->escritorio, $this->dia, 'rejeitado');

        $tela   = $this->tela();
        $cartao = $tela->filter(self::CARTAO);

        foreach (['.card', '.card-header', '.card-body', 'h3.card-title', '.badge', '.btn', 'table.table', 'ul', 'li', '.text-muted'] as $saiu) {
            self::assertCount(0, $cartao->filter($saiu), $saiu . ' saiu');
        }
        $texto = $cartao->text();
        foreach (['Pendente', 'Rejeitado', 'Sem registro', 'Não está mais pendente', 'Pendências de registro', '—', '–'] as $saiu) {
            self::assertStringNotContainsString($saiu, $texto);
        }
        self::assertCount(1, $tela->filter('.pe-dir > section.pe-abas'), 'um cartão só: as pendências e as justificativas não ficam também fora dele');
    }

    #[TestDox('Confirmação antes de apagar (D4): a lixeira só abre a janela; quem apaga é o "Excluir" da janela')]
    public function testConfirmacaoAntesDeApagar(): void
    {
        $j = $this->justificativa($this->eu, $this->escritorio, $this->dia, 'pendente');

        $tela = $this->tela('?aba=justificativas');

        $lixeira = $this->linha($tela, (int) $j->getId())->filter('button[aria-label="Excluir justificativa"]');
        self::assertSame('button', $lixeira->attr('type'), 'não envia nada sozinha');
        self::assertSame('/ponto/justificativa/' . $j->getId() . '/excluir', $lixeira->attr('data-excluir-url'));
        self::assertSame($this->dia->format('d/m/Y') . ' · Atestado Médico', $lixeira->attr('data-info'));
        self::assertCount(0, $tela->filter('form[action*="/excluir"]'), 'nenhum formulário apaga direto');

        $janela = $tela->filter('.pe-page > #ponto-janela-excluir.pe-janela-fundo[hidden] > div.pe-janela[role="dialog"][aria-modal="true"]');
        self::assertCount(1, $janela, 'a janela nasce fechada, na moldura do desenho');
        self::assertSame('Excluir justificativa', $janela->filter('div.pe-janela > div.pe-janela-topo:first-child > b.pe-janela-titulo')->text());
        self::assertCount(1, $janela->filter('div.pe-janela-topo > i.bi-trash3:first-child'));
        self::assertCount(1, $janela->filter('div.pe-janela-topo > button.pe-janela-fechar[aria-label="Fechar"]:last-child > i.bi-x-lg'));
        self::assertSame(
            ['Cancelar', 'Excluir'],
            $janela->filter('div.pe-janela > div.pe-janela-rodape:last-child > button')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertCount(1, $janela->filter('div.pe-janela-rodape > button.pe-janela-acao[data-excluir-confirmar]:last-child'));
    }

    #[TestDox('P40 na ficha do gestor: "Em análise", "Abonado", "Recusado" e o botão "Recusar"; nada de "Pendente", "Rejeitado" nem "rejeição"')]
    public function testRotulosNaFichaDoGestor(): void
    {
        $this->justificativa($this->eu, $this->escritorio, $this->dia, 'pendente');
        $this->justificativa($this->eu, $this->escritorio, $this->dia->modify('+1 day'), 'abonado');
        $this->justificativa($this->eu, $this->escritorio, $this->dia->modify('+2 days'), 'rejeitado');
        $gestor = $this->pessoa('gestor');
        $this->vincularPrimario($gestor, $this->escritorio);

        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=justificativas&competencia=%s', $this->escritorio->getId(), $this->eu->getId(), $this->dia->format('Y-m')));
        self::assertResponseIsSuccessful();

        $selos = $ficha->filter('#justificativas .badge, [id^="batch-"] .badge')->each(static fn (Crawler $b): string => trim($b->text()));
        foreach (['Em análise', 'Abonado', 'Recusado'] as $rotulo) {
            self::assertContains($rotulo, $selos, $rotulo);
        }
        self::assertNotContains('Pendente', $selos);
        self::assertNotContains('Rejeitado', $selos);
        self::assertStringContainsString('Voltar para análise', $ficha->text());
        self::assertStringNotContainsString('Reverter para Pendente', $ficha->text());

        $recusa = $ficha->filter('form[action$="/rejeitar"]');
        self::assertCount(1, $recusa);
        self::assertSame('Recusar', trim($recusa->filter('button[type="submit"]')->text()));
        self::assertSame('Motivo da recusa (obrigatório)', $recusa->filter('input[name="observacaoAnalise"]')->attr('placeholder'));
        self::assertSame('Informe o motivo da recusa.', $recusa->attr('data-sem-motivo'));
        self::assertSame('Recusar justificativa de ' . $this->dia->format('d/m/Y') . '?', $recusa->attr('data-confirmar'));
        self::assertStringContainsString('alert(this.dataset.semMotivo)', (string) $recusa->attr('onsubmit'));
        self::assertStringContainsString('confirm(this.dataset.confirmar)', (string) $recusa->attr('onsubmit'));
        self::assertDoesNotMatchRegularExpression('/Rejeitar|rejeição/u', $recusa->outerHtml());
    }

    private function tela(string $consulta = ''): Crawler
    {
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function linha(Crawler $tela, int $id): Crawler
    {
        $linha = $tela->filter(self::LINHAS . '[data-justificativa="' . $id . '"]');
        self::assertCount(1, $linha);

        return $linha;
    }

    /** @return list<string> Atestado, Obs. da Análise e Analisado em */
    private function celulasDeTexto(Crawler $linha): array
    {
        return $linha->filter('tr > td.pe-just-cel')->each(static fn (Crawler $td): string => trim($td->text()));
    }
}
