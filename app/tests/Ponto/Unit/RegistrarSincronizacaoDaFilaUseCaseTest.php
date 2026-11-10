<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\SincronizacaoDaFilaInput;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\UseCase\RegistrarSincronizacaoDaFilaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O evento "Sincronização" da fila sem internet (E14, dc L438): um evento com as batidas da pessoa que vieram da fila,
 * sem as que já entraram numa sincronização, conferidas depois da trava da trilha do escritório.
 */
#[CoversClass(RegistrarSincronizacaoDaFilaUseCase::class)]
#[CoversClass(SincronizacaoDaFilaInput::class)]
#[Group('ponto')]
final class RegistrarSincronizacaoDaFilaUseCaseTest extends TestCase
{
    use TrilhaDoPontoDeTesteTrait;

    private Tenant $tenant;
    private User $pessoa;
    private RegistroPontoRepository&MockObject $registros;
    private TrilhaDoPontoRepository&MockObject $repositorioDaTrilha;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->pessoa = (new User())->setEmail('ana@teste.com')->setFullName('Ana');
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->pessoa, 5);
        $this->registros = $this->createMock(RegistroPontoRepository::class);
        $this->repositorioDaTrilha = $this->createMock(TrilhaDoPontoRepository::class);
    }

    #[TestDox('Batidas da fila ainda fora de uma sincronização: um evento com elas, a pessoa como autora, depois da trava')]
    public function testGravaUmEvento(): void
    {
        $this->registros->expects(self::once())->method('semInternetDosEnvios')
            ->with($this->pessoa, $this->tenant, ['a', 'b'])->willReturn([$this->batida(11), $this->batida(12)]);
        $ordem = [];
        $this->repositorioDaTrilha->expects(self::once())->method('travar')->with(7)->willReturnCallback(function () use (&$ordem): void {
            $ordem[] = 'travar';
        });
        $this->repositorioDaTrilha->expects(self::once())->method('jaSincronizados')->with(7, [11, 12])->willReturnCallback(function () use (&$ordem): array {
            $ordem[] = 'conferir';

            return [];
        });

        self::assertSame(2, $this->casoDeUso()->executar(new SincronizacaoDaFilaInput($this->pessoa, $this->tenant, ['a', 'b'])));

        self::assertSame(['travar', 'conferir'], $ordem, 'as repetidas são conferidas depois da trava');
        self::assertCount(1, $this->eventosDaTrilha);
        self::assertSame(['sincronizacao', 5, 7], [$this->eventosDaTrilha[0]['acao'], $this->eventosDaTrilha[0]['autor_id'], $this->eventosDaTrilha[0]['tenant_id']]);
        self::assertSame(['colaborador_id' => 5, 'colaborador' => 'Ana', 'registro_ids' => [11, 12], 'quantidade' => 2], $this->eventosDaTrilha[0]['detalhe']);
    }

    #[TestDox('Algumas já sincronizadas: o evento leva só as novas; todas já sincronizadas: nenhum evento')]
    public function testTiraAsJaSincronizadas(): void
    {
        $this->registros->method('semInternetDosEnvios')->willReturn([$this->batida(11), $this->batida(12), $this->batida(13)]);
        $this->repositorioDaTrilha->method('jaSincronizados')->willReturnOnConsecutiveCalls([11], [11, 12, 13]);

        self::assertSame(2, $this->casoDeUso()->executar(new SincronizacaoDaFilaInput($this->pessoa, $this->tenant, ['a', 'b', 'c'])));
        self::assertSame(0, $this->casoDeUso()->executar(new SincronizacaoDaFilaInput($this->pessoa, $this->tenant, ['a', 'b', 'c'])));

        self::assertCount(1, $this->eventosDaTrilha);
        self::assertSame([12, 13], $this->eventosDaTrilha[0]['detalhe']['registro_ids']);
    }

    #[TestDox('Nenhuma batida dela da fila com esses envios: nada grava, nem trava a trilha')]
    public function testSemBatidaDaFila(): void
    {
        $this->registros->method('semInternetDosEnvios')->willReturn([]);
        $this->repositorioDaTrilha->expects(self::never())->method('travar');

        self::assertSame(0, $this->casoDeUso()->executar(new SincronizacaoDaFilaInput($this->pessoa, $this->tenant, ['x'])));
        self::assertSame([], $this->eventosDaTrilha);
    }

    #[TestDox('Corpo JSON: lista de identificadores de 1 a 36 caracteres, sem repetidos, até 100')]
    public function testCorpoDoPedido(): void
    {
        foreach ([[], ['idsEnvio' => []], ['idsEnvio' => 'a'], ['idsEnvio' => [1]], ['idsEnvio' => ['']], ['idsEnvio' => [str_repeat('a', 37)]], ['idsEnvio' => ['k' => 'a']], ['idsEnvio' => array_fill(0, 101, 'a')]] as $corpo) {
            self::assertNull(SincronizacaoDaFilaInput::doJson($this->pessoa, $this->tenant, $corpo), (string) json_encode($corpo));
        }
        $pedido = SincronizacaoDaFilaInput::doJson($this->pessoa, $this->tenant, ['idsEnvio' => ['a', 'b', 'a'], 'tenant' => 99]);
        self::assertNotNull($pedido);
        self::assertSame(['a', 'b'], $pedido->idsEnvio);
        self::assertSame($this->tenant, $pedido->tenant, 'o escritório é o da sessão; o corpo não escolhe');
    }

    private function casoDeUso(): RegistrarSincronizacaoDaFilaUseCase
    {
        return new RegistrarSincronizacaoDaFilaUseCase($this->registros, $this->repositorioDaTrilha, $this->trilhaDeTeste());
    }

    private function batida(int $id): RegistroPonto
    {
        $batida = new RegistroPonto();
        (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($batida, $id);

        return $batida;
    }
}
