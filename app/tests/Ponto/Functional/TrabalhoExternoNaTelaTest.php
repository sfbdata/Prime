<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ConfiguracoesDoPontoController;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Service\GestaoDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * E12 do desenho do Ponto (spec docs/specs/ponto-desenho-0811.md, P18 e P74; desenho 16 L253, L289, L545 e L766): a
 * janela "Trabalho externo" na moldura do desenho, o `data-trabalho-externo` da tela, a caixa "Permitir trabalho
 * externo, com motivo e análise" da aba Configurações e o "TIPO às HH:MM" da justificativa de trabalho externo.
 *
 * O comportamento (o bloco fora do raio, o envio com o motivo) é JavaScript e fica travado em
 * `App\Tests\Ponto\Unit\TrabalhoExternoForaDoRaioJsTest`. Aqui fica o HTML de que ele depende, conferido por filho
 * direto (`>`). As variáveis do servidor da etapa (`trabalho_externo_permitido`, `permite_trabalho_externo`) podem
 * faltar: o render não quebra, e o estado marcado é conferido renderizando o parcial com cada valor.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(ConfiguracoesDoPontoController::class)]
final class TrabalhoExternoNaTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const FUNDO = '.pe-page > div.pe-janela-fundo#ponto-janela-externo[hidden]';
    private const JANELA = self::FUNDO . ' > div.pe-janela[role="dialog"][aria-modal="true"][aria-label="Trabalho externo"]';
    private const SECAO = '.pe-config > section.pe-sf-secao[data-pc-sedes] > .pe-sedes-cabeca > b.pe-sedes-titulo + div.pe-config-externo[data-config-externo]';

    private KernelBrowser $client;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Externo');
    }

    #[TestDox('Janela "Trabalho externo" na moldura do desenho (L766, L289): topo com bi-briefcase, texto, "Local e motivo" (3 linhas, até 500), Cancelar e Registrar')]
    public function testJanelaNaMolduraDoDesenho(): void
    {
        $tela = $this->tela();
        $janela = $tela->filter(self::JANELA);
        self::assertCount(1, $janela, 'dentro da página (herda os tokens --pe-*), escondida, role="dialog" e aria-modal');

        self::assertCount(1, $janela->filter('div.pe-janela > div.pe-janela-topo:first-child + div.pe-janela-corpo + div.pe-janela-erro[data-janela-erro][hidden] + div.pe-janela-rodape:last-child'), 'topo, corpo, a linha de erro e o rodapé');

        $topo = $janela->filter('div.pe-janela > div.pe-janela-topo');
        self::assertCount(1, $topo->filter('div.pe-janela-topo > i.bi.bi-briefcase[aria-hidden="true"]:first-child + b.pe-janela-titulo + button.pe-janela-fechar[type="button"][aria-label="Fechar"][data-janela-fechar]:last-child'));
        self::assertSame('Trabalho externo', trim($topo->filter('b.pe-janela-titulo')->text()));

        $corpo = $janela->filter('div.pe-janela > div.pe-janela-corpo');
        self::assertCount(1, $corpo->filter('div.pe-janela-corpo > div.pe-janela-info:first-child + label.pe-janela-rotulo:last-child'));
        self::assertSame('O registro será feito fora do raio e seguirá para análise do gestor.', trim($corpo->filter('div.pe-janela-info')->text()));
        self::assertNull($corpo->filter('div.pe-janela-info')->attr('data-janela-info'), 'o texto é fixo: a moldura não o troca');
        self::assertSame('Local e motivo', trim((string) $corpo->filter('label.pe-janela-rotulo')->getNode(0)?->firstChild?->textContent));
        self::assertCount(1, $corpo->filter('label.pe-janela-rotulo > textarea.pe-janela-campo[rows="3"][maxlength="500"][data-externo-motivo]'));

        $rodape = $janela->filter('div.pe-janela > div.pe-janela-rodape');
        self::assertCount(1, $rodape->filter('div.pe-janela-rodape > button.pe-janela-cancelar[type="button"][data-janela-fechar]:first-child + button.pe-janela-acao[type="button"][data-externo-registrar]:last-child'));
        self::assertSame(['Cancelar', 'Registrar'], $rodape->filter('button')->each(static fn (Crawler $b): string => trim($b->text())));

        self::assertCount(0, $tela->filter('#ponto-janela-externo .modal, #ponto-janela-externo .modal-dialog, #ponto-janela-externo .btn, #ponto-janela-externo .form-control, #ponto-janela-externo .alert, #ponto-janela-externo [data-bs-dismiss], #ponto-janela-externo [data-bs-toggle]'), 'componentes do desenho, nada do Bootstrap');
        self::assertCount(0, $tela->filter('#ponto-janela-externo.modal'));
        self::assertCount(1, $tela->filter('#ponto-janela-externo'), 'uma janela só');
    }

    #[TestDox('A tela diz se o escritório permite o trabalho externo, junto dos data-atributos da fila (E14), sem quebrar sem a variável')]
    public function testDataAtributoDaTela(): void
    {
        $tela = $this->tela();
        $fila = $tela->filter('.pe-page > .pe-grade > .pe-esq > section.pe-registrar > .pe-corpo > div#ponto-fila.pe-fila');
        self::assertCount(1, $fila);
        self::assertContains($fila->attr('data-trabalho-externo'), ['0', '1'], 'só 0 ou 1: o script compara com "1"');
        self::assertNotEmpty($fila->attr('data-usuario-id'), 'os da E14 continuam');
        self::assertNotEmpty($fila->attr('data-escritorio-id'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("const TRABALHO_EXTERNO_PERMITIDO = (document.getElementById('ponto-fila') || { dataset: {} }).dataset.trabalhoExterno === '1';", $html);
        self::assertCount(0, $tela->filter('.pe-fora-externo'), 'o botão só nasce na resposta fora do raio, pelo script');
    }

    #[TestDox('Configurações: quem administra o ponto vê a caixa "Permitir trabalho externo, com motivo e análise" no cabeçalho "Sedes e raio de registro", entre o título e os botões (desenho L253)')]
    public function testCaixaParaQuemAdministra(): void
    {
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $this->escritorio);
        $this->logarComTenant($this->client, $primario, $this->escritorio);

        $painel = $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseIsSuccessful();

        $secao = $painel->filter(self::SECAO);
        self::assertCount(1, $secao, 'filha direta do cabeçalho das sedes, logo depois do título');
        self::assertCount(1, $painel->filter('.pe-sedes-cabeca > div.pe-config-externo + a.pe-sf-contorno'), 'antes dos botões do cabeçalho');
        self::assertCount(1, $painel->filter('[data-config-externo]'), 'uma caixa só: com as sedes à vista, ela não fica também sozinha');
        self::assertSame('/ponto/configuracoes/trabalho-externo', $secao->attr('data-url'), 'a rota ponto_configuracao_trabalho_externo');
        self::assertCount(1, $secao->filter('div.pe-config-externo > label.pe-config-externo-marca:first-child + span.pe-config-externo-erro[role="alert"][data-config-externo-erro][hidden]:last-child'));
        self::assertCount(1, $secao->filter('label.pe-config-externo-marca > input[type="checkbox"][data-config-externo-caixa]:first-child'));
        self::assertSame('Permitir trabalho externo, com motivo e análise', trim($secao->filter('label.pe-config-externo-marca')->text()));
        self::assertCount(0, $secao->filter('.form-check, .form-switch, .form-check-input, .btn'), 'a caixa do desenho, nada do Bootstrap');
    }

    #[TestDox('Configurações: quem só cuida dos feriados vê a aba sem a caixa, como sem as chaves das funções')]
    public function testSemACaixaParaQuemNaoAdministra(): void
    {
        $feriados = $this->pessoa('feriados');
        $this->vincular($feriados, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);
        $this->logarComTenant($this->client, $feriados, $this->escritorio);

        $painel = $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $painel->filter('[data-config-externo]'));
    }

    #[TestDox('A caixa vem marcada pelo permite_trabalho_externo do servidor; sem a variável, desmarcada e sem erro')]
    public function testCaixaMarcadaPeloServidor(): void
    {
        $marcada = new Crawler($this->twig()->render('ponto/_config_trabalho_externo.html.twig', ['funcoesDoPonto' => true, 'permite_trabalho_externo' => true]));
        self::assertNotNull($marcada->filter('input[data-config-externo-caixa]')->attr('checked'));

        $desmarcada = new Crawler($this->twig()->render('ponto/_config_trabalho_externo.html.twig', ['funcoesDoPonto' => true, 'permite_trabalho_externo' => false]));
        self::assertNull($desmarcada->filter('input[data-config-externo-caixa]')->attr('checked'));

        $semVariavel = new Crawler($this->twig()->render('ponto/_config_trabalho_externo.html.twig', ['funcoesDoPonto' => true]));
        self::assertCount(1, $semVariavel->filter('input[data-config-externo-caixa]'));
        self::assertNull($semVariavel->filter('input[data-config-externo-caixa]')->attr('checked'));

        self::assertSame('', trim($this->twig()->render('ponto/_config_trabalho_externo.html.twig', ['funcoesDoPonto' => null, 'permite_trabalho_externo' => true])), 'sem quem administre, nada');
    }

    #[TestDox('Trabalho externo na lista: "Entrada às HH:MM" da batida ligada (L545); sem a batida ligada, sem subtítulo')]
    public function testSubtituloDaJustificativaDeTrabalhoExterno(): void
    {
        $fuso = new \DateTimeZone(date_default_timezone_get());
        $ligada = ['tipo' => 'trabalho_externo', 'abonoParcial' => false, 'registroPonto' => ['tipo' => 'entrada', 'dataHora' => new \DateTimeImmutable('2026-11-09 08:05:41', $fuso)]];
        self::assertSame('Entrada às 08:05', $this->subtitulo($ligada));
        $ligada['registroPonto']['tipo'] = 'saida';
        self::assertSame('Saída às 08:05', $this->subtitulo($ligada));

        self::assertSame('', $this->subtitulo(['tipo' => 'trabalho_externo', 'abonoParcial' => false, 'registroPonto' => null]), 'as de antes da E12');
        self::assertSame('', $this->subtitulo(['tipo' => 'trabalho_externo', 'abonoParcial' => false]), 'objeto sem o campo');

        // A justificativa de verdade: sem a batida ligada (ou sem o campo, antes do servidor da etapa), vazio e sem erro.
        $eu = $this->pessoa('lista');
        $this->vincularColaborador($eu, $this->escritorio);
        $justificativa = $this->justificativa($eu, $this->escritorio, new \DateTimeImmutable('today'), 'pendente', ['tipo' => 'trabalho_externo']);
        self::assertInstanceOf(JustificativaPonto::class, $justificativa);
        self::assertSame('', $this->subtitulo($justificativa));

        $esquecimento = ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'retorno', 'horaRegistroEsquecido' => new \DateTimeImmutable('1970-01-01 13:10:00', $fuso), 'abonoParcial' => false];
        self::assertSame('Retorno às 13:10', $this->subtitulo($esquecimento), 'o esquecimento continua como antes');
    }

    #[TestDox('Minhas Justificativas renderiza a de trabalho externo sem a batida ligada, sem subtítulo')]
    public function testListaComTrabalhoExterno(): void
    {
        $eu = $this->pessoa('aba');
        $this->vincularColaborador($eu, $this->escritorio);
        $this->justificativa($eu, $this->escritorio, new \DateTimeImmutable('today'), 'pendente', ['tipo' => 'trabalho_externo', 'motivo' => 'Audiência no fórum']);

        $this->logarComTenant($this->client, $eu, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/?aba=justificativas');
        self::assertResponseIsSuccessful();

        $celula = $tela->filter('tr.pe-just-lin > td.pe-just-celtipo');
        self::assertCount(1, $celula);
        // L184: o subtítulo sai sempre, vazio aqui; o margin-top dele separa o selo do motivo.
        self::assertSame('', trim($celula->filter('td.pe-just-celtipo > div.pe-just-sub')->text()));
        self::assertCount(1, $celula->filter('td.pe-just-celtipo > span.pe-just-tipo + div.pe-just-sub + div.pe-just-mot'));
        self::assertSame('Audiência no fórum', trim($celula->filter('.pe-just-mot')->text()));

        // Sem a batida ligada, o lápis e a lixeira continuam (antes e depois do campo do servidor da etapa).
        $acoes = $tela->filter('tr.pe-just-lin > td.pe-just-acoes');
        self::assertCount(1, $acoes->filter('td.pe-just-acoes > button.pe-just-icone--editar[data-justificativa-editar]'));
        self::assertCount(1, $acoes->filter('td.pe-just-acoes > button.pe-just-icone[data-excluir-url]'));
        self::assertCount(0, $acoes->filter('.pe-just-segue'));
    }

    #[TestDox('Ligada a uma batida de trabalho externo: sem lápis nem lixeira, com "Segue para a análise do gestor."; as outras como sempre')]
    public function testJustificativaDaBatidaSemEditarNemExcluir(): void
    {
        $batida = ['tipo' => 'entrada', 'dataHora' => new \DateTimeImmutable('2026-11-09 08:05:00')];

        $acoes = $this->linhaDaLista(['registroPonto' => $batida]);
        self::assertCount(1, $acoes->filter('td.pe-just-acoes > span.pe-just-segue[data-justificativa-da-batida]:only-child'), 'no lugar dos dois botões');
        self::assertSame('Segue para a análise do gestor.', trim($acoes->filter('span.pe-just-segue')->text()));
        self::assertCount(0, $acoes->filter('[data-justificativa-editar], [data-excluir-url], .bi-pencil, .bi-trash3'));

        foreach (['sem a batida ligada' => ['registroPonto' => null], 'sem o campo (antes do servidor da etapa)' => []] as $caso => $campos) {
            $acoes = $this->linhaDaLista($campos);
            self::assertCount(1, $acoes->filter('td.pe-just-acoes > button.pe-just-icone--editar[data-justificativa-editar]'), $caso);
            self::assertCount(1, $acoes->filter('td.pe-just-acoes > button.pe-just-icone[data-excluir-url]'), $caso);
            self::assertCount(0, $acoes->filter('.pe-just-segue'), $caso);
        }

        // Quem analisa a própria (o primário, o Master): só "Abonar" e "Recusar", sem a linha e sem lápis nem lixeira.
        $propria = $this->linhaDaLista(['registroPonto' => $batida], true);
        self::assertCount(1, $propria->filter('td.pe-just-acoes > span.pe-just-analise > button[data-analisar="abonar"] + button[data-analisar="recusar"]'));
        self::assertCount(0, $propria->filter('.pe-just-segue, [data-justificativa-da-batida]'), 'sem "Segue para a análise do gestor." para quem é o gestor');
        self::assertCount(0, $propria->filter('[data-justificativa-editar], [data-excluir-url]'));

        // Analisada, a trava de sempre (a linha é só da que está em análise).
        $analisada = $this->linhaDaLista(['registroPonto' => $batida, 'status' => 'abonado', 'dataAnalise' => new \DateTimeImmutable('2026-11-10')]);
        self::assertCount(1, $analisada->filter('td.pe-just-acoes > span.pe-just-trava[data-justificativa-bloqueada]'));
        self::assertCount(0, $analisada->filter('.pe-just-segue'));

        // A lista antiga (tela antiga), do mesmo jeito: sem o lápis da ligada em análise.
        $antiga = new Crawler($this->renderComSessao('ponto/_justificativas_list.html.twig', ['justificativas' => [$this->justificativaEmArray(['registroPonto' => $batida])]]));
        self::assertCount(1, $antiga->filter('tbody > tr > td > span[data-justificativa-da-batida]'));
        self::assertSame('Segue para a análise do gestor.', trim($antiga->filter('[data-justificativa-da-batida]')->text()));
        self::assertCount(0, $antiga->filter('button[title="Editar justificativa"]'));
        $antigaSemLigacao = new Crawler($this->renderComSessao('ponto/_justificativas_list.html.twig', ['justificativas' => [$this->justificativaEmArray(['registroPonto' => null])]]));
        self::assertCount(1, $antigaSemLigacao->filter('button[title="Editar justificativa"]'));
        self::assertCount(0, $antigaSemLigacao->filter('[data-justificativa-da-batida]'));
    }

    private function tela(): Crawler
    {
        $eu = $this->pessoa('externo');
        $this->vincularColaborador($eu, $this->escritorio);
        $this->logarComTenant($this->client, $eu, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function twig(): Environment
    {
        return static::getContainer()->get('twig');
    }

    /**
     * As ações de uma linha de "Minhas Justificativas" (o parcial da aba), com uma justificativa em array: o campo
     * `registroPonto` chega com o servidor da etapa e não existe na entidade desta branch.
     *
     * @param array<string, mixed> $campos
     */
    private function linhaDaLista(array $campos, bool $podeAnalisarAPropria = false): Crawler
    {
        $html = $this->renderComSessao('ponto/_abas_justificativas.html.twig', ['justificativas' => [$this->justificativaEmArray($campos)], 'podeAnalisarAPropria' => $podeAnalisarAPropria]);

        return (new Crawler($html))->filter('table.pe-just-tab > tbody > tr.pe-just-lin > td.pe-just-acoes');
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function justificativaEmArray(array $campos): array
    {
        return $campos + [
            'id' => 41,
            'data' => new \DateTimeImmutable('2026-11-09'),
            'tipo' => 'trabalho_externo',
            'labelTipo' => 'Trabalho externo',
            'motivo' => 'Audiência no fórum',
            'status' => 'pendente',
            'anexoPath' => null,
            'observacaoAnalise' => null,
            'dataAnalise' => null,
            'abonoParcial' => false,
            'horaInicioAbono' => null,
            'horaFimAbono' => null,
            'tipoRegistroEsquecido' => null,
            'horaRegistroEsquecido' => null,
            'batchId' => null,
            'tenant' => ['id' => 7],
            'user' => ['id' => 9],
        ];
    }

    /**
     * Render de um parcial que usa `csrf_token()`: precisa de uma sessão na pilha de pedidos.
     *
     * @param array<string, mixed> $contexto
     */
    private function renderComSessao(string $modelo, array $contexto): string
    {
        $pedido = new Request();
        $pedido->setSession(new Session(new MockArraySessionStorage()));
        $pilha = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $pilha);
        $pilha->push($pedido);
        try {
            return $this->twig()->render($modelo, $contexto);
        } finally {
            $pilha->pop();
        }
    }

    private function subtitulo(mixed $justificativa): string
    {
        $modelo = $this->twig()->createTemplate("{% import 'ponto/_justificativa_macros.html.twig' as jm %}{{ jm.subtitulo(j)|trim }}");

        return $modelo->render(['j' => $justificativa]);
    }
}
