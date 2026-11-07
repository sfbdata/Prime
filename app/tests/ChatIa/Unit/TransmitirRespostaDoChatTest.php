<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\ConsultasDoChat;
use App\ChatIa\Service\FerramentasDaConsulta;
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
use App\Inteligencia\Exception\FalhaDoProvedorException;
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

    /** O provedor de mentira do caso: guarda, em `pedidos`, o que chegou a ele, em ordem. */
    private ProvedorDeLinguagemEmFluxo $provedor;

    /**
     * @param list<string>              $trechos  o que a primeira chamada escreve
     * @param list<ChamadaDeFerramenta> $chamadas as ferramentas que a primeira chamada pede
     * @param list<array{trechos: list<string>, chamadas?: list<ChamadaDeFerramenta>, entrada?: int, saida?: int, falha?: bool}> $seguintes
     *        as chamadas seguintes (a segunda, depois de uma consulta)
     */
    private function caso(array $trechos, array $chamadas = [], bool $podeProporCliente = true, array $seguintes = []): TransmitirRespostaDoChatUseCase
    {
        $rodadas = [['trechos' => $trechos, 'chamadas' => $chamadas], ...$seguintes];
        $provedor = new class ($rodadas) implements ProvedorDeLinguagemEmFluxo {
            /** @var list<PedidoDeLinguagem> */
            public array $pedidos = [];

            /** @param list<array{trechos: list<string>, chamadas?: list<ChamadaDeFerramenta>, entrada?: int, saida?: int, falha?: bool}> $rodadas */
            public function __construct(private array $rodadas)
            {
            }

            public function transmitir(PedidoDeLinguagem $pedido): \Generator
            {
                $this->pedidos[] = $pedido;
                $rodada = array_shift($this->rodadas) ?? throw new \LogicException('chamada a mais ao provedor');
                foreach ($rodada['trechos'] as $t) {
                    yield $t;
                }
                if ($rodada['falha'] ?? false) {
                    throw new FalhaDoProvedorException('529 overloaded_error', true);
                }

                return new UsoDaTransmissao('m', $rodada['entrada'] ?? 100, $rodada['saida'] ?? 50, null, false, $rodada['chamadas'] ?? []);
            }
        };
        $this->provedor = $provedor;
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

        return new TransmitirRespostaDoChatUseCase($provedor, $repo, $titulo, $registrador, $propostas, $cartao, $transacao, new MockClock('2026-10-28 15:00:00'), new NullLogger(), self::consultasDeMentira());
    }

    /** A consulta de mentira: devolve o mesmo pedido com um bloco fixo antes da pergunta, CONSULTA FEITA no sistema e sem ferramentas. */
    private static function consultasDeMentira(): ConsultasDoChat
    {
        return new class () implements ConsultasDoChat {
            public function ehConsulta(ChamadaDeFerramenta $chamada): bool
            {
                return $chamada->nome === FerramentasDaConsulta::PRAZOS_E_METAS;
            }

            public function pedidoComResultado(RespostaPreparada $preparada, array $chamadas): PedidoDeLinguagem
            {
                $p = $preparada->pedido;
                $ultima = ['papel' => 'usuario', 'conteudo' => "<prazos_e_metas>\n• resultado\n</prazos_e_metas>\n\n" . $p->mensagens[\count($p->mensagens) - 1]['conteudo']];

                return new PedidoDeLinguagem($p->sistema . "\nCONSULTA FEITA", [...\array_slice($p->mensagens, 0, -1), $ultima], $p->maxTokens, $p->temperatura, $p->exigeJson, $p->rotuloDeUso, $p->anexos, $p->modelo, []);
            }
        };
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

    private static function consultaDoModelo(string $periodo = 'hoje'): ChamadaDeFerramenta
    {
        return new ChamadaDeFerramenta(FerramentasDaConsulta::PRAZOS_E_METAS, ['periodo' => $periodo]);
    }

    /** @return list<PedidoDeLinguagem> */
    private function pedidosAoProvedor(): array
    {
        return $this->provedor->pedidos; // @phpstan-ignore property.notFound (a classe anônima do caso)
    }

    #[TestDox('Consulta: a segunda chamada leva o resultado antes da pergunta e nenhuma ferramenta; o texto das duas vira uma resposta')]
    public function testConsultaFazSegundaChamadaSemFerramentas(): void
    {
        $caso = $this->caso(['Vou olhar.'], [self::consultaDoModelo()], seguintes: [['trechos' => ['Hoje vence ', 'uma meta.'], 'entrada' => 300, 'saida' => 20]]);

        $eventos = iterator_to_array($caso->executar($this->preparada([], 'SISTEMA', 'tem prazo hoje?'), static fn (): bool => false), false);

        $pedidos = $this->pedidosAoProvedor();
        self::assertCount(2, $pedidos, 'uma chamada a mais, nunca um laço');
        self::assertSame([], $pedidos[1]->ferramentas);
        self::assertStringEndsWith('CONSULTA FEITA', $pedidos[1]->sistema);
        self::assertStringStartsWith('<prazos_e_metas>', $pedidos[1]->mensagens[0]['conteudo']);
        self::assertStringEndsWith('tem prazo hoje?', $pedidos[1]->mensagens[0]['conteudo']);
        self::assertSame(['delta', 'delta', 'delta', 'delta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame("Vou olhar.\n\nHoje vence uma meta.", $this->salvas[0]->getConteudo());
        self::assertFalse($this->salvas[0]->foiInterrompida());
    }

    #[TestDox('Consulta: os tokens gravados são a soma das duas chamadas (o teto diário vê as duas)')]
    public function testConsultaSomaOsTokens(): void
    {
        $caso = $this->caso(['Vou olhar.'], [self::consultaDoModelo()], seguintes: [['trechos' => ['Pronto.'], 'entrada' => 300, 'saida' => 20]]);

        $eventos = iterator_to_array($caso->executar($this->preparada(), static fn (): bool => false), false);

        self::assertSame(400, $this->salvas[0]->getTokensEntrada());
        self::assertSame(70, $this->salvas[0]->getTokensSaida());
        $fim = $eventos[\count($eventos) - 1];
        self::assertSame(['evento' => 'fim', 'tokensEntrada' => 400, 'tokensSaida' => 70], ['evento' => $fim['evento'], 'tokensEntrada' => $fim['dados']['tokensEntrada'], 'tokensSaida' => $fim['dados']['tokensSaida']]);
    }

    #[TestDox('Consulta sem texto na primeira chamada: sem linha em branco no começo e sem o aviso de cadastro')]
    public function testConsultaSemTextoNaPrimeira(): void
    {
        [$eventos, $texto] = $this->responder($this->caso([], [self::consultaDoModelo()], seguintes: [['trechos' => ['Nada vence hoje.']]]));

        self::assertSame(['delta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame('Nada vence hoje.', $texto);
        self::assertStringNotContainsString('Não consegui montar o cadastro', $texto);
        self::assertSame([], $this->propostasSalvas);
    }

    #[TestDox('Consulta junto com proposta: a proposta da primeira chamada continua virando cartão')]
    public function testConsultaComProposta(): void
    {
        $caso = $this->caso(['Ok.'], [self::consultaDoModelo(), $this->clienteDoModelo()], seguintes: [['trechos' => ['Sem prazos hoje.']]]);

        [$eventos, $texto] = $this->responder($caso);

        self::assertSame(['delta', 'delta', 'delta', 'proposta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame("Ok.\n\nSem prazos hoje.", $texto);
        self::assertCount(1, $this->propostasSalvas);
    }

    #[TestDox('Consulta: pedido de ferramenta na SEGUNDA chamada é ignorado (não há terceira chamada nem cartão)')]
    public function testSegundaChamadaNaoAbreOutraConsulta(): void
    {
        $caso = $this->caso(['Ok.'], [self::consultaDoModelo()], seguintes: [['trechos' => ['Pronto.'], 'chamadas' => [self::consultaDoModelo('atrasadas'), $this->clienteDoModelo()]]]);

        [, $texto] = $this->responder($caso);

        self::assertCount(2, $this->pedidosAoProvedor());
        self::assertSame("Ok.\n\nPronto.", $texto);
        self::assertSame([], $this->propostasSalvas, 'as propostas são só as da primeira chamada');
    }

    #[TestDox('Consulta: cliente que sai na segunda chamada grava o parcial com a primeira real e a segunda estimada')]
    public function testAbortoNaSegundaChamada(): void
    {
        $caso = $this->caso(['abc'], [self::consultaDoModelo()], seguintes: [['trechos' => ['defghij', 'nunca lido']]]);
        $chamadas = 0;

        iterator_to_array($caso->executar($this->preparada([], str_repeat('s', 70), str_repeat('p', 35)), static function () use (&$chamadas): bool {
            return ++$chamadas >= 2; // depois de cada trecho do provedor: abc, defghij
        }), false);

        $m = $this->salvas[0];
        self::assertTrue($m->foiInterrompida());
        self::assertSame("abc\n\ndefghij", $m->getConteudo());
        self::assertSame(50 + 2, $m->getTokensSaida(), 'a primeira real (50) + ceil(7 / 3,5) da segunda');
        $segundo = $this->pedidosAoProvedor()[1];
        $entradaDaSegunda = (int) ceil(\strlen($segundo->sistema . $segundo->mensagens[0]['conteudo']) / 3.5);
        self::assertSame(100 + $entradaDaSegunda, $m->getTokensEntrada());
    }

    #[TestDox('Consulta: falha do provedor na segunda chamada grava o que chegou e devolve o erro')]
    public function testFalhaNaSegundaChamada(): void
    {
        $caso = $this->caso(['Vou olhar.'], [self::consultaDoModelo()], seguintes: [['trechos' => ['Parcial'], 'falha' => true]]);

        $eventos = iterator_to_array($caso->executar($this->preparada(), static fn (): bool => false), false);

        self::assertSame('erro', $eventos[\count($eventos) - 1]['evento']);
        self::assertSame('falha_do_provedor', $eventos[\count($eventos) - 1]['dados']['codigo']);
        self::assertSame("Vou olhar.\n\nParcial", $this->salvas[0]->getConteudo());
        self::assertTrue($this->salvas[0]->foiInterrompida());
        self::assertSame(50 + 2, $this->salvas[0]->getTokensSaida(), 'a primeira real + ceil(7 / 3,5)');
    }

    #[TestDox('Consulta: a segunda falha sem texto e a primeira não escreveu nada; o custo real da primeira fica no teto do dia')]
    public function testFalhaSemTextoGuardaOCustoDaPrimeira(): void
    {
        $caso = $this->caso([], [self::consultaDoModelo()], seguintes: [['trechos' => [], 'falha' => true]]);

        $eventos = iterator_to_array($caso->executar($this->preparada(), static fn (): bool => false), false);

        self::assertSame(['erro'], array_column($eventos, 'evento'));
        self::assertCount(1, $this->salvas);
        $registro = $this->salvas[0];
        self::assertTrue($registro->foiSubstituida(), 'registro de custo: fora da conversa e do histórico');
        self::assertSame('', $registro->getConteudo());
        self::assertSame([100, 50], [$registro->getTokensEntrada(), $registro->getTokensSaida()], 'os tokens reais da primeira');
    }

    #[TestDox('Consulta: as duas chamadas sem texto (resposta_vazia) guardam o custo real das duas no teto do dia')]
    public function testRespostaVaziaGuardaOCusto(): void
    {
        $caso = $this->caso([], [self::consultaDoModelo()], seguintes: [['trechos' => [], 'entrada' => 300, 'saida' => 2]]);

        $eventos = iterator_to_array($caso->executar($this->preparada(), static fn (): bool => false), false);

        self::assertSame(['erro'], array_column($eventos, 'evento'));
        self::assertSame('resposta_vazia', $eventos[0]['dados']['codigo']);
        self::assertCount(1, $this->salvas);
        self::assertTrue($this->salvas[0]->foiSubstituida());
        self::assertSame([400, 52], [$this->salvas[0]->getTokensEntrada(), $this->salvas[0]->getTokensSaida()]);
    }

    #[TestDox('Sem consulta, resposta vazia continua sem registro, como antes')]
    public function testRespostaVaziaSemConsultaNaoGrava(): void
    {
        $eventos = iterator_to_array($this->caso([])->executar($this->preparada(), static fn (): bool => false), false);

        self::assertSame(['erro'], array_column($eventos, 'evento'));
        self::assertSame([], $this->salvas);
    }
}
