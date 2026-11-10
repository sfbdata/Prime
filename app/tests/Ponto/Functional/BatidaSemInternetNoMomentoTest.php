<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\TurnoAbertoNoEscritorio;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Etapa E14 do desenho novo do Ponto (spec ponto-desenho-0811 §5), revisão de 09/11/2026: a batida guardada sem internet
 * passa por TODAS as regras da batida no momento dela, nunca na hora em que chegou. A interjornada e o repouso mínimo só
 * olham as batidas até o momento; o home office e o choque com outro escritório valem no dia e na hora da batida; a
 * entrada que falta é a que falta até o momento. E a batida guardada em outro escritório volta 409 mesmo com a sessão
 * num escritório sem o módulo de ponto, para a fila não a apagar.
 *
 * As batidas são de ontem e de anteontem, em horas fixas: dentro das 72 h a qualquer hora do dia.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(RegistroPontoRepository::class)]
final class BatidaSemInternetNoMomentoTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private Tenant $a;
    private Tenant $b;
    private User $pessoa;
    private \DateTimeImmutable $ontem;
    private \DateTimeImmutable $anteontem;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->a = $this->escritorio('Escritório A');
        $this->b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa da fila');
        $this->vincular($this->pessoa, $this->a);
        $this->vincular($this->pessoa, $this->b);
        $this->ontem = new \DateTimeImmutable('yesterday');
        $this->anteontem = new \DateTimeImmutable('-2 days midnight');
    }

    #[TestDox('Interjornada: a saída batida DEPOIS do momento da entrada da fila não conta (o revisor viu -1680 min e 422)')]
    public function testInterjornadaIgnoraASaidaPosteriorAoMomento(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        // O celular guardou a entrada de anteontem 08:00; ontem a pessoa bateu entrada e saída pelo computador.
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->ontem->setTime(8, 0));
        $this->registrarBatida($this->pessoa, $this->a, 'saida', $this->ontem->setTime(12, 0));

        $resposta = $this->daFila('entrada', $this->anteontem->setTime(8, 0));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame('08:00:00', $resposta['data']['hora']);
        self::assertSame(3, $this->batidasEm($this->a));
    }

    #[TestDox('Com a chave ponto-alertas desligada, a interjornada continua valendo no momento: a saída anterior à entrada da fila, a 8 h dela, recusa')]
    public function testInterjornadaValeNoMomento(): void
    {
        $this->desligarOsAlertas();
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->anteontem->setTime(14, 0));
        $this->registrarBatida($this->pessoa, $this->a, 'saida', $this->anteontem->setTime(22, 0));

        $resposta = $this->daFila('entrada', $this->ontem->setTime(6, 0));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Interjornada mínima é de 11h. Aguarde mais 3h00m.', $resposta['message']);
        self::assertSame(2, $this->batidasEm($this->a));
    }

    #[TestDox('E11: com os alertas ligados, a entrada da fila antes da interjornada grava na hora do aparelho, sem a janela (a fila não fica presa)')]
    public function testEntradaDaFilaAntesDaInterjornadaNaoFicaPresa(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->anteontem->setTime(14, 0));
        $this->registrarBatida($this->pessoa, $this->a, 'saida', $this->anteontem->setTime(22, 0));

        $resposta = $this->daFila('entrada', $this->ontem->setTime(6, 0));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertArrayNotHasKey('alerta', $resposta);
        self::assertSame(3, $this->batidasEm($this->a));
    }

    #[TestDox('Repouso mínimo: o repouso gravado DEPOIS do retorno da fila não mede o intervalo')]
    public function testRepousoPosteriorAoRetornoDaFilaNaoConta(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->ontem->setTime(8, 0));
        $this->registrarBatida($this->pessoa, $this->a, 'repouso', $this->ontem->setTime(15, 0));

        $resposta = $this->daFila('retorno', $this->ontem->setTime(13, 10));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame(3, $this->batidasEm($this->a));
    }

    #[TestDox('Home office do dia da batida: liberado ontem (e não hoje), a batida de ontem entra sem GPS')]
    public function testHomeOfficeDoDiaDaBatida(): void
    {
        $this->homeOfficeEmA([(int) $this->ontem->format('N')]);

        $resposta = $this->daFila('entrada', $this->ontem->setTime(10, 0));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        $registro = $this->em()->getRepository(RegistroPonto::class)->findOneBy(['user' => $this->pessoa->getId(), 'tenant' => $this->a->getId()]);
        self::assertNotNull($registro);
        self::assertTrue($registro->isHomeOffice());
    }

    #[TestDox('Home office do dia da chegada não vale para a batida de ontem: sem GPS, 422 e nada gravado')]
    public function testHomeOfficeDoDiaDaChegadaNaoVale(): void
    {
        $this->homeOfficeEmA([(int) (new \DateTimeImmutable())->format('N')]);

        $resposta = $this->daFila('entrada', $this->ontem->setTime(10, 0));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Geolocalização é obrigatória para registrar o ponto.', $resposta['message']);
        self::assertSame(0, $this->batidasEm($this->a));
    }

    #[TestDox('Choque entre escritórios no momento: ontem às 10:00 a pessoa trabalhava em B, e a batida da fila em A é recusada')]
    public function testChoqueNoMomentoDaBatida(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->jornadaPessoal($this->pessoa, $this->b, [[[(int) $this->ontem->format('N')], '09:00', null, null, '11:00']]);

        $resposta = $this->daFila('entrada', $this->ontem->setTime(10, 0));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, '09:00', '11:00'), $resposta['message']);
        self::assertSame(0, $this->batidasEm($this->a));
    }

    #[TestDox('Choque na hora da chegada não vale para a batida de ontem: B só tem jornada hoje, e a batida da fila entra')]
    public function testChoqueDaChegadaNaoVale(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->jornadaPessoal($this->pessoa, $this->b, [[[(int) (new \DateTimeImmutable())->format('N')], '00:00', null, null, '23:59']]);

        $resposta = $this->daFila('entrada', $this->ontem->setTime(10, 0));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame(1, $this->batidasEm($this->a));
    }

    #[TestDox('Entrada que falta, no momento: a saída da fila às 07:00 de ontem avisa, mesmo com a entrada das 09:00 batida depois')]
    public function testFaltouAEntradaNoMomento(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->ontem->setTime(9, 0));

        $resposta = $this->daFila('saida', $this->ontem->setTime(7, 0));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame([[
            'texto' => TurnoAbertoNoEscritorio::AVISO_SEM_ENTRADA,
            'link' => '/ponto/?competencia=' . $this->ontem->format('Y-m') . '&esquecimento=' . $this->ontem->format('Y-m-d') . ':entrada',
            'rotulo' => TurnoAbertoNoEscritorio::ROTULO_ENVIAR,
        ]], $resposta['avisos']);
    }

    #[TestDox('Recusa entre escritórios no momento: a saída da fila às 07:00 de ontem é solta (a entrada das 09:00 veio depois) e passa, mesmo no horário de B')]
    public function testSaidaSoltaNoMomentoNaoCaiNoChoque(): void
    {
        $this->homeOfficeEmA([1, 2, 3, 4, 5, 6, 7]);
        $this->jornadaPessoal($this->pessoa, $this->b, [[[(int) $this->ontem->format('N')], '06:00', null, null, '08:00']]);
        // A entrada das 09:00 foi batida pelo computador neste escritório, depois do momento da saída guardada.
        $this->registrarBatida($this->pessoa, $this->a, 'entrada', $this->ontem->setTime(9, 0));

        $resposta = $this->daFila('saida', $this->ontem->setTime(7, 0));

        self::assertTrue($resposta['success'] ?? false, (string) ($resposta['message'] ?? ''));
        self::assertSame('07:00:00', $resposta['data']['hora']);
        self::assertSame([TurnoAbertoNoEscritorio::AVISO_SEM_ENTRADA], array_column($resposta['avisos'], 'texto'));
        self::assertSame(2, $this->batidasEm($this->a));
    }

    #[TestDox('Batida guardada em A, enviada com a sessão em C sem o módulo de ponto: 409 antes da permissão e do CSRF, e nada gravado')]
    public function testOutroEscritorioSemModuloVolta409(): void
    {
        $c = $this->escritorio('Escritório C');
        $this->vincular($this->pessoa, $c, []);
        $this->logarComTenant($this->client, $this->pessoa, $c);
        $corpo = $this->corpoDaFila('entrada', $this->ontem->setTime(10, 0));

        $resposta = $this->chamarJson($this->client, 'POST', '/ponto/batida', $corpo);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['success' => false, 'message' => 'Esta batida foi guardada em outra conta ou escritório e não foi enviada.'], $resposta);

        $this->client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($corpo));
        self::assertResponseStatusCodeSame(409, 'sem o token CSRF, a batida de outro escritório também volta 409');

        unset($corpo['semInternet']);
        $this->chamarJson($this->client, 'POST', '/ponto/batida', $corpo);
        self::assertResponseStatusCodeSame(403, 'a batida normal segue a ordem de hoje: sem o módulo, 403');

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE user_id = ?', [$this->pessoa->getId()]));
    }

    // ----------------------------------------------------------------- apoio

    /** @return array<string, mixed> */
    private function daFila(string $tipo, \DateTimeImmutable $aparelho): array
    {
        $this->logarComTenant($this->client, $this->pessoa, $this->a);
        $resposta = $this->chamarJson($this->client, 'POST', '/ponto/batida', $this->corpoDaFila($tipo, $aparelho));
        $this->em()->clear();

        return $resposta;
    }

    /** @return array<string, mixed> */
    private function corpoDaFila(string $tipo, \DateTimeImmutable $aparelho): array
    {
        return [
            'tipo' => $tipo,
            'idEnvio' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFFFFFFFFFF)),
            'horaAparelho' => $aparelho->getTimestamp() * 1000,
            'semInternet' => true,
            'usuarioId' => $this->pessoa->getId(),
            'escritorioId' => $this->a->getId(),
        ];
    }

    /** @param list<int> $dias */
    private function homeOfficeEmA(array $dias): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->pessoa);
        $config->setTenant($this->a);
        $config->setDiasSemana($dias);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    /** A chave `ponto-alertas` desligada pelo Master (camada Global): vale a recusa de antes da E11. */
    private function desligarOsAlertas(): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => FuncoesDoPonto::ALERTAS]) ?? new RecursoEstado(FuncoesDoPonto::ALERTAS);
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    private function batidasEm(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->pessoa->getId(), $tenant->getId()],
        );
    }
}
