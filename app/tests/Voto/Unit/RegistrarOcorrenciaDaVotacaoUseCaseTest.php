<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\Service\UnidadeDoLink;
use App\Voto\UseCase\RegistrarOcorrenciaDaVotacaoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A ocorrência do eleitor no Portal (pedido de 26/11/2026), com os repositórios dublados: grava com a qualificação
 * congelada, recusa texto vazio ou longo, limita a 5 por unidade e confere a pauta na assembleia da unidade.
 */
#[CoversClass(RegistrarOcorrenciaDaVotacaoUseCase::class)]
#[CoversClass(OcorrenciaDaVotacao::class)]
#[Group('voto')]
final class RegistrarOcorrenciaDaVotacaoUseCaseTest extends TestCase
{
    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef01';

    private UnidadeDaAssembleiaRepository&MockObject $unidades;
    private PautaDaAssembleiaRepository&MockObject $pautas;
    private OcorrenciaDaVotacaoRepository&MockObject $ocorrencias;
    private MockClock $relogio;
    private UnidadeDaAssembleia $unidade;
    private Assembleia $assembleia;
    /** @var list<OcorrenciaDaVotacao> */
    private array $salvas = [];
    private int $jaRegistradas = 0;

    protected function setUp(): void
    {
        $this->relogio = new MockClock('2026-11-26 10:00:00');
        $this->assembleia = new Assembleia(new Tenant(), 'AGO 2026', PesoDoVoto::Unidade, 'Gestor');
        $tokens = new TokenDaUnidade('segredo-de-teste');
        $this->unidade = new UnidadeDaAssembleia($this->assembleia, '101', str_repeat('a', 32), $tokens->hash(self::TOKEN), 'Bloco A', null, null, 'Ana Souza');
        $this->unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $this->unidades->method('findOneByTokenHash')->willReturnCallback(
            fn (string $hash): ?UnidadeDaAssembleia => $hash === $tokens->hash(self::TOKEN) ? $this->unidade : null,
        );
        $this->pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $this->ocorrencias = $this->createMock(OcorrenciaDaVotacaoRepository::class);
        $this->ocorrencias->method('contarDaUnidade')->willReturnCallback(fn (): int => $this->jaRegistradas);
        $this->ocorrencias->method('salvar')->willReturnCallback(function (OcorrenciaDaVotacao $o): void {
            $this->salvas[] = $o;
        });
    }

    #[TestDox('Registra com a qualificação congelada (nome e bloco · unidade) e a hora do relógio')]
    public function testRegistraCongelandoAQualificacao(): void
    {
        $ocorrencia = $this->caso()->executar(self::TOKEN, null, '  Fila grande na entrada  ');

        self::assertSame([$ocorrencia], $this->salvas);
        self::assertSame('Fila grande na entrada', $ocorrencia->getTexto());
        self::assertSame('Ana Souza', $ocorrencia->getNomeDoEleitor());
        self::assertSame('Bloco A · 101', $ocorrencia->getRotuloDaUnidade());
        self::assertNull($ocorrencia->getPauta());
        self::assertSame($this->unidade, $ocorrencia->getUnidade());
        self::assertSame($this->assembleia->getTenant(), $ocorrencia->getTenant());
        self::assertEquals($this->relogio->now(), $ocorrencia->getCriadaEm());
    }

    #[TestDox('Texto vazio é recusado e nada é gravado')]
    public function testRecusaTextoVazio(): void
    {
        $this->assertRecusa('Escreva a ocorrência.', '   ');
    }

    #[TestDox('Texto de 501 caracteres é recusado; o de 500 passa')]
    public function testRecusaTextoLongo(): void
    {
        $this->assertRecusa('A ocorrência pode ter no máximo 500 caracteres.', str_repeat('a', 501));

        $ocorrencia = $this->caso()->executar(self::TOKEN, null, str_repeat('b', 500));
        self::assertSame(500, mb_strlen($ocorrencia->getTexto()));
    }

    #[TestDox('A sexta ocorrência da mesma unidade é recusada com a mensagem do limite; a quinta passa')]
    public function testLimiteDeCinco(): void
    {
        $this->jaRegistradas = 4;
        $this->caso()->executar(self::TOKEN, null, 'Quinta');
        self::assertCount(1, $this->salvas);

        $this->salvas = [];
        $this->jaRegistradas = 5;
        $this->assertRecusa('voto.assembleia.cedula.ocorrencia.limite', 'Sexta', 1);
    }

    #[TestDox('A pauta precisa ser da assembleia da unidade: pauta de outra assembleia é recusada')]
    public function testPautaDeOutraAssembleia(): void
    {
        $this->pautas->method('buscarDaAssembleia')->willReturn(null);

        $this->assertRecusa('voto.assembleia.cedula.ocorrencia.pauta_invalida', 'Sobre a pauta', 99);
    }

    #[TestDox('Com pauta da assembleia, a ocorrência guarda a pauta')]
    public function testComPauta(): void
    {
        $pauta = new PautaDaAssembleia($this->assembleia, 1, 'Aprovação das contas');
        $this->pautas->method('buscarDaAssembleia')->willReturn($pauta);

        $ocorrencia = $this->caso()->executar(self::TOKEN, 10, 'Sobre a pauta');

        self::assertSame($pauta, $ocorrencia->getPauta());
    }

    #[TestDox('Token que não leva a nenhuma unidade: cédula não encontrada, nada gravado')]
    public function testTokenInexistente(): void
    {
        $this->expectException(CedulaNaoEncontradaException::class);

        try {
            $this->caso()->executar(str_repeat('0', 40), null, 'Texto');
        } finally {
            self::assertSame([], $this->salvas);
        }
    }

    private function assertRecusa(string $mensagem, string $texto, ?int $pauta = null): void
    {
        try {
            $this->caso()->executar(self::TOKEN, $pauta, $texto);
            self::fail('A ocorrência devia ser recusada: ' . $mensagem);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
        self::assertSame([], $this->salvas);
    }

    private function caso(): RegistrarOcorrenciaDaVotacaoUseCase
    {
        $liberacao = new LiberacaoDoVoto($this->assembleia->getTenant());
        $liberacao->liberar();
        $liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $liberacoes->method('findDoTenant')->willReturn($liberacao);
        $tradutor = $this->createMock(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnArgument(0);
        $transacao = new class () implements TransacaoDoVoto {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }

            public function gravar(): void
            {
            }
        };
        $link = new UnidadeDoLink(new TokenDaUnidade('segredo-de-teste'), $this->unidades, $liberacoes);

        return new RegistrarOcorrenciaDaVotacaoUseCase($link, $this->pautas, $this->ocorrencias, $transacao, $this->relogio, $tradutor);
    }
}
