<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\LoteDoVotoController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * As telas do ciclo de vida da votação (F1-2b e F1-3, desenho 18): itens de menu por situação, janela
 * Retirar/Suspender/Cancelar, chips, linha "próximo passo", faixa de arquivada, Mostrar/Ocultar arquivadas, seleção em
 * lote (sucesso, as que não se encaixam, CSRF, outro escritório), Resultados e Acompanhar sem arquivada e retirada, o
 * telão com Suspender e Retomar e a ata de retirada e de cancelada.
 */
#[CoversClass(LoteDoVotoController::class)]
#[Group('voto')]
final class CicloNasTelasTest extends JusPrimeWebTestCase
{
    use Factories;

    private const DATA = '\d\d\/\d\d\/\d{4} \d\d:\d\d';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio das Telas ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $this->assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2026'])->publicada()->create()->_real();
    }

    // ---- menu e janela -------------------------------------------------------------------------------------

    #[TestDox('Menu da pauta por situação: publicada retira, aberta suspende e cancela, suspensa retoma e cancela, encerrada não tem menu')]
    public function testMenuDaPautaPorSituacao(): void
    {
        $aguardando = $this->pauta(1, 'Aguardando');
        $aberta = $this->pauta(2, 'Aberta', true);
        $suspensa = $this->pauta(3, 'Suspensa', true);
        $suspensa->suspender('Queda de energia', 'Mesa');
        $encerrada = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 4, 'titulo' => 'Encerrada'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $this->em->flush();

        $tela = $this->painel();

        $menu = static fn (PautaDaAssembleia $p): Crawler => $tela->filter('#pauta-' . $p->getId() . ' > .voto-pauta-topo > .vKw > .vKm');
        self::assertSame(['Retirar de pauta ou cancelar'], $menu($aguardando)->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(1, $menu($aguardando)->filter('button.vKd[data-voto-janela="#janela-ciclo-' . $aguardando->getId() . '-ret"]'), 'vermelho e abre a janela');
        self::assertSame(['Suspender votação', 'Cancelar votação'], $menu($aberta)->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(0, $menu($aberta)->filter('button')->eq(0)->filter('.vKd'), 'Suspender não é vermelho');
        self::assertCount(1, $menu($aberta)->filter('button.vKd[data-voto-janela="#janela-ciclo-' . $aberta->getId() . '-can"]'));
        self::assertSame(['Retomar votação', 'Cancelar votação'], $menu($suspensa)->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringEndsWith('/pautas/' . $suspensa->getId() . '/retomar', (string) $menu($suspensa)->filter('form')->attr('action'));
        self::assertCount(0, $tela->filter('#pauta-' . $encerrada->getId() . ' .vKw'), 'encerrada: sem menu de ciclo');
    }

    #[TestDox('Janela do ciclo: textos do desenho por tipo, cabeçalho #a3232b (#8a6200 na suspensão), motivo e rodapé verde')]
    public function testJanelaDoCiclo(): void
    {
        $aguardando = $this->pauta(1, 'Aguardando');
        $aberta = $this->pauta(2, 'Aberta', true);
        $tela = $this->painel();

        $ret = $tela->filter('#janela-ciclo-' . $aguardando->getId() . '-ret[hidden].vModCiclo > .vModC');
        self::assertCount(1, $ret);
        self::assertStringContainsString('background:#a3232b', (string) $ret->filter('.vH')->attr('style'));
        self::assertSame('Retirar de pauta', trim($ret->filter('.vH span')->first()->text()));
        self::assertSame('Aguardando', trim($ret->filter('.vModCorpo > b')->text()));
        self::assertSame('O que acontece:', trim($ret->filter('.vModCorpo > div')->first()->text()));
        $efeitos = $ret->filter('.vModCorpo > ul > li')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertCount(5, $efeitos);
        self::assertSame('Sai da tela de votação, do QR Code e do link do evento, de Resultados, de Acompanhar e do telão.', $efeitos[0]);
        self::assertSame('Pode ser desfeito em Gestão eleitoral, Mostrar arquivadas, Desarquivar.', $efeitos[4]);
        self::assertSame('As outras 1 pauta(s) do evento continuam normalmente, com o mesmo link e QR Code.', trim($ret->filter('.vModOutras')->text()));
        self::assertSame('Ex.: matéria adiada para a próxima assembleia', $ret->filter('input[name="motivo"]')->attr('placeholder'));
        self::assertSame('Motivo (vai para a ata e para a auditoria)', trim($ret->filter('label.vL')->text()));
        self::assertStringEndsWith('/pautas/' . $aguardando->getId() . '/retirar', (string) $ret->filter('form')->attr('action'));
        self::assertSame('Informe o motivo da retirada.', $ret->filter('form')->attr('data-voto-exige-motivo'));
        self::assertSame(['Cancelar', 'Retirar de pauta'], $ret->filter('.vModRodape > button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(1, $ret->filter('.vModRodape > button.vB.vD[type="submit"]'));

        $sus = $tela->filter('#janela-ciclo-' . $aberta->getId() . '-sus > .vModC');
        self::assertStringContainsString('background:#8a6200', (string) $sus->filter('.vH')->attr('style'));
        self::assertSame('Suspender votação', trim($sus->filter('.vH span')->first()->text()));
        self::assertSame('Para de aceitar votos agora, até a mesa retomar.', trim($sus->filter('li')->first()->text()));
        self::assertSame('Ex.: queda de energia no salão', $sus->filter('input[name="motivo"]')->attr('placeholder'));
        self::assertStringEndsWith('/suspender', (string) $sus->filter('form')->attr('action'));

        $can = $tela->filter('#janela-ciclo-' . $aberta->getId() . '-can > .vModC');
        self::assertStringContainsString('background:#a3232b', (string) $can->filter('.vH')->attr('style'));
        self::assertSame('Não pode ser desfeito. Para repetir, use Duplicar votação.', trim($can->filter('li')->eq(4)->text()));
        self::assertSame('Ex.: convocação irregular', $can->filter('input[name="motivo"]')->attr('placeholder'));
        self::assertStringEndsWith('/cancelar', (string) $can->filter('form')->attr('action'));
        self::assertStringContainsString('min(560px, 100%)', (string) file_get_contents(__DIR__ . '/../../../public/css/voto-janela.css'));
    }

    #[TestDox('Rodapé da janela: "Era a única pauta publicada deste evento." quando não há outra na ordem do dia')]
    public function testJanelaComPautaUnica(): void
    {
        $unica = $this->pauta(1, 'Única');

        $tela = $this->painel();

        self::assertSame('Era a única pauta publicada deste evento.', trim($tela->filter('#janela-ciclo-' . $unica->getId() . '-ret .vModOutras')->text()));
    }

    #[TestDox('Menu da assembleia: rascunho nunca publicado tem Excluir rascunho (vermelho) com a confirmação do desenho')]
    public function testExcluirRascunhoNoMenu(): void
    {
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();

        $tela = $this->painel();

        $cartao = $tela->filter('#assembleia-' . $rascunho->getId());
        self::assertCount(1, $cartao->filter('.vH > .vKw > .vKm > button.vKd[data-voto-revelar="#excluir-' . $rascunho->getId() . '"]'));
        self::assertSame('Excluir rascunho', trim($cartao->filter('.vKm button.vKd')->text()));
        $faixa = $cartao->filter('#excluir-' . $rascunho->getId() . '[hidden] > form');
        self::assertStringEndsWith('/voto/assembleias/' . $rascunho->getId() . '/excluir', (string) $faixa->attr('action'));
        self::assertSame('Excluir este rascunho? Ele nunca foi publicado e a exclusão fica registrada na auditoria.', trim($faixa->filter('b')->text()));
        self::assertSame(['Manter', 'Excluir rascunho'], $faixa->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(0, $tela->filter('#assembleia-' . $this->assembleia->getId() . ' .vKm .vKd[data-voto-revelar]'), 'publicada não se exclui');
        self::assertCount(0, $cartao->filter('[data-voto-revelar^="#arquivar"]'), 'rascunho não se arquiva');
    }

    #[TestDox('Menu da assembleia: Arquivar (com motivo e o título do desenho) só sem votação aberta; com pauta aberta não aparece')]
    public function testArquivarNoMenu(): void
    {
        $this->pauta(1, 'Aguardando');
        $outra = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGE 2026'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $outra, 'titulo' => 'Aberta'])->comOpcoesPadrao()->aberta()->create();
        $deliberada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2025'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $deliberada, 'titulo' => 'Encerrada'])->comOpcoesPadrao()->encerrada()->create();

        $tela = $this->painel();

        $id = $this->assembleia->getId();
        self::assertCount(1, $tela->filter('#assembleia-' . $id . ' .vKm > button[data-voto-revelar="#arquivar-' . $id . '"]:not(.vKd)'));
        $faixa = $tela->filter('#arquivar-' . $id . '[hidden] > form');
        self::assertSame('Arquivar sem deliberar: a votação não poderá ser aberta e sai das listas.', trim($faixa->filter('.voto-faixa-titulo')->text()));
        self::assertSame('Ex.: pauta retirada da ordem do dia', $faixa->filter('input[name="motivo"]')->attr('placeholder'));
        self::assertSame('Motivo', trim($faixa->filter('label.vL')->text()));
        self::assertSame(['Cancelar', 'Arquivar'], $faixa->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringEndsWith('/voto/assembleias/' . $id . '/arquivar', (string) $faixa->attr('action'));
        self::assertSame('Arquivar votação deliberada: resultado, ata, relatório e trilha de auditoria continuam guardados.', trim($tela->filter('#arquivar-' . $deliberada->getId() . ' .voto-faixa-titulo')->text()));
        self::assertCount(0, $tela->filter('#assembleia-' . $outra->getId() . ' [data-voto-revelar]'), 'com votação aberta não se arquiva');
    }

    // ---- chips, próximo passo, arquivadas ---------------------------------------------------------------------

    #[TestDox('Chips do ciclo e linha "próximo passo": Suspensa #8a6200 e Cancelada #6b2a2e com os textos do desenho')]
    public function testChipsEProximoPasso(): void
    {
        $suspensa = $this->pauta(1, 'Suspensa', true);
        $suspensa->suspender('Queda de energia', 'Mesa');
        $cancelada = $this->pauta(2, 'Cancelada', true);
        $cancelada->cancelar('Convocação irregular', 'Mesa');
        $normal = $this->pauta(3, 'Normal');
        $this->em->flush();

        $tela = $this->painel();

        $chip = static fn (Crawler $linha): Crawler => $linha->filter('.voto-pauta-topo > .vChip');
        $linhaSuspensa = $tela->filter('#pauta-' . $suspensa->getId());
        self::assertSame('Suspensa', trim($chip($linhaSuspensa)->text()));
        self::assertStringContainsString('background:#8a6200', (string) $chip($linhaSuspensa)->attr('style'));
        self::assertMatchesRegularExpression('/^Suspensa pela mesa desde ' . self::DATA . '\. Motivo: Queda de energia\. Retome pelo menu\.$/', trim($linhaSuspensa->filter('.voto-proximo > b')->text()));
        $linhaCancelada = $tela->filter('#pauta-' . $cancelada->getId());
        self::assertSame('Cancelada', trim($chip($linhaCancelada)->text()));
        self::assertStringContainsString('background:#6b2a2e', (string) $chip($linhaCancelada)->attr('style'));
        self::assertMatchesRegularExpression('/^Cancelada em ' . self::DATA . '\. Motivo: Convocação irregular\. As cédulas ficam lacradas e não serão apuradas\.$/', trim($linhaCancelada->filter('.voto-proximo > b')->text()));
        self::assertSame('Publicada', trim($chip($tela->filter('#pauta-' . $normal->getId()))->text()));
        self::assertCount(0, $tela->filter('#pauta-' . $normal->getId() . ' .voto-proximo'));
    }

    #[TestDox('Mostrar arquivadas: a arquivada e a pauta retirada saem da lista; o botão conta e, ligado, mostra faixa, chip e Desarquivar')]
    public function testMostrarEOcultarArquivadas(): void
    {
        $retirada = $this->pauta(1, 'Pauta retirada');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $this->pauta(2, 'Pauta que fica');
        $arquivada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2024'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $arquivada, 'titulo' => 'Contas 2024'])->comOpcoesPadrao()->create();
        $arquivada->arquivar('Assembleia concluída', 'Mesa');
        $this->em->flush();

        $padrao = $this->painel();

        self::assertCount(0, $padrao->filter('#assembleia-' . $arquivada->getId()), 'arquivada fora da lista padrão');
        self::assertCount(0, $padrao->filter('#pauta-' . $retirada->getId()), 'retirada fora da lista padrão');
        $botao = $padrao->filter('.voto-barra > a[data-voto-arquivadas]');
        self::assertSame('Mostrar arquivadas (2)', trim($botao->text()));
        self::assertCount(1, $botao->filter('i.bi-archive'));
        self::assertSame('/voto?aba=ges&arquivadas=1', $botao->attr('href'));

        $ligado = $this->painel('&arquivadas=1');

        $botao = $ligado->filter('.voto-barra > a[data-voto-arquivadas]');
        self::assertSame('Ocultar arquivadas', trim($botao->text()));
        self::assertCount(1, $botao->filter('i.bi-eye-slash'));
        self::assertSame('/voto?aba=ges', $botao->attr('href'));
        $cartao = $ligado->filter('#assembleia-' . $arquivada->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Arquivada', trim($cartao->filter('.vH > .vChip')->text()));
        self::assertStringContainsString('background:#5b7383', (string) $cartao->filter('.vH > .vChip')->attr('style'));
        self::assertMatchesRegularExpression('/^Arquivada em ' . self::DATA . ', sem deliberação\. Motivo: Assembleia concluída\. Dados e evidências preservados; não aparece em Votar, Resultados e Acompanhar\.$/', trim($cartao->filter('.voto-faixa-arquivada')->text()));
        self::assertSame(['Desarquivar'], $cartao->filter('.vH .vKm button')->reduce(static fn (Crawler $c): bool => 'Desarquivar' === trim($c->text()))->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringEndsWith('/voto/assembleias/' . $arquivada->getId() . '/desarquivar', (string) $cartao->filter('.vH .vKm form')->last()->attr('action'));
        self::assertCount(0, $cartao->filter('[data-voto-revelar^="#arquivar"], a[href$="/telao"]'), 'arquivada não arquiva de novo nem vai ao telão');

        $linha = $ligado->filter('#pauta-' . $retirada->getId());
        self::assertSame('Arquivada', trim($linha->filter('.voto-pauta-topo > .vChip')->text()));
        self::assertSame(['Desarquivar'], $linha->filter('.voto-pauta-topo .vKm button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringEndsWith('/pautas/' . $retirada->getId() . '/devolver', (string) $linha->filter('.vKm form')->attr('action'));
        self::assertStringContainsString('Retirada de pauta: Matéria adiada', $linha->filter('.voto-faixa-arquivada')->text());
        self::assertCount(0, $linha->filter('[data-voto-janela]'), 'retirada não abre nem retira de novo');
    }

    // ---- lote ------------------------------------------------------------------------------------------

    #[TestDox('Painel: barra de seleção do desenho com "Selecionar todas", Arquivar (n), Excluir (n), Limpar seleção e as faixas escondidas')]
    public function testBarraDeSelecao(): void
    {
        $this->pauta(1, 'Aguardando');
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();

        $tela = $this->painel();

        $lote = $tela->filter('.voto-painel > .vC[data-voto-lote]');
        self::assertSame('Selecionar todas', trim($lote->filter('.vSelBar > label.voto-lote-todas')->text()));
        self::assertCount(1, $lote->filter('.vSelBar > label > input.vSel[type="checkbox"]'));
        self::assertSame(['Arquivar ({n})', 'Excluir ({n})', 'Limpar seleção'], $lote->filter('.vSelBar > .voto-lote-acoes[hidden] > button')->each(static fn (Crawler $c): string => trim($c->text()) ?: (string) $c->attr('data-modelo')));
        self::assertCount(1, $lote->filter('.vSelBar button.vB.vD[data-voto-lote-pedir="excluir"]'));
        $arquivar = $lote->filter('form[data-voto-lote-form="arquivar"][hidden]');
        self::assertStringEndsWith('/voto/assembleias/lote/arquivar', (string) $arquivar->attr('action'));
        self::assertSame('Motivo (vale para todas)', trim($arquivar->filter('label.vL')->text()));
        self::assertSame('Ex.: assembleia de 2025 concluída', $arquivar->filter('input[name="motivo"]')->attr('placeholder'));
        self::assertSame(['Cancelar', 'Arquivar selecionadas'], $arquivar->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('{n} selecionada(s) não se encaixam e serão mantidas como estão.', (string) $arquivar->filter('[data-voto-lote-obs]')->attr('data-modelo-fora'));
        $excluir = $lote->filter('form[data-voto-lote-form="excluir"][hidden]');
        self::assertStringEndsWith('/voto/assembleias/lote/excluir', (string) $excluir->attr('action'));
        self::assertSame(['Cancelar', 'Excluir selecionados'], $excluir->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('Votação publicada não é excluída, para não apagar prova: use Arquivar.', $excluir->filter('.voto-lote-texto')->text());
        self::assertSame('Excluir definitivamente {n} item(ns): rascunhos nunca publicados.', $excluir->filter('[data-voto-lote-titulo]')->attr('data-modelo'));

        $caixas = $tela->filter('.voto-acoes > input.vSel[data-voto-lote-item]');
        self::assertCount(2, $caixas);
        self::assertSame('1', $tela->filter('#assembleia-' . $this->assembleia->getId() . ' input.vSel')->attr('data-arquivar'));
        self::assertSame('0', $tela->filter('#assembleia-' . $this->assembleia->getId() . ' input.vSel')->attr('data-excluir'));
        self::assertSame('1', $tela->filter('#assembleia-' . $rascunho->getId() . ' input.vSel')->attr('data-excluir'));
        self::assertSame('0', $tela->filter('#assembleia-' . $rascunho->getId() . ' input.vSel')->attr('data-arquivar'));
        self::assertStringContainsString('width: 19px; height: 19px', (string) file_get_contents(__DIR__ . '/../../../public/css/voto-ciclo.css'));
        self::assertStringContainsString('accent-color: #0a6fa0', (string) file_get_contents(__DIR__ . '/../../../public/css/voto-ciclo.css'));
    }

    #[TestDox('Lote, arquivar: arquiva as que cabem, mantém as que não (rascunho) e avisa as duas contagens; o motivo vale para todas')]
    public function testLoteArquivar(): void
    {
        $this->pauta(1, 'Aguardando');
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();
        $deliberada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2025'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $deliberada])->comOpcoesPadrao()->encerrada()->create();

        $this->postarLote('arquivar', [$this->assembleia->getId(), $deliberada->getId(), $rascunho->getId()], ['motivo' => 'Assembleia de 2025 concluída']);

        self::assertResponseRedirects('/voto?aba=ges');
        self::assertSame('2 votação(ões) arquivada(s). 1 selecionada(s) não se encaixam e foram mantidas como estão.', $this->avisoDoPainel());
        foreach ([$this->assembleia, $deliberada] as $a) {
            self::assertSame('Assembleia de 2025 concluída', $this->coluna('voto.assembleia', 'arquivada_motivo', $a->getId()));
        }
        self::assertNull($this->coluna('voto.assembleia', 'arquivada_motivo', $rascunho->getId()));
    }

    #[TestDox('Lote, arquivar: motivo curto não arquiva nada; nenhuma que caiba avisa a regra; sem seleção avisa')]
    public function testLoteArquivarRecusas(): void
    {
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();
        $this->pauta(1, 'Aguardando');

        $this->postarLote('arquivar', [$this->assembleia->getId()], ['motivo' => 'abc']);
        self::assertSame('Informe o motivo do arquivamento.', $this->avisoDoPainel());
        self::assertNull($this->coluna('voto.assembleia', 'arquivada_em', $this->assembleia->getId()));

        $this->postarLote('arquivar', [$rascunho->getId()], ['motivo' => 'Assembleia concluída']);
        self::assertSame('Nenhuma selecionada pode ser arquivada: só publicadas sem votação aberta.', $this->avisoDoPainel());

        $this->postarLote('arquivar', [], ['motivo' => 'Assembleia concluída']);
        self::assertSame('Selecione ao menos uma votação.', $this->avisoDoPainel());
    }

    #[TestDox('Lote, excluir: apaga só o rascunho nunca publicado; a publicada fica e entra na contagem das mantidas')]
    public function testLoteExcluir(): void
    {
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();
        $rascunhoId = $rascunho->getId();
        PautaDaAssembleiaFactory::new(['assembleia' => $rascunho])->comOpcoesPadrao()->create();

        $this->postarLote('excluir', [$rascunhoId, $this->assembleia->getId()]);

        self::assertResponseRedirects('/voto?aba=ges');
        self::assertSame('1 item(ns) excluído(s). 1 selecionada(s) não se encaixam e foram mantidas como estão.', $this->avisoDoPainel());
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$rascunhoId]));
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$this->assembleia->getId()]), 'a publicada nunca é excluída');

        $this->postarLote('excluir', [$this->assembleia->getId()]);
        self::assertSame('Nenhuma selecionada pode ser excluída: só rascunhos nunca publicados. Use Arquivar.', $this->avisoDoPainel());
    }

    #[TestDox('Lote: CSRF inválido não faz nada; uma assembleia de outro escritório dá 404 e o lote inteiro fica como estava')]
    public function testLoteCsrfEOutroEscritorio(): void
    {
        $this->pauta(1, 'Aguardando');
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();
        $rascunhoId = $rascunho->getId();

        foreach (['arquivar', 'excluir'] as $acao) {
            $this->client->request('POST', '/voto/assembleias/lote/' . $acao, ['_token' => 'invalido', 'motivo' => 'Motivo válido', 'ids' => [$this->assembleia->getId(), $rascunhoId]]);
            self::assertResponseRedirects('/voto?aba=ges');
            self::assertSame('A sessão expirou. Recarregue a página e tente de novo.', $this->avisoDoPainel(), $acao);
        }
        self::assertNull($this->coluna('voto.assembleia', 'arquivada_em', $this->assembleia->getId()));
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$rascunhoId]));

        foreach (['arquivar', 'excluir'] as $acao) {
            $this->postarLote($acao, [$this->assembleia->getId(), $rascunhoId, $alheia->getId()], ['motivo' => 'Motivo válido']);
            self::assertResponseStatusCodeSame(404, $acao);
        }
        self::assertNull($this->coluna('voto.assembleia', 'arquivada_em', $this->assembleia->getId()), 'nenhuma do lote foi arquivada');
        self::assertNull($this->coluna('voto.assembleia', 'arquivada_em', $alheia->getId()));
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$rascunhoId]), 'nenhuma do lote foi excluída');
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$alheia->getId()]));
    }

    #[TestDox('Lote: só POST')]
    public function testLoteSoPost(): void
    {
        $this->client->request('GET', '/voto/assembleias/lote/arquivar');

        self::assertResponseStatusCodeSame(405);
    }

    // ---- Resultados, Acompanhar, Assistir ------------------------------------------------------------------

    #[TestDox('Resultados: a cancelada mostra a situação sem números; arquivada e retirada não aparecem')]
    public function testResultadosECancelada(): void
    {
        $cancelada = $this->pauta(1, 'Pauta cancelada', true);
        $cancelada->cancelar('Convocação irregular', 'Mesa');
        $encerrada = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Pauta apurada'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $retirada = $this->pauta(3, 'Pauta retirada');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $arquivada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2024'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $arquivada, 'titulo' => 'Contas 2024'])->comOpcoesPadrao()->encerrada()->create();
        $arquivada->arquivar('Assembleia concluída', 'Mesa');
        $this->em->flush();

        $tela = $this->client->request('GET', '/voto?aba=tra');

        $card = $tela->filter('.voto-resultado[data-pauta="' . $cancelada->getId() . '"]');
        self::assertCount(1, $card);
        self::assertSame('Cancelada', trim($card->filter('.vH > .vChip')->text()));
        self::assertStringContainsString('background:#6b2a2e', (string) $card->filter('.vH > .vChip')->attr('style'));
        self::assertMatchesRegularExpression('/^Cancelada em ' . self::DATA . '\. Motivo: Convocação irregular\. As cédulas ficam lacradas e não serão apuradas\.$/', trim($card->filter('[data-voto-cancelada]')->text()));
        self::assertCount(0, $card->filter('.vKpis, .voto-resultado-opcao, .voto-resultado-faixa'), 'sem números nem decisão');
        self::assertCount(1, $tela->filter('.voto-resultado[data-pauta="' . $encerrada->getId() . '"] .vKpis'), 'a apurada continua com os números');
        self::assertCount(0, $tela->filter('.voto-resultado[data-pauta="' . $retirada->getId() . '"]'));
        self::assertStringNotContainsString('Contas 2024', $tela->text(), 'arquivada fora de Resultados');
    }

    #[TestDox('Acompanhar: arquivada e retirada saem, a suspensa leva o chip; Assistir da retirada e da arquivada dá 404')]
    public function testAcompanharEAssistir(): void
    {
        $suspensa = $this->pauta(1, 'Pauta suspensa', true);
        $suspensa->suspender('Queda de energia', 'Mesa');
        $retirada = $this->pauta(2, 'Pauta retirada');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $arquivada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2024'])->publicada()->create()->_real();
        $pautaArquivada = PautaDaAssembleiaFactory::new(['assembleia' => $arquivada, 'titulo' => 'Contas 2024'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $arquivada->arquivar('Assembleia concluída', 'Mesa');
        $this->em->flush();

        $tela = $this->client->request('GET', '/voto?aba=aco');

        self::assertCount(1, $tela->filter('.voto-aco-linha'));
        self::assertSame('Suspensa', trim($tela->filter('.voto-aco-linha[data-pauta="' . $suspensa->getId() . '"] .vChip')->text()));
        self::assertStringNotContainsString('Pauta retirada', $tela->text());
        self::assertStringNotContainsString('Contas 2024', $tela->text());
        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/pautas/' . $retirada->getId() . '/assistir');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/voto/assembleias/' . $arquivada->getId() . '/pautas/' . $pautaArquivada->getId() . '/assistir');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/pautas/' . $suspensa->getId() . '/assistir');
        self::assertResponseIsSuccessful();
    }

    // ---- telão ---------------------------------------------------------------------------------------------

    #[TestDox('Telão: aberta tem Suspender (janela do ciclo, envio por fetch); suspensa tem Retomar votação e o chip Suspensa; cancelada sem apuração')]
    public function testTelaoSuspenderERetomar(): void
    {
        $aberta = $this->pauta(1, 'Pauta aberta', true);
        $suspensa = $this->pauta(2, 'Pauta suspensa', true);
        $suspensa->suspender('Queda de energia', 'Mesa');
        $cancelada = $this->pauta(3, 'Pauta cancelada', true);
        $cancelada->cancelar('Convocação irregular', 'Mesa');
        $retirada = $this->pauta(4, 'Pauta retirada');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $this->em->flush();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/telao');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $tela->filter('.vAoG > .vAoL > .vAoI'), 'a retirada sai da ordem do dia');
        self::assertCount(0, $tela->filter('[data-telao-pauta="' . $retirada->getId() . '"]'));
        self::assertSame(['Votação aberta', 'Suspensa', 'Cancelada'], $tela->filter('.vAoG > .vAoL > .vAoI > .vChip')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('background:#8a6200', (string) $tela->filter('.vAoI .vChip')->eq(1)->attr('style'));
        self::assertStringContainsString('background:#6b2a2e', (string) $tela->filter('.vAoI .vChip')->eq(2)->attr('style'));

        $mesaAberta = $tela->filter('.vAoM[data-telao-pauta="' . $aberta->getId() . '"] .vAoMesa');
        self::assertSame('Suspender', trim($mesaAberta->filter('button.vAoB.vAoSuspender[data-voto-janela]')->text()));
        $janela = $mesaAberta->filter('.vModCiclo[hidden] form[data-telao-ciclo]');
        self::assertStringEndsWith('/pautas/' . $aberta->getId() . '/suspender', (string) $janela->attr('action'));
        self::assertCount(1, $mesaAberta->filter('button[data-telao-pedir-encerrar]'), 'Encerrar votação continua');

        $mesaSuspensa = $tela->filter('.vAoM[data-telao-pauta="' . $suspensa->getId() . '"]');
        self::assertSame('Suspensa', trim($mesaSuspensa->filter('.vAoChip')->text()));
        $retomar = $mesaSuspensa->filter('.vAoMesa > form.vAoRetomar[data-telao-ciclo]');
        self::assertStringEndsWith('/pautas/' . $suspensa->getId() . '/retomar', (string) $retomar->attr('action'));
        self::assertSame('Retomar votação', trim($retomar->filter('button.vAoP')->text()));
        self::assertCount(0, $mesaSuspensa->filter('.vAoSuspender'), 'suspensa não suspende de novo');
        self::assertNotSame('', (string) $retomar->filter('input[name="_token"]')->attr('value'));

        $painelCancelada = $tela->filter('.vAoM[data-telao-pauta="' . $cancelada->getId() . '"]');
        self::assertSame('Cancelada', trim($painelCancelada->filter('.vAoChip')->text()));
        self::assertCount(0, $painelCancelada->filter('.vAoRes, .vAoMesa a.vAoP'), 'cancelada: sem resultado nem Ir para a apuração');

        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/telao/dados');
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['normal', 'suspensa', 'cancelada'], array_column($json['pautas'], 'ciclo'));
        self::assertNotContains($retirada->getId(), array_column($json['pautas'], 'id'));
    }

    #[TestDox('Telão: assembleia arquivada dá 404 (o desenho esconde "Conduzir no telão" nas arquivadas), também no JSON')]
    public function testTelaoDaArquivadaDa404(): void
    {
        $this->pauta(1, 'Encerrada');
        $arquivada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2024'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $arquivada, 'titulo' => 'Contas 2024'])->comOpcoesPadrao()->encerrada()->create();
        $arquivada->arquivar('Assembleia concluída', 'Mesa');
        $this->em->flush();

        $this->client->request('GET', '/voto/assembleias/' . $arquivada->getId() . '/telao');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/voto/assembleias/' . $arquivada->getId() . '/telao/dados');
        self::assertResponseStatusCodeSame(404);
    }

    // ---- ata -----------------------------------------------------------------------------------------------

    #[TestDox('Ata: a pauta retirada entra como "retirada da ordem do dia", sem deliberação; a cancelada, com os votos lacrados e não apurados')]
    public function testAtaDeRetiradaECancelada(): void
    {
        $cancelada = $this->pauta(1, 'Pauta cancelada', true);
        $cancelada->cancelar('Convocação irregular', 'Mesa');
        $retirada = $this->pauta(2, 'Pauta retirada');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $this->em->flush();

        $ata = $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/ata');

        self::assertResponseIsSuccessful();
        $texto = preg_replace('/\s+/', ' ', $ata->filter('body')->text());
        self::assertMatchesRegularExpression('/Retirada da ordem do dia: Pauta retirada, por decisão registrada em \d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d\. Motivo: Matéria adiada\. Não houve deliberação sobre essa matéria\./', $texto);
        self::assertCount(0, $ata->filter('#pauta-' . $retirada->getId()), 'a retirada não é seção de votação');
        self::assertMatchesRegularExpression('/Votação cancelada pela mesa em \d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d\. Motivo: Convocação irregular\. Os votos recebidos ficaram lacrados e não foram apurados\./', $texto);
        self::assertCount(1, $ata->filter('#pauta-' . $cancelada->getId() . ' .voto-ata-cancelada'));
        self::assertStringNotContainsString('Ainda não apurada', $ata->filter('#pauta-' . $cancelada->getId())->text());
    }

    // ---- apoio ---------------------------------------------------------------------------------------------

    private function pauta(int $ordem, string $titulo, bool $aberta = false): PautaDaAssembleia
    {
        $fabrica = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => $ordem, 'titulo' => $titulo])->comOpcoesPadrao();
        $pauta = ($aberta ? $fabrica->aberta() : $fabrica)->create()->_real();
        $this->em->refresh($this->assembleia);

        return $pauta;
    }

    private function painel(string $consulta = ''): Crawler
    {
        $tela = $this->client->request('GET', '/voto?aba=ges' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /**
     * @param list<int>             $ids
     * @param array<string, string> $dados
     */
    private function postarLote(string $acao, array $ids, array $dados = []): void
    {
        $this->client->request('POST', '/voto/assembleias/lote/' . $acao, ['_token' => $this->tokenValido('voto_assembleia_lote'), 'ids' => $ids] + $dados);
    }

    private function avisoDoPainel(): string
    {
        $resposta = $this->client->getResponse();
        if (!$resposta->isRedirect()) {
            return '';
        }
        $tela = $this->client->followRedirect();

        return trim($tela->filter('.voto-toast')->count() > 0 ? $tela->filter('.voto-toast')->first()->text() : '');
    }

    private function coluna(string $tabela, string $coluna, int $id): mixed
    {
        $valor = $this->em->getConnection()->fetchOne('SELECT ' . $coluna . ' FROM ' . $tabela . ' WHERE id = ?', [$id]);

        return false === $valor ? null : $valor;
    }

    /** Grava na sessão um token CSRF conhecido, como o SessionTokenStorage (ver AssembleiaGestorTest::tokenValido). */
    private function tokenValido(string $id): string
    {
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
