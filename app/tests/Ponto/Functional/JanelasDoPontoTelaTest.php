<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Form\JustificativaPontoType;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E4 do desenho do Ponto (desenho 16 L269 a L318; spec docs/specs/ponto-desenho-0811.md, P44 a P47): as janelas
 * "Justificar falta", "Registro de esquecimento" e "Editar justificativa" na moldura do desenho, sem modal do
 * Bootstrap. Arranjo por filho direto, os rótulos do desenho, o que ficou do sistema (P45 e P47) e o motivo na aba.
 *
 * O comportamento (abrir, Esc, conferência antes do envio) mora no js/ponto-janelas.js e no js/ponto-abas.js: o PHPUnit
 * não executa script, e as escolhas dele ficam travadas em PontoJanelasJsTest. Aqui fica o HTML de que ele depende.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class JanelasDoPontoTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    // As janelas ficam dentro da página (herdam os tokens `--pe-*`), fora das regiões trocadas depois da batida.
    private const FUNDO = '.pe-page > div.pe-janela-fundo#%s[hidden]';
    private const JANELA = self::FUNDO . ' > form.pe-janela[role="dialog"][aria-modal="true"][aria-label="%s"][data-janela-form="%s"][novalidate]';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Janelas');
        $this->eu         = $this->pessoa('janelas');
        $this->vincularColaborador($this->eu, $this->escritorio);
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function janelas(): iterable
    {
        yield 'Justificar falta (P44)' => ['ponto-janela-justificar', 'Justificar falta', 'justificar', 'bi-calendar-check', 'Enviar'];
        yield 'Registro de esquecimento (P46)' => ['ponto-janela-esquecimento', 'Registro de esquecimento', 'esquecimento', 'bi-plus-circle', 'Enviar'];
        yield 'Editar justificativa (P47, fica)' => ['ponto-janela-editar', 'Editar justificativa', 'editar', 'bi-pencil', 'Salvar alterações'];
    }

    #[TestDox('Moldura do desenho em $_dataName: topo azul com ícone, título e "x"; corpo; linha de erro; rodapé com Cancelar e a ação')]
    #[DataProvider('janelas')]
    public function testMolduraDoDesenho(string $id, string $titulo, string $forma, string $icone, string $acao): void
    {
        $janela = $this->tela()->filter(sprintf(self::JANELA, $id, $titulo, $forma));
        self::assertCount(1, $janela, 'role="dialog", aria-modal e o nome da janela, dentro da página');

        $partes = 'form > div.pe-janela-topo + div.pe-janela-corpo + div.pe-janela-erro[data-janela-erro][hidden] + div.pe-janela-rodape:last-child';
        self::assertCount(1, $janela->filter($partes), 'topo, corpo, a linha de erro do desenho (entre o corpo e o rodapé) e o rodapé');

        $topo = $janela->filter('form > div.pe-janela-topo');
        self::assertCount(1, $topo->filter('div > i.bi.' . $icone . '[aria-hidden="true"]:first-child + b.pe-janela-titulo + button.pe-janela-fechar[type="button"][aria-label="Fechar"][data-janela-fechar]:last-child'));
        self::assertSame($titulo, trim($topo->filter('b.pe-janela-titulo')->text()));

        $rodape = $janela->filter('form > div.pe-janela-rodape');
        self::assertCount(1, $rodape->filter('div > button.pe-janela-cancelar[type="button"][data-janela-fechar]:first-child + button.pe-janela-acao[type="submit"][data-janela-enviar]:last-child'));
        self::assertSame('Cancelar', trim($rodape->filter('.pe-janela-cancelar')->text()));
        self::assertSame($acao, trim($rodape->filter('.pe-janela-acao')->text()));

        self::assertCount(0, $janela->filter('.modal, .modal-dialog, .btn, .form-control, .form-select, .form-check, .alert, [data-bs-dismiss], [data-bs-toggle]'), 'componentes do desenho, nada do Bootstrap');
        self::assertCount(1, $janela->filter('form > div.pe-janela-corpo > label.pe-janela-rotulo > textarea.pe-janela-campo[data-janela-motivo][maxlength="' . JustificativaPontoType::MOTIVO_MAXIMO . '"]'), 'o "Motivo" do desenho');
    }

    #[TestDox('Os modais do Bootstrap saíram: o "Justificar falta" do cartão abre a janela do desenho, e o js dela vem depois do das abas')]
    public function testSemModalDoBootstrap(): void
    {
        $tela = $this->tela();

        self::assertCount(0, $tela->filter('#modalJustificativa, #modalEsquecimentoRegistro, #modalEditarJustificativa'));
        $botao = $tela->filter('#btn-justificar-falta');
        self::assertCount(1, $botao);
        self::assertNull($botao->attr('data-bs-toggle'));
        self::assertNull($botao->attr('data-bs-target'));
        self::assertSame('', $botao->attr('data-janela-justificar'));

        $scripts = $tela->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
        $abas    = array_key_first(array_filter($scripts, static fn (string $s): bool => str_ends_with($s, 'js/ponto-abas.js')));
        $janelas = array_key_first(array_filter($scripts, static fn (string $s): bool => str_ends_with($s, 'js/ponto-janelas.js')));
        self::assertIsInt($abas);
        self::assertIsInt($janelas);
        self::assertGreaterThan($abas, $janelas, 'as janelas usam a moldura do js/ponto-abas.js');
    }

    #[TestDox('Justificar falta: Data da falta (calendário com hoje), o tipo, o motivo e o atestado; ficam do sistema os vários dias, os tipos, o esquecimento e o abono parcial')]
    public function testCamposDoJustificar(): void
    {
        $janela = $this->tela()->filter(sprintf(self::JANELA, 'ponto-janela-justificar', 'Justificar falta', 'justificar'));
        self::assertSame('/ponto/justificativa/nova', $janela->attr('action'));
        self::assertSame('post', strtolower((string) $janela->attr('method')));
        self::assertSame('multipart/form-data', $janela->attr('enctype'));
        self::assertCount(1, $janela->filter('form > input[type="hidden"][name="justificativa_ponto[datas]"][data-just-datas]'));
        self::assertCount(1, $janela->filter('form > input[type="hidden"][name="justificativa_ponto[_token]"]'));
        self::assertCount(1, $janela->filter('form > input[type="hidden"][name="competencia"]'));

        $ordem = 'form > div.pe-janela-corpo'
            . ' > div.pe-janela-rotulo[role="group"][aria-labelledby="just-cal-rotulo"]:first-child'
            . ' + label.pe-janela-rotulo'
            . ' + div.pe-janela-aviso[data-just-nao-justificada][hidden]'
            . ' + div.pe-janela-par[data-just-esquecido][hidden]'
            . ' + label.pe-janela-marca[data-just-abono]'
            . ' + div.pe-janela-par[data-just-abono-horario][hidden]'
            . ' + label.pe-janela-rotulo'
            . ' + label.pe-janela-rotulo:last-child';
        self::assertCount(1, $janela->filter($ordem), 'data, tipo, os blocos do tipo e do abono, motivo e atestado, nesta ordem');

        $rotulos = $janela->filter('form > div.pe-janela-corpo > label.pe-janela-rotulo')->each(static fn (Crawler $l): string => trim((string) $l->getNode(0)?->firstChild?->textContent));
        self::assertSame(['Tipo de justificativa', 'Motivo', 'Atestado ou documento (opcional)'], $rotulos, 'os rótulos do desenho (L281 a L283) e o do tipo');
        self::assertSame('Data da falta', trim($janela->filter('#just-cal-rotulo')->text()));

        $calendario = $janela->filter('div.pe-janela-rotulo[role="group"] > span#just-cal-rotulo:first-child + div.pe-cal[data-just-calendario]:last-child');
        self::assertCount(1, $calendario);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $calendario->attr('data-hoje'), 'hoje do servidor, já marcado ao abrir');
        self::assertCount(1, $calendario->filter('div.pe-cal > div.pe-cal-barra:first-child > button.pe-cal-seta[data-cal-anterior]:first-child + span.pe-cal-mes[data-cal-mes] + button.pe-cal-seta[data-cal-seguinte]:last-child'));
        self::assertCount(1, $calendario->filter('div.pe-cal > div.pe-cal-barra + div.pe-cal-grade[data-cal-grade] + div.pe-cal-escolhidos:last-child > b[data-cal-escolhidos]'));

        $tipos = $janela->filter('select#just-tipo[name="justificativa_ponto[tipo]"].pe-janela-select > option');
        self::assertSame('Selecione o tipo', trim($tipos->first()->text()));
        self::assertSame('', $tipos->first()->attr('value'));
        self::assertCount(\count(TipoJustificativa::cases()) + 1, $tipos, 'todos os tipos do sistema (P45)');

        self::assertCount(1, $janela->filter('div[data-just-esquecido] > span.pe-janela-par-titulo:first-child + label > select[name="justificativa_ponto[tipoRegistroEsquecido]"]'));
        self::assertCount(1, $janela->filter('div[data-just-esquecido] input[type="time"][step="1"][name="justificativa_ponto[horaRegistroEsquecido]"]'), 'o horário com segundos, como o do esquecimento');
        self::assertCount(1, $janela->filter('label[data-just-abono] > input[type="checkbox"][name="justificativa_ponto[abonoParcial]"][value="1"]:first-child'));
        self::assertCount(1, $janela->filter('div[data-just-abono-horario] input[type="time"][name="justificativa_ponto[horaInicioAbono]"]'));
        self::assertCount(1, $janela->filter('div[data-just-abono-horario] input[type="time"][name="justificativa_ponto[horaFimAbono]"]'));
        self::assertCount(1, $janela->filter('textarea[name="justificativa_ponto[motivo]"]'));
        self::assertCount(1, $janela->filter('input[type="file"][name="justificativa_ponto[anexo]"][accept=".pdf,.jpg,.jpeg,.png"]'), 'o que o servidor aceita');
    }

    #[TestDox('Registro de esquecimento: a data e a batida do "+", o Horário com segundos e o Motivo; a dica antiga saiu')]
    public function testCamposDoEsquecimento(): void
    {
        $tela   = $this->tela();
        $janela = $tela->filter(sprintf(self::JANELA, 'ponto-janela-esquecimento', 'Registro de esquecimento', 'esquecimento'));
        self::assertSame('/ponto/justificativa/nova', $janela->attr('action'));
        self::assertCount(1, $janela->filter('form > input[type="hidden"][name="justificativa_ponto[tipo]"][value="esquecimento_registro"]'));
        self::assertCount(1, $janela->filter('form > input[type="hidden"]#esquecimentoData[name="justificativa_ponto[datas]"]'));
        self::assertCount(1, $janela->filter('form > input[type="hidden"]#esquecimentoCampo[name="justificativa_ponto[tipoRegistroEsquecido]"]'));

        $ordem = 'form > div.pe-janela-corpo > div.pe-janela-info#esquecimentoResumo[data-janela-info]:first-child'
            . ' + label.pe-janela-rotulo > input[type="time"][step="1"]#esquecimentoHora.pe-janela-entrada[name="justificativa_ponto[horaRegistroEsquecido]"]';
        self::assertCount(1, $janela->filter($ordem), 'o resumo do dia e da batida, depois o Horário (L276 e L277)');
        self::assertCount(1, $janela->filter('form > div.pe-janela-corpo > label.pe-janela-rotulo:last-child > textarea[name="justificativa_ponto[motivo]"]'), 'o Motivo por último (L278)');
        $rotulos = $janela->filter('form > div.pe-janela-corpo > label.pe-janela-rotulo')->each(static fn (Crawler $l): string => trim($l->text()));
        self::assertSame(['Horário', 'Motivo'], $rotulos);
        self::assertStringNotContainsString('Enviado para aprovação', $janela->text(), 'a dica de dentro da janela saiu (o aviso flutuante vem na E8)');
    }

    #[TestDox('Editar justificativa: os campos de hoje na moldura nova, mais o Motivo; o horário do esquecimento segue em minutos')]
    public function testCamposDoEditar(): void
    {
        $janela = $this->tela()->filter(sprintf(self::JANELA, 'ponto-janela-editar', 'Editar justificativa', 'editar'));
        self::assertCount(1, $janela->filter('form#formEditarJustificativa[enctype="multipart/form-data"] > input[type="hidden"]#editarJustificativaToken[name="_token"]'));
        $corpo = $janela->filter('form > div.pe-janela-corpo');
        self::assertCount(1, $corpo->filter('div > div.pe-janela-info[data-janela-info]:first-child + label.pe-janela-rotulo > select#editarJustificativaTipo[name="tipo"]'));
        self::assertCount(\count(TipoJustificativa::cases()) + 1, $corpo->filter('select#editarJustificativaTipo > option'));
        self::assertCount(1, $corpo->filter('div > div.pe-janela-par#editarBlocoRegistroEsquecido[hidden] select#editarTipoRegEsquecido[name="tipoRegistroEsquecido"]'));
        self::assertCount(1, $corpo->filter('#editarBlocoRegistroEsquecido input[type="time"]#editarHoraRegEsquecido[name="horaRegistroEsquecido"]:not([step])'), 'a edição lê H:i');
        self::assertCount(1, $corpo->filter('div > label.pe-janela-marca#editarBlocoAbonoParcial > input[type="checkbox"]#editarAbonoParcial[name="abonoParcial"]'));
        self::assertCount(1, $corpo->filter('div > div.pe-janela-par#editarBlocoHorario[hidden] input#editarHoraInicio[name="horaInicioAbono"]'));
        self::assertCount(1, $corpo->filter('#editarBlocoHorario input#editarHoraFim[name="horaFimAbono"]'));
        self::assertCount(1, $corpo->filter('div > label.pe-janela-rotulo > textarea#editarMotivo[name="motivo"][data-janela-motivo]'));
        self::assertCount(1, $corpo->filter('div > label.pe-janela-rotulo#editarBlocoAnexo > input[type="file"]#editarAnexo[name="anexo"]'));
        self::assertCount(1, $corpo->filter('div > div.pe-janela-aviso#editarAvisoAnexoAnalisado[hidden]:last-child'));
    }

    #[TestDox('O motivo aparece na aba abaixo do tipo (L184) e vai no lápis de editar; a de antes da E4 fica sem a linha')]
    public function testMotivoNaAbaENoLapis(): void
    {
        $dia    = new \DateTimeImmutable('first day of this month');
        $com    = $this->justificativa($this->eu, $this->escritorio, $dia, 'pendente', ['motivo' => 'Consulta no posto de saúde.']);
        $sem    = $this->justificativa($this->eu, $this->escritorio, $dia->modify('+1 day'), 'pendente');
        $tela   = $this->tela('?aba=justificativas');
        $linhas = '#ponto-painel-justificativas tr.pe-just-lin[data-justificativa="%d"]';

        $celula = $tela->filter(sprintf($linhas, $com->getId()) . ' > td.pe-just-celtipo');
        self::assertCount(1, $celula->filter('td > span.pe-just-tipo:first-child + div.pe-just-mot:last-child'));
        self::assertSame('Consulta no posto de saúde.', trim($celula->filter('.pe-just-mot')->text()));
        self::assertCount(0, $tela->filter(sprintf($linhas, $sem->getId()) . ' .pe-just-mot'));

        $lapis = $tela->filter(sprintf($linhas, $com->getId()) . ' button[data-justificativa-editar]');
        self::assertCount(1, $lapis);
        self::assertSame('Consulta no posto de saúde.', $lapis->attr('data-motivo'));
        self::assertSame($dia->format('d/m/Y') . ' · Atestado Médico', $lapis->attr('data-info'));
        self::assertNull($lapis->attr('onclick'), 'delegação no documento, sem onclick');
        self::assertSame('', $tela->filter(sprintf($linhas, $sem->getId()) . ' button[data-justificativa-editar]')->attr('data-motivo'));
    }

    #[TestDox('O motivo de outro escritório não aparece na minha aba')]
    public function testMotivoDeOutroEscritorioNaoVaza(): void
    {
        $outro = $this->escritorio('Escritório Vizinho');
        $this->vincularColaborador($this->eu, $outro);
        $dia = new \DateTimeImmutable('first day of this month');
        $this->justificativa($this->eu, $outro, $dia, 'pendente', ['motivo' => 'Motivo do escritório vizinho.']);
        $this->justificativa($this->eu, $this->escritorio, $dia, 'pendente', ['motivo' => 'Motivo daqui.']);

        $html = (string) $this->tela('?aba=justificativas')->html();

        self::assertStringContainsString('Motivo daqui.', $html);
        self::assertStringNotContainsString('Motivo do escritório vizinho.', $html);
    }

    #[TestDox('`?esquecimento=` do cabeçalho: a tela traz o "+" daquele dia e batida, que abre a janela do esquecimento')]
    public function testEsquecimentoPeloEndereco(): void
    {
        $dia = $this->diaUtilDoMesAnterior();
        $registro = new RegistroPonto();
        $registro->setUser($this->eu);
        $registro->setTenant($this->escritorio);
        $registro->setTipo('entrada');
        $registro->setDataHora(new \DateTime($dia->format('Y-m-d') . ' 08:00:00'));
        $this->em()->persist($registro);
        $this->em()->flush();

        $tela = $this->tela(sprintf('?competencia=%s&esquecimento=%s:saida', $dia->format('Y-m'), $dia->format('Y-m-d')));

        $mais = $tela->filter(sprintf('button.pe-mais[data-esquecimento-dia="%s"][data-esquecimento-campo="saida"]', $dia->format('Y-m-d')));
        self::assertCount(1, $mais, 'o alvo do link: o mesmo "+" da folha');
        self::assertSame('Saída', $mais->attr('data-esquecimento-rotulo'), 'o resumo da janela: "dd/mm/aaaa · Saída"');
        self::assertCount(1, $tela->filter(sprintf(self::JANELA, 'ponto-janela-esquecimento', 'Registro de esquecimento', 'esquecimento')));
        self::assertStringContainsString("get('esquecimento')", (string) $tela->filter('#ponto-janela-esquecimento + script')->text());
    }

    #[TestDox('Na ficha do gestor, o motivo da justificativa entra no resumo do lote')]
    public function testMotivoNaFichaDoGestor(): void
    {
        $dia = new \DateTimeImmutable('first day of this month');
        $this->justificativa($this->eu, $this->escritorio, $dia, 'pendente', ['motivo' => 'Levei meu filho ao médico.']);
        $gestor = $this->pessoa('gestor-janelas');
        $this->vincularPrimario($gestor, $this->escritorio);

        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=justificativas&competencia=%s', $this->escritorio->getId(), $this->eu->getId(), $dia->format('Y-m')));
        self::assertResponseIsSuccessful();

        $motivo = $ficha->filter('dl > dt')->reduce(static fn (Crawler $dt): bool => trim($dt->text()) === 'Motivo');
        self::assertCount(1, $motivo);
        self::assertSame('Levei meu filho ao médico.', trim($motivo->nextAll()->first()->text()));
    }

    private function tela(string $consulta = ''): Crawler
    {
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function diaUtilDoMesAnterior(): \DateTimeImmutable
    {
        $dia = new \DateTimeImmutable('first day of last month');
        while ((int) $dia->format('N') >= 6) {
            $dia = $dia->modify('+1 day');
        }

        return $dia->modify('+7 days');
    }
}
