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
 * O envio da batida (spec ponto-desenho-0811 §5 E13): o identificador do envio em minúsculas, a hora do aparelho só como
 * informação e a hora oficial na chegada. Sem os campos novos, tudo como antes.
 */
#[Group('ponto')]
#[CoversClass(EnvioDaBatida::class)]
#[CoversClass(EnvioLidoDaBatida::class)]
#[CoversClass(RecusaDoEnvio::class)]
final class EnvioDaBatidaTest extends TestCase
{
    private const ID = '3f2b8c1e-9a4d-4e6f-8b7a-1c2d3e4f5a6b';

    private \DateTimeImmutable $chegada;

    protected function setUp(): void
    {
        $this->chegada = new \DateTimeImmutable('2026-11-09 10:00:00');
    }

    #[TestDox('Sem os campos novos: sem identificador, sem hora do aparelho e o momento é a chegada')]
    public function testSemOsCamposNovos(): void
    {
        $envio = (new EnvioDaBatida())->ler(['tipo' => 'entrada'], $this->chegada);

        self::assertNull($envio->recusaDoPedido);
        self::assertNull($envio->idEnvio);
        self::assertNull($envio->horaAparelho);
        self::assertSame($this->chegada, $envio->chegada);
        self::assertSame($this->chegada, $envio->momento);
    }

    #[TestDox('Identificador UUID v4 válido é aceito como veio')]
    public function testIdentificadorValido(): void
    {
        $envio = (new EnvioDaBatida())->ler(['idEnvio' => self::ID], $this->chegada);

        self::assertNull($envio->recusaDoPedido);
        self::assertSame(self::ID, $envio->idEnvio);
    }

    #[TestDox('Identificador em maiúsculas é normalizado para minúsculas')]
    public function testIdentificadorMaiusculoNormalizado(): void
    {
        $envio = (new EnvioDaBatida())->ler(['idEnvio' => strtoupper(self::ID)], $this->chegada);

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
        $envio = (new EnvioDaBatida())->ler(['idEnvio' => $idEnvio], $this->chegada);

        self::assertNotNull($envio->recusaDoPedido);
        self::assertSame(422, $envio->recusaDoPedido->status);
        self::assertSame('Identificador do envio inválido.', $envio->recusaDoPedido->mensagem);
    }

    #[TestDox('Hora do aparelho em milissegundos vira o instante no fuso padrão do PHP')]
    public function testHoraDoAparelhoNoFusoPadrao(): void
    {
        $ms = (new \DateTimeImmutable('2026-11-09 09:58:30', new \DateTimeZone('UTC')))->getTimestamp() * 1000 + 250;

        $envio = (new EnvioDaBatida())->ler(['horaAparelho' => $ms], $this->chegada);

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
        $envio = (new EnvioDaBatida())->ler(['idEnvio' => self::ID, 'horaAparelho' => $hora], $this->chegada);

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
}
