<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ConfiguracoesDoPontoController;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\Service\SedesEFeriadosDoPonto;
use App\Ponto\UseCase\CadastrarFeriadoDoPontoUseCase;
use App\Ponto\UseCase\RemoverFeriadoDoPontoUseCase;
use App\Ponto\UseCase\RemoverSedeDoPontoUseCase;
use App\Ponto\UseCase\SalvarSedeDoPontoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sedes e feriados na aba Configurações da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E6; desenho 16 L253 a
 * L258): o arranjo e os textos do desenho, cada seção só para quem tem a permissão dela, e as rotas de gravação com o
 * escritório da sessão, a permissão da seção (403), o token da página (403) e a posse (404).
 */
#[CoversClass(ConfiguracoesDoPontoController::class)]
#[CoversClass(SalvarSedeDoPontoUseCase::class)]
#[CoversClass(RemoverSedeDoPontoUseCase::class)]
#[CoversClass(CadastrarFeriadoDoPontoUseCase::class)]
#[CoversClass(RemoverFeriadoDoPontoUseCase::class)]
#[CoversClass(SedesEFeriadosDoPonto::class)]
#[Group('ponto')]
final class ConfiguracoesDoPontoSedesEFeriadosTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const PAINEL = 'div.pe-config';
    private const SEDES = self::PAINEL . ' > section.pe-sf-secao[data-sedes][data-url-criar="/ponto/configuracoes/sedes"]';
    private const FERIADOS = self::PAINEL . ' > section.pe-sf-secao[data-feriados]';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Configurações');
        $this->outro = $this->escritorio('Outro escritório');
        $this->primario = $this->pessoa('primario');
        $this->vincularPrimario($this->primario, $this->escritorio);
    }

    // ------------------------------------------------------------- o painel

    #[TestDox('Sedes: título, "Mapa e busca por endereço", "Adicionar sede", a tabela do desenho e a sede com o raio dela')]
    public function testSecaoDasSedes(): void
    {
        $sede = $this->sede('Maceió/AL', '-9.66599900', '-35.73500000', 100);
        $this->sede('Sede de lá', '-8.05', '-34.9', 200, $this->outro);

        $painel = $this->painel($this->primario);

        $cabeca = self::SEDES . ' > div.pe-sedes-cabeca';
        self::assertSame('Sedes e raio de registro', $painel->filter($cabeca . ' > b.pe-sedes-titulo:first-child')->text());
        $mapa = $painel->filter($cabeca . ' > a.pe-sf-contorno[href="/tenant/' . $this->escritorio->getId() . '/sedes"]');
        self::assertSame('Mapa e busca por endereço', trim($mapa->text()), 'P69: a página antiga fica');
        $adicionar = $painel->filter($cabeca . ' > button.pe-sf-contorno[type="button"][data-sede-adicionar]:last-child');
        self::assertSame('Adicionar sede', trim($adicionar->text()));
        self::assertCount(1, $adicionar->filter('button > i.bi.bi-plus-lg:first-child'));

        $tabela = self::SEDES . ' > div.pe-sedes-rolagem > table.pe-tab.pe-sedes-tab';
        self::assertSame(['Sede', 'Latitude', 'Longitude', 'Raio (m)', ''], $painel->filter($tabela . ' > thead > tr > th')->each(static fn (Crawler $th): string => $th->text()));
        $linhas = $painel->filter($tabela . ' > tbody[data-sedes-corpo] > tr.pe-sede-lin[data-sede-linha]');
        self::assertCount(1, $linhas, 'só a sede deste escritório');
        $linha = $linhas->first();
        self::assertSame('/ponto/configuracoes/sedes/' . $sede->getId(), $linha->attr('data-url-salvar'));
        self::assertSame('/ponto/configuracoes/sedes/' . $sede->getId() . '/remover', $linha->attr('data-url-remover'));
        self::assertSame('Maceió/AL', $linha->filter('tr > td.pe-sede-cel-nome > input.pe-sf-campo[name="nome"][aria-label="Nome da sede"]')->attr('value'));
        self::assertSame('-9.665999', $linha->filter('tr > td.pe-sede-cel-coord > input.pe-sf-campo[name="latitude"][aria-label="Latitude"][placeholder="informar"]')->attr('value'), 'sem os zeros da coluna');
        self::assertSame('-35.735', $linha->filter('tr > td.pe-sede-cel-coord > input.pe-sf-campo[name="longitude"][aria-label="Longitude"][placeholder="informar"]')->attr('value'));
        self::assertSame('100', $linha->filter('tr > td.pe-sede-cel-raio > input.pe-sf-campo[type="number"][name="raio"][aria-label="Raio em metros"]')->attr('value'), 'a sede que já existe fica com o raio dela');
        $posicao = $linha->filter('tr > td.pe-sede-acoes:last-child > button.pe-sf-contorno[data-sede-posicao]:first-child');
        self::assertSame('Usar minha posição', trim($posicao->text()));
        self::assertCount(1, $posicao->filter('button > i.bi.bi-crosshair:first-child'));
        self::assertCount(1, $linha->filter('tr > td.pe-sede-acoes > button.pe-sede-remover[aria-label="Remover sede"][data-sede-remover]:last-child > i.bi.bi-trash3'));
        self::assertCount(1, $painel->filter($tabela . ' > tbody > tr.pe-sede-lin + tr.pe-sede-erro-lin[data-sede-erro][hidden] > td.pe-sede-erro[colspan="5"][role="alert"]'), 'a linha do erro logo abaixo');
    }

    #[TestDox('P70 a: a "Nova sede" do "Adicionar sede" nasce com 150 m e sem coordenada; a página antiga também abre com 150 m')]
    public function testSedeNovaCom150Metros(): void
    {
        $painel = $this->painel($this->primario);

        $modelo = $painel->filter(self::SEDES . ' > template[data-sede-modelo]');
        self::assertCount(1, $modelo);
        $html = (string) $modelo->html();
        self::assertStringContainsString('value="Nova sede"', $html);
        self::assertMatchesRegularExpression('/name="raio"[^>]*value="150"/', $html);
        self::assertStringNotContainsString('data-url-salvar', $html, 'a nova só ganha endereço quando for criada');

        $this->client->request('GET', '/tenant/' . $this->escritorio->getId() . '/sedes');
        self::assertResponseIsSuccessful();
        self::assertSame('150', $this->client->getCrawler()->filter('input[name="sede[raioPermitido]"]')->attr('value'));
    }

    #[TestDox('Feriados: título, texto, formulário (Data, Nome, Abrangência com as lotações, "Repete todo ano", "Adicionar") e a lista')]
    public function testSecaoDosFeriados(): void
    {
        $maceio = $this->lotacao('MACEIÓ/AL');
        $brasilia = $this->lotacao('BRASÍLIA/DF');
        $this->lotacao('DE LÁ', $this->outro);
        $this->feriado('Emancipação de Alagoas', '2026-09-16', $maceio, true);
        $this->feriado('Ponto facultativo', '2026-11-21', null, false);
        $this->feriado('Feriado de lá', '2026-10-01', null, true, $this->outro);

        $painel = $this->painel($this->primario);

        self::assertSame('Feriados do escritório', $painel->filter(self::FERIADOS . ' > b.pe-fer-titulo:first-child')->text());
        self::assertSame('Os nacionais já estão incluídos. Cadastre aqui os estaduais e municipais, por sede.', $painel->filter(self::FERIADOS . ' > b.pe-fer-titulo + div.pe-fer-texto')->text());
        $form = self::FERIADOS . ' > form.pe-fer-form[data-feriado-form][action="/ponto/configuracoes/feriados"]';
        self::assertSame(['Data', 'Nome', 'Abrangência'], $painel->filter($form . ' > label.pe-fer-rotulo')->each(static fn (Crawler $l): string => trim($l->getNode(0)?->firstChild?->textContent ?? '')));
        self::assertCount(1, $painel->filter($form . ' > label.pe-fer-rotulo > input.pe-sf-campo[type="date"][name="data"]'));
        self::assertCount(1, $painel->filter($form . ' > label.pe-fer-rotulo > input.pe-sf-campo[name="nome"]'));
        self::assertSame(
            ['' => 'Todas as sedes', (string) $brasilia->getId() => 'BRASÍLIA/DF', (string) $maceio->getId() => 'MACEIÓ/AL'],
            $this->opcoes($painel->filter($form . ' > label.pe-fer-rotulo > select.pe-sf-campo[name="abrangencia"] > option')),
            '"Todas as sedes" e as lotações deste escritório, em ordem de nome',
        );
        self::assertSame('Repete todo ano', trim($painel->filter($form . ' > label.pe-fer-repete > input[type="checkbox"][name="recorrente"][checked]')->closest('label')?->text() ?? ''));
        self::assertSame('Adicionar', $painel->filter($form . ' > button.pe-fer-adicionar[type="submit"]:last-child')->text());

        $linhas = $painel->filter(self::FERIADOS . ' > div.pe-fer-lista[data-feriados-lista] > div.pe-fer-lin');
        self::assertCount(2, $linhas, 'o de outro escritório não aparece');
        self::assertSame('16/09', $linhas->eq(0)->filter('div > b:first-child')->text(), 'o que repete mostra dia e mês');
        self::assertSame('Emancipação de Alagoas', $linhas->eq(0)->filter('div > span.pe-fer-nome')->text());
        self::assertSame('Todo ano', trim($linhas->eq(0)->filter('div > span.pe-fer-nome + span.pe-fer-marca')->text()));
        self::assertSame('MACEIÓ/AL', $linhas->eq(0)->filter('div > span.pe-fer-abrangencia')->text());
        self::assertSame('21/11/2026', $linhas->eq(1)->filter('div > b:first-child')->text(), 'o pontual mostra o ano');
        self::assertSame('Todas as sedes', $linhas->eq(1)->filter('div > span.pe-fer-abrangencia')->text());
        self::assertCount(0, $linhas->eq(1)->filter('.pe-fer-marca'));
        self::assertCount(2, $painel->filter(self::FERIADOS . ' div.pe-fer-lin > button.pe-fer-remover[type="button"][aria-label="Remover feriado"][data-feriado-remover]:last-child > i.bi.bi-x-lg'));
    }

    #[TestDox('D4: remover sede e remover feriado abrem a janela do desenho (role=dialog), com "Cancelar" e "Remover"')]
    public function testJanelasDeConfirmacao(): void
    {
        $painel = $this->painel($this->primario);

        foreach ([[self::SEDES, 'sede', 'Remover sede'], [self::FERIADOS, 'feriado', 'Remover feriado']] as [$secao, $tipo, $titulo]) {
            $janela = $secao . ' > div.pe-janela-fundo#ponto-janela-remover-' . $tipo . '[hidden][data-config-janela] > div.pe-janela[role="dialog"][aria-modal="true"][aria-label="' . $titulo . '"]';
            self::assertSame($titulo, $painel->filter($janela . ' > div.pe-janela-topo > b.pe-janela-titulo')->text());
            self::assertCount(1, $painel->filter($janela . ' > div.pe-janela-topo > button.pe-janela-fechar[aria-label="Fechar"][data-config-fechar]'));
            self::assertCount(1, $painel->filter($janela . ' > div.pe-janela-corpo > div.pe-janela-info[data-config-info]'));
            self::assertStringContainsString('Não é possível desfazer.', $painel->filter($janela . ' > div.pe-janela-corpo > div.pe-janela-texto')->text());
            self::assertCount(1, $painel->filter($janela . ' > div.pe-janela-erro[data-config-erro][hidden]'));
            self::assertSame(['Cancelar', 'Remover'], $painel->filter($janela . ' > div.pe-janela-rodape > button')->each(static fn (Crawler $b): string => $b->text()));
            self::assertCount(1, $painel->filter($janela . ' > div.pe-janela-rodape > button.pe-janela-acao[data-config-confirmar="' . $tipo . '"]'));
        }
    }

    #[TestDox('Cada seção só para quem tem a permissão dela: sedes com admin.ponto.manage, feriados com admin.tenant.settings.manage')]
    public function testSecoesPorPermissao(): void
    {
        $soSedes = $this->pessoa('sedes');
        $this->vincular($soSedes, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::SEDES], false);
        $soFeriados = $this->pessoa('feriados');
        $this->vincular($soFeriados, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);

        $painel = $this->painel($soSedes);
        self::assertCount(1, $painel->filter(self::SEDES));
        self::assertCount(0, $painel->filter('[data-feriados]'));

        $painel = $this->painel($soFeriados);
        self::assertCount(0, $painel->filter('[data-sedes]'));
        self::assertCount(1, $painel->filter(self::FERIADOS));
    }

    // ---------------------------------------------------------------- sedes

    #[TestDox('Criar sede: coordenada com vírgula vira ponto, raio vazio vira 150 m, a sede é do escritório da sessão')]
    public function testCriarSede(): void
    {
        $dados = $this->postar($this->primario, '/ponto/configuracoes/sedes', ['nome' => ' Nova sede ', 'latitude' => '-9,665999', 'longitude' => ' -35,7350 ', 'raio' => '']);

        self::assertResponseIsSuccessful();
        self::assertTrue($dados['ok']);
        $sede = $this->emSemFiltro()->getRepository(Sede::class)->find($dados['sede']['id']);
        self::assertNotNull($sede);
        self::assertSame($this->escritorio->getId(), $sede->getTenant()?->getId());
        self::assertSame('Nova sede', $sede->getNome());
        self::assertSame(Sede::RAIO_PADRAO, $sede->getRaioPermitido());
        self::assertSame(['id' => $sede->getId(), 'nome' => 'Nova sede', 'latitude' => '-9.665999', 'longitude' => '-35.735', 'raio' => 150], array_intersect_key($dados['sede'], array_flip(['id', 'nome', 'latitude', 'longitude', 'raio'])));
        self::assertSame('/ponto/configuracoes/sedes/' . $sede->getId(), $dados['sede']['urlSalvar']);
        self::assertSame('/ponto/configuracoes/sedes/' . $sede->getId() . '/remover', $dados['sede']['urlRemover']);
    }

    #[TestDox('Sede sem coordenada não vai para o banco: 422 com o erro de cada campo, nas frases da página antiga')]
    public function testSedeSemCoordenadaNaoGrava(): void
    {
        $dados = $this->postar($this->primario, '/ponto/configuracoes/sedes', ['nome' => 'Nova sede', 'latitude' => '', 'longitude' => '', 'raio' => '150']);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($dados['ok']);
        self::assertSame(['latitude' => 'Latitude é obrigatória.', 'longitude' => 'Longitude é obrigatória.'], $dados['erros']);
        self::assertSame([], $this->emSemFiltro()->getRepository(Sede::class)->findBy(['tenant' => $this->escritorio->getId()]));
    }

    #[TestDox('Gravar sede: mesmas validações da página antiga; nada muda quando um campo falha')]
    public function testSalvarSedeComAsValidacoesDaPaginaAntiga(): void
    {
        $sede = $this->sede('Maceió/AL', '-9.665999', '-35.735', 100);
        $url = '/ponto/configuracoes/sedes/' . $sede->getId();
        $casos = [
            [['nome' => '', 'latitude' => '-9.6', 'longitude' => '-35.7', 'raio' => '100'], ['nome' => 'Informe o nome da sede.']],
            [['nome' => 'X', 'latitude' => '95', 'longitude' => '-35.7', 'raio' => '100'], ['latitude' => 'Latitude deve estar entre -90 e 90.']],
            [['nome' => 'X', 'latitude' => 'abc', 'longitude' => '-181', 'raio' => '100'], ['latitude' => 'Latitude deve ser um número.', 'longitude' => 'Longitude deve estar entre -180 e 180.']],
            [['nome' => 'X', 'latitude' => '-9.6', 'longitude' => '-35.7', 'raio' => '10,5'], ['raio' => 'Raio permitido deve ser um número inteiro de metros.']],
            [['nome' => 'X', 'latitude' => '-9.6', 'longitude' => '-35.7', 'raio' => 'muito'], ['raio' => 'Raio permitido deve ser um número.']],
            [['nome' => 'X', 'latitude' => '-9.6', 'longitude' => '-35.7', 'raio' => '501'], ['raio' => 'Raio permitido deve estar entre 10 e 500 metros.']],
        ];
        foreach ($casos as [$corpo, $erros]) {
            $dados = $this->postar($this->primario, $url, $corpo);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($erros, $dados['erros'], json_encode($corpo, JSON_THROW_ON_ERROR));
        }

        $intacta = $this->emSemFiltro()->getRepository(Sede::class)->find($sede->getId());
        self::assertSame('Maceió/AL', $intacta?->getNome());
        self::assertSame(100, $intacta?->getRaioPermitido());

        $dados = $this->postar($this->primario, $url, ['nome' => 'Maceió (centro)', 'latitude' => '-9.665999', 'longitude' => '-35.735', 'raio' => '100']);
        self::assertResponseIsSuccessful();
        self::assertSame('Maceió (centro)', $dados['sede']['nome']);
        self::assertSame(100, $this->emSemFiltro()->getRepository(Sede::class)->find($sede->getId())?->getRaioPermitido(), 'a sede que já existe fica com o raio dela');
    }

    #[TestDox('Remover sede: as batidas dela ficam sem a sede, com o nome guardado, e o aviso conta quantas')]
    public function testRemoverSede(): void
    {
        $sede = $this->sede('Maceió/AL', '-9.665999', '-35.735', 150);
        $batida = (new RegistroPonto())->setUser($this->primario)->setTenant($this->escritorio)->setTipo('entrada')->setDataHora(new \DateTime('2026-11-03 09:00'))->setSede($sede);
        $this->em()->persist($batida);
        $this->em()->flush();

        $dados = $this->postar($this->primario, '/ponto/configuracoes/sedes/' . $sede->getId() . '/remover', []);

        self::assertResponseIsSuccessful();
        self::assertSame('Sede removida com sucesso. 1 registro(s) de ponto foram desvinculados automaticamente da sede removida.', $dados['mensagem']);
        $em = $this->emSemFiltro();
        self::assertNull($em->getRepository(Sede::class)->find($sede->getId()));
        $guardada = $em->getRepository(RegistroPonto::class)->find($batida->getId());
        self::assertNull($guardada?->getSede());
        self::assertSame('Maceió/AL', $guardada?->getSedeNomeSnapshot());
    }

    // ------------------------------------------------------------- feriados

    #[TestDox('Cadastrar feriado da lotação: grava a abrangência e "Repete todo ano" e devolve a lista nova')]
    public function testCadastrarFeriado(): void
    {
        $maceio = $this->lotacao('MACEIÓ/AL');

        $dados = $this->postar($this->primario, '/ponto/configuracoes/feriados', ['data' => '2026-09-16', 'nome' => ' Emancipação de Alagoas ', 'abrangencia' => (string) $maceio->getId(), 'recorrente' => '1']);

        self::assertResponseIsSuccessful();
        $lista = new Crawler('<div>' . $dados['lista'] . '</div>');
        self::assertSame('Emancipação de Alagoas', $lista->filter('div.pe-fer-lin > span.pe-fer-nome')->text());
        self::assertSame('MACEIÓ/AL', $lista->filter('div.pe-fer-lin > span.pe-fer-abrangencia')->text());
        $feriado = $this->emSemFiltro()->getRepository(Feriado::class)->findOneBy(['tenant' => $this->escritorio->getId()]);
        self::assertSame('Emancipação de Alagoas', $feriado?->getNome());
        self::assertSame('2026-09-16', $feriado?->getData()?->format('Y-m-d'));
        self::assertSame($maceio->getId(), $feriado?->getLotacao()?->getId());
        self::assertTrue($feriado?->isRecorrente());

        $this->postar($this->primario, '/ponto/configuracoes/feriados', ['data' => '2026-11-21', 'nome' => 'Ponto facultativo', 'abrangencia' => '']);
        self::assertResponseIsSuccessful();
        $pontual = $this->emSemFiltro()->getRepository(Feriado::class)->findOneBy(['nome' => 'Ponto facultativo']);
        self::assertNull($pontual?->getLotacao(), '"Todas as sedes"');
        self::assertFalse($pontual?->isRecorrente(), 'caixa desmarcada');
    }

    #[TestDox('Feriado sem data ou nome: 422 com a frase do desenho; lotação de outro escritório: 404; nada é gravado')]
    public function testFeriadoRecusado(): void
    {
        $deLa = $this->lotacao('DE LÁ', $this->outro);
        foreach ([['data' => '', 'nome' => 'Sem data'], ['data' => '2026-09-16', 'nome' => '  '], ['data' => '2026-02-31', 'nome' => 'Data que não existe']] as $corpo) {
            $dados = $this->postar($this->primario, '/ponto/configuracoes/feriados', $corpo + ['abrangencia' => '']);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Informe a data e o nome do feriado.', $dados['mensagem']);
        }

        $dados = $this->postar($this->primario, '/ponto/configuracoes/feriados', ['data' => '2026-09-16', 'nome' => 'Feriado', 'abrangencia' => (string) $deLa->getId()]);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($dados['ok']);

        self::assertSame([], $this->emSemFiltro()->getRepository(Feriado::class)->findBy(['tenant' => $this->escritorio->getId()]));
    }

    #[TestDox('Remover feriado: sai do banco e a lista volta sem ele')]
    public function testRemoverFeriado(): void
    {
        $feriado = $this->feriado('Emancipação de Alagoas', '2026-09-16', null, true);

        $dados = $this->postar($this->primario, '/ponto/configuracoes/feriados/' . $feriado->getId() . '/remover', []);

        self::assertResponseIsSuccessful();
        self::assertSame('Feriado removido.', $dados['mensagem']);
        self::assertStringContainsString('Nenhum feriado cadastrado.', $dados['lista']);
        self::assertNull($this->emSemFiltro()->getRepository(Feriado::class)->find($feriado->getId()));
    }

    #[TestDox('A página antiga "Feriados e Folgas": editar o feriado não perde a abrangência')]
    public function testEditarNaPaginaAntigaPreservaALotacao(): void
    {
        $maceio = $this->lotacao('MACEIÓ/AL');
        $feriado = $this->feriado('Emancipação', '2026-09-16', $maceio, true);
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $tela = $this->client->request('GET', '/tenant/' . $this->escritorio->getId() . '/feriados/' . $feriado->getId() . '/editar');
        $this->client->submit($tela->filter('form[name="feriado"]')->form(['feriado[nome]' => 'Emancipação de Alagoas']));

        self::assertResponseRedirects('/tenant/' . $this->escritorio->getId() . '/feriados');
        $salvo = $this->emSemFiltro()->getRepository(Feriado::class)->find($feriado->getId());
        self::assertSame('Emancipação de Alagoas', $salvo?->getNome());
        self::assertSame($maceio->getId(), $salvo?->getLotacao()?->getId());
    }

    // ------------------------------------------------------- 403 e 404

    #[TestDox('403 nas rotas de gravação para quem não tem a permissão da seção no escritório da sessão, e sem o token da página')]
    public function testSemPermissaoDaSecao(): void
    {
        $sede = $this->sede('Maceió/AL', '-9.6', '-35.7', 150);
        $feriado = $this->feriado('Padroeira', '2026-08-27', null, true);
        $soSedes = $this->pessoa('sedes');
        $this->vincular($soSedes, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::SEDES], false);
        $soFeriados = $this->pessoa('feriados');
        $this->vincular($soFeriados, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $gestorDeLa = $this->pessoa('gestor-de-la');
        $this->vincularColaborador($gestorDeLa, $this->escritorio);
        $this->vincularPrimario($gestorDeLa, $this->outro);

        $rotasDeSede = ['/ponto/configuracoes/sedes', '/ponto/configuracoes/sedes/' . $sede->getId(), '/ponto/configuracoes/sedes/' . $sede->getId() . '/remover'];
        $rotasDeFeriado = ['/ponto/configuracoes/feriados', '/ponto/configuracoes/feriados/' . $feriado->getId() . '/remover'];
        $casos = [
            [$soFeriados, $rotasDeSede],
            [$soSedes, $rotasDeFeriado],
            [$colaborador, [...$rotasDeSede, ...$rotasDeFeriado]],
            [$gestorDeLa, [...$rotasDeSede, ...$rotasDeFeriado]],   // primário em outro escritório, colaborador neste
        ];
        foreach ($casos as [$quem, $rotas]) {
            foreach ($rotas as $rota) {
                $this->postar($quem, $rota, ['nome' => 'Tomada', 'latitude' => '1', 'longitude' => '1', 'data' => '2026-01-02']);
                self::assertResponseStatusCodeSame(403, $rota);
            }
        }

        $this->postar($this->primario, '/ponto/configuracoes/sedes/' . $sede->getId() . '/remover', [], null);
        self::assertResponseStatusCodeSame(403, 'sem o token da página');

        $em = $this->emSemFiltro();
        self::assertSame('Maceió/AL', $em->getRepository(Sede::class)->find($sede->getId())?->getNome());
        self::assertNotNull($em->getRepository(Feriado::class)->find($feriado->getId()));
        self::assertSame([], $em->getRepository(Feriado::class)->findBy(['nome' => 'Tomada']));
        self::assertCount(1, $em->getRepository(Sede::class)->findBy(['tenant' => $this->escritorio->getId()]));
    }

    #[TestDox('404 para a sede e o feriado de outro escritório: nada muda lá')]
    public function testOutroEscritorio(): void
    {
        $sedeDeLa = $this->sede('Sede de lá', '-8.05', '-34.9', 200, $this->outro);
        $feriadoDeLa = $this->feriado('Feriado de lá', '2026-10-01', null, true, $this->outro);

        $this->postar($this->primario, '/ponto/configuracoes/sedes/' . $sedeDeLa->getId(), ['nome' => 'Tomada', 'latitude' => '1', 'longitude' => '1', 'raio' => '100']);
        self::assertResponseStatusCodeSame(404);
        $this->postar($this->primario, '/ponto/configuracoes/sedes/' . $sedeDeLa->getId() . '/remover', []);
        self::assertResponseStatusCodeSame(404);
        $this->postar($this->primario, '/ponto/configuracoes/feriados/' . $feriadoDeLa->getId() . '/remover', []);
        self::assertResponseStatusCodeSame(404);

        $em = $this->emSemFiltro();
        self::assertSame('Sede de lá', $em->getRepository(Sede::class)->find($sedeDeLa->getId())?->getNome());
        self::assertSame(200, $em->getRepository(Sede::class)->find($sedeDeLa->getId())?->getRaioPermitido());
        self::assertNotNull($em->getRepository(Feriado::class)->find($feriadoDeLa->getId()));
    }

    // ----------------------------------------------------------------- apoio

    private function painel(User $quem): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler();
    }

    /**
     * @param array<string, string> $corpo
     *
     * @return array<string, mixed>
     */
    private function postar(User $quem, string $rota, array $corpo, ?string $token = 'TOKEN_ajax'): array
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->em()->clear();
        $cabecalhos = ['HTTP_ACCEPT' => 'application/json'] + ($token !== null ? ['HTTP_X_CSRF_TOKEN' => $token] : []);
        $this->client->request('POST', $rota, $corpo, [], $cabecalhos);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function sede(string $nome, string $latitude, string $longitude, int $raio, ?Tenant $escritorio = null): Sede
    {
        $sede = (new Sede())->setNome($nome)->setLatitude($latitude)->setLongitude($longitude)->setRaioPermitido($raio)->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($sede);
        $this->em()->flush();

        return $sede;
    }

    private function lotacao(string $nome, ?Tenant $escritorio = null): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    private function feriado(string $nome, string $dia, ?Lotacao $lotacao, bool $recorrente, ?Tenant $escritorio = null): Feriado
    {
        $feriado = (new Feriado())->setNome($nome)->setData(new \DateTime($dia))->setRecorrente($recorrente)->setTenant($escritorio ?? $this->escritorio)->setLotacao($lotacao);
        $this->em()->persist($feriado);
        $this->em()->flush();

        return $feriado;
    }

    /** @return array<string, string> valor => texto */
    private function opcoes(Crawler $opcoes): array
    {
        $lista = [];
        foreach ($opcoes as $opcao) {
            $lista[(string) $opcao->attributes?->getNamedItem('value')?->nodeValue] = trim((string) $opcao->textContent);
        }

        return $lista;
    }
}
