<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\SolicitarResumoDoPushInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Inteligencia\UseCase\SolicitarResumoDoPushUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Dublê do bus: guarda o que foi despachado e, armado, lança no dispatch (o caminho "fila fora do
 * ar", que não pode virar 500 para o usuário).
 */
final class BusEspiao implements MessageBusInterface
{
    /** @var list<object> */
    public array $despachadas = [];
    public ?\Throwable $falha = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->falha !== null) {
            throw $this->falha;
        }
        $this->despachadas[] = $message;

        return new Envelope($message, $stamps);
    }
}

#[CoversClass(SolicitarResumoDoPushUseCase::class)]
final class SolicitarResumoDoPushUseCaseTest extends TestCase
{
    private const PASTA_ID = 7;

    private Tenant $tenant;
    private User $user;
    private Pasta $pasta;
    private Processo $processo;
    private PastaRepository&MockObject $pastas;
    private PermissionChecker&MockObject $permissoes;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private FonteDeMovimentacoesFalsa $fonte;

    private BusEspiao $bus;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->user = new User();
        DefineId::em($this->user, 9);

        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso('07011345720258070007');
        $this->processo->setClasseProcessual('Procedimento Comum');
        DefineId::em($this->processo, 3);

        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('1234');
        $this->pasta->vincularProcesso($this->processo);
        DefineId::em($this->pasta, self::PASTA_ID);

        $this->pastas = $this->createMock(PastaRepository::class);
        $this->pastas->method('findOneBy')->willReturnCallback(
            fn (array $criterios): ?Pasta => ($criterios['id'] ?? null) === self::PASTA_ID ? $this->pasta : null,
        );

        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessResource')->willReturn(true);
        $this->permissoes->method('canAccessModule')->willReturn(true);

        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $configuracao->atualizar(true, 50, 500, true, $this->user);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);

        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->analises->method('contarDesde')->willReturn(0);

        $this->fonte = new FonteDeMovimentacoesFalsa();
        $this->fonte->publicacoes = [$this->publicacao(11, 'Intime-se a parte autora para réplica.')];

        $this->bus = new BusEspiao();
    }

    private function publicacao(int $id, string $texto): PublicacaoDjen
    {
        $p = new PublicacaoDjen();
        $p->setTenant($this->tenant);
        $p->setDjenId((string) $id);
        $p->setNumeroProcesso('07011345720258070007');
        $p->setSiglaTribunal('TJDFT');
        $p->setTipoComunicacao('Intimação');
        $p->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-01'));
        $p->setTexto($texto);
        DefineId::em($p, $id);

        return $p;
    }

    private function montador(): MontadorDeContextoDoPush
    {
        return new MontadorDeContextoDoPush($this->fonte, $this->configuracoes, new MascaradorDeDadosPessoais());
    }

    private function sut(ProvedorDeLinguagem $provedor = new ProvedorFalso()): SolicitarResumoDoPushUseCase
    {
        $disponibilidade = new DisponibilidadeDeInteligencia($provedor, $this->configuracoes, $this->analises, $this->permissoes, true);

        return new SolicitarResumoDoPushUseCase(
            $this->pastas,
            $this->permissoes,
            $disponibilidade,
            $this->montador(),
            $this->analises,
            // O enfileiramento (dispatch + registro honesto da falha) saiu para um serviço
            // compartilhado com os agentes da pasta; o dublê do bus continua sendo o mesmo.
            new EnfileiradorDeAnalise($this->analises, $this->bus, new NullLogger()),
        );
    }

    private function input(int $pastaId = self::PASTA_ID): SolicitarResumoDoPushInput
    {
        return new SolicitarResumoDoPushInput($pastaId);
    }

    private function analiseExistente(string $hash, bool $concluida): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia($this->tenant, $this->user, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, self::PASTA_ID, 'push-v1', $hash, ['chaves' => ['pub:11']]);
        DefineId::em($a, 100);
        if ($concluida) {
            $a->iniciarProcessamento();
            $a->concluir('Resumo anterior.', [], null, '{}', 'falso', 'falso-1', 1, 1, 1);
        }

        return $a;
    }

    #[TestDox('indisponível (sem provedor) → InteligenciaIndisponivelException com o motivo e NADA persistido nem enfileirado')]
    public function testIndisponivelNaoPersiste(): void
    {
        $this->analises->expects($this->never())->method('salvar');

        try {
            $this->sut(new ProvedorNaoConfigurado())->executar($this->input(), $this->user, $this->tenant);
            self::fail('deveria ter lançado');
        } catch (InteligenciaIndisponivelException $e) {
            self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $e->motivo);
        }

        self::assertSame([], $this->bus->despachadas);
    }

    #[TestDox('pasta inexistente ou de outro escritório → PastaNaoEncontradaException')]
    public function testPastaNaoEncontrada(): void
    {
        $this->expectException(PastaNaoEncontradaException::class);

        $this->sut()->executar($this->input(999), $this->user, $this->tenant);
    }

    #[TestDox('sem permissão de ver a pasta → AccessDeniedException, antes de olhar a disponibilidade')]
    public function testSemPermissaoNaPasta(): void
    {
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturn(false);
        $this->permissoes = $permissoes;

        $this->expectException(AccessDeniedException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }

    #[TestDox('já existe análise pendente/processando para a pasta → devolve a mesma, sem novo dispatch')]
    public function testPendenteExistenteEhDevolvida(): void
    {
        $pendente = $this->analiseExistente(str_repeat('p', 64), concluida: false);
        $this->analises->method('findPendenteDoAlvo')->willReturn($pendente);
        $this->analises->expects($this->never())->method('salvar');

        $saida = $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertSame(100, $saida->id);
        self::assertSame('pendente', $saida->status);
        self::assertNotNull($saida->aviso);
        self::assertSame([], $this->bus->despachadas);
    }

    #[TestDox('contexto igual ao da última concluída → devolve a última com aviso "nada novo", sem gastar cota')]
    public function testNadaNovoDesdeAUltima(): void
    {
        $hashAtual = $this->montador()->para($this->tenant, $this->pasta)->hash;
        $ultima = $this->analiseExistente($hashAtual, concluida: true);
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn($ultima);
        $this->analises->expects($this->never())->method('salvar');

        $saida = $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertSame(100, $saida->id);
        self::assertSame('concluida', $saida->status);
        self::assertSame('Nada novo desde a última análise.', $saida->aviso);
        self::assertSame([], $this->bus->despachadas);
    }

    #[TestDox('caminho feliz: linha pendente com hash/resumo do contexto e UMA mensagem com ids escalares')]
    public function testCaminhoFeliz(): void
    {
        $ultima = $this->analiseExistente(str_repeat('v', 64), concluida: true); // hash diferente → há algo novo
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn($ultima);

        $persistida = null;
        $this->analises->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (AnaliseDeInteligencia $a, bool $flush) use (&$persistida): void {
                self::assertTrue($flush);
                $persistida = $a;
                DefineId::em($a, 555);
            });

        $saida = $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertInstanceOf(AnaliseDeInteligencia::class, $persistida);
        self::assertSame(StatusDaAnalise::Pendente, $persistida->getStatus());
        self::assertSame($this->tenant, $persistida->getTenant());
        self::assertSame($this->user, $persistida->getSolicitante());
        self::assertSame(self::PASTA_ID, $persistida->getAlvoId());
        self::assertSame('push-v1', $persistida->getVersaoDoPrompt());
        self::assertSame(['pub:11'], $persistida->getChavesAnalisadas());
        self::assertSame(1, $persistida->getContextoResumo()['total']);
        self::assertSame(0, $persistida->getContextoResumo()['novas'], 'pub:11 já constava na última concluída');

        self::assertSame(555, $saida->id);
        self::assertSame('pendente', $saida->status);
        self::assertNull($saida->aviso);

        self::assertCount(1, $this->bus->despachadas);
        $mensagem = $this->bus->despachadas[0];
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
        self::assertSame(555, $mensagem->analiseId);
        self::assertSame(42, $mensagem->tenantId);
    }

    #[TestDox('dispatch que lança com o EM aberto → a linha vira falhou e o chamador NÃO recebe exceção')]
    public function testDispatchQueFalha(): void
    {
        $this->bus->falha = new \RuntimeException('fila fora do ar');
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $this->analises->method('emAberto')->willReturn(true);

        $gravacoes = [];
        $this->analises->expects($this->exactly(2))->method('salvar')
            ->willReturnCallback(static function (AnaliseDeInteligencia $a) use (&$gravacoes): void {
                $gravacoes[] = $a->getStatus();
            });

        $saida = $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertSame([StatusDaAnalise::Pendente, StatusDaAnalise::Falhou], $gravacoes);
        self::assertSame('falhou', $saida->status);
        self::assertSame([], $this->bus->despachadas);
    }

    #[TestDox('dispatch que lança com o EM FECHADO → nada mais é persistido e sobe FilaIndisponivelException (503), não um 500')]
    public function testDispatchQueFalhaComEmFechado(): void
    {
        $this->bus->falha = new \RuntimeException('conexão perdida ao gravar na fila');
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $this->analises->method('emAberto')->willReturn(false);

        // Só a gravação da pendente, ANTES do dispatch; depois do erro, nenhum flush em EM fechado.
        $this->analises->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (AnaliseDeInteligencia $a): void {
                self::assertSame(StatusDaAnalise::Pendente, $a->getStatus());
                DefineId::em($a, 77);
            });

        try {
            $this->sut()->executar($this->input(), $this->user, $this->tenant);
            self::fail('deveria ter lançado FilaIndisponivelException');
        } catch (FilaIndisponivelException $e) {
            self::assertSame(77, $e->analiseId);
            self::assertSame('fila_indisponivel', $e->motivo());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    #[TestDox('dispatch que lança e a gravação da falha também lança → FilaIndisponivelException, sem 500')]
    public function testDispatchQueFalhaERegistroDaFalhaTambemFalha(): void
    {
        $this->bus->falha = new \RuntimeException('fila fora do ar');
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $this->analises->method('emAberto')->willReturn(true);

        $chamadas = 0;
        $this->analises->expects($this->exactly(2))->method('salvar')
            ->willReturnCallback(static function (AnaliseDeInteligencia $a) use (&$chamadas): void {
                if (++$chamadas === 2) {
                    throw new \RuntimeException('banco recusou o UPDATE');
                }
            });

        $this->expectException(FilaIndisponivelException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }

    #[TestDox('pasta sem movimentação → ContextoVazioException, nada persistido')]
    public function testContextoVazio(): void
    {
        $this->fonte->publicacoes = [];
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->expects($this->never())->method('salvar');

        $this->expectException(ContextoVazioException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }

    #[TestDox('processo em segredo de justiça → ContextoBloqueadoException, nada persistido')]
    public function testSigiloBloqueia(): void
    {
        $this->processo->setNivelSigilo(1);
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->expects($this->never())->method('salvar');

        $this->expectException(ContextoBloqueadoException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }
}
