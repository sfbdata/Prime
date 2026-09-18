<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * D9 provado por COMPORTAMENTO, não só pela forma.
 *
 * `NucleoDeArmazenamentoArquiteturaTest` prova que os dois tipos não são confundíveis — que o
 * emprestado não tem `liberar()` nem destrutor, que não há supertipo comum, que o possuído não
 * aceita caminho arbitrário. Aqui se prova a outra metade: que isso **funciona** quando os
 * objetos vivem e morrem de verdade.
 *
 * A distinção não é acadêmica. `BinaryFileResponse` só abre o arquivo em `sendContent()`
 * (`vendor/symfony/http-foundation/BinaryFileResponse.php:321`), **depois** que o controller
 * retornou — o objeto que carregava o caminho já pode ter sido coletado. Se aquele objeto
 * apagasse no destrutor, servir um documento o removeria do disco.
 */
#[CoversClass(ArquivoEmprestado::class)]
#[CoversClass(ArquivoTemporarioPossuido::class)]
final class OwnershipDeMaterializacaoTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/e2-ownership-' . bin2hex(random_bytes(6));
        mkdir($this->raiz, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->raiz . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->raiz);
    }

    /**
     * O teste central de INV-9. O emprestado sai de escopo e é coletado; o arquivo tem de
     * continuar lá. Se alguém acrescentar um `__destruct` que apaga, este teste cai.
     */
    #[TestDox('destruir um ArquivoEmprestado NÃO remove o arquivo persistido')]
    public function testEmprestadoNaoApagaAoSerDestruido(): void
    {
        $persistido = $this->raiz . '/documento-do-cliente.pdf';
        file_put_contents($persistido, 'conteudo que nao pode sumir');

        $emprestado = new ArquivoEmprestado($persistido);
        self::assertSame($persistido, $emprestado->caminho());

        unset($emprestado);
        gc_collect_cycles();

        self::assertFileExists(
            $persistido,
            'o emprestado apagou o arquivo de produção — é exatamente o INV-9 quebrado',
        );
        self::assertSame('conteudo que nao pode sumir', file_get_contents($persistido));
    }

    #[TestDox('o cleanup de um temporário possuído remove SOMENTE o temporário')]
    public function testPossuidoApagaSoASuaCopia(): void
    {
        $persistido = $this->raiz . '/original.pdf';
        file_put_contents($persistido, 'original intacto');

        $copia = ArquivoTemporarioPossuido::criarEm($this->raiz, 'copia-');
        file_put_contents($copia->caminho(), 'copia gravavel');
        $caminhoDaCopia = $copia->caminho();

        self::assertFileExists($caminhoDaCopia);
        self::assertNotSame($persistido, $caminhoDaCopia);

        $copia->liberar();

        self::assertFileDoesNotExist($caminhoDaCopia, 'o possuído deve apagar a própria cópia');
        self::assertFileExists($persistido, 'e não pode encostar no original');
        self::assertSame('original intacto', file_get_contents($persistido));
    }

    #[TestDox('liberar é idempotente')]
    public function testLiberarEhIdempotente(): void
    {
        $copia = ArquivoTemporarioPossuido::criarEm($this->raiz);

        $copia->liberar();
        $copia->liberar();

        self::assertTrue($copia->foiLiberado());
    }

    /**
     * Depois de liberado, pedir o caminho é erro de programação — devolver a string faria alguém
     * escrever num caminho que já não existe, ou pior, que outro processo reocupou.
     */
    #[TestDox('pedir o caminho depois de liberar falha alto, em vez de devolver string morta')]
    public function testCaminhoDepoisDeLiberarLanca(): void
    {
        $copia = ArquivoTemporarioPossuido::criarEm($this->raiz);
        $copia->liberar();

        $this->expectException(FalhaDeArmazenamento::class);
        $copia->caminho();
    }

    #[TestDox('o destrutor do possuído é rede de segurança para o caminho de exceção')]
    public function testDestrutorDoPossuidoApaga(): void
    {
        $copia   = ArquivoTemporarioPossuido::criarEm($this->raiz, 'orfao-');
        $caminho = $copia->caminho();

        self::assertFileExists($caminho);

        unset($copia);
        gc_collect_cycles();

        self::assertFileDoesNotExist($caminho, 'temporário possuído não pode virar lixo em disco');
    }

    /**
     * A única porta para "possuir" é criar. Sem isso, alguém envolveria o caminho de um documento
     * de cliente num objeto cujo destrutor apaga — e o defeito só apareceria quando o GC rodasse.
     */
    #[TestDox('não existe forma pública de possuir um arquivo que já existia')]
    public function testNaoDaParaPossuirArquivoExistente(): void
    {
        $persistido = $this->raiz . '/ja-existia.pdf';
        file_put_contents($persistido, 'x');

        $construtor = (new \ReflectionClass(ArquivoTemporarioPossuido::class))->getConstructor();

        self::assertNotNull($construtor);
        self::assertFalse(
            $construtor->isPublic(),
            'construtor público permitiria possuir — e depois apagar — um arquivo persistido',
        );
        self::assertFileExists($persistido);
    }
}
