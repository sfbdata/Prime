<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\EquipeDoPontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\JustificativasAAnalisarDaEquipe;
use App\Ponto\UseCase\ConsultarEquipeDoPontoUseCase;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O painel da aba "Equipe" da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5, P54 a P57; desenho 16 L210 a L219):
 * o arranjo do desenho por filho direto, os textos literais, quem entra na equipe e na lista, e o isolamento entre
 * escritórios. O relógio fica em hoje às 15:00, para a situação de hoje não depender da hora em que a suíte roda.
 */
#[CoversClass(EquipeDoPontoController::class)]
#[CoversClass(ConsultarEquipeDoPontoUseCase::class)]
#[CoversClass(JustificativasAAnalisarDaEquipe::class)]
#[Group('ponto')]
final class EquipeDoPontoTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;
    use ClockSensitiveTrait;
    use LiberaSessaoDoRelogioFixoTrait;

    private const PAINEL = 'div.pe-equipe';
    private const LINHAS = self::PAINEL . ' > div.pe-eq-rolagem > table.pe-tab.pe-eq-tab > tbody > tr.pe-eq-lin';
    private const FORM = self::PAINEL . ' > form.pe-eq-abonar[method="post"][action="/ponto/equipe/abonar"][data-equipe-abonar]';

    /** 40h: segunda a sexta 8h. */
    private const QUARENTA = [[[1, 2, 3, 4, 5], '08:00', '12:00', '13:00', '17:00']];
    /** 44h: segunda a quinta 9h, sexta 8h (nenhuma escala do desenho). */
    private const QUARENTA_E_QUATRO = [[[1, 2, 3, 4], '08:00', '12:00', '13:00', '18:00'], [[5], '08:00', '12:00', '13:00', '17:00']];
    /** 30h corridas: segunda a sexta 6h, em outro horário que o do modelo. */
    private const TRINTA = [[[1, 2, 3, 4, 5], '07:00', null, null, '13:00']];

    private KernelBrowser $client;
    private \DateTimeImmutable $hoje;
    private Tenant $escritorio;
    private Tenant $escritorioDoGil;
    private User $gestora;
    private User $ana;
    private User $bruno;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->hoje = new \DateTimeImmutable('today');
        self::mockTime($this->hoje->setTime(15, 0));
        $this->liberarSessaoDoRelogioFixo();
    }

    #[TestDox('Arranjo e textos do desenho: indicadores, tabela da equipe, barra e lista das justificativas')]
    public function testArranjoETextos(): void
    {
        $justificativas = $this->montarEquipe();

        $tela = $this->painel($this->gestora);

        self::assertCount(1, $tela->filter(self::PAINEL . ' > div.pe-eq-erro[role="alert"][data-equipe-erro][hidden]:empty'));
        self::assertCount(1, $tela->filter(self::PAINEL . ' > div.pe-eq-voz[aria-live="polite"][data-equipe-aviso]:empty'));
        self::assertStringNotContainsString('Dados de exemplo', $tela->text(), 'a nota do desenho não entra (D16)');

        // Indicadores (L212 e L213): 5 quadros, rótulo e valor.
        $quadros = $tela->filter(self::PAINEL . ' > div.pe-eq-kpis > div.pe-eq-kpi');
        self::assertCount(5, $quadros);
        self::assertSame(['Em jornada', 'Em intervalo', 'Sem registro', 'Pendências', 'A analisar'], $quadros->filter('div.pe-eq-kpi > div.pe-eq-kpi-rotulo:first-child')->each(static fn (Crawler $c): string => $c->text()));
        $valores = $quadros->filter('div.pe-eq-kpi > div.pe-eq-kpi-rotulo + b.pe-eq-kpi-valor:last-child');
        self::assertSame(['jornada', 'intervalo', 'sem', 'pendencias', 'analisar'], $valores->each(static fn (Crawler $c): string => substr((string) $c->attr('class'), strlen('pe-eq-kpi-valor pe-eq-kpi-valor--'))));

        // Tabela (L215).
        self::assertSame(
            ['Colaborador', 'Situação', 'Entrada', 'Último registro', 'Banco', 'Pendências', 'Escala'],
            $tela->filter(self::PAINEL . ' > div.pe-eq-rolagem > table.pe-tab.pe-eq-tab > thead > tr > th')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertCount(0, $tela->filter('table.pe-eq-tab.pe-resp'), 'no celular a tabela rola para o lado');
        $linhas = $tela->filter(self::LINHAS);
        self::assertSame(
            [
                ['Gestora', 'Você', 'Sem registro', 'sem', 'Sem registro', 'Sem registro'],
                ['Ana Lima', 'Advogada', 'Em jornada', 'jornada', '09:00', '09:00 · Entrada'],
                ['Bruno Reis', '', 'Em intervalo', 'intervalo', '08:00', '12:00 · Repouso'],
                ['Carla Dias', '', 'Sem registro', 'sem', 'Sem registro', 'Sem registro'],
                ['Davi Melo', '', 'Encerrada', 'encerrada', '08:00', '14:30 · Saída'],
            ],
            $linhas->each(static fn (Crawler $l): array => [
                $l->filter('tr > td:nth-child(1) > b')->text(),
                $l->filter('tr > td:nth-child(1) > b + div.pe-eq-sub')->count() > 0 ? $l->filter('tr > td:nth-child(1) > div.pe-eq-sub')->text() : '',
                $l->filter('tr > td:nth-child(2) > span.pe-eq-selo')->text(),
                substr((string) $l->filter('tr > td:nth-child(2) > span.pe-eq-selo')->attr('class'), strlen('pe-eq-selo pe-eq-selo--')),
                $l->filter('tr > td:nth-child(3)')->text(),
                $l->filter('tr > td:nth-child(4)')->text(),
            ]),
            'quem vê vem primeiro ("Você"); os outros por nome, com o cargo; fora quem não registra ponto, quem saiu e outro escritório',
        );
        self::assertCount(5, $tela->filter(self::LINHAS . ' > td.pe-eq-banco:nth-child(5)'));
        self::assertCount(35, $tela->filter(self::LINHAS . ' > td'), '7 colunas por linha');
        self::assertCount(5, $tela->filter(self::LINHAS . ' > td:last-child > select.pe-eq-escala'));

        // Os números dos indicadores saem da tabela e da lista.
        $pendencias = array_sum($linhas->each(static fn (Crawler $l): int => (int) $l->filter('tr > td:nth-child(6)')->text()));
        self::assertSame(['1', '1', '2', (string) $pendencias, '3'], $valores->each(static fn (Crawler $c): string => $c->text()));

        // Barra e lista (L216 a L218).
        $form = $tela->filter(self::FORM);
        self::assertCount(1, $form);
        self::assertCount(1, $form->filter('form > input[type="hidden"][name="_token"]:first-child'));
        self::assertSame('Justificativas a analisar (3)', $form->filter('form > div.pe-eq-barra > b.pe-eq-barra-titulo:first-child')->text());
        $botao = $form->filter('form > div.pe-eq-barra > b.pe-eq-barra-titulo + button.pe-eq-btn-abonar[type="submit"][data-equipe-abonar-botao][disabled]:last-child');
        self::assertSame('Abonar selecionadas', $botao->text());

        [$daGestora, $daAna, $doBruno] = $justificativas;
        $itens = $form->filter('form > div.pe-eq-barra ~ label.pe-eq-just');
        self::assertSame(
            [
                [$daGestora->getId(), 'Gestora', $this->dia(-5), 'Atestado Médico', ''],
                [$doBruno->getId(), 'Bruno Reis', $this->dia(-1), 'Atestado Médico', ''],
                [$daAna->getId(), 'Ana Lima', $this->dia(-2), 'Esquecimento de Registro', 'Entrada às 09:00'],
            ],
            $itens->each(static function (Crawler $item): array {
                $id = (int) $item->filter('label > input.pe-eq-just-marca[type="checkbox"][name="ids[]"]:first-child')->attr('value');
                self::assertSame('pe-eq-just-' . $id, $item->attr('for'));
                self::assertCount(1, $item->filter('label > input#pe-eq-just-' . $id));

                return [
                    $id,
                    $item->filter('label > input + b')->text(),
                    $item->filter('label > b + span.pe-eq-just-data')->text(),
                    $item->filter('label > span.pe-eq-just-data + span')->text(),
                    $item->filter('label > span + span.pe-eq-just-sub:last-child')->text(),
                ];
            }),
            'a da gestora primeiro (primária, P43 a); depois a mais nova; fora a analisada, a de quem saiu e a de outro escritório',
        );
        self::assertCount(0, $form->filter('div.pe-eq-just-vazia'));
    }

    #[TestDox('Escala: a do catálogo com os mesmos minutos por dia vem marcada; nenhuma igual, a opção que descreve a atual')]
    public function testEscala(): void
    {
        $this->montarEquipe();

        $linhas = $this->painel($this->gestora)->filter(self::LINHAS);
        $escalas = [];
        foreach ($linhas as $no) {
            $linha = new Crawler($no);
            $select = $linha->filter('select.pe-eq-escala');
            $nome = $linha->filter('td:first-child > b')->text();
            self::assertSame('Escala de ' . $nome, $select->attr('aria-label'));
            self::assertSame('/ponto/equipe/' . $linha->attr('data-pessoa') . '/escala', $select->attr('data-url'));
            $escalas[$nome] = $select->filter('select > option')->each(static fn (Crawler $o): string => sprintf(
                '%s|%s%s%s',
                $o->attr('value'),
                $o->text(),
                $o->attr('selected') !== null ? '|marcada' : '',
                $o->attr('disabled') !== null ? '|desligada' : '',
            ));
        }

        $catalogo = ['44-6|44h · seg a sáb (7h20)', '44-5|44h · seg a sex (8h48)', '40-5|40h · seg a sex (8h)', '30-5|30h · seg a sex (6h)'];
        self::assertSame(['|Personalizada (44h)|marcada|desligada', ...$catalogo], $escalas['Ana Lima'], 'jornada pessoal fora do catálogo');
        self::assertSame(['44-6|44h · seg a sáb (7h20)', '44-5|44h · seg a sex (8h48)', '40-5|40h · seg a sex (8h)|marcada', '30-5|30h · seg a sex (6h)'], $escalas['Bruno Reis'], 'sem pessoal: a do escritório (40h)');
        self::assertSame(['44-6|44h · seg a sáb (7h20)', '44-5|44h · seg a sex (8h48)', '40-5|40h · seg a sex (8h)', '30-5|30h · seg a sex (6h)|marcada'], $escalas['Carla Dias'], 'mesmos minutos, outro horário');
    }

    #[TestDox('Escritório com jornada fora do catálogo: a opção "Jornada do escritório (Xh)"')]
    public function testEscalaDoEscritorioForaDoCatalogo(): void
    {
        $escritorio = $this->escritorio('Escritório 42h30');
        $this->jornadaPadrao($escritorio, [[[1, 2, 3, 4, 5], '08:00', '12:00', '13:00', '17:30']])->setCargaHorariaSemanal(2550);
        $this->em()->flush();
        $gestora = $this->pessoa('Gestora');
        $this->vincular($gestora, $escritorio, [], true);

        $this->logarComTenant($this->client, $gestora, $escritorio);
        $tela = $this->client->request('GET', '/ponto/equipe');

        self::assertResponseIsSuccessful();
        $primeira = $tela->filter(self::LINHAS . ' select.pe-eq-escala > option')->first();
        self::assertSame(['', 'Jornada do escritório (42h30)'], [$primeira->attr('value'), $primeira->text()]);
        self::assertNotNull($primeira->attr('selected'));
    }

    #[TestDox('Banco e Pendências: os mesmos números da /ponto de cada pessoa')]
    public function testBancoEPendenciasComoNaPontoDaPessoa(): void
    {
        $this->montarEquipe();
        // Dias de antes: Ana fez 10 horas num dia da semana passada; Bruno não bateu nada.
        $diaUtil = $this->hoje->modify('monday last week');
        $this->registrarBatida($this->ana, $this->escritorio, 'entrada', $diaUtil->setTime(8, 0));
        $this->registrarBatida($this->ana, $this->escritorio, 'saida', $diaUtil->setTime(18, 0));

        $linhas = $this->painel($this->gestora)->filter(self::LINHAS);
        $naEquipe = [];
        foreach ($linhas as $no) {
            $linha = new Crawler($no);
            $naEquipe[$linha->filter('td:first-child > b')->text()] = [$linha->filter('td.pe-eq-banco')->text(), $linha->filter('td:nth-child(6)')->text()];
        }

        foreach (['Ana Lima' => $this->ana, 'Bruno Reis' => $this->bruno] as $nome => $pessoa) {
            $this->logarComTenant($this->client, $pessoa, $this->escritorio);
            $ponto = $this->client->request('GET', '/ponto/');
            self::assertResponseIsSuccessful();
            $numeroDaAba = $ponto->filter('button#ponto-aba-pendencias > span.pe-aba-num');
            self::assertSame(
                [$ponto->filter('b.pe-banco-valor')->text(), $numeroDaAba->count() > 0 ? $numeroDaAba->text() : '0'],
                $naEquipe[$nome],
                $nome,
            );
        }
    }

    #[TestDox('Gestor pelo perfil vê a equipe; a própria justificativa fica fora da lista e do número (P43 a); a da primária entra pela data')]
    public function testGestorPeloPerfil(): void
    {
        $this->montarEquipe();
        $gestor = $this->pessoa('Gestor do Perfil');
        $this->vincular($gestor, $this->escritorio, ['modules.ponto.view', 'admin.users.manage']);
        $this->justificativa($gestor, $this->escritorio, -3, 'pendente');

        $tela = $this->painel($gestor);

        self::assertSame('Gestor do Perfil', $tela->filter(self::LINHAS)->first()->filter('td:first-child > b')->text());
        self::assertSame('Você', $tela->filter(self::LINHAS)->first()->filter('td:first-child > div.pe-eq-sub')->text());
        self::assertSame('Justificativas a analisar (3)', $tela->filter(self::FORM . ' b.pe-eq-barra-titulo')->text());
        self::assertSame(['Bruno Reis', 'Ana Lima', 'Gestora'], $tela->filter(self::FORM . ' > label.pe-eq-just > b')->each(static fn (Crawler $c): string => $c->text()));

        self::assertSame(3, $this->aAnalisar()->contarAAnalisar($gestor, $this->escritorio), 'o número da aba não conta a dele');
        self::assertSame(4, $this->aAnalisar()->contarAAnalisar($this->gestora, $this->escritorio), 'a primária conta a dela e a do gestor');
    }

    #[TestDox('Sem justificativas a analisar: a frase do desenho, o número zero e o botão desligado')]
    public function testListaVazia(): void
    {
        $escritorio = $this->escritorio('Escritório vazio');
        $gestora = $this->pessoa('Gestora');
        $this->vincular($gestora, $escritorio, [], true);
        $this->justificativa($gestora, $escritorio, -1, 'abonado');

        $this->logarComTenant($this->client, $gestora, $escritorio);
        $tela = $this->client->request('GET', '/ponto/equipe');

        self::assertResponseIsSuccessful();
        self::assertSame('Justificativas a analisar (0)', $tela->filter(self::FORM . ' > div.pe-eq-barra > b.pe-eq-barra-titulo')->text());
        self::assertSame('Nenhuma justificativa aguardando análise.', $tela->filter(self::FORM . ' > div.pe-eq-barra + div.pe-eq-just-vazia:last-child')->text());
        self::assertCount(1, $tela->filter(self::FORM . ' button.pe-eq-btn-abonar[disabled]'));
        self::assertSame('0', $tela->filter(self::PAINEL . ' > div.pe-eq-kpis > div.pe-eq-kpi:last-child > b')->text());
        self::assertSame(0, $this->aAnalisar()->contarAAnalisar($gestora, $escritorio));
    }

    #[TestDox('Isolamento: quem vê de outro escritório não enxerga esta equipe nem as justificativas dela')]
    public function testOutroEscritorio(): void
    {
        $this->montarEquipe();
        $outro = $this->escritorioDoGil;
        $gestoraDeLa = $this->pessoa('Gestora de lá');
        $this->vincular($gestoraDeLa, $outro, [], true);

        $tela = $this->painel($gestoraDeLa, $outro);

        self::assertSame(['Gestora de lá', 'Gil Souza'], $tela->filter(self::LINHAS . ' > td:first-child > b')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Gil Souza'], $tela->filter(self::FORM . ' > label.pe-eq-just > b')->each(static fn (Crawler $c): string => $c->text()), 'só a do Gil, que é de lá');
        self::assertSame(1, $this->aAnalisar()->contarAAnalisar($gestoraDeLa, $outro));
    }

    /**
     * A equipe do escritório (jornada de 40h): a gestora primária (sem batida hoje), Ana (pessoal de 44h, cargo, só a
     * entrada), Bruno (no repouso), Carla (pessoal de 30h, sem batida), Davi (já saiu); fora: Ely (não registra ponto),
     * Fred (suspenso) e Gil (de outro escritório, onde o Gil tem uma justificativa).
     *
     * @return array{JustificativaPonto, JustificativaPonto, JustificativaPonto} a da gestora, a da Ana e a do Bruno
     */
    private function montarEquipe(): array
    {
        $this->escritorio = $this->escritorio('Escritório da equipe');
        $this->jornadaPadrao($this->escritorio, self::QUARENTA);
        $this->gestora = $this->pessoa('Gestora');
        $this->vincular($this->gestora, $this->escritorio, [], true);

        $this->ana = $this->pessoa('Ana Lima');
        $cargo = (new Cargo())->setNome('Advogada')->setTenant($this->escritorio);
        $this->em()->persist($cargo);
        $this->vincular($this->ana, $this->escritorio)->setCargo($cargo);
        $this->jornadaPessoal($this->ana, $this->escritorio, self::QUARENTA_E_QUATRO);
        $this->bater($this->ana, ['entrada' => '09:00']);

        $this->bruno = $this->pessoa('Bruno Reis');
        $this->vincular($this->bruno, $this->escritorio);
        $this->bater($this->bruno, ['entrada' => '08:00', 'repouso' => '12:00']);

        $carla = $this->pessoa('Carla Dias');
        $this->vincular($carla, $this->escritorio);
        $this->jornadaPessoal($carla, $this->escritorio, self::TRINTA);

        $davi = $this->pessoa('Davi Melo');
        $this->vincular($davi, $this->escritorio);
        $this->bater($davi, ['entrada' => '08:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '14:30']);

        $ely = $this->pessoa('Ely Nunes');
        $this->vincular($ely, $this->escritorio, registraPonto: false);
        $fred = $this->pessoa('Fred Alves');
        $this->vincular($fred, $this->escritorio)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
        $this->justificativa($fred, $this->escritorio, -1, 'pendente');

        $this->escritorioDoGil = $this->escritorio('Escritório do Gil');
        $gil = $this->pessoa('Gil Souza');
        $this->vincular($gil, $this->escritorioDoGil);
        $this->justificativa($gil, $this->escritorioDoGil, -1, 'pendente');

        $this->justificativa($this->bruno, $this->escritorio, -4, 'abonado');

        return [
            $this->justificativa($this->gestora, $this->escritorio, -5, 'pendente'),
            $this->justificativa($this->ana, $this->escritorio, -2, 'pendente', 'esquecimento_registro', ['entrada', '09:00']),
            $this->justificativa($this->bruno, $this->escritorio, -1, 'pendente'),
        ];
    }

    /** @param array<string, string> $batidas tipo => HH:MM, hoje */
    private function bater(User $pessoa, array $batidas): void
    {
        foreach ($batidas as $tipo => $hora) {
            [$h, $m] = array_map('intval', explode(':', $hora));
            $this->registrarBatida($pessoa, $this->escritorio, $tipo, $this->hoje->setTime($h, $m));
        }
    }

    /** @param array{string, string}|null $esquecida tipo da batida e hora */
    private function justificativa(User $pessoa, Tenant $escritorio, int $dias, string $status, string $tipo = 'atestado_medico', ?array $esquecida = null): JustificativaPonto
    {
        $j = new JustificativaPonto();
        $j->setUser($pessoa);
        $j->setTenant($escritorio);
        $j->setData(new \DateTime($this->hoje->modify($dias . ' days')->format('Y-m-d')));
        $j->setStatus($status);
        $j->setTipo($tipo);
        $j->setTipoRegistroEsquecido($esquecida[0] ?? null);
        $j->setHoraRegistroEsquecido($esquecida !== null ? new \DateTime('1970-01-01 ' . $esquecida[1] . ':00') : null);
        $j->setBatchId(bin2hex(random_bytes(8)));
        $this->em()->persist($j);
        $this->em()->flush();

        return $j;
    }

    private function painel(User $quem, ?Tenant $escritorio = null): Crawler
    {
        $this->logarComTenant($this->client, $quem, $escritorio ?? $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/equipe');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function dia(int $dias): string
    {
        return $this->hoje->modify($dias . ' days')->format('d/m/Y');
    }

    private function aAnalisar(): JustificativasAAnalisarDaEquipe
    {
        $vinculos = static::getContainer()->get(UserTenantRepository::class);

        return new JustificativasAAnalisarDaEquipe(
            static::getContainer()->get(JustificativaPontoRepository::class),
            new AnaliseDaPropriaJustificativa(new RegraDosPrimarios($vinculos)),
        );
    }
}
