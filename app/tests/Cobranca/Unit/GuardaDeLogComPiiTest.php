<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 🔴 INV-Q10 — a guarda que impede um comando do espelho de despejar CPF, e-mail e telefone.
 *
 * O incidente que a originou: uma carga rodou com `APP_ENV=prod` **sem** `APP_DEBUG=0`, e o log do
 * Doctrine imprimiu os parâmetros de cada INSERT — nome, CPF, e-mail e telefone de condômino — numa
 * saída que é rotineiramente colada em chat.
 */
#[CoversClass(GuardaDeLogComPii::class)]
final class GuardaDeLogComPiiTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool, 1: string, 2: bool}>
     */
    public static function ambientes(): iterable
    {
        //                                    debug, ambiente, bloqueia?
        yield 'prod com debug ligado (o incidente)' => [true, 'prod', true];
        yield 'dev' => [true, 'dev', true];
        yield 'prod com debug desligado' => [false, 'prod', false];
        // O `test` é exceção deliberada: saída em buffer de asserção, DAMA revertendo, dados
        // fabricados pelo Foundry. Guardar por `debug` puro derrubaria toda a suíte funcional de
        // comandos, que roda com kernel.debug = true.
        yield 'test com debug ligado' => [true, 'test', false];
    }

    #[DataProvider('ambientes')]
    #[TestDox('A guarda dispara com debug ligado FORA do ambiente de teste')]
    public function testQuandoDispara(bool $debug, string $ambiente, bool $esperado): void
    {
        self::assertSame($esperado, (new GuardaDeLogComPii($debug, $ambiente))->vaiDespejarLog());
    }

    /**
     * **Reintrodução provada:** removendo a chamada a `bloqueia()` do `execute()` de qualquer um dos
     * nove comandos, o teste de arquitetura irmão fica vermelho; trocando o `&& !$aceitou` por
     * `|| !$aceitou` aqui, este fica.
     */
    #[TestDox('Bloqueia imprimindo o comando certo, e diz que nada foi lido nem gravado')]
    public function testMensagemDizOQueFazer(): void
    {
        $saida = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $saida);

        $bloqueou = (new GuardaDeLogComPii(true, 'prod'))
            ->bloqueia($io, aceitouExplicitamente: false, comando: 'app:cobranca:espelho:carregar');

        self::assertTrue($bloqueou);

        $texto = preg_replace('/\s+/', ' ', $saida->fetch()) ?? '';

        self::assertStringContainsString('CPF, e-mail e telefone', $texto);
        self::assertStringContainsString('Nada foi lido nem gravado', $texto);
        self::assertStringContainsString('APP_DEBUG=0', $texto, 'a mensagem entrega o comando pronto');
        self::assertStringContainsString('app:cobranca:espelho:carregar', $texto);
        self::assertStringContainsString('--aceito-log-com-pii', $texto, 'e diz qual é o escape consciente');
    }

    #[TestDox('O escape explícito passa — e é o único jeito de passar com debug ligado')]
    public function testEscapeExplicito(): void
    {
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $guarda = new GuardaDeLogComPii(true, 'prod');

        self::assertFalse($guarda->bloqueia($io, aceitouExplicitamente: true, comando: 'x'));
        self::assertTrue($guarda->bloqueia($io, aceitouExplicitamente: false, comando: 'x'));
    }

    /**
     * 🔴 O fio inteiro, comando → guarda → código 69 — o que faltava.
     *
     * `GuardaDeLogComPiiTest` cobria a classe, e o teste de arquitetura cobria a *forma* da chamada.
     * Nenhum dos dois provava que o comando **de fato sai com 69**: em `env=test` a guarda nunca
     * dispara (e não pode disparar, senão a suíte funcional inteira cai).
     *
     * Aqui o comando é montado à mão com uma guarda de produção — é o único jeito de exercitar o
     * caminho sem mentir sobre o ambiente.
     */
    #[TestDox('🔴 O comando REALMENTE sai com 69 quando a guarda bloqueia')]
    public function testComandoSaiCom69(): void
    {
        $comando = new class(new GuardaDeLogComPii(debug: true, ambiente: 'prod')) extends Command {
            public function __construct(private readonly GuardaDeLogComPii $guardaDeLog)
            {
                parent::__construct('teste:fio-da-guarda');
            }

            protected function configure(): void
            {
                $this->addOption('aceito-log-com-pii', null, InputOption::VALUE_NONE);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);

                if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'teste')) {
                    return GuardaDeLogComPii::LOG_COM_PII;
                }

                $io->success('chegou no corpo do comando');

                return Command::SUCCESS;
            }
        };

        $tester = new CommandTester($comando);

        self::assertSame(GuardaDeLogComPii::LOG_COM_PII, $tester->execute([]));
        self::assertStringNotContainsString(
            'chegou no corpo do comando',
            $tester->getDisplay(),
            'a guarda tem de interromper ANTES de qualquer leitura',
        );

        // E o escape explícito atravessa até o corpo.
        self::assertSame(Command::SUCCESS, $tester->execute(['--aceito-log-com-pii' => true]));
        self::assertStringContainsString('chegou no corpo do comando', $tester->getDisplay());
    }

    #[TestDox('O código de saída não colide com os dos comandos irmãos')]
    public function testCodigoDeSaidaProprio(): void
    {
        // `1` é exceção não capturada, `2` é Command::INVALID, e `65`–`68` já têm significados
        // diferentes em `espelho:encargos` e na reconciliação. Um wrapper que leia código sem saber
        // qual comando rodou já é frágil; colidir tornaria isso indistinguível.
        self::assertSame(69, GuardaDeLogComPii::LOG_COM_PII);
        self::assertNotContains(GuardaDeLogComPii::LOG_COM_PII, [0, 1, 2, 64, 65, 66, 67, 68]);
    }
}
