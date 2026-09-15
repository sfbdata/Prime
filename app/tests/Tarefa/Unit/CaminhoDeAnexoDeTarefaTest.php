<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Service\CaminhoDeAnexoDeTarefa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Ataca o serviço DIRETAMENTE, com o valor malicioso na mão.
 *
 * Um teste equivalente por HTTP não provaria nada: o roteador do Symfony normaliza `../..` antes
 * de o controller ser chamado, então a rota devolve 404 com ou sem a guarda — é exatamente a
 * armadilha que `ServirFotoControllerTest::testServirFotoComPathTraversalRetorna404` documenta
 * em seus próprios comentários.
 */
#[CoversClass(CaminhoDeAnexoDeTarefa::class)]
final class CaminhoDeAnexoDeTarefaTest extends TestCase
{
    private string $raizTemp;
    private CaminhoDeAnexoDeTarefa $servico;

    protected function setUp(): void
    {
        $this->raizTemp = sys_get_temp_dir() . '/anexo-tarefa-' . bin2hex(random_bytes(8));
        mkdir($this->raizTemp . '/public/uploads/tarefas/chat', 0777, true);
        mkdir($this->raizTemp . '/public/uploads/tarefas/admin', 0777, true);

        file_put_contents($this->raizTemp . '/public/uploads/tarefas/chat/arquivo_ok.pdf', 'conteudo');
        // Um alvo fora da raiz de anexos, para as tentativas de fuga terem o que buscar.
        file_put_contents($this->raizTemp . '/public/segredo.txt', 'nao deve sair daqui');

        $this->servico = new CaminhoDeAnexoDeTarefa($this->raizTemp);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->raizTemp)) {
            exec('rm -rf ' . escapeshellarg($this->raizTemp));
        }
    }

    #[TestDox('Anexo legítimo em chat/ resolve para o caminho real do arquivo')]
    public function testAnexoLegitimoResolve(): void
    {
        $resolvido = $this->servico->resolver('/uploads/tarefas/chat/arquivo_ok.pdf');

        self::assertNotNull($resolvido);
        self::assertSame(
            realpath($this->raizTemp . '/public/uploads/tarefas/chat/arquivo_ok.pdf'),
            $resolvido,
        );
    }

    #[TestDox('Travessia com ../ para fora da raiz é recusada')]
    public function testTravessiaParaForaDaRaizRetornaNull(): void
    {
        self::assertNull($this->servico->resolver('/uploads/tarefas/chat/../../../.env'));
        self::assertNull($this->servico->resolver('/uploads/tarefas/chat/../../segredo.txt'));
        self::assertNull($this->servico->resolver('/uploads/tarefas/chat/..'));
        self::assertNull($this->servico->resolver('/uploads/tarefas/../../segredo.txt'));
    }

    #[TestDox('Caminho absoluto arbitrário fora de uploads/tarefas é recusado')]
    public function testCaminhoForaDaAllowlistRetornaNull(): void
    {
        self::assertNull($this->servico->resolver('/etc/passwd'));
        self::assertNull($this->servico->resolver('/uploads/pastas/abc.pdf'));
        self::assertNull($this->servico->resolver('/uploads/tarefas/outro/abc.pdf'));
        self::assertNull($this->servico->resolver('uploads/tarefas/chat/arquivo_ok.pdf'));
    }

    #[TestDox('Valor vazio é recusado')]
    public function testValorVazioRetornaNull(): void
    {
        self::assertNull($this->servico->resolver(''));
    }

    #[TestDox('Arquivo inexistente é recusado (o chamador já traduzia ausência em 404)')]
    public function testArquivoInexistenteRetornaNull(): void
    {
        self::assertNull($this->servico->resolver('/uploads/tarefas/chat/nao_existe.pdf'));
    }

    #[TestDox('Symlink que aponta para fora da raiz é recusado pelo confinamento')]
    public function testSymlinkParaForaDaRaizRetornaNull(): void
    {
        $link = $this->raizTemp . '/public/uploads/tarefas/chat/atalho.pdf';
        symlink($this->raizTemp . '/public/segredo.txt', $link);

        // A allowlist e o regex passam: o nome é limpo. Quem barra aqui é o realpath +
        // confinamento — esta é a única asserção que prova a terceira camada.
        self::assertNull($this->servico->resolver('/uploads/tarefas/chat/atalho.pdf'));
    }

    #[TestDox('Subdiretório admin (legado) continua servível')]
    public function testSubdiretorioAdminResolve(): void
    {
        file_put_contents($this->raizTemp . '/public/uploads/tarefas/admin/antigo.pdf', 'x');

        self::assertNotNull($this->servico->resolver('/uploads/tarefas/admin/antigo.pdf'));
    }
}
