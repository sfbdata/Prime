<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Service\ChaveDoPontoEletronico;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A batida com a chave do ponto e o choque de horário (spec `docs/specs/seguranca-b-jornada.md` §4, regras 2 e 3): em
 * A a pessoa tem home office todo dia (a batida passa sem GPS); B tem a jornada dela de hoje em torno de agora, ou de
 * amanhã, conforme o caso.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(ChaveDoPontoEletronico::class)]
#[CoversClass(ChoqueDeJornadaEntreEscritorios::class)]
final class BatidaComChaveEChoqueTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private Tenant $a;
    private Tenant $b;
    private User $pessoa;
    private UserTenant $vinculoEmA;
    private UserTenant $vinculoEmB;

    protected function setUp(): void
    {
        $minutos = (int) (new \DateTimeImmutable())->format('G') * 60 + (int) (new \DateTimeImmutable())->format('i');
        if ($minutos < 3 || $minutos > 1435) {
            self::markTestSkipped('Perto da meia-noite o trecho "em torno de agora" atravessaria o dia.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->a = $this->escritorio('Escritório A');
        $this->b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa nos dois');
        $this->vinculoEmA = $this->vincular($this->pessoa, $this->a);
        $this->vinculoEmB = $this->vincular($this->pessoa, $this->b);
        $this->homeOfficeTodoDia($this->a);
    }

    #[TestDox('Agora dentro do horário de trabalho de hoje em B: a batida em A é recusada com 422 e nada é gravado')]
    public function testDentroDoHorarioDeBRecusa(): void
    {
        $trecho = $this->jornadaDeHojeEmTornoDeAgora();

        $resposta = $this->baterEmA('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['success' => false, 'message' => sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, ...$trecho)], $resposta);
        self::assertStringNotContainsString($this->b->getName(), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->batidasEm($this->a));
    }

    #[TestDox('A e B cobrem o momento (choque que entrou sem conferência): a entrada em A passa, ninguém fica travado')]
    public function testQuandoOsDoisCobremOMomentoAEntradaPassa(): void
    {
        [$inicio, $fim] = $this->jornadaDeHojeEmTornoDeAgora();
        // A jornada de A é gravada direto no banco, sem a conferência do choque: é o caso que a regra cobre.
        $this->jornadaPessoal($this->pessoa, $this->a, [[[(int) (new \DateTimeImmutable())->format('N')], $inicio, null, null, $fim]]);

        $resposta = $this->baterEmA('entrada');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame(1, $this->batidasEm($this->a));
    }

    #[TestDox('Fora do horário de B (a jornada de B é de outro dia da semana): a batida em A passa')]
    public function testForaDoHorarioDeBAceita(): void
    {
        $amanha = ((int) (new \DateTimeImmutable())->format('N')) % 7 + 1;
        $this->jornadaPessoal($this->pessoa, $this->b, [[[$amanha], '00:00', null, null, '23:59']]);

        $resposta = $this->baterEmA('entrada');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame(1, $this->batidasEm($this->a));
    }

    #[TestDox('B com a chave desligada não conta: a batida em A passa mesmo no horário de B')]
    public function testChaveDesligadaEmBNaoTrava(): void
    {
        $this->jornadaDeHojeEmTornoDeAgora();
        $this->vinculoEmB->alterarRegistroDePonto(false);
        $this->em()->flush();

        $resposta = $this->baterEmA('entrada');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
    }

    #[TestDox('Chave desligada em A: a entrada em A é recusada com a mensagem, mesmo com o módulo de ponto')]
    public function testChaveDesligadaEmARecusa(): void
    {
        $this->vinculoEmA->alterarRegistroDePonto(false);
        $this->em()->flush();

        $resposta = $this->baterEmA('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['success' => false, 'message' => ChaveDoPontoEletronico::MENSAGEM_DESLIGADA], $resposta);
        self::assertSame(0, $this->batidasEm($this->a));
    }

    #[TestDox('Entrada em A cedo, fora do horário de B; a saída em A no horário de B passa, porque fecha a entrada aberta em A')]
    public function testSaidaQueFechaEntradaAbertaPassa(): void
    {
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->hojeCedo());
        $this->jornadaDeHojeEmTornoDeAgora();

        $resposta = $this->baterEmA('saida');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
    }

    #[TestDox('A ida ao repouso no horário de B também passa quando fecha a entrada aberta em A')]
    public function testRepousoQueFechaEntradaAbertaPassa(): void
    {
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->hojeCedo());
        $this->jornadaDeHojeEmTornoDeAgora();

        $resposta = $this->baterEmA('repouso');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
    }

    #[TestDox('Saída solta em A, sem entrada aberta aqui, no horário de B: recusa como a entrada')]
    public function testSaidaSoltaNoHorarioDeBRecusa(): void
    {
        $trecho = $this->jornadaDeHojeEmTornoDeAgora();

        $resposta = $this->baterEmA('saida');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, ...$trecho), $resposta['message'] ?? null);
        self::assertSame(0, $this->batidasEm($this->a));
    }

    #[TestDox('Chave desligada em A com a entrada aberta em A: a saída em A passa')]
    public function testChaveDesligadaComEntradaAbertaSaidaPassa(): void
    {
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->hojeCedo());
        $this->vinculoEmA->alterarRegistroDePonto(false);
        $this->em()->flush();

        $resposta = $this->baterEmA('saida');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
    }

    #[TestDox('Turno que começou há 8 horas (o noturno começa ontem): com a chave desligada, a saída em A passa')]
    public function testTurnoLongoComChaveDesligadaSaidaPassa(): void
    {
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', new \DateTimeImmutable('-8 hours'));
        $this->vinculoEmA->alterarRegistroDePonto(false);
        $this->em()->flush();

        $resposta = $this->baterEmA('saida');

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
    }

    #[TestDox('Repouso e depois saída no horário de B, sem entrada aberta: a saída é recusada')]
    public function testRepousoSeguidoDeSaidaSemEntradaAbertaRecusa(): void
    {
        $this->registrarBatida($this->pessoa, $this->a, 'repouso', $this->hojeCedo());
        $trecho = $this->jornadaDeHojeEmTornoDeAgora();

        $resposta = $this->baterEmA('saida');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, ...$trecho), $resposta['message'] ?? null);
    }

    #[TestDox('Chave desligada em A sem entrada aberta: a saída em A é recusada')]
    public function testChaveDesligadaSemEntradaAbertaSaidaRecusa(): void
    {
        $this->vinculoEmA->alterarRegistroDePonto(false);
        $this->em()->flush();

        $resposta = $this->baterEmA('saida');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ChaveDoPontoEletronico::MENSAGEM_DESLIGADA, $resposta['message'] ?? null);
    }

    #[TestDox('Retorno do repouso no horário de B continua recusado, como a entrada')]
    public function testRetornoNoHorarioDeBRecusa(): void
    {
        $trecho = $this->jornadaDeHojeEmTornoDeAgora();

        $resposta = $this->baterEmA('retorno');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, ...$trecho), $resposta['message'] ?? null);
    }

    /** @return array{0: string, 1: string} o trecho de hoje em B, de uma hora antes a uma hora depois de agora */
    private function jornadaDeHojeEmTornoDeAgora(): array
    {
        $agora = new \DateTimeImmutable();
        $minutos = (int) $agora->format('G') * 60 + (int) $agora->format('i');
        $hhmm = static fn (int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $trecho = [$hhmm(max(0, $minutos - 60)), $hhmm(min(1439, $minutos + 2))];
        $this->jornadaPessoal($this->pessoa, $this->b, [[[(int) $agora->format('N')], $trecho[0], null, null, $trecho[1]]]);

        return $trecho;
    }

    /** Meio minuto depois da meia-noite de hoje: antes do agora (o setUp pula os primeiros minutos do dia). */
    private function hojeCedo(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('+30 seconds');
    }

    /** @return array<string, mixed> */
    private function baterEmA(string $tipo): array
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->a);

        return $this->chamarJson($this->client, 'POST', '/ponto/batida', ['tipo' => $tipo]);
    }

    private function batidasEm(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->pessoa->getId(), $tenant->getId()],
        );
    }

    private function homeOfficeTodoDia(Tenant $tenant): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->pessoa);
        $config->setTenant($tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }
}
