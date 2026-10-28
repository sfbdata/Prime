<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\TituloAutomaticoDoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\ChatIa\UseCase\TransmitirRespostaDoChatUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\UsoDaTransmissao;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorDeLinguagemEmFluxo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/** A transmissão sem banco nem rede: o provedor é um gerador de mentira, o repositório guarda o que foi salvo. */
#[CoversClass(TransmitirRespostaDoChatUseCase::class)]
final class TransmitirRespostaDoChatTest extends TestCase
{
    /** @var list<MensagemDeChat> */
    private array $salvas = [];

    /** @param list<string> $trechos */
    private function caso(array $trechos): TransmitirRespostaDoChatUseCase
    {
        $provedor = new class ($trechos) implements ProvedorDeLinguagemEmFluxo {
            /** @param list<string> $trechos */
            public function __construct(private readonly array $trechos)
            {
            }

            public function transmitir(PedidoDeLinguagem $pedido): \Generator
            {
                foreach ($this->trechos as $t) {
                    yield $t;
                }

                return new UsoDaTransmissao('m', 100, 50);
            }
        };
        $this->salvas = [];
        $repo = $this->createStub(MensagemDeChatRepository::class);
        $repo->method('salvar')->willReturnCallback(function (MensagemDeChat $m): void {
            $this->salvas[] = $m;
        });
        $transacao = new class () implements TransacaoDoChat {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }
        };
        $titulo = new TituloAutomaticoDoChat($this->createStub(ProvedorDeLinguagem::class));

        return new TransmitirRespostaDoChatUseCase($provedor, $repo, $titulo, $transacao, new MockClock('2026-10-28 15:00:00'), new NullLogger());
    }

    /** @param list<AnexoDeDocumento> $anexos */
    private function preparada(array $anexos = [], string $sistema = '', string $pergunta = ''): RespostaPreparada
    {
        $tenant = new Tenant();
        $conversa = new ConversaDeChat($tenant, new User(), ModeloDeIa::PADRAO, new \DateTimeImmutable('2026-10-28 10:00:00'));
        $pedido = new PedidoDeLinguagem($sistema, [['papel' => 'usuario', 'conteudo' => $pergunta]], 500, 0.2, false, 'chat-livre-v1', $anexos);

        return new RespostaPreparada($conversa, $tenant, ModeloDeIa::PADRAO, $pedido, $pergunta, false);
    }

    #[TestDox('Cliente que saiu: grava o parcial como interrompido com tokens ESTIMADOS e termina sem evento fim')]
    public function testAbortoGravaEstimativa(): void
    {
        $caso = $this->caso(['abcdefg', 'hijklmn', 'nunca lido']);
        $chamadas = 0;

        $eventos = iterator_to_array($caso->executar($this->preparada([], str_repeat('s', 70), str_repeat('p', 35)), static function () use (&$chamadas): bool {
            return ++$chamadas >= 2;
        }), false);

        self::assertSame(['delta', 'delta'], array_column($eventos, 'evento'));
        self::assertCount(1, $this->salvas);
        $m = $this->salvas[0];
        self::assertTrue($m->foiInterrompida());
        self::assertSame('abcdefghijklmn', $m->getConteudo());
        self::assertSame(4, $m->getTokensSaida(), 'ceil(14 / 3,5)');
        self::assertSame(30, $m->getTokensEntrada(), 'ceil(105 / 3,5)');
    }

    #[TestDox('No aborto a entrada estimada soma os anexos: bytes / 3 por arquivo, no máximo 30 mil tokens cada')]
    public function testAbortoSomaOsAnexos(): void
    {
        $pequeno = new AnexoDeDocumento(1, 'a.pdf', AnexoDeDocumento::PDF, str_repeat('x', 300));
        $enorme = new AnexoDeDocumento(2, 'b.png', 'image/png', str_repeat('x', 200_000));
        $caso = $this->caso(['ab']);

        iterator_to_array($caso->executar($this->preparada([$pequeno, $enorme]), static fn (): bool => true), false);

        self::assertSame(100 + 30_000, $this->salvas[0]->getTokensEntrada(), '300 / 3 = 100; 200 mil / 3 passa do teto de 30 mil');
    }

    #[TestDox('Fim normal: grava com os tokens reais do provedor, sem marcar interrompida')]
    public function testFimNormal(): void
    {
        $caso = $this->caso(['Olá']);

        $eventos = iterator_to_array($caso->executar($this->preparada(), static fn (): bool => false), false);

        self::assertSame(['delta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame(100, $this->salvas[0]->getTokensEntrada());
        self::assertSame(50, $this->salvas[0]->getTokensSaida());
        self::assertFalse($this->salvas[0]->foiInterrompida());
    }
}
