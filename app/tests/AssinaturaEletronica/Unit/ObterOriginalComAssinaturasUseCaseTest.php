<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\UseCase\ObterOriginalComAssinaturasUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * "Baixar original com as assinaturas" (5A, presOrig de dc:383): a versão mais recente que chegou assinada, com a
 * chave do escritório do documento e o nome "{nome} - original.pdf"; sem ela (ou só com o PDF para assinar), não
 * encontrado.
 */
#[CoversClass(ObterOriginalComAssinaturasUseCase::class)]
final class ObterOriginalComAssinaturasUseCaseTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ANALISE = ['v' => 1, 'situacao' => 'integro'];

    #[TestDox('a última versão assinada sai com a chave do escritório e o nome do documento + " - original.pdf"')]
    public function testEntregaAUltimaVersao(): void
    {
        [$tenant, $documento] = self::rascunho();
        $versao = VersaoDoPdf::original($documento, 1, 'original-1.pdf', 'enviado.pdf', self::SHA, 10, self::ANALISE, new \DateTimeImmutable());

        $arquivo = $this->useCase($documento, $versao)->executar($tenant, 5);

        self::assertSame('Contrato - original.pdf', $arquivo->nome);
        self::assertSame('original-1.pdf', $arquivo->chave->nome);
        self::assertSame(CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $arquivo->chave->categoria);
    }

    #[TestDox('sem versão, só com o PDF para assinar ou com documento de outro escritório: não encontrado')]
    public function testNaoEncontrado(): void
    {
        [$tenant, $documento] = self::rascunho();
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable());
        $paraAssinar = VersaoDoPdf::paraAssinar($documento, 1, 'para.pdf', 'para.pdf', self::SHA, 10, self::ANALISE, new \DateTimeImmutable());

        $recusas = 0;
        foreach ([[$documento, null], [$documento, $paraAssinar], [null, null]] as [$doc, $versao]) {
            try {
                $this->useCase($doc, $versao)->executar($tenant, 5);
            } catch (DocumentoNaoEncontrado) {
                ++$recusas;
            }
        }

        self::assertSame(3, $recusas);
    }

    private function useCase(?DocumentoParaAssinar $documento, ?VersaoDoPdf $ultima): ObterOriginalComAssinaturasUseCase
    {
        $documentos = $this->createStub(DocumentoParaAssinarRepository::class);
        $documentos->method('doEscritorioMesmoNaLixeira')->willReturn($documento);
        $versoes = $this->createStub(VersaoDoPdfRepository::class);
        $versoes->method('ultima')->willReturn($ultima);
        $tradutor = new Translator('pt_BR');
        $tradutor->addLoader('yaml', new YamlFileLoader());
        CatalogoPtBr::registrarEm($tradutor);

        return new ObterOriginalComAssinaturasUseCase($documentos, $versoes, $tradutor);
    }

    /** @return array{Tenant, DocumentoParaAssinar} */
    private static function rascunho(): array
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 9);
        $documento = new DocumentoParaAssinar($tenant, null, new \DateTimeImmutable());
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com']],
        ]), [], new \DateTimeImmutable());

        return [$tenant, $documento];
    }
}
