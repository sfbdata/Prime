<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\EquipeDoPontoController;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Ponto\UseCase\TrocarEscalaNaEquipeUseCase;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Trocar a escala na aba Equipe (spec `docs/specs/ponto-desenho-0811.md`, E5, P56 a): grava uma versão nova da jornada
 * pessoal valendo a partir de hoje, os dias de antes não mudam (saldo e folha), a versão aparece no histórico da ficha
 * do colaborador, e as guardas: quem não gere a equipe, pessoa de fora, suspensa ou que saiu, escala desconhecida,
 * choque, duas trocas ao mesmo tempo e CSRF. O número do Banco não tem regra própria na aba: é o do motor do saldo, o
 * mesmo do topo da /ponto da pessoa (EquipeDoPontoTelaTest::testBancoEPendenciasComoNaPontoDaPessoa).
 */
#[CoversClass(EquipeDoPontoController::class)]
#[CoversClass(TrocarEscalaNaEquipeUseCase::class)]
#[Group('ponto')]
final class TrocarEscalaNaEquipeTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;
    use ClockSensitiveTrait;

    /** 40h: segunda a sexta 8h. */
    private const QUARENTA = [[[1, 2, 3, 4, 5], '08:00', '12:00', '13:00', '17:00']];
    /** A tarde, de segunda a sexta. */
    private const TARDE = [[[1, 2, 3, 4, 5], '13:00', null, null, '17:00']];

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $gestora;
    private User $ana;
    private \DateTimeImmutable $hoje;
    private \DateTimeImmutable $mesPassado;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->hoje = new \DateTimeImmutable('today');
        $this->mesPassado = $this->hoje->modify('first day of last month');
        $this->escritorio = $this->escritorio('Escritório da escala');
        $this->gestora = $this->pessoa('Gestora');
        $this->vincular($this->gestora, $this->escritorio, [], true);
        $this->ana = $this->pessoa('Ana Lima');
        $this->vincular($this->ana, $this->escritorio);
        $this->logarComTenant($this->client, $this->gestora, $this->escritorio);
    }

    #[TestDox('Com jornada pessoal: versão nova a partir de hoje; o mês passado fica com o mesmo saldo e a mesma folha')]
    public function testVersaoNovaNaoMudaOPassado(): void
    {
        $antiga = $this->jornadaPessoal($this->ana, $this->escritorio, self::QUARENTA)
            ->setValeAPartirDe(new \DateTimeImmutable('2026-01-05'))->setCargaHorariaSemanal(2400);
        $this->em()->flush();
        $this->registrarDiaLongoNoMesPassado();
        $saldoAntes = $this->saldoAteOMesPassado();
        $folhaAntes = $this->folhaDoMesPassadoDaAna();

        $resposta = $this->trocar($this->ana, '44-6');

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $resposta);
        $versoes = $this->historico()->versoes();
        self::assertCount(2, $versoes);
        self::assertSame($antiga->getId(), $versoes[0]->getId());
        self::assertSame(2400, $versoes[0]->getCargaHorariaSemanal(), 'a de antes não muda');
        self::assertSame($this->hoje->format('Y-m-d'), $versoes[1]->getValeAPartirDe()->format('Y-m-d'));
        self::assertSame(2640, $versoes[1]->getCargaHorariaSemanal());
        $bloco = $versoes[1]->getBlocos()->first();
        self::assertNotFalse($bloco);
        self::assertSame([[1, 2, 3, 4, 5, 6], '09:00', '12:00', '13:00', '17:20'], [$bloco->getDiasSemana(), $bloco->getEntrada(), $bloco->getRepouso(), $bloco->getRetorno(), $bloco->getSaida()]);

        self::assertSame($saldoAntes, $this->saldoAteOMesPassado());
        self::assertSame($folhaAntes, $this->folhaDoMesPassadoDaAna());
    }

    #[TestDox('Sem jornada pessoal: a primeira vale só de hoje em diante; a folha do mês passado não muda')]
    public function testPrimeiraJornadaNaoMudaOPassado(): void
    {
        $this->jornadaPadrao($this->escritorio, self::QUARENTA);
        $this->registrarDiaLongoNoMesPassado();
        $folhaAntes = $this->folhaDoMesPassadoDaAna();

        $this->trocar($this->ana, '30-5');

        self::assertResponseIsSuccessful();
        $historico = $this->historico();
        self::assertCount(1, $historico->versoes());
        self::assertFalse($historico->versoes()[0]->cobreDiasAnteriores());
        self::assertNull($historico->vigenteEm($this->hoje->modify('-1 day')), 'ontem segue a jornada do escritório');
        self::assertSame(1800, $historico->vigenteEm($this->hoje)?->getCargaHorariaSemanal());
        self::assertSame($folhaAntes, $this->folhaDoMesPassadoDaAna());
    }

    #[TestDox('Trocar de novo no mesmo dia atualiza a versão de hoje, sem criar outra')]
    public function testDuasTrocasNoMesmoDia(): void
    {
        $this->trocar($this->ana, '44-6');
        $this->trocar($this->ana, '40-5');

        self::assertResponseIsSuccessful();
        $versoes = $this->historico()->versoes();
        self::assertCount(1, $versoes);
        self::assertSame(2400, $versoes[0]->getCargaHorariaSemanal());
        self::assertCount(1, $versoes[0]->getBlocos());
    }

    #[TestDox('A versão aparece no histórico da ficha do colaborador, valendo hoje')]
    public function testHistoricoNaFicha(): void
    {
        $this->jornadaPessoal($this->ana, $this->escritorio, self::QUARENTA)
            ->setValeAPartirDe(new \DateTimeImmutable('2026-01-05'))->setCargaHorariaSemanal(2400);
        $this->em()->flush();

        $this->trocar($this->ana, '44-5');
        $ficha = $this->chamarJson($this->client, 'GET', sprintf('/tenant/%d/user/%d/jornada-colaborador', $this->escritorio->getId(), $this->ana->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $ficha['versoes']);
        $nova = $ficha['versoes'][1];
        self::assertSame([$this->hoje->format('Y-m-d'), true, 2640], [$nova['valeAPartirDe'], $nova['vigente'], $nova['cargaHorariaSemanal']]);
        self::assertSame($nova['id'], $ficha['versaoVigenteId']);
    }

    #[TestDox('Choque com o horário da pessoa em outro escritório: 409 com a mensagem, nada gravado')]
    public function testChoque(): void
    {
        $tarde = $this->escritorio('Escritório da tarde');
        $this->vincular($this->ana, $tarde);
        $this->jornadaPessoal($this->ana, $tarde, self::TARDE);

        $resposta = $this->trocar($this->ana, '40-5');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE, '13:00', '17:00')], $resposta);
        self::assertNull($this->jornadas()->historicoDe($this->ana, $this->escritorio));
    }

    #[TestDox('Pessoa com vínculo suspenso e pessoa que saiu do escritório: 404 e nada gravado')]
    public function testSuspensaEQueSaiu(): void
    {
        $suspensa = $this->pessoa('Suspensa');
        $this->vincular($suspensa, $this->escritorio)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $saiu = $this->pessoa('Saiu');
        $this->em()->remove($this->vincular($saiu, $this->escritorio));
        $this->em()->flush();

        foreach ([$suspensa, $saiu] as $pessoa) {
            $resposta = $this->trocar($pessoa, '44-6');

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['erro' => 'Pessoa não encontrada na equipe deste escritório.'], $resposta);
            self::assertNull($this->jornadas()->historicoDe($pessoa, $this->escritorio), 'nada gravado');
        }
    }

    #[TestDox('Duas trocas ao mesmo tempo: a segunda bate no índice da versão de hoje e responde 409 com a mensagem, sem gravar')]
    public function testDuasTrocasAoMesmoTempo(): void
    {
        // A outra troca acabou de gravar a versão de hoje. Esta não a enxerga na leitura (o repositório devolve "nenhuma
        // jornada", como no JornadaSalvaAoMesmoTempoTest), tenta criar outra e a gravação vai ao banco de verdade.
        $this->jornadaPessoal($this->ana, $this->escritorio, self::QUARENTA)->setValeAPartirDe($this->hoje)->setCargaHorariaSemanal(2400);
        $this->em()->flush();
        $em = $this->em();
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('salvar')->willReturnCallback(static fn (JornadaColaborador $jornada) => $em->persist($jornada));
        $jornadas->method('gravar')->willReturnCallback(static fn () => $em->flush());
        static::getContainer()->set(JornadaColaboradorRepository::class, $jornadas);

        $resposta = $this->trocar($this->ana, '44-6');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => JornadaColaboradorController::JORNADA_ALTERADA_AO_MESMO_TEMPO], $resposta);
        self::assertSame(
            [[$this->hoje->format('Y-m-d'), 2400]],
            $em->getConnection()->fetchAllNumeric('SELECT vale_a_partir_de, carga_horaria_semanal FROM jornada_colaborador WHERE user_id = ?', [$this->ana->getId()]),
            'fica só a versão da outra troca',
        );
    }

    #[TestDox('Guardas: colaborador 403; pessoa de outro escritório e que não existe 404; escala fora da lista 422; corpo inválido 400; sem CSRF 403')]
    public function testGuardas(): void
    {
        $outro = $this->escritorio('Outro escritório');
        $deFora = $this->pessoa('De Fora');
        $this->vincular($deFora, $outro);
        $colega = $this->pessoa('Colega');
        $this->vincular($colega, $this->escritorio);

        $this->trocar($deFora, '44-6');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['erro' => 'Pessoa não encontrada na equipe deste escritório.'], $this->json());
        $this->client->request('POST', '/ponto/equipe/999999999/escala', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{"escala":"44-6"}');
        self::assertResponseStatusCodeSame(404);
        $this->trocar($this->ana, '12x36');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Escolha uma das escalas da lista.'], $this->json());
        $this->client->request('POST', $this->url($this->ana), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '[1]');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', $this->url($this->ana), [], [], ['CONTENT_TYPE' => 'application/json'], '{"escala":"44-6"}');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $colega, $this->escritorio);
        $this->trocar($this->ana, '44-6');
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->jornadas()->historicoDe($this->ana, $this->escritorio), 'nada gravado');
        self::assertNull($this->jornadas()->historicoDe($deFora, $outro));
    }

    /** @return array<mixed> */
    private function trocar(User $pessoa, string $escala): array
    {
        return $this->chamarJson($this->client, 'POST', $this->url($pessoa), ['escala' => $escala]);
    }

    private function url(User $pessoa): string
    {
        return sprintf('/ponto/equipe/%d/escala', $pessoa->getId());
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Uma segunda-feira do mês passado com 10 horas (08:00 às 18:00). */
    private function registrarDiaLongoNoMesPassado(): void
    {
        $segunda = $this->mesPassado->modify('first monday of this month');
        $this->registrarBatida($this->ana, $this->escritorio, 'entrada', $segunda->setTime(8, 0));
        $this->registrarBatida($this->ana, $this->escritorio, 'saida', $segunda->setTime(18, 0));
    }

    private function saldoAteOMesPassado(): int
    {
        $this->em()->clear();
        $ana = $this->em()->getReference(User::class, $this->ana->getId());
        $escritorio = $this->em()->find(Tenant::class, $this->escritorio->getId());
        self::assertNotNull($escritorio);

        return static::getContainer()->get(FolhaPontoBuilder::class)->calcularSaldoAteMes(
            $ana,
            (int) $this->mesPassado->format('Y'),
            (int) $this->mesPassado->format('n'),
            [],
            $escritorio->getJornadaTenant(),
            new \DateTimeImmutable('2026-01-01'),
            $escritorio,
        );
    }

    private function folhaDoMesPassadoDaAna(): string
    {
        $this->logarComTenant($this->client, $this->ana, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/?competencia=' . $this->mesPassado->format('Y-m'));
        self::assertResponseIsSuccessful();
        $this->logarComTenant($this->client, $this->gestora, $this->escritorio);

        return $tela->filter('table.pe-tab')->first()->text();
    }

    /** O histórico como está no banco, sem o que ficou na memória do teste. */
    private function historico(): HistoricoDeJornadas
    {
        $this->em()->clear();
        $historico = $this->jornadas()->historicoDe(
            $this->em()->getReference(User::class, $this->ana->getId()),
            $this->em()->getReference(Tenant::class, $this->escritorio->getId()),
        );
        self::assertNotNull($historico);

        return $historico;
    }

    private function jornadas(): JornadaColaboradorRepository
    {
        return static::getContainer()->get(JornadaColaboradorRepository::class);
    }
}
