<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\ExpiracaoDoChatIa;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A ordem da retenção do Chat I.A, sem banco: as linhas saem dentro da transação e os ARQUIVOS dos anexos só depois do
 * COMMIT (skill `armazenamento-de-arquivos`). Se o banco falha, nenhum arquivo sai.
 */
#[CoversClass(ExpiracaoDoChatIa::class)]
final class ExpiracaoDoChatIaTest extends TestCase
{
    /** @var list<string> o que aconteceu, na ordem: 'banco:...', 'commit', 'arquivo:<nome>' */
    private array $passos = [];

    private Tenant $tenant;
    private ConversaDeChat $conversa;
    private User $dono;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 7);
        $this->dono = new User();
        $this->conversa = new ConversaDeChat($this->tenant, $this->dono, ModeloDeIa::PADRAO, new \DateTimeImmutable('2025-01-01'));
    }

    private function anexo(string $chave): AnexoDeChat
    {
        return new AnexoDeChat($this->tenant, $this->dono, $this->conversa, $chave, 'foto.png', 'image/png', 8, new \DateTimeImmutable('2025-01-01'));
    }

    /** A transação de mentira: roda o trabalho, depois "confirma"; com `$falhar`, o COMMIT falha. */
    private function transacao(bool $falhar = false): TransacaoDoChat
    {
        $passos = &$this->passos;

        return new class ($passos, $falhar) implements TransacaoDoChat {
            /** @param list<string> $passos */
            public function __construct(private array &$passos, private readonly bool $falhar)
            {
            }

            public function executar(callable $trabalho): mixed
            {
                $resultado = $trabalho();
                if ($this->falhar) {
                    throw new \RuntimeException('COMMIT falhou');
                }
                $this->passos[] = 'commit';

                return $resultado;
            }
        };
    }

    private function remocao(): RemocaoAposTransacao
    {
        $armazenamento = $this->createMock(ArmazenamentoDeArquivos::class);
        $armazenamento->method('existe')->willReturn(true);
        $armazenamento->method('excluir')->willReturnCallback(function (ChaveDeArquivo $chave): void {
            $this->passos[] = 'arquivo:' . $chave->nome;
        });

        return new RemocaoAposTransacao($armazenamento, new NullLogger());
    }

    private function sut(bool $falhar = false): ExpiracaoDoChatIa
    {
        $limite = new \DateTimeImmutable('2025-11-05 00:20:00');
        $mensagens = $this->createMock(MensagemDeChatRepository::class);
        $mensagens->method('conversasComMensagemAntesDe')->with($limite)->willReturn([10, 11]);
        $mensagens->method('apagarAntesDe')->with($limite)->willReturnCallback(function (): int {
            $this->passos[] = 'banco:mensagens';

            return 4;
        });

        $anexos = $this->createMock(AnexoDeChatRepository::class);
        $anexos->method('dasMensagensAntesDe')->with($limite)->willReturn([$this->anexo('a.png')]);
        $anexos->method('apagarDasMensagensAntesDe')->with($limite)->willReturnCallback(function (): int {
            $this->passos[] = 'banco:anexos';

            return 1;
        });
        $anexos->method('dasConversas')->with([11])->willReturn([$this->anexo('b.png')]);

        $conversas = $this->createMock(ConversaDeChatRepository::class);
        $conversas->method('travarVaziasEntre')->with([10, 11])->willReturn([11]);
        $conversas->method('apagarVaziasTravadas')->with([11])->willReturnCallback(function (): int {
            $this->passos[] = 'banco:conversas';

            return 1;
        });

        return new ExpiracaoDoChatIa($mensagens, $anexos, $conversas, $this->transacao($falhar), $this->remocao());
    }

    #[TestDox('apaga anexos, mensagens e a conversa vazia no banco; os arquivos (da fala e o solto da conversa vazia) saem só depois do COMMIT')]
    public function testArquivosSaemDepoisDoCommit(): void
    {
        $resultado = $this->sut()->apagarAntesDe(new \DateTimeImmutable('2025-11-05 00:20:00'));

        self::assertSame(['mensagens' => 4, 'anexos' => 2, 'conversas' => 1], $resultado);
        self::assertSame(
            ['banco:anexos', 'banco:mensagens', 'banco:conversas', 'commit', 'arquivo:a.png', 'arquivo:b.png'],
            $this->passos,
        );
    }

    #[TestDox('se o COMMIT falha, a exceção sobe e nenhum arquivo sai do armazenamento')]
    public function testFalhaNoBancoPreservaOsArquivos(): void
    {
        try {
            $this->sut(falhar: true)->apagarAntesDe(new \DateTimeImmutable('2025-11-05 00:20:00'));
            self::fail('a falha do banco deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame('COMMIT falhou', $e->getMessage());
        }

        self::assertSame([], array_values(array_filter($this->passos, static fn (string $p): bool => str_starts_with($p, 'arquivo:'))));
    }
}
