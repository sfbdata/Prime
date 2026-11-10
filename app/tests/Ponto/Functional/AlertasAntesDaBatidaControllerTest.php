<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AlertasAntesDaBatida;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\InicioContagemResolver;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Twig\Environment;

/**
 * E11 do desenho do Ponto (spec docs/specs/ponto-desenho-0811.md, P21; risco ALTO): o intervalo curto, a interjornada
 * e o 7º dia seguido avisam antes de gravar, com os mínimos da Jornada do escritório (aqui 30 minutos de intervalo e
 * 8 horas de interjornada). A primeira tentativa devolve o alerta sem gravar; "Registrar assim mesmo" manda de novo com
 * a confirmação e o mesmo `idEnvio`, a batida grava e a folha marca o não conforme. A chave `ponto-alertas` desligada
 * volta à recusa de antes. A batida da fila e a saída de esquecimento ficam em BatidaSemInternet*Test e
 * InterjornadaDeEsquecimentoTest.
 *
 * A pessoa tem home office liberado todo dia (a batida passa sem GPS).
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(AlertasAntesDaBatida::class)]
final class AlertasAntesDaBatidaControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const ID_DO_ENVIO = '7c1e9a40-5b2d-4f63-8e17-2a9c0d4b6e11';
    private const ALERTA_DO_INTERVALO = 'Faltam 18 minutos para completar o intervalo mínimo de 30min. Se registrar agora, a folha marcará o intervalo intrajornada como não conforme.';
    private const SETIMO_DIA = 'Este será o 7º dia seguido com registro, sem o descanso semanal.';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $pessoa;
    private JornadaTenant $jornada;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->escritorio = $this->escritorio('Escritório alertas');
        $this->pessoa = $this->pessoa('alertas');
        $this->vincular($this->pessoa, $this->escritorio);
        $this->jornada = $this->jornadaPadrao($this->escritorio, [[[1, 2, 3, 4, 5, 6, 7], '08:00', '12:00', '13:00', '17:00']]);
        $this->jornada->setMinimoMinutosRepouso(30);
        $this->jornada->setMinimoMinutosInterjornada(480);
        $homeOffice = new HomeOfficeConfig();
        $homeOffice->setUser($this->pessoa);
        $homeOffice->setTenant($this->escritorio);
        $homeOffice->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $homeOffice->setAtivo(true);
        $this->em()->persist($homeOffice);
        $this->em()->flush();
    }

    #[TestDox('Retorno 12 minutos depois do repouso: o alerta volta sem gravar; com a confirmação e o mesmo idEnvio, grava, e a folha marca o intrajornada não conforme')]
    public function testRetornoComRepousoCurto(): void
    {
        $this->pularPertoDaMeiaNoite();
        $this->jornadaPessoal($this->pessoa, $this->escritorio, [[[1, 2, 3, 4, 5, 6, 7], '08:00', '12:00', '13:00', '17:00']]);
        $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', new \DateTimeImmutable('-40 minutes'));
        $this->registrarBatida($this->pessoa, $this->escritorio, 'repouso', new \DateTimeImmutable('-12 minutes'));

        $alerta = $this->bater('retorno');

        self::assertResponseIsSuccessful();
        self::assertFalse($alerta['success']);
        self::assertSame(self::ALERTA_DO_INTERVALO, $alerta['alerta']);
        self::assertSame(0, $this->batidas('retorno'), 'o alerta não grava');

        $gravou = $this->bater('retorno', true);

        self::assertTrue($gravou['success'], (string) ($gravou['message'] ?? ''));
        self::assertSame(1, $this->batidas('retorno'));
        self::assertSame(self::ID_DO_ENVIO, $this->em()->getConnection()->fetchOne("SELECT id_envio FROM registro_ponto WHERE user_id = ? AND tipo = 'retorno'", [$this->pessoa->getId()]));
        // A folha só mede o intervalo do dia com horas apuradas, e o dia de hoje só apura com a saída.
        $this->registrarBatida(
            $this->em()->getReference(User::class, (int) $this->pessoa->getId()),
            $this->em()->getReference(Tenant::class, (int) $this->escritorio->getId()),
            'saida',
            new \DateTimeImmutable('+1 second'),
        );   // as requisições limparam o EntityManager
        $folha = $this->dadosDaFolha(new \DateTimeImmutable());
        self::assertFalse($folha['intrajornadaConforme'], 'a folha reflete o horário real');
        self::assertStringContainsString('Intervalo Intrajornada (m&#237;n. ', $this->pdf($folha));
    }

    #[TestDox('Entrada antes das 8 horas de interjornada do escritório: o alerta volta sem gravar; com a confirmação, grava, e a folha marca a interjornada não conforme')]
    public function testEntradaAntesDaInterjornada(): void
    {
        // 40 horas de interjornada contadas da saída de ontem às 08:00: falta sempre algo, a qualquer hora de hoje.
        $this->jornada->setMinimoMinutosInterjornada(2400);
        $this->em()->flush();
        $this->jornadaPessoal($this->pessoa, $this->escritorio, [[[1, 2, 3, 4, 5, 6, 7], '08:00', '12:00', '13:00', '17:00']]);
        $ontem = new \DateTimeImmutable('yesterday');
        $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', $ontem->setTime(7, 0));
        $this->registrarBatida($this->pessoa, $this->escritorio, 'saida', $ontem->setTime(8, 0));

        $alerta = $this->bater('entrada');

        self::assertResponseIsSuccessful();
        self::assertFalse($alerta['success']);
        self::assertMatchesRegularExpression(
            '/^Faltam (\d+h\d{2}|\d+ minutos?) para completar 40h de descanso desde a saída de ontem\. Se registrar agora, a folha marcará a interjornada como não conforme\.$/u',
            (string) $alerta['alerta'],
        );
        self::assertSame(1, $this->batidas('entrada'), 'só a de ontem');

        self::assertTrue($this->bater('entrada', true)['success']);
        self::assertSame(2, $this->batidas('entrada'));
        if ((int) $ontem->format('n') === (int) date('n')) {   // a folha do mês não vê a saída do mês anterior
            self::assertFalse($this->dadosDaFolha(new \DateTimeImmutable())['interjornadaConforme']);
        }
    }

    #[TestDox('7º dia seguido com registro (seis dias antes de hoje): o alerta volta sem gravar; com a confirmação, grava')]
    public function testSetimoDiaAvisa(): void
    {
        $this->diasComRegistro(6);

        $alerta = $this->bater('entrada');

        self::assertFalse($alerta['success']);
        self::assertSame(self::SETIMO_DIA, $alerta['alerta']);
        self::assertSame(6, $this->batidas('entrada'));

        self::assertTrue($this->bater('entrada', true)['success']);
        self::assertSame(7, $this->batidas('entrada'));
    }

    #[TestDox('6º dia seguido (cinco dias antes de hoje): nada avisa, a entrada grava')]
    public function testSextoDiaNaoAvisa(): void
    {
        $this->diasComRegistro(5);

        $resposta = $this->bater('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertArrayNotHasKey('alerta', $resposta);
        self::assertSame(6, $this->batidas('entrada'));
    }

    #[TestDox('Validação do intervalo desligada na Jornada do escritório: o retorno 12 minutos depois do repouso grava sem alerta')]
    public function testValidacaoDoIntervaloDesligadaNaoAvisa(): void
    {
        $this->pularPertoDaMeiaNoite();
        $this->jornada->setValidacaoRepousoHabilitada(false);
        $this->em()->flush();
        $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', new \DateTimeImmutable('-40 minutes'));
        $this->registrarBatida($this->pessoa, $this->escritorio, 'repouso', new \DateTimeImmutable('-12 minutes'));

        $resposta = $this->bater('retorno');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertArrayNotHasKey('alerta', $resposta);
    }

    #[TestDox('Validação da interjornada desligada na Jornada do escritório: a entrada no 7º dia seguido e antes da interjornada grava sem alerta')]
    public function testValidacaoDaInterjornadaDesligadaNaoAvisa(): void
    {
        $this->jornada->setMinimoMinutosInterjornada(2400);   // a saída de ontem às 09:00 ainda não cumpriu as 40 horas
        $this->jornada->setValidacaoInterjornadaHabilitada(false);
        $this->em()->flush();
        $this->diasComRegistro(6);

        $resposta = $this->bater('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertArrayNotHasKey('alerta', $resposta);
    }

    #[TestDox('Chave ponto-alertas desligada no escritório: o retorno curto é recusado como antes da E11 (422), o 7º dia não avisa e o cartão perde o aviso do intervalo')]
    public function testChaveDesligadaRecusaComoAntes(): void
    {
        $this->pularPertoDaMeiaNoite();
        self::assertSame('30', $this->minimoDoAvisoNoCartao(), 'com a chave ligada o cartão avisa com o mínimo do escritório');
        $configuracao = new ConfiguracaoDoPonto($this->escritorio);
        $configuracao->definir(FuncoesDoPonto::ALERTAS, false, $this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($configuracao);
        $this->em()->flush();
        $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', new \DateTimeImmutable('-40 minutes'));
        $this->registrarBatida($this->pessoa, $this->escritorio, 'repouso', new \DateTimeImmutable('-12 minutes'));

        $resposta = $this->bater('retorno', true);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Intervalo mínimo de repouso é de 30 minuto(s). Aguarde mais 18 minuto(s).', $resposta['message']);
        self::assertArrayNotHasKey('alerta', $resposta);
        self::assertSame(0, $this->batidas('retorno'), 'a confirmação não fura a recusa');
        self::assertSame('0', $this->minimoDoAvisoNoCartao());
    }

    #[TestDox('Chave ponto-alertas desligada: o 7º dia seguido não é olhado, a entrada grava')]
    public function testChaveDesligadaNaoOlhaOSetimoDia(): void
    {
        $configuracao = new ConfiguracaoDoPonto($this->escritorio);
        $configuracao->definir(FuncoesDoPonto::ALERTAS, false, $this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($configuracao);
        $this->em()->flush();
        $this->diasComRegistro(6);

        self::assertTrue($this->bater('entrada')['success']);
    }

    #[TestDox('A janela "Antes de registrar" na moldura do desenho: topo com ícone, título e "x"; o alerta e a nota; a linha de erro; Aguardar e Registrar assim mesmo, sem Bootstrap')]
    public function testJanelaNaTela(): void
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        $janela = $tela->filter('.pe-page > div.pe-janela-fundo#ponto-janela-alerta[hidden] > div.pe-janela[role="dialog"][aria-modal="true"][aria-label="Antes de registrar"][aria-describedby="ponto-alerta-texto"]');
        self::assertCount(1, $janela);
        self::assertCount(1, $janela->filter('div.pe-janela > div.pe-janela-topo:first-child + div.pe-janela-alerta + div.pe-janela-erro[data-janela-erro][hidden] + div.pe-janela-rodape:last-child'));
        self::assertCount(1, $janela->filter('div.pe-janela-topo > i.bi.bi-exclamation-triangle[aria-hidden="true"]:first-child + b.pe-janela-titulo + button.pe-janela-fechar[type="button"][aria-label="Fechar"][data-janela-fechar]:last-child'));
        self::assertSame('Antes de registrar', trim($janela->filter('b.pe-janela-titulo')->text()));
        self::assertCount(1, $janela->filter('div.pe-janela-alerta > div#ponto-alerta-texto[data-alerta-texto]:first-child + div.pe-janela-alerta-nota:last-child'));
        self::assertSame('', trim($janela->filter('#ponto-alerta-texto')->text()), 'o alerta entra pelo script (textContent)');
        self::assertSame(AlertasAntesDaBatida::NOTA, trim($janela->filter('.pe-janela-alerta-nota')->text()));
        $rodape = $janela->filter('div.pe-janela-rodape');
        self::assertCount(1, $rodape->filter('div > button.pe-janela-cancelar[type="button"][data-janela-fechar]:first-child + button.pe-janela-acao[type="button"][data-alerta-registrar]:last-child'));
        self::assertSame('Aguardar', trim($rodape->filter('.pe-janela-cancelar')->text()));
        self::assertSame('Registrar assim mesmo', trim($rodape->filter('.pe-janela-acao')->text()));
        self::assertCount(0, $janela->filter('.modal, .modal-dialog, .btn, .alert, [data-bs-dismiss], [data-bs-toggle]'), 'componentes do desenho, nada do Bootstrap');
    }

    // ----------------------------------------------------------------- apoio

    /** As batidas de minutos atrás cairiam em ontem perto da meia-noite. */
    private function pularPertoDaMeiaNoite(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 100) {
            self::markTestSkipped('Antes da 01:00 as batidas de minutos atrás cairiam em ontem.');
        }
    }

    /** Uma entrada e uma saída, das 08:00 às 09:00, em cada um dos `$dias` dias antes de hoje. */
    private function diasComRegistro(int $dias): void
    {
        for ($d = 1; $d <= $dias; ++$d) {
            $dia = new \DateTimeImmutable(sprintf('-%d days', $d));
            $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', $dia->setTime(8, 0));
            $this->registrarBatida($this->pessoa, $this->escritorio, 'saida', $dia->setTime(9, 0));
        }
    }

    /** @return array<string, mixed> */
    private function bater(string $tipo, bool $confirmar = false, string $idEnvio = self::ID_DO_ENVIO): array
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $corpo = ['tipo' => $tipo, 'idEnvio' => $idEnvio];
        if ($confirmar) {
            $corpo[AlertasAntesDaBatida::CAMPO_DA_CONFIRMACAO] = true;
        }

        return $this->chamarJson($this->client, 'POST', '/ponto/batida', $corpo);
    }

    private function batidas(string $tipo): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ? AND tipo = ?',
            [$this->pessoa->getId(), $this->escritorio->getId(), $tipo],
        );
    }

    private function minimoDoAvisoNoCartao(): string
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return (string) $tela->filter('#ponto-aviso-dia')->attr('data-intervalo-minimo-minutos');
    }

    /**
     * Os dados que o PDF da folha recebe, com os passos de `PontoController::exportarFolhaPdf` (o Dompdf comprime o
     * texto; o que dá para ler é o que ele recebe, como no FolhaExportacaoMesmaEscolhaTest).
     *
     * @return array<string, mixed>
     */
    private function dadosDaFolha(\DateTimeImmutable $dia): array
    {
        $container = static::getContainer();
        $this->em()->clear();
        $pessoa = $this->em()->find(User::class, $this->pessoa->getId());
        $escritorio = $this->em()->find(Tenant::class, $this->escritorio->getId());
        self::assertNotNull($pessoa);
        self::assertNotNull($escritorio);
        $ano = (int) $dia->format('Y');
        $mes = (int) $dia->format('n');
        $jornada = $this->em()->getRepository(JornadaColaborador::class)->findOneBy(['user' => $pessoa, 'tenant' => $escritorio]);
        $builder = $container->get(FolhaPontoBuilder::class);
        $feriados = $container->get(FeriadoRepository::class)->findByTenant($escritorio);
        $jornadaTenant = $escritorio->getJornadaTenant();
        $inicioContagem = $container->get(InicioContagemResolver::class)->resolver($pessoa, $escritorio);
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $rows = $builder->buildRows(
            $inicio,
            $inicio->modify('last day of this month')->setTime(23, 59, 59),
            $container->get(RegistroPontoRepository::class)->findByUserAndCompetencia($pessoa, $escritorio, $ano, $mes),
            true,
            false,
            $jornada,
            $feriados,
            $container->get(JustificativaPontoRepository::class)->findByUserAndCompetenciaIndexed($pessoa, $escritorio, $ano, $mes),
            $jornadaTenant,
            $inicioContagem,
        );
        $controller = $container->get(PontoController::class);

        return (new \ReflectionMethod($controller, 'montarDadosFolha'))
            ->invoke($controller, $pessoa, $escritorio, $ano, $mes, $rows, $feriados, $jornada, $jornadaTenant, $builder, $inicioContagem);
    }

    /** @param array<string, mixed> $dados */
    private function pdf(array $dados): string
    {
        $html = static::getContainer()->get(Environment::class)->render('ponto/folha_pdf.html.twig', $dados);
        self::assertStringContainsString('<span class="nao-conforme">&#10007; N&#227;o Conforme</span>', $html);

        return $html;
    }
}
