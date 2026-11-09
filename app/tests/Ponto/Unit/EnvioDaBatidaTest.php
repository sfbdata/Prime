<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\EnvioDaBatida;
use App\Ponto\Service\EnvioLidoDaBatida;
use App\Ponto\Service\RecusaDoEnvio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O envio da batida (spec ponto-desenho-0811 §5 E13 e E14): o identificador do envio em minúsculas, a hora do aparelho só
 * como informação e a hora oficial na chegada. Sem os campos novos, tudo como antes. Na batida guardada sem internet vale
 * a hora do aparelho, até 72 h antes da chegada e até 2 min à frente dela; outra conta ou escritório volta 409.
 */
#[Group('ponto')]
#[CoversClass(EnvioDaBatida::class)]
#[CoversClass(EnvioLidoDaBatida::class)]
#[CoversClass(RecusaDoEnvio::class)]
final class EnvioDaBatidaTest extends TestCase
{
    private const ID = '3f2b8c1e-9a4d-4e6f-8b7a-1c2d3e4f5a6b';
    private const USUARIO = 41;
    private const ESCRITORIO = 7;

    private \DateTimeImmutable $chegada;

    protected function setUp(): void
    {
        $this->chegada = new \DateTimeImmutable('2026-11-09 10:00:00');
    }

    #[TestDox('Sem os campos novos: sem identificador, sem hora do aparelho e o momento é a chegada')]
    public function testSemOsCamposNovos(): void
    {
        $envio = $this->ler(['tipo' => 'entrada']);

        self::assertNull($envio->recusaDoPedido);
        self::assertNull($envio->idEnvio);
        self::assertNull($envio->horaAparelho);
        self::assertSame($this->chegada, $envio->chegada);
        self::assertSame($this->chegada, $envio->momento);
    }

    #[TestDox('Identificador UUID v4 válido é aceito como veio')]
    public function testIdentificadorValido(): void
    {
        $envio = $this->ler(['idEnvio' => self::ID]);

        self::assertNull($envio->recusaDoPedido);
        self::assertSame(self::ID, $envio->idEnvio);
    }

    #[TestDox('Identificador em maiúsculas é normalizado para minúsculas')]
    public function testIdentificadorMaiusculoNormalizado(): void
    {
        $envio = $this->ler(['idEnvio' => strtoupper(self::ID)]);

        self::assertNull($envio->recusaDoPedido);
        self::assertSame(self::ID, $envio->idEnvio);
    }

    /** @return iterable<string, array{mixed}> */
    public static function identificadoresInvalidos(): iterable
    {
        yield 'texto qualquer' => ['abc'];
        yield 'vazio' => [''];
        yield 'número' => [123];
        yield 'versão 1' => ['3f2b8c1e-9a4d-1e6f-8b7a-1c2d3e4f5a6b'];
        yield 'variante fora do padrão' => ['3f2b8c1e-9a4d-4e6f-cb7a-1c2d3e4f5a6b'];
        yield 'sem os hífens' => ['3f2b8c1e9a4d4e6f8b7a1c2d3e4f5a6b'];
        yield 'com sobra' => [self::ID . '0'];
        yield 'lista' => [[self::ID]];
    }

    #[DataProvider('identificadoresInvalidos')]
    #[TestDox('Identificador fora do formato recusa com 422 e a mensagem do contrato')]
    public function testIdentificadorInvalidoRecusa(mixed $idEnvio): void
    {
        $envio = $this->ler(['idEnvio' => $idEnvio]);

        self::assertNotNull($envio->recusaDoPedido);
        self::assertSame(422, $envio->recusaDoPedido->status);
        self::assertSame('Identificador do envio inválido.', $envio->recusaDoPedido->mensagem);
    }

    #[TestDox('Hora do aparelho em milissegundos vira o instante no fuso padrão do PHP')]
    public function testHoraDoAparelhoNoFusoPadrao(): void
    {
        $ms = (new \DateTimeImmutable('2026-11-09 09:58:30', new \DateTimeZone('UTC')))->getTimestamp() * 1000 + 250;

        $envio = $this->ler(['horaAparelho' => $ms]);

        self::assertNotNull($envio->horaAparelho);
        self::assertSame(date_default_timezone_get(), $envio->horaAparelho->getTimezone()->getName());
        self::assertSame(intdiv($ms, 1000), $envio->horaAparelho->getTimestamp());
        self::assertSame('250', $envio->horaAparelho->format('v'));
        self::assertSame($this->chegada, $envio->momento, 'na batida normal a hora do aparelho é só informação');
    }

    /** @return iterable<string, array{mixed}> */
    public static function horasInvalidas(): iterable
    {
        yield 'texto' => ['ontem'];
        yield 'número em texto' => ['1762693110000'];
        yield 'fração' => [1762693110000.5];
        yield 'negativo' => [-1];
        yield 'além do ano 9999' => [253402300800000];
        yield 'booleano' => [true];
        yield 'nulo' => [null];
    }

    #[DataProvider('horasInvalidas')]
    #[TestDox('Na batida normal, hora do aparelho inválida vira null e nunca recusa')]
    public function testHoraDoAparelhoInvalidaViraNull(mixed $hora): void
    {
        $envio = $this->ler(['idEnvio' => self::ID, 'horaAparelho' => $hora]);

        self::assertNull($envio->recusaDoPedido);
        self::assertNull($envio->horaAparelho);
        self::assertSame($this->chegada, $envio->momento);
    }

    #[TestDox('Gravar na batida leva o momento, a chegada, a hora do aparelho e o identificador, sem a marca da fila')]
    public function testGravarEmLevaAsDuasHoras(): void
    {
        $aparelho = new \DateTimeImmutable('2026-11-09 09:59:00');
        $envio = new EnvioLidoDaBatida($this->chegada, $this->chegada, self::ID, $aparelho);
        $registro = new RegistroPonto();

        $envio->gravarEm($registro);

        self::assertInstanceOf(\DateTime::class, $registro->getDataHora(), 'a coluna data_hora é datetime mutável');
        self::assertSame('2026-11-09 10:00:00', $registro->getDataHora()->format('Y-m-d H:i:s'));
        self::assertSame($this->chegada, $registro->getHoraChegada());
        self::assertSame($aparelho, $registro->getHoraAparelho());
        self::assertSame(self::ID, $registro->getIdEnvio());
        self::assertFalse($registro->isEnviadaSemInternet());
    }

    #[TestDox('semInternet que não é o booleano true deixa a batida normal: vale a chegada')]
    public function testSemInternetSoComTrue(): void
    {
        foreach (['true', 1, false, null] as $valor) {
            $envio = $this->ler($this->fila(['semInternet' => $valor, 'horaAparelho' => $this->ms('-1 hour')]));

            self::assertFalse($envio->semInternet);
            self::assertSame($this->chegada, $envio->momento);
            self::assertNull($envio->recusaDaFila);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function filasIncompletas(): iterable
    {
        yield 'sem identificador' => [['idEnvio' => null]];
        yield 'sem hora do aparelho' => [['horaAparelho' => null]];
        yield 'hora do aparelho em texto' => [['horaAparelho' => '1762693110000']];
        yield 'sem a conta' => [['usuarioId' => null]];
        yield 'conta em texto' => [['usuarioId' => '41']];
        yield 'sem o escritório' => [['escritorioId' => null]];
        yield 'escritório fracionado' => [['escritorioId' => 7.0]];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('filasIncompletas')]
    #[TestDox('Batida da fila sem um campo obrigatório recusa com 422 antes de procurar a batida que já entrou')]
    public function testFilaSemCampoObrigatorioRecusa(array $troca): void
    {
        $envio = $this->ler(array_merge($this->fila(['horaAparelho' => $this->ms('-1 hour')]), $troca));

        self::assertNotNull($envio->recusaDoPedido);
        self::assertSame(422, $envio->recusaDoPedido->status);
        self::assertSame('A batida guardada sem internet chegou incompleta e não foi registrada.', $envio->recusaDoPedido->mensagem);
    }

    #[TestDox('Batida da fila dentro dos limites: vale a hora do aparelho, com a marca e a chegada guardada')]
    public function testFilaValeAHoraDoAparelho(): void
    {
        $envio = $this->ler($this->fila(['horaAparelho' => $this->ms('-3 hours')]));

        self::assertNull($envio->recusaDoPedido);
        self::assertNull($envio->recusaDaFila);
        self::assertTrue($envio->semInternet);
        self::assertSame(self::ID, $envio->idEnvio);
        self::assertSame('2026-11-09 07:00:00', $envio->momento->format('Y-m-d H:i:s'));
        self::assertSame($this->chegada, $envio->chegada);
    }

    #[TestDox('72 h menos 1 min antes da chegada passa; exatamente 72 h também')]
    public function testFilaDentroDoTeto(): void
    {
        $quase = $this->ler($this->fila(['horaAparelho' => $this->ms('-72 hours +1 minute')]));
        $exato = $this->ler($this->fila(['horaAparelho' => $this->ms('-72 hours')]));

        self::assertNull($quase->recusaDaFila);
        self::assertSame('2026-11-06 10:01:00', $quase->momento->format('Y-m-d H:i:s'));
        self::assertNull($exato->recusaDaFila);
        self::assertSame('2026-11-06 10:00:00', $exato->momento->format('Y-m-d H:i:s'));
    }

    #[TestDox('72 h e 1 s antes da chegada recusa com 422 e a mensagem do teto')]
    public function testFilaAlemDoTetoRecusa(): void
    {
        $envio = $this->ler($this->fila(['horaAparelho' => $this->ms('-72 hours -1 second')]));

        self::assertNull($envio->recusaDoPedido, 'não é pedido malformado: a procura da batida que já entrou vem antes');
        self::assertNotNull($envio->recusaDaFila);
        self::assertSame(422, $envio->recusaDaFila->status);
        self::assertSame(
            'Esta batida ficou guardada sem internet por mais de 72 horas e não pode mais ser enviada. Peça o esquecimento de registro do dia.',
            $envio->recusaDaFila->mensagem
        );
    }

    #[TestDox('Relógio do aparelho 1 min à frente: o momento é a chegada; exatamente 2 min também')]
    public function testFilaUmPoucoAFrenteViraAChegada(): void
    {
        $umMinuto = $this->ler($this->fila(['horaAparelho' => $this->ms('+1 minute')]));
        $doisMinutos = $this->ler($this->fila(['horaAparelho' => $this->ms('+2 minutes')]));

        self::assertNull($umMinuto->recusaDaFila);
        self::assertSame($this->chegada, $umMinuto->momento);
        self::assertSame('2026-11-09 10:01:00', $umMinuto->horaAparelho?->format('Y-m-d H:i:s'), 'a hora do aparelho fica guardada como veio');
        self::assertNull($doisMinutos->recusaDaFila);
        self::assertSame($this->chegada, $doisMinutos->momento);
    }

    #[TestDox('Relógio do aparelho 2 min e 1 s à frente recusa com 422 e a mensagem do relógio adiantado')]
    public function testFilaAdiantadaRecusa(): void
    {
        $envio = $this->ler($this->fila(['horaAparelho' => $this->ms('+2 minutes +1 second')]));

        self::assertNotNull($envio->recusaDaFila);
        self::assertSame(422, $envio->recusaDaFila->status);
        self::assertSame(
            'O relógio deste aparelho está adiantado em relação à hora oficial, e a batida guardada sem internet não pôde ser enviada. Acerte o relógio e peça o esquecimento de registro do dia.',
            $envio->recusaDaFila->mensagem
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function outrasContas(): iterable
    {
        yield 'outra conta' => [['usuarioId' => self::USUARIO + 1]];
        yield 'outro escritório' => [['escritorioId' => self::ESCRITORIO + 1]];
        yield 'outro escritório e além do teto' => [['escritorioId' => self::ESCRITORIO + 1, 'horaAparelho' => 0]];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('outrasContas')]
    #[TestDox('Batida guardada em outra conta ou outro escritório volta 409, antes do teto e do relógio')]
    public function testFilaDeOutraContaVolta409(array $troca): void
    {
        $envio = $this->ler(array_merge($this->fila(['horaAparelho' => $this->ms('-1 hour')]), $troca));

        self::assertNotNull($envio->recusaDaFila);
        self::assertSame(409, $envio->recusaDaFila->status);
        self::assertSame('Esta batida foi guardada em outra conta ou escritório e não foi enviada.', $envio->recusaDaFila->mensagem);
    }

    #[TestDox('Gravar a batida da fila leva a hora do aparelho como momento, a chegada e a marca "enviada sem internet"')]
    public function testGravarEmDaFila(): void
    {
        $envio = $this->ler($this->fila(['horaAparelho' => $this->ms('-30 minutes')]));
        $registro = new RegistroPonto();

        $envio->gravarEm($registro);

        self::assertSame('2026-11-09 09:30:00', $registro->getDataHora()?->format('Y-m-d H:i:s'));
        self::assertSame($this->chegada, $registro->getHoraChegada());
        self::assertSame('2026-11-09 09:30:00', $registro->getHoraAparelho()?->format('Y-m-d H:i:s'));
        self::assertTrue($registro->isEnviadaSemInternet());
    }

    /** @param array<mixed> $dados */
    private function ler(array $dados): EnvioLidoDaBatida
    {
        return (new EnvioDaBatida())->ler($dados, self::USUARIO, self::ESCRITORIO, $this->chegada);
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private function fila(array $troca): array
    {
        return array_merge(
            ['tipo' => 'entrada', 'idEnvio' => self::ID, 'semInternet' => true, 'usuarioId' => self::USUARIO, 'escritorioId' => self::ESCRITORIO],
            $troca,
        );
    }

    /** A hora do aparelho, em milissegundos, relativa à chegada. */
    private function ms(string $relativo): int
    {
        return $this->chegada->modify($relativo)->getTimestamp() * 1000;
    }
}
