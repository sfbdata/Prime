<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Master\DTO\AlterarConvenioDaUfInput;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Service\AvisoDeConvenioDaUf;
use App\Master\UseCase\AlterarConvenioDaUfUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Master\Exception\AvisoDeConvenioNaoEnfileirado;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AlterarConvenioDaUfUseCase::class)]
final class AlterarConvenioDaUfUseCaseTest extends TestCase
{
    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];

    private ?ConvenioDaUf $gravado = null;
    private int $gravacoes = 0;
    private int $remocoes = 0;

    /** @param list<array{email: string, nome: string}> $primarios */
    private function useCase(?ConvenioDaUf $atual, array $primarios, ?\Throwable $falhaNoEnvio = null): AlterarConvenioDaUfUseCase
    {
        $convenios = $this->createStub(ConvenioDaUfRepository::class);
        $convenios->method('buscar')->willReturn($atual);
        $convenios->method('salvar')->willReturnCallback(function (ConvenioDaUf $c): void {
            $this->gravado = $c;
            ++$this->gravacoes;
        });
        $convenios->method('remover')->willReturnCallback(function (): void {
            ++$this->remocoes;
        });
        $painel = $this->createStub(PainelMasterRepository::class);
        $painel->method('escritorios')->willReturn([['id' => 1, 'estado' => 'SP', 'ativo' => true]]);
        $repo = $this->createStub(PrimariosDosEscritoriosRepository::class);
        $repo->method('dos')->willReturn($primarios);
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturnCallback(function (MensagemDeEmail $m) use ($falhaNoEnvio): ResultadoDoEnvio {
            if ($falhaNoEnvio !== null) {
                throw $falhaNoEnvio;
            }
            $this->enviadas[] = $m;

            return new ResultadoDoEnvio(1, ResultadoDoEnvio::ENFILEIRADO);
        });
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $aviso = new AvisoDeConvenioDaUf($painel, $repo, $carteiro, $tradutor);
        $conexao = $this->createStub(Connection::class);
        $conexao->method('transactional')->willReturnCallback(static fn (\Closure $f): mixed => $f());

        return new AlterarConvenioDaUfUseCase($convenios, new MockClock(new \DateTimeImmutable('2026-10-26 10:00:00')), new EscopoDeAuditoriaDePlataforma(), $aviso, $conexao, new NullLogger());
    }

    private function pedido(string $modo, float $valor = 9.9, string $desde = '2026-11-01', ?string $motivo = null): AlterarConvenioDaUfInput
    {
        return new AlterarConvenioDaUfInput(null, 'SP', $modo, $valor, $desde, $motivo);
    }

    private function existente(string $modo = 'dif', float $valor = 9.9): ConvenioDaUf
    {
        return new ConvenioDaUf('SP', $modo, $valor, new \DateTimeImmutable('2026-11-01'), null, null, new \DateTimeImmutable('2026-10-01'));
    }

    private const PRIMARIOS = [['email' => 'a@x.com', 'nome' => 'Ana'], ['email' => 'b@x.com', 'nome' => 'Beto']];

    #[TestDox('convênio novo: grava e enfileira um aviso por primário')]
    public function testNovo(): void
    {
        $n = $this->useCase(null, self::PRIMARIOS)->executar($this->pedido('dif'));

        self::assertSame(2, $n);
        self::assertCount(2, $this->enviadas);
        self::assertSame(1, $this->gravacoes);
    }

    #[TestDox('mesmo estado salvo duas vezes: grava nada e não avisa')]
    public function testIdempotente(): void
    {
        $n = $this->useCase($this->existente(), self::PRIMARIOS)->executar($this->pedido('dif'));

        self::assertSame(0, $n);
        self::assertSame([], $this->enviadas);
        self::assertSame(0, $this->gravacoes);
    }

    #[TestDox('mudou o valor: grava na linha existente e avisa')]
    public function testMudouOValor(): void
    {
        $atual = $this->existente();
        $n = $this->useCase($atual, self::PRIMARIOS)->executar($this->pedido('dif', 12.5));

        self::assertSame(2, $n);
        self::assertSame(12.5, $atual->getValor());
    }

    #[TestDox('mudou só motivo e data: grava e não avisa')]
    public function testMudouSoMotivoEData(): void
    {
        $atual = $this->existente();
        $n = $this->useCase($atual, self::PRIMARIOS)->executar($this->pedido('dif', 9.9, '2026-12-15', 'Novo motivo'));

        self::assertSame(0, $n);
        self::assertSame([], $this->enviadas);
        self::assertSame(1, $this->gravacoes);
        self::assertSame('2026-12-15', $atual->getDesde()->format('Y-m-d'));
    }

    #[TestDox('livre: remove e avisa; livre sem linha não faz nada')]
    public function testLivre(): void
    {
        self::assertSame(2, $this->useCase($this->existente(), self::PRIMARIOS)->executar($this->pedido('livre')));
        self::assertSame(1, $this->remocoes);

        $this->enviadas = [];
        self::assertSame(0, $this->useCase(null, self::PRIMARIOS)->executar($this->pedido('livre')));
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('UF grande: sem teto, todos entram na fila')]
    public function testSemTeto(): void
    {
        $muitos = array_map(static fn (int $i): array => ['email' => "p$i@x.com", 'nome' => "P$i"], range(1, 1500));

        self::assertSame(1500, $this->useCase(null, $muitos)->executar($this->pedido('dif')));
    }

    #[TestDox('falha ao enfileirar propaga como AvisoDeConvenioNaoEnfileirado (a transação desfaz a gravação)')]
    public function testFalhaAoEnfileirar(): void
    {
        $this->expectException(AvisoDeConvenioNaoEnfileirado::class);

        $this->useCase(null, self::PRIMARIOS, new \RuntimeException('fila fora'))->executar($this->pedido('dif'));
    }

    #[TestDox('sem primários no estado: grava e avisa zero')]
    public function testSemPrimarios(): void
    {
        self::assertSame(0, $this->useCase(null, [])->executar($this->pedido('sem', 29.9, '2026-12-01', 'Fim')));
        self::assertSame(1, $this->gravacoes);
    }
}
