<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\TituloDePecaLongoDemaisException;
use App\Pasta\UseCase\EditarPecaTextoUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Edição de peça. Desde a E2.4B o HTML é regravado por chave; e, pela D14, peça cujo arquivo
 * sumiu NÃO é recriada — a edição falha sem gravar nada e sem tocar na entidade.
 */
#[CoversClass(EditarPecaTextoUseCase::class)]
final class EditarPecaTextoUseCaseTest extends TestCase
{
    private const ORIGINAL = '<p>Conteúdo original</p>';

    private EntityManagerInterface&MockObject $em;
    private ArmazenamentoEmMemoria $armazenamento;
    private EditarPecaTextoUseCase $useCase;
    private PastaDocumento $doc;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->useCase       = new EditarPecaTextoUseCase($this->em, $this->armazenamento);

        $this->doc = $this->documento('5f2b8c0e9a1d4e7f8a6b3c2d1e0f9a8b.html');
        $this->armazenamento->gravar(ChavesDePasta::documento($this->doc), FonteDeConteudo::deTexto(self::ORIGINAL));
        $this->armazenamento->gravadas = [];
    }

    #[TestDox('Editar com novo conteúdo e título regrava na MESMA chave e atualiza título e tamanhoBytes')]
    public function testEditarComConteudoEtituloNovoAtualizaTudo(): void
    {
        $novoConteudo = '<p>Novo conteúdo editado</p>';

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->doc, $novoConteudo, 'Nova Petição');

        $chave = ChavesDePasta::documento($this->doc);
        self::assertSame($novoConteudo, $this->armazenamento->ler($chave));
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertTrue($this->armazenamento->ultimaGravada()->ehIgualA($chave), 'a peça tem de ser regravada na chave dela');
        self::assertSame('5f2b8c0e9a1d4e7f8a6b3c2d1e0f9a8b.html', $this->doc->getCaminhoArquivo());
        self::assertSame('NOVA PETIÇÃO', $this->doc->getTitulo());
        self::assertSame('Nova Petição.html', $this->doc->getNomeOriginal());
        self::assertSame(strlen($novoConteudo), $this->doc->getTamanhoBytes());
    }

    /**
     * D14. Antes da E2.4B o `file_put_contents` recriava o arquivo em silêncio e respondia sucesso.
     */
    #[TestDox('D14: peça cujo arquivo sumiu → ArquivoNaoEncontrado, nada é gravado nem alterado')]
    public function testArquivoAusenteFalhaSemRecriar(): void
    {
        $orfa = $this->documento('0123456789abcdef0123456789abcdef.html');

        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($orfa, '<p>não pode ser gravado</p>', 'Outro título');
            self::fail('a edição de peça sem arquivo devia falhar');
        } catch (ArquivoNaoEncontrado) {
        }

        self::assertSame([], $this->armazenamento->gravadas, 'o arquivo sumido foi recriado');
        self::assertFalse($this->armazenamento->existe(ChavesDePasta::documento($orfa)));
        self::assertSame('PETIÇÃO INICIAL', $orfa->getTitulo());
        self::assertSame('Petição Inicial.html', $orfa->getNomeOriginal());
        self::assertSame(strlen(self::ORIGINAL), $orfa->getTamanhoBytes());
    }

    /**
     * D12: a pane não é "arquivo sumido". Contra o disco de verdade, com o diretório ilegível, o
     * `existe()` lança `FalhaDeArmazenamento` — que não pode virar `ArquivoNaoEncontrado` (404)
     * nem ser engolida.
     */
    #[TestDox('D12: diretório ilegível → FalhaDeArmazenamento (não ArquivoNaoEncontrado), nada alterado')]
    public function testDiretorioIlegivelEhPaneNaoAusencia(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório; o guarda não é observável');
        }

        $raiz  = sys_get_temp_dir() . '/e2-editar-peca-' . bin2hex(random_bytes(6));
        $disco = $this->discoEm($raiz);
        $disco->gravar(ChavesDePasta::documento($this->doc), FonteDeConteudo::deTexto(self::ORIGINAL));
        $useCase = new EditarPecaTextoUseCase($this->em, $disco);

        $this->em->expects($this->never())->method('flush');
        chmod($raiz, 0o000);

        try {
            $falha = null;
            try {
                $useCase->executar($this->doc, '<p>novo</p>', 'Outro título');
            } catch (FalhaDeArmazenamento $e) {
                $falha = $e;
            } finally {
                chmod($raiz, 0o755);
            }

            self::assertInstanceOf(FalhaDeArmazenamento::class, $falha, 'a edição devia falhar');
            self::assertNotInstanceOf(ArquivoNaoEncontrado::class, $falha);
            self::assertSame(self::ORIGINAL, $disco->ler(ChavesDePasta::documento($this->doc)));
            self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
            self::assertSame(strlen(self::ORIGINAL), $this->doc->getTamanhoBytes());
        } finally {
            @unlink($raiz . '/pastas/5f2b8c0e9a1d4e7f8a6b3c2d1e0f9a8b.html');
            @rmdir($raiz . '/pastas');
            @rmdir($raiz);
        }
    }

    #[TestDox('Falha ao gravar propaga, sem flush e sem mexer nos campos do documento')]
    public function testFalhaAoGravarNaoAlteraODocumento(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->doc, '<p>maior que o original, com certeza</p>', 'Outro título');
            self::fail('a falha do storage devia propagar');
        } catch (FalhaDeArmazenamento $e) {
            self::assertSame('disco cheio', $e->getMessage());
        }

        self::assertSame(self::ORIGINAL, $this->armazenamento->ler(ChavesDePasta::documento($this->doc)));
        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
        self::assertSame(strlen(self::ORIGINAL), $this->doc->getTamanhoBytes());
    }

    #[TestDox('O tamanho persistido é o que o storage relata, não uma conta própria')]
    public function testTamanhoVemDoStorage(): void
    {
        $this->armazenamento->tamanhoRelatado = 4242;

        $this->useCase->executar($this->doc, '<p>x</p>');

        self::assertSame(4242, $this->doc->getTamanhoBytes());
    }

    /**
     * O banco recusaria o título no flush — mas aí o conteúdo novo já estaria publicado e o
     * usuário receberia erro achando que nada foi salvo. A recusa vem antes de tocar no arquivo.
     */
    #[TestDox('Título novo que não cabe na coluna é recusado ANTES de regravar a peça')]
    public function testTituloLongoDemaisNaoRegrava(): void
    {
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->doc, '<p>não pode ser gravado</p>', str_repeat('a', 251));
            self::fail('devia recusar título longo demais');
        } catch (TituloDePecaLongoDemaisException $e) {
            self::assertSame('O título da peça é longo demais (máximo de 250 caracteres).', $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertSame(self::ORIGINAL, $this->armazenamento->ler(ChavesDePasta::documento($this->doc)));
        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
    }

    /** `mb_strtoupper` pode crescer o texto (`ß` → `SS`): o limite vale para o título já em maiúsculas. */
    #[TestDox('Título que só estoura depois de ir para maiúsculas também é recusado')]
    public function testTituloQueCresceNasMaiusculasEhRecusado(): void
    {
        $this->expectException(TituloDePecaLongoDemaisException::class);

        try {
            $this->useCase->executar($this->doc, '<p>x</p>', str_repeat('ß', 200));
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    #[TestDox('Editar sem título (null) mantém título existente')]
    public function testEditarSemTituloMantemTituloExistente(): void
    {
        $this->useCase->executar($this->doc, '<p>Novo HTML</p>');

        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
        self::assertSame('<p>Novo HTML</p>', $this->armazenamento->ler(ChavesDePasta::documento($this->doc)));
    }

    #[TestDox('Editar com título vazio (string vazia) não atualiza o título')]
    public function testEditarComTituloVazioNaoAtualizaTitulo(): void
    {
        $this->useCase->executar($this->doc, '<p>HTML</p>', '');

        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
    }

    #[TestDox('Editar com título apenas espaços não atualiza o título')]
    public function testEditarComTituloApenasEspacosNaoAtualizaTitulo(): void
    {
        $this->useCase->executar($this->doc, '<p>HTML</p>', '   ');

        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
    }

    #[TestDox('TamanhoBytes é recalculado com base no novo conteúdo')]
    public function testTamanhoEmBytesRecalculadoComNovoConteudo(): void
    {
        $conteudoMaior = str_repeat('<p>Parágrafo com texto</p>', 10);

        $this->useCase->executar($this->doc, $conteudoMaior);

        self::assertSame(strlen($conteudoMaior), $this->doc->getTamanhoBytes());
    }

    private function documento(string $caminho): PastaDocumento
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 7);

        return (new PastaDocumento())
            ->setTenant($tenant)
            ->setTitulo('Petição Inicial')
            ->setCaminhoArquivo($caminho)
            ->setCategoria('PECA')
            ->setMimeType('text/html')
            ->setNomeOriginal('Petição Inicial.html')
            ->setTamanhoBytes(strlen(self::ORIGINAL));
    }

    private function discoEm(string $raiz): ArmazenamentoDeArquivos
    {
        return new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
            uploadsDir: $raiz . '/pastas',
            clientesUploadsDir: $raiz . '/clientes',
            chamadosUploadsDir: $raiz . '/chamados',
            justificativasUploadsDir: $raiz . '/justificativas',
            fotosPerfilDir: $raiz . '/perfil',
            cobrancasUploadsDir: $raiz . '/cobrancas',
            kanbanUploadsDir: $raiz . '/kanban',
        ));
    }
}
