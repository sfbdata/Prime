<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\EditarDocumentoDaPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\UseCase\EditarDocumentoDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A edição de metadados do documento (D3): as regras herdadas do `editDocumento` antigo, mais o
 * `modificado_em` — gravado só quando algo mudou de fato.
 */
#[CoversClass(EditarDocumentoDaPastaUseCase::class)]
final class EditarDocumentoDaPastaUseCaseTest extends TestCase
{
    // MockClock com string assume UTC; aqui só se compara o instante devolvido, então não importa.
    private const AGORA = '2026-10-06 15:00:00';

    private EntityManagerInterface&MockObject $em;
    private EditarDocumentoDaPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarDocumentoDaPastaUseCase($this->em, new MockClock(new \DateTimeImmutable(self::AGORA)));
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    private function documento(string $nome = 'contrato.pdf', string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($this->tenant);
        $doc->setPasta(new Pasta());
        $doc->setTitulo($nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo('x/' . $nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);

        return $doc;
    }

    private function input(?string $categoria = null, ?string $descricao = null, ?string $numero = null, ?string $nomeBase = null): EditarDocumentoDaPastaInput
    {
        return new EditarDocumentoDaPastaInput($categoria, $descricao, $numero, $nomeBase);
    }

    #[TestDox('edita nome (com a extensão de volta), categoria, número e descrição, marca modificadoEm e dá UM flush')]
    public function testEditaTudoEMarcaModificadoEm(): void
    {
        $doc = $this->documento('contrato.pdf');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input('procuracao', ' Procuração do cliente ', '001/2026', 'Procuração assinada'));

        self::assertSame('Procuração assinada.pdf', $doc->getNomeOriginal(), 'a extensão volta sozinha');
        self::assertSame(PastaDocumento::CATEGORIA_PROCURACAO, $doc->getCategoria(), 'categoria normalizada para maiúsculas');
        self::assertSame('Procuração do cliente', $doc->getDescricao());
        self::assertSame('001/2026', $doc->getNumero());
        self::assertSame(self::AGORA, $doc->getModificadoEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('categoria desconhecida (ou CONTRATO, que não é da aba) mantém a atual')]
    public function testCategoriaForaDaAbaMantemAAtual(): void
    {
        $doc = $this->documento('x.pdf', PastaDocumento::CATEGORIA_PECA);

        $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input('INVENTADA', null, null, 'y'));
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $doc->getCategoria());

        $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input(PastaDocumento::CATEGORIA_CONTRATO, null, null, 'z'));
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $doc->getCategoria(), 'CONTRATO é da aba Financeiro');
    }

    #[TestDox('descrição e número em branco viram NULL (o formulário manda a chave vazia)')]
    public function testVaziosViramNull(): void
    {
        $doc = $this->documento();
        $doc->setDescricao('tinha');
        $doc->setNumero('tinha');

        $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input(null, '', '   ', ''));

        self::assertNull($doc->getDescricao());
        self::assertNull($doc->getNumero());
        self::assertSame('contrato.pdf', $doc->getNomeOriginal(), 'nomeBase vazio mantém o nome');
    }

    #[TestDox('arquivo sem extensão recebe só a base; a base com ponto não ganha extensão fantasma')]
    public function testNomeSemExtensao(): void
    {
        $doc = $this->documento('SEMEXT');

        $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input(null, null, null, 'novo nome'));

        self::assertSame('novo nome', $doc->getNomeOriginal());
    }

    #[TestDox('submeter sem mudar nada NÃO marca modificadoEm')]
    public function testSemMudancaNaoMarcaModificadoEm(): void
    {
        $doc = $this->documento('contrato.pdf');
        $doc->setDescricao('igual');
        $doc->setNumero('123');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input(PastaDocumento::CATEGORIA_DEMAIS, 'igual', '123', 'contrato'));

        self::assertNull($doc->getModificadoEm());
        self::assertSame('contrato.pdf', $doc->getNomeOriginal());
    }

    #[TestDox('documento de outro escritório: AccessDenied, nada muda, nada é gravado')]
    public function testTenantErrado(): void
    {
        $doc = $this->documento();
        $doc->setTenant(new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        try {
            $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input(null, 'x', null, 'y'));
        } finally {
            self::assertSame('contrato.pdf', $doc->getNomeOriginal());
            self::assertNull($doc->getDescricao());
        }
    }

    #[TestDox('nome com mais de 255 caracteres (com a extensão) é recusado antes de tocar no documento')]
    public function testNomeLongoDemais(): void
    {
        $doc = $this->documento('contrato.pdf');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('255');

        try {
            $this->useCase->executar($doc, $this->autor, $this->tenant, $this->input('PECA', 'nova', null, str_repeat('a', 252)));
        } finally {
            self::assertSame('contrato.pdf', $doc->getNomeOriginal());
            self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $doc->getCategoria(), 'tudo ou nada: a categoria não mudou');
            self::assertNull($doc->getModificadoEm());
        }
    }
}
