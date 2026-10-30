<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\GuardaDasPropostas;
use App\ChatIa\Service\InterpretadorDaChamadaDeCadastro;
use App\ChatIa\Service\MontadorDoCartaoDaProposta;
use App\ChatIa\Service\RegistradorDasPropostas;
use App\ChatIa\Service\TituloAutomaticoDoChat;
use App\ChatIa\Service\ValidadorDaProposta;
use App\ChatIa\Service\TransacaoDoChat;
use App\ChatIa\UseCase\TransmitirRespostaDoChatUseCase;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Cliente\Service\CamposDoCadastro;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\DTO\ChamadaDeFerramenta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\UsoDaTransmissao;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorDeLinguagemEmFluxo;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
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

    /** @var list<PropostaDeCadastro> */
    private array $propostasSalvas = [];

    /**
     * @param list<string>              $trechos
     * @param list<ChamadaDeFerramenta> $chamadas
     */
    private function caso(array $trechos, array $chamadas = [], bool $podeProporCliente = true): TransmitirRespostaDoChatUseCase
    {
        $provedor = new class ($trechos, $chamadas) implements ProvedorDeLinguagemEmFluxo {
            /**
             * @param list<string>              $trechos
             * @param list<ChamadaDeFerramenta> $chamadas
             */
            public function __construct(private readonly array $trechos, private readonly array $chamadas)
            {
            }

            public function transmitir(PedidoDeLinguagem $pedido): \Generator
            {
                foreach ($this->trechos as $t) {
                    yield $t;
                }

                return new UsoDaTransmissao('m', 100, 50, null, false, $this->chamadas);
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
        $this->propostasSalvas = [];
        $propostas = $this->createStub(PropostaDeCadastroRepository::class);
        $propostas->method('salvar')->willReturnCallback(function (PropostaDeCadastro $p): void {
            $this->propostasSalvas[] = $p;
        });
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('hasPermission')->willReturn($podeProporCliente);
        $permissoes->method('canAccessModule')->willReturn(true);
        $registrador = new RegistradorDasPropostas(new InterpretadorDaChamadaDeCadastro(), new GuardaDasPropostas($permissoes));
        $cartao = new MontadorDoCartaoDaProposta(
            new ValidadorDaProposta(new CamposDoCadastro($this->createStub(ClientePFRepository::class), $this->createStub(ClientePJRepository::class))),
            $this->createStub(PastaRepository::class),
        );

        return new TransmitirRespostaDoChatUseCase($provedor, $repo, $titulo, $registrador, $propostas, $cartao, $transacao, new MockClock('2026-10-28 15:00:00'), new NullLogger());
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

    /** @return array{0: list<array{evento: string, dados: array<string, mixed>}>, 1: string} os eventos e o texto gravado */
    private function responder(TransmitirRespostaDoChatUseCase $caso): array
    {
        $eventos = iterator_to_array($caso->executar($this->preparada(), static fn (): bool => false), false);

        return [$eventos, $this->salvas[0]->getConteudo()];
    }

    private function clienteDoModelo(string $nome = 'Maria da Silva'): ChamadaDeFerramenta
    {
        return new ChamadaDeFerramenta('propor_cliente', ['tipo' => 'pf', 'campos' => ['nome' => $nome, 'cpf' => '529.982.247-25']]);
    }

    #[TestDox('Tool use: grava a proposta pendente ligada à resposta e emite `proposta` com o cartão antes do `fim`')]
    public function testChamadaViraProposta(): void
    {
        [$eventos, $texto] = $this->responder($this->caso(['Vou propor o cadastro.'], [$this->clienteDoModelo()]));

        self::assertSame(['delta', 'proposta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame('Vou propor o cadastro.', $texto);
        self::assertCount(1, $this->propostasSalvas);
        self::assertSame($this->salvas[0], $this->propostasSalvas[0]->getMensagem());
        self::assertTrue($this->propostasSalvas[0]->estaPendente());
        $cartao = $eventos[1]['dados'];
        self::assertSame('cliente', $cartao['tipo']);
        self::assertSame('pendente', $cartao['estado']);
        self::assertSame([['chave' => 'nome', 'rotulo' => 'Nome', 'valor' => 'Maria da Silva'], ['chave' => 'cpf', 'rotulo' => 'CPF', 'valor' => '529.982.247-25']], $cartao['campos']);
    }

    #[TestDox('Sem texto do modelo, a resposta ganha a frase padrão (também enviada como delta); o evento `fim` continua')]
    public function testSemTextoGanhaFrasePadrao(): void
    {
        [$eventos, $texto] = $this->responder($this->caso([], [$this->clienteDoModelo()]));

        self::assertSame(['delta', 'proposta', 'fim'], array_column($eventos, 'evento'));
        self::assertStringStartsWith('Montei a proposta abaixo', $texto);
        self::assertSame($texto, $eventos[0]['dados']['t']);
    }

    #[TestDox('Chamada inválida (chave fora da lista) é descartada: nenhuma proposta e o texto avisa que não deu para montar')]
    public function testChamadaInvalidaEhDescartada(): void
    {
        $invalida = new ChamadaDeFerramenta('propor_cliente', ['tipo' => 'pf', 'campos' => ['nome' => 'Ana', 'senha' => 'x']]);

        [$eventos, $texto] = $this->responder($this->caso(['Ok.'], [$invalida]));

        self::assertSame([], $this->propostasSalvas);
        self::assertSame(['delta', 'delta', 'fim'], array_column($eventos, 'evento'));
        self::assertStringContainsString('Não consegui montar o cadastro', $texto);
        self::assertStringStartsWith('Ok.', $texto, 'o texto do modelo fica como veio');
    }

    #[TestDox('Ferramenta que a pessoa não pode usar é descartada mesmo se o modelo a chamar')]
    public function testSemPermissaoDescarta(): void
    {
        [, $texto] = $this->responder($this->caso(['Ok.'], [$this->clienteDoModelo()], podeProporCliente: false));

        self::assertSame([], $this->propostasSalvas);
        self::assertStringContainsString('Não consegui montar o cadastro', $texto);
    }

    #[TestDox('No máximo uma proposta por tipo e duas por resposta: a repetida e as de fora do teto somem')]
    public function testUmaPorTipoEDuasNoMaximo(): void
    {
        $pasta = new ChamadaDeFerramenta('propor_pasta', ['nome_cliente' => 'Maria', 'nome_acao' => 'Cobrança']);
        $chamadas = [$this->clienteDoModelo(), $this->clienteDoModelo('Outro Nome'), $pasta, $pasta];

        [$eventos] = $this->responder($this->caso(['Ok.'], $chamadas));

        self::assertSame(['delta', 'proposta', 'proposta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame(['cliente', 'pasta'], array_map(static fn (PropostaDeCadastro $p): string => $p->getTipo()->value, $this->propostasSalvas));
        self::assertSame('Maria da Silva', $eventos[1]['dados']['campos'][0]['valor'], 'vale a primeira de cada tipo');
    }
}
