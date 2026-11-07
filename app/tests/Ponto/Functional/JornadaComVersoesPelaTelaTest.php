<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A aba "Jornada Personalizada" com versões (spec `docs/specs/ponto-jornada-vigencia.md` §10.2): salvar "a partir de",
 * editar e excluir uma versão do histórico, limpar a escala, e o caso real que motivou a frente.
 */
#[CoversClass(JornadaColaboradorController::class)]
final class JornadaComVersoesPelaTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    /** 44h: segunda a quinta 9h, sexta 8h. */
    private const QUARENTA_E_QUATRO = [
        [[1, 2, 3, 4], '08:00', '12:00', '13:00', '18:00'],
        [[5], '08:00', '12:00', '13:00', '17:00'],
    ];
    /** 40h: segunda a sexta 8h. */
    private const QUARENTA = [[[1, 2, 3, 4, 5], '08:00', '12:00', '13:00', '17:00']];
    private const FUTURO = '2099-03-02';
    private const MANHA = [[[1, 2, 3, 4, 5], '08:00', null, null, '12:00']];
    private const TARDE = [[[1, 2, 3, 4, 5], '13:00', null, null, '17:00']];

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->escritorio = $this->escritorio('Escritório');
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $this->escritorio, [], true);
        $this->logarComTenant($this->client, $admin, $this->escritorio);
        $this->pessoa = $this->pessoa('Samuel');
        $this->vincular($this->pessoa, $this->escritorio);
    }

    #[TestDox('Caso do Samuel como a migração deixa: 44h desde 01/04 (1º registro); 40h até 30/04 e 44h a partir de 01/05')]
    public function testCasoDoSamuel(): void
    {
        // Como a migração deixa em produção: a jornada de 44h datada do 1º registro, cobrindo os dias de antes.
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-01');
        $this->registrarAbrilInteiro();

        // O formulário abre no modo "nova versão": 40h a partir de 01/04 (a mesma data, atualiza) e 44h a partir de 01/05.
        $this->salvar(self::QUARENTA, '2026-04-01');
        self::assertResponseIsSuccessful();
        $this->salvar(self::QUARENTA_E_QUATRO, '2026-05-01');
        self::assertResponseIsSuccessful();

        $historico = $this->historico();
        $versoes = $historico->versoes();
        self::assertCount(2, $versoes);
        self::assertSame($migrada->getId(), $versoes[0]->getId(), 'a de 01/04 foi atualizada, não duplicada');
        self::assertTrue($versoes[0]->cobreDiasAnteriores());
        self::assertFalse($versoes[1]->cobreDiasAnteriores());
        $this->assertAbrilCom40EMaioCom44($historico);
    }

    #[TestDox('Caso do Samuel com a versão nova na frente: 44h desde 22/04; ele cria 40h em 01/04 e leva a de 44h para 01/05')]
    public function testCasoDoSamuelComAVersaoNovaNaFrente(): void
    {
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $this->registrarAbrilInteiro();

        // A de 40h nasce sem a marca e vira a mais antiga; a de 44h (migrada, com a marca) vai para 01/05 pelo "Editar".
        $this->salvar(self::QUARENTA, '2026-04-01');
        self::assertResponseIsSuccessful();
        $this->salvar(self::QUARENTA_E_QUATRO, '2026-05-01', $migrada->getId());
        self::assertResponseIsSuccessful();

        $historico = $this->historico();
        $versoes = $historico->versoes();
        self::assertCount(2, $versoes);
        self::assertFalse($versoes[0]->cobreDiasAnteriores(), 'a mais antiga é a nova, sem a marca');
        self::assertSame($migrada->getId(), $versoes[1]->getId());
        self::assertTrue($versoes[1]->cobreDiasAnteriores());
        $this->assertAbrilCom40EMaioCom44($historico);
    }

    #[TestDox('O GET devolve o histórico: até quando cada versão vale, a de hoje e a agendada')]
    public function testHistoricoNoGet(): void
    {
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $this->salvar(self::QUARENTA, self::FUTURO);

        $resposta = $this->chamarJson($this->client, 'GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSame($migrada->getId(), $resposta['versaoVigenteId']);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $resposta['hoje']);
        self::assertSame(2640, $resposta['cargaHorariaSemanal'], 'o formulário abre com a versão de hoje');
        self::assertCount(2, $resposta['versoes']);
        [$primeira, $agendada] = $resposta['versoes'];
        self::assertSame(['2026-04-22', '2099-03-01', true, true, false, 2640], [
            $primeira['valeAPartirDe'], $primeira['ate'], $primeira['valeParaTras'], $primeira['vigente'], $primeira['agendada'],
            $primeira['cargaHorariaSemanal'],
        ]);
        self::assertSame([self::FUTURO, null, false, false, true, 2400], [
            $agendada['valeAPartirDe'], $agendada['ate'], $agendada['valeParaTras'], $agendada['vigente'], $agendada['agendada'],
            $agendada['cargaHorariaSemanal'],
        ]);
        self::assertCount(1, $agendada['blocos']);
    }

    #[TestDox('A primeira jornada criada pela tela vale só da data dela em diante')]
    public function testPrimeiraJornadaNaoMudaOPassado(): void
    {
        $this->salvar(self::QUARENTA, '2026-04-27');
        self::assertResponseIsSuccessful();

        $historico = $this->historico();
        self::assertFalse($historico->versoes()[0]->cobreDiasAnteriores());
        self::assertNull($historico->vigenteEm(new \DateTimeImmutable('2026-04-24')));
        self::assertSame(2400, $historico->vigenteEm(new \DateTimeImmutable('2026-04-27'))?->getCargaHorariaSemanal());
    }

    #[TestDox('Agendar a primeira jornada não tira o saldo dos dias de antes na tela do ponto do colaborador')]
    public function testPontoDoColaboradorNaoPerdeOSaldo(): void
    {
        $admin = $this->em()->getRepository(User::class)->findOneBy(['fullName' => 'Admin']);
        self::assertInstanceOf(User::class, $admin);
        $this->jornadaPadrao($this->escritorio, self::QUARENTA);
        $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', new \DateTimeImmutable('2026-10-05 08:00:00'));
        $this->registrarBatida($this->pessoa, $this->escritorio, 'saida', new \DateTimeImmutable('2026-10-05 18:00:00'));

        $antes = $this->folhaDeOutubroDoColaborador();
        self::assertStringContainsString('+2h00m', $antes, 'a segunda 05/10 fecha com +2h pela padrão');

        $this->logarComTenant($this->client, $admin, $this->escritorio);
        $this->salvar(self::QUARENTA_E_QUATRO, self::FUTURO);
        self::assertResponseIsSuccessful();

        self::assertSame($antes, $this->folhaDeOutubroDoColaborador());
    }

    #[TestDox('Sem a data, a versão vale a partir de hoje; data inválida é recusada')]
    public function testDataDaVersao(): void
    {
        $this->chamarJson($this->client, 'POST', $this->url(), $this->corpoDaJornada(self::QUARENTA));
        self::assertResponseIsSuccessful();
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $this->historico()->versoes()[0]->getValeAPartirDe()->format('Y-m-d'),
        );

        foreach (['31/04/2026', '2026-02-30', '0000-01-01', '1999-12-31', '2100-01-01'] as $invalida) {
            $resposta = $this->salvar(self::QUARENTA, $invalida);
            self::assertResponseStatusCodeSame(422, $invalida);
            self::assertSame(['erro' => 'Informe a data em que a jornada passa a valer (dd/mm/aaaa).'], $resposta);
        }
    }

    #[TestDox('Editar uma versão sem mandar a data é recusado, para ela não mudar de data sem aviso')]
    public function testEditarSemData(): void
    {
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');

        $resposta = $this->chamarJson($this->client, 'POST', $this->url(), $this->corpoDaJornada(self::QUARENTA) + ['versaoId' => $migrada->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe a data em que a jornada passa a valer (dd/mm/aaaa).'], $resposta);
        self::assertSame('2026-04-22', $this->historico()->versoes()[0]->getValeAPartirDe()->format('Y-m-d'));
    }

    #[TestDox('Excluir versão: quem não administra usuários recebe 403; versão da pessoa em outro escritório, 404')]
    public function testExcluirVersaoGuardas(): void
    {
        $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $outroEscritorio = $this->escritorio('Outro escritório');
        $this->vincular($this->pessoa, $outroEscritorio);
        $deOutroEscritorio = $this->jornadaPessoal($this->pessoa, $outroEscritorio, self::QUARENTA)->setValeAPartirDe(new \DateTimeImmutable('2026-01-05'));
        $this->em()->flush();
        $this->jornadaPessoal($this->pessoa, $outroEscritorio, self::QUARENTA_E_QUATRO);
        $deOutroId = $deOutroEscritorio->getId();
        $colega = $this->pessoa('Colega');
        $this->vincular($colega, $this->escritorio);

        $this->chamarJson($this->client, 'DELETE', $this->url() . '/versao/' . $deOutroId);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $colega, $this->escritorio);
        $this->chamarJson($this->client, 'DELETE', $this->url() . '/versao/' . $deOutroId);
        self::assertResponseStatusCodeSame(403);

        self::assertCount(2, $this->jornadas()->historicoDe($this->pessoa, $outroEscritorio)?->versoes() ?? []);
    }

    #[TestDox('Editar para a data de outra versão: 422 com a mensagem; versão de outra pessoa: 404')]
    public function testEditarRecusado(): void
    {
        $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $outra = $this->pessoa('Outra');
        $this->vincular($outra, $this->escritorio);
        $daOutraId = $this->jornadaAntiga($outra, self::QUARENTA, '2026-01-05')->getId();
        $this->salvar(self::QUARENTA, self::FUTURO);
        $agendada = $this->historico()->versoes()[1];

        $resposta = $this->salvar(self::QUARENTA, '2026-04-22', $agendada->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Já existe uma versão da jornada que começa em 22/04/2026.'], $resposta);

        $this->salvar(self::QUARENTA_E_QUATRO, '2026-06-01', $daOutraId);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(2400, $this->jornadas()->historicoDe($outra, $this->escritorio)?->versoes()[0]->getCargaHorariaSemanal());
        self::assertCount(2, $this->historico()->versoes(), 'nada foi criado para a pessoa da URL');
    }

    #[TestDox('Excluir uma versão: os dias passam para a vizinha; a única não sai; a de outra pessoa não é achada')]
    public function testExcluirVersao(): void
    {
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $migradaId = $migrada->getId();
        $outra = $this->pessoa('Outra');
        $this->vincular($outra, $this->escritorio);
        $daOutra = $this->jornadaAntiga($outra, self::QUARENTA, '2026-01-05');
        $this->jornadaAntiga($outra, self::QUARENTA_E_QUATRO, '2026-05-01');
        $this->salvar(self::QUARENTA, self::FUTURO);
        $agendadaId = $this->historico()->versoes()[1]->getId();

        $this->chamarJson($this->client, 'DELETE', $this->url() . '/versao/' . $migradaId);
        self::assertResponseIsSuccessful();
        $historico = $this->historico();
        self::assertCount(1, $historico->versoes());
        self::assertSame($agendadaId, $historico->versoes()[0]->getId());
        self::assertTrue($historico->versoes()[0]->cobreDiasAnteriores(), 'os dias de antes continuam cobertos');

        $resposta = $this->chamarJson($this->client, 'DELETE', $this->url() . '/versao/' . $agendadaId);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'A jornada tem uma versão só. Para tirar a jornada personalizada, use "Limpar escala".'], $resposta);

        $this->chamarJson($this->client, 'DELETE', $this->url() . '/versao/' . $daOutra->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertCount(2, $this->jornadas()->historicoDe($outra, $this->escritorio)?->versoes() ?? []);
    }

    #[TestDox('Levar a versão de hoje para frente: 422 quando a que passa a valer hoje bate com o outro escritório')]
    public function testChoqueAoLevarAVersaoDeHojeParaFrente(): void
    {
        $this->pessoaTambemNoEscritorioDaTarde();
        $this->jornadaAntiga($this->pessoa, self::TARDE, '2026-01-05');
        $manhaId = $this->jornadaAntiga($this->pessoa, self::MANHA, '2026-10-01')->getId();

        $resposta = $this->salvar(self::MANHA, '2099-01-05', $manhaId);

        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty($resposta['erro'] ?? null);
        self::assertSame('2026-10-01', $this->historico()->versoes()[1]->getValeAPartirDe()->format('Y-m-d'));
    }

    #[TestDox('Levar a versão de hoje para frente sem deixar de ser a que vale hoje não é recusado')]
    public function testLevarParaFrenteContinuandoAValerHoje(): void
    {
        $this->pessoaTambemNoEscritorioDaTarde();
        $manhaId = $this->jornadaAntiga($this->pessoa, self::MANHA, '2026-04-01')->getId();
        $this->jornadaAntiga($this->pessoa, self::TARDE, self::FUTURO);

        // A da manhã continua a mais antiga e cobre os dias de antes: segue valendo hoje, e a da tarde não entra hoje.
        $this->salvar(self::MANHA, '2099-01-05', $manhaId);

        self::assertResponseIsSuccessful();
        self::assertSame($manhaId, $this->historico()->vigenteEm(new \DateTimeImmutable('today'))?->getId());
    }

    #[TestDox('Excluir a versão de hoje: 422 quando a que passa a valer hoje bate com o outro escritório')]
    public function testChoqueAoExcluirAVersaoDeHoje(): void
    {
        $this->pessoaTambemNoEscritorioDaTarde();
        $this->jornadaAntiga($this->pessoa, self::TARDE, '2026-01-05');
        $manhaId = $this->jornadaAntiga($this->pessoa, self::MANHA, '2026-10-01')->getId();

        $resposta = $this->chamarJson($this->client, 'DELETE', $this->url() . '/versao/' . $manhaId);

        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty($resposta['erro'] ?? null);
        self::assertCount(2, $this->historico()->versoes());
    }

    #[TestDox('Excluir uma versão sem o token CSRF: 403')]
    public function testExcluirVersaoSemToken(): void
    {
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $this->salvar(self::QUARENTA, self::FUTURO);

        $this->client->request('DELETE', $this->url() . '/versao/' . $migrada->getId(), [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $this->historico()->versoes());
    }

    #[TestDox('Limpar escala: com jornada de antes das versões, fica uma linha sem blocos; só com versões da tela, nenhuma')]
    public function testLimparEscala(): void
    {
        $migrada = $this->jornadaAntiga($this->pessoa, self::QUARENTA_E_QUATRO, '2026-04-22');
        $outra = $this->pessoa('Outra');
        $this->vincular($outra, $this->escritorio);
        $this->salvar(self::QUARENTA, self::FUTURO);

        $this->chamarJson($this->client, 'DELETE', $this->url());
        self::assertResponseIsSuccessful();
        $versoes = $this->historico()->versoes();
        self::assertCount(1, $versoes);
        self::assertSame($migrada->getId(), $versoes[0]->getId());
        self::assertCount(0, $versoes[0]->getBlocos());
        self::assertNull($versoes[0]->getCargaHorariaSemanal());

        $this->chamarJson($this->client, 'POST', $this->url($outra), $this->corpoDaJornada(self::QUARENTA) + ['valeAPartirDe' => self::FUTURO]);
        self::assertResponseIsSuccessful();
        $this->chamarJson($this->client, 'DELETE', $this->url($outra));
        self::assertResponseIsSuccessful();
        self::assertNull($this->jornadas()->historicoDe($outra, $this->escritorio));
    }

    #[TestDox('A aba da ficha tem a data "Vale a partir de", o histórico e o Limpar escondido para quem não tem jornada')]
    public function testAbaDaFicha(): void
    {
        $crawler = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $this->escritorio->getId(), $this->pessoa->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#tab-escala #escalaFormContainer input#escalaValeAPartirDe[type="date"]'));
        self::assertCount(1, $crawler->filter('#tab-escala #escalaFormContainer > #escalaHistorico #escalaHistoricoLinhas'));
        self::assertCount(1, $crawler->filter('#tab-escala #btnLimparEscala.d-none'));
        self::assertSame(
            'A folha usa esta jornada a partir desta data. Os dias anteriores seguem a versão anterior.',
            $crawler->filter('#tab-escala #escalaValeAPartirDe + .form-text')->text(),
        );

        $this->jornadaAntiga($this->pessoa, self::QUARENTA, '2026-04-22');
        $crawler = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $this->escritorio->getId(), $this->pessoa->getId()));
        self::assertCount(1, $crawler->filter('#tab-escala #btnLimparEscala:not(.d-none)'));
    }

    /** A pessoa registra ponto também noutro escritório, onde a jornada dela é à tarde. */
    private function pessoaTambemNoEscritorioDaTarde(): void
    {
        $tarde = $this->escritorio('Escritório da tarde');
        $this->vincular($this->pessoa, $tarde);
        $this->jornadaPessoal($this->pessoa, $tarde, self::TARDE);
    }

    /** Os 22 dias úteis de abril de 2026, de 08:00 às 18:00 (10 horas, sem intervalo). */
    private function registrarAbrilInteiro(): void
    {
        for ($dia = new \DateTimeImmutable('2026-04-01'); $dia->format('m') === '04'; $dia = $dia->modify('+1 day')) {
            if ((int) $dia->format('N') <= 5) {
                $this->registrarBatida($this->pessoa, $this->escritorio, 'entrada', $dia->setTime(8, 0));
                $this->registrarBatida($this->pessoa, $this->escritorio, 'saida', $dia->setTime(18, 0));
            }
        }
    }

    /**
     * Abril cobra 8h por dia, desde o dia 1º: 22 dias de +2h dão +44h (com 44h seriam +26h). Maio já é 44h.
     */
    private function assertAbrilCom40EMaioCom44(HistoricoDeJornadas $historico): void
    {
        self::assertSame(2400, $historico->vigenteEm(new \DateTimeImmutable('2026-03-31'))?->getCargaHorariaSemanal());
        self::assertSame(2400, $historico->vigenteEm(new \DateTimeImmutable('2026-04-01'))?->getCargaHorariaSemanal());
        self::assertSame(2400, $historico->vigenteEm(new \DateTimeImmutable('2026-04-30'))?->getCargaHorariaSemanal());
        self::assertSame(2640, $historico->vigenteEm(new \DateTimeImmutable('2026-05-04'))?->getCargaHorariaSemanal());

        $saldo = static::getContainer()->get(FolhaPontoBuilder::class)
            ->calcularSaldoAteMes($this->pessoa, 2026, 4, [], null, new \DateTimeImmutable('2026-04-01'), $this->escritorio);
        self::assertSame(22 * 120, $saldo);
    }

    private function folhaDeOutubroDoColaborador(): string
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/?competencia=2026-10');
        self::assertResponseIsSuccessful();

        return $tela->filter('table.table-striped')->first()->text();
    }

    /**
     * Uma jornada como a migração deixou: com carga, datada e cobrindo os dias antes da data.
     *
     * @param list<array{0: list<int>, 1: string, 2: ?string, 3: ?string, 4: string}> $blocos
     */
    private function jornadaAntiga(User $pessoa, array $blocos, string $valeAPartirDe): JornadaColaborador
    {
        $jornada = $this->jornadaPessoal($pessoa, $this->escritorio, $blocos)
            ->setValeAPartirDe(new \DateTimeImmutable($valeAPartirDe))
            ->setCargaHorariaSemanal($this->corpoDaJornada($blocos)['cargaHorariaSemanal']);
        $this->em()->flush();

        return $jornada;
    }

    /**
     * @param list<array{0: list<int>, 1: string, 2: ?string, 3: ?string, 4: string}> $blocos
     *
     * @return array<mixed>
     */
    private function salvar(array $blocos, string $valeAPartirDe, ?int $versaoId = null): array
    {
        $corpo = $this->corpoDaJornada($blocos) + ['valeAPartirDe' => $valeAPartirDe];
        if ($versaoId !== null) {
            $corpo['versaoId'] = $versaoId;
        }

        return $this->chamarJson($this->client, 'POST', $this->url(), $corpo);
    }

    private function url(?User $pessoa = null): string
    {
        return sprintf('/tenant/%d/user/%d/jornada-colaborador', $this->escritorio->getId(), ($pessoa ?? $this->pessoa)->getId());
    }

    /** O histórico como está no banco, sem o que ficou na memória do teste. */
    private function historico(): HistoricoDeJornadas
    {
        $this->em()->clear();
        $pessoa = $this->em()->getReference(User::class, $this->pessoa->getId());
        $escritorio = $this->em()->getReference(Tenant::class, $this->escritorio->getId());
        $historico = $this->jornadas()->historicoDe($pessoa, $escritorio);
        self::assertNotNull($historico);

        return $historico;
    }

    private function jornadas(): JornadaColaboradorRepository
    {
        return static::getContainer()->get(JornadaColaboradorRepository::class);
    }
}
