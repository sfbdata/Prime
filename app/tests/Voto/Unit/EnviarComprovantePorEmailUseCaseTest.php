<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ComprovanteDeParticipacao;
use App\Voto\DTO\EnviarComprovantePorEmailInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ComprovanteDoVoto;
use App\Voto\Service\CorreioDoComprovante;
use App\Voto\Service\LimiteDosEnviosDoComprovante;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\Service\UnidadeDoLink;
use App\Voto\UseCase\EnviarComprovantePorEmailUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Validator\Validation;

#[CoversClass(EnviarComprovantePorEmailUseCase::class)]
#[CoversClass(LimiteDosEnviosDoComprovante::class)]
#[Group('voto')]
final class EnviarComprovantePorEmailUseCaseTest extends TestCase
{
    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef01';

    private EnviarComprovantePorEmailUseCase $caso;
    private MockClock $relogio;
    private ?UnidadeDaAssembleia $unidade;
    private ?PautaDaAssembleia $pauta;
    private bool $participou = true;
    private bool $falhar = false;
    /** @var list<array{string, ComprovanteDeParticipacao}> */
    private array $enviados = [];

    protected function setUp(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'Assembleia de teste', PesoDoVoto::Unidade, 'Mesa');
        $this->unidade = new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        (new \ReflectionProperty(UnidadeDaAssembleia::class, 'id'))->setValue($this->unidade, 7);
        $this->pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('findOneByTokenHash')->willReturnCallback(fn () => $this->unidade);
        $liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $liberacoes->method('findDoTenant')->willReturn($liberacao);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->willReturnCallback(fn () => $this->pauta);
        $participacoes = $this->createMock(ParticipacaoNaPautaRepository::class);
        $participacoes->method('horariosDaUnidade')->willReturnCallback(fn () => $this->participou ? [10 => new \DateTimeImmutable('2026-11-13 12:00:00'), 11 => new \DateTimeImmutable('2026-11-13 12:00:00')] : []);
        $correio = $this->createMock(CorreioDoComprovante::class);
        $correio->method('enviar')->willReturnCallback(function (string $email, ComprovanteDeParticipacao $comprovante): void {
            $this->enviados[] = [$email, $comprovante];
            if ($this->falhar) {
                throw new TransportException('Falha de teste');
            }
        });
        $this->relogio = new MockClock('2026-11-13 12:00:00');
        $limite = new LimiteDosEnviosDoComprovante(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $this->relogio);
        $this->caso = new EnviarComprovantePorEmailUseCase(new UnidadeDoLink(new TokenDaUnidade('teste'), $unidades, $liberacoes), $pautas, $participacoes, new ComprovanteDoVoto('teste'), $correio, $limite, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }

    public function testEnviaSomenteDadosDaParticipacaoParaODestinatarioEditado(): void
    {
        $this->enviar('outro@example.invalid');
        [$destinatario, $comprovante] = $this->enviados[0];
        self::assertSame('outro@example.invalid', $destinatario);
        self::assertSame('Assembleia de teste', $comprovante->assembleia);
        self::assertSame('Contas', $comprovante->pauta);
        self::assertSame((new ComprovanteDoVoto('teste'))->codigo(7, 10, new \DateTimeImmutable('2026-11-13 12:00:00')), $comprovante->codigo);
        self::assertSame(['assembleia', 'pauta', 'unidade', 'registradaEm', 'codigo'], array_keys(get_object_vars($comprovante)));
    }

    public function testSemParticipacaoRecusaAntesDeConsumirCota(): void
    {
        $this->participou = false;
        try {
            $this->enviar();
            self::fail('Deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException) {
        }
        $this->participou = true;
        $this->enviar();
        $this->enviar();
        $this->enviar();
        self::assertCount(3, $this->enviados);
    }

    public function testEmailInvalidoNaoConsomeCota(): void
    {
        try {
            $this->enviar("invalido\r\nBcc: outro@example.invalid");
            self::fail('Deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException) {
        }
        $this->enviar();
        $this->enviar();
        $this->enviar();
        self::assertCount(3, $this->enviados);
    }

    public function testLinkInvalidoRecusa(): void
    {
        $this->unidade = null;
        $this->expectException(CedulaNaoEncontradaException::class);
        $this->enviar();
    }

    public function testPautaDeOutraAssembleiaRecusa(): void
    {
        $this->pauta = null;
        $this->expectException(CedulaNaoEncontradaException::class);
        $this->enviar();
    }

    public function testTresTentativasPorUnidadeMesmoTrocandoDestinatario(): void
    {
        $this->enviar('a@example.invalid');
        $this->enviar('b@example.invalid');
        $this->enviar('c@example.invalid');
        $this->expectException(\App\Voto\Exception\LimiteDeEnviosDoComprovanteException::class);
        $this->enviar('d@example.invalid');
    }

    public function testFalhaDeCorreioTambemConsomeTentativa(): void
    {
        $this->falhar = true;
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->enviar();
                self::fail('Deveria falhar');
            } catch (TransportException) {
            }
        }
        $this->falhar = false;
        $this->expectException(\App\Voto\Exception\LimiteDeEnviosDoComprovanteException::class);
        $this->enviar();
    }

    public function testJanelaMovelLiberaSomenteTentativasComUmaHoraCompleta(): void
    {
        $this->enviar();
        $this->relogio->sleep(1800);
        $this->enviar();
        $this->enviar();
        $this->relogio->sleep(1800);
        $this->enviar();
        self::assertCount(4, $this->enviados);
        $this->expectException(\App\Voto\Exception\LimiteDeEnviosDoComprovanteException::class);
        $this->enviar();
    }

    public function testCotaCompartilhadaEntrePautas(): void
    {
        $this->enviar();
        $this->caso->executar(new EnviarComprovantePorEmailInput(self::TOKEN, 11, 'outro@example.invalid'));
        $this->enviar();
        $this->expectException(\App\Voto\Exception\LimiteDeEnviosDoComprovanteException::class);
        $this->caso->executar(new EnviarComprovantePorEmailInput(self::TOKEN, 11, 'terceiro@example.invalid'));
    }

    private function enviar(string $email = 'teste@example.invalid'): void
    {
        $this->caso->executar(new EnviarComprovantePorEmailInput(self::TOKEN, 10, $email));
    }
}
