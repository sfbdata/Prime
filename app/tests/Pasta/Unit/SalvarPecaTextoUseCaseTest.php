<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Exception\TituloDePecaLongoDemaisException;
use App\Pasta\UseCase\SalvarPecaTextoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Peça nova escrita no editor. Desde a E2.4B o HTML é gravado por `ArmazenamentoDeArquivos` — o
 * dublê em memória guarda o escopo na chave, então escritório errado quebra o teste (R1).
 */
#[CoversClass(SalvarPecaTextoUseCase::class)]
final class SalvarPecaTextoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArmazenamentoEmMemoria $armazenamento;
    private SalvarPecaTextoUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->useCase       = new SalvarPecaTextoUseCase($this->em, $this->armazenamento);
        $this->tenant        = $this->tenant(7);
        $this->pasta         = (new Pasta())->setTenant($this->tenant);
    }

    #[TestDox('Salvar peça texto válida grava o HTML e retorna PastaDocumento com mimeType text/html')]
    public function testSalvarTextoValidoRetornaPastaDocumento(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, '<p>Conteúdo</p>', 'Petição Inicial', 'PECA', $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('PETIÇÃO INICIAL', $resultado->getTitulo());
        self::assertSame('PECA', $resultado->getCategoria());
        self::assertSame('text/html', $resultado->getMimeType());
        self::assertSame('Petição Inicial.html', $resultado->getNomeOriginal());
        self::assertSame($this->pasta, $resultado->getPasta());
        self::assertSame($this->tenant, $resultado->getTenant());
        self::assertNull($resultado->getSecao());

        // O nome é o que o storage cunhou, e o conteúdo gravado é o HTML recebido.
        $gravada = $this->armazenamento->ultimaGravada();
        self::assertSame($gravada->nome, $resultado->getCaminhoArquivo());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.html$/', $resultado->getCaminhoArquivo());
        self::assertSame('<p>Conteúdo</p>', $this->armazenamento->ler($gravada));
    }

    #[TestDox('R1: a chave gravada é a mesma que a leitura monta a partir do documento persistido')]
    public function testChaveGravadaEhADaLeitura(): void
    {
        $resultado = $this->useCase->executar($this->pasta, null, '<p>x</p>', 'Título', 'PECA', $this->tenant);

        $gravada = $this->armazenamento->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
        self::assertSame(7, $gravada->escopo->tenantIdOuNull());
        self::assertTrue($gravada->ehIgualA(ChavesDePasta::documento($resultado)));
    }

    /**
     * O dublê mede `application/octet-stream` e o disco mediria `text/plain` para um fragmento
     * curto — o documento tem de continuar `text/html`, senão editar e exportar o recusam.
     */
    #[TestDox('O MIME da peça é text/html fixo, não o medido pelo storage')]
    public function testMimeNaoEhOMedido(): void
    {
        $resultado = $this->useCase->executar($this->pasta, null, '<p>curto</p>', 'Título', 'PECA', $this->tenant);

        self::assertSame('text/html', $resultado->getMimeType());
    }

    #[TestDox('Tamanho em bytes é o do conteúdo gravado')]
    public function testTamanhoEmBytesEhODoConteudoGravado(): void
    {
        $conteudo = '<p>Teste com acentuação ç</p>';

        $resultado = $this->useCase->executar($this->pasta, null, $conteudo, 'Título', 'PECA', $this->tenant);

        self::assertSame(strlen($conteudo), $resultado->getTamanhoBytes());
        self::assertSame(
            strlen($this->armazenamento->ler($this->armazenamento->ultimaGravada())),
            $resultado->getTamanhoBytes(),
        );
    }

    /** Distingue "o que o storage mediu" de "strlen do HTML", que num teste comum coincidem. */
    #[TestDox('O tamanho persistido é o que o storage relata, não uma conta própria')]
    public function testTamanhoVemDoStorage(): void
    {
        $this->armazenamento->tamanhoRelatado = 4242;

        $resultado = $this->useCase->executar($this->pasta, null, '<p>x</p>', 'Título', 'PECA', $this->tenant);

        self::assertSame(4242, $resultado->getTamanhoBytes());
    }

    /**
     * `titulo` e `nome_original` são VARCHAR(255). Um título que não cabe seria recusado pelo
     * banco no flush — com o arquivo já gravado, órfão. A recusa vem antes.
     */
    #[TestDox('Título que não cabe na coluna é recusado ANTES de gravar')]
    public function testTituloLongoDemaisNaoGrava(): void
    {
        $this->em->expects($this->never())->method('persist');

        try {
            $this->useCase->executar($this->pasta, null, '<p>x</p>', str_repeat('a', 251), 'PECA', $this->tenant);
            self::fail('devia recusar título longo demais');
        } catch (TituloDePecaLongoDemaisException $e) {
            self::assertSame('O título da peça é longo demais (máximo de 250 caracteres).', $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
    }

    #[TestDox('Título de 250 caracteres ainda cabe (nome original com .html fica em 255)')]
    public function testTituloNoLimiteCabe(): void
    {
        $resultado = $this->useCase->executar($this->pasta, null, '<p>x</p>', str_repeat('a', 250), 'PECA', $this->tenant);

        self::assertSame(255, mb_strlen($resultado->getNomeOriginal()));
        self::assertCount(1, $this->armazenamento->gravadas);
    }

    /** D12: pane do storage não é mascarada — e nada é persistido. */
    #[TestDox('Falha do storage propaga e nenhum documento é persistido')]
    public function testFalhaDoStorageNaoPersiste(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(FalhaDeArmazenamento::class);
        $this->expectExceptionMessage('disco cheio');

        $this->useCase->executar($this->pasta, null, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);
    }

    #[TestDox('Título vazio lança InvalidArgumentException sem gravar')]
    public function testTituloVazioLancaInvalidArgumentException(): void
    {
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->pasta, null, '<p>HTML</p>', '', 'PECA', $this->tenant);
            self::fail('devia recusar título vazio');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('O título da peça não pode ser vazio.', $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
    }

    #[TestDox('Título apenas espaços é tratado como vazio')]
    public function testTituloApenasEspacosLancaInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O título da peça não pode ser vazio.');

        try {
            $this->useCase->executar($this->pasta, null, '<p>HTML</p>', '   ', 'PECA', $this->tenant);
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    #[TestDox('Categoria diferente é salva corretamente')]
    public function testCategoriaDiferenteESalvaCorretamente(): void
    {
        $resultado = $this->useCase->executar($this->pasta, null, '<p>Contrato</p>', 'Contrato Social', 'CONTRATO', $this->tenant);

        self::assertSame('CONTRATO', $resultado->getCategoria());
    }

    #[TestDox('Conteúdo HTML vazio é salvo (documento vazio permitido)')]
    public function testConteudoHtmlVazioESalvoSemErro(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, '', 'Rascunho', 'PECA', $this->tenant);

        self::assertSame(0, $resultado->getTamanhoBytes());
        self::assertSame('', $this->armazenamento->ler($this->armazenamento->ultimaGravada()));
    }

    #[TestDox('Seção válida é associada ao documento criado')]
    public function testSalvarTextoComSecaoValidaAssociaDocumentoASecao(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome('Petições');

        $resultado = $this->useCase->executar($this->pasta, $secao, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);

        self::assertSame($secao, $resultado->getSecao());
    }

    #[TestDox('Seção de tenant diferente lança AccessDeniedException sem gravar')]
    public function testSalvarTextoComSecaoDeTenantErradoLancaAccessDeniedException(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant(99));

        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->pasta, $secao, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);
            self::fail('devia recusar seção de outro escritório');
        } catch (AccessDeniedException) {
        }

        self::assertSame([], $this->armazenamento->gravadas);
    }

    #[TestDox('Seção de outra pasta lança InvalidArgumentException sem gravar')]
    public function testSalvarTextoComSecaoDeOutraPastaLancaInvalidArgumentException(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta((new Pasta())->setTenant($this->tenant));
        $secao->setTenant($this->tenant);

        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->pasta, $secao, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);
            self::fail('devia recusar seção de outra pasta');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Seção não pertence à pasta do documento.', $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
