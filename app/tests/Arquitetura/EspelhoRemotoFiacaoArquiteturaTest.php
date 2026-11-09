<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Pasta\Command\CalcularHashDosDocumentosCommand;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\EspelhoRemoto\Acervo\CarregadorDoAcervo;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Shared\EspelhoRemoto\Replica\ReplicadorDeArquivos;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Quem MEDE o disco para o espelho recebe o disco local direto, sem cota e sem o decorador (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md`, Etapa A). Pelo decorador, um arquivo que saiu do disco
 * seria respondido pelo R2: a reconciliação não veria a ausência, e a réplica e a carga mediriam o
 * cache em vez do disco.
 */
#[CoversNothing]
final class EspelhoRemotoFiacaoArquiteturaTest extends KernelTestCase
{
    /** Os serviços do espelho que recebem armazenamento, e o nome da propriedade. */
    private const QUEM_MEDE_O_DISCO = [
        ReplicadorDeArquivos::class   => 'local',
        ReconciliadorDoEspelho::class => 'local',
        CarregadorDoAcervo::class     => 'local',
    ];

    #[TestDox('réplica, reconciliação e carga do acervo recebem o ArmazenamentoLocal, não a cadeia cota, espelho, disco')]
    public function testQuemMedeRecebeOLocal(): void
    {
        self::bootKernel();

        foreach (self::QUEM_MEDE_O_DISCO as $classe => $propriedade) {
            $servico = static::getContainer()->get($classe);
            $valor   = (new \ReflectionProperty($classe, $propriedade))->getValue($servico);

            self::assertInstanceOf(ArmazenamentoLocal::class, $valor, $classe);
        }
    }

    /** Fora do espelho, quem também mede o disco do acervo inteiro (§5.0, condição 3). */
    private const QUEM_MEDE_O_DISCO_FORA_DO_ESPELHO = [
        CalcularHashDosDocumentosCommand::class => ['armazenamento'],
    ];

    #[TestDox('o cálculo de hash dos documentos lê o ArmazenamentoLocal, sem leitura pelo R2')]
    public function testCalculoDeHashRecebeOLocal(): void
    {
        self::bootKernel();

        foreach (self::QUEM_MEDE_O_DISCO_FORA_DO_ESPELHO as $classe => $propriedades) {
            $servico = static::getContainer()->get($classe);
            foreach ($propriedades as $propriedade) {
                self::assertInstanceOf(ArmazenamentoLocal::class, (new \ReflectionProperty($classe, $propriedade))->getValue($servico), $classe . '::$' . $propriedade);
            }
        }
    }

    /**
     * A exceção da §5.0, só neste comando: o `--paginas` precisa do PDF inteiro e o liberado só existe
     * no R2; o hash do liberado sai da prova (`metadados`), sem baixar. O download do `--paginas`
     * passa pelo cache de leitura, com o teto, o piso de espaço livre e a trava por SHA.
     */
    #[TestDox('o calcular-hash recebe o ArmazenamentoEspelhado só como $espelhado (prova do hash e PDF do --paginas)')]
    public function testCalculoDeHashRecebeOEspelhadoSoParaAProvaEAsPaginas(): void
    {
        self::bootKernel();

        $servico = static::getContainer()->get(CalcularHashDosDocumentosCommand::class);

        self::assertInstanceOf(ArmazenamentoEspelhado::class, (new \ReflectionProperty(CalcularHashDosDocumentosCommand::class, 'espelhado'))->getValue($servico));
    }

    #[TestDox('nenhuma outra classe do espelho recebe armazenamento no construtor sem estar nesta lista')]
    public function testNenhumOutroRecebeArmazenamento(): void
    {
        $raiz      = \dirname(__DIR__, 2) . '/src/Shared/EspelhoRemoto';
        $tipos     = [ArmazenamentoDeArquivos::class, MaterializadorDeArquivo::class, ArmazenamentoComPrefixo::class, ArmazenamentoLocal::class];
        $recebem   = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            /** @var \SplFileInfo $arquivo */
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }
            $classe = 'App\\Shared\\EspelhoRemoto\\' . str_replace(['/', '.php'], ['\\', ''], substr($arquivo->getPathname(), \strlen($raiz) + 1));
            if (!class_exists($classe) || $classe === ArmazenamentoEspelhado::class) {
                continue;
            }
            foreach ((new \ReflectionClass($classe))->getConstructor()?->getParameters() ?? [] as $parametro) {
                foreach ($tipos as $tipo) {
                    if (str_contains((string) $parametro->getType(), $tipo)) {
                        $recebem[] = $classe;
                    }
                }
            }
        }
        $recebem = array_values(array_unique($recebem));
        sort($recebem);
        $esperado = array_keys(self::QUEM_MEDE_O_DISCO);
        sort($esperado);

        self::assertSame($esperado, $recebem, 'classe nova do espelho que recebe armazenamento entra na fiação do Local e nesta lista');
    }
}
