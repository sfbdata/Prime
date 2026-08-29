<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\ResultadoExclusaoPasta;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O que este teste prova: DADA a resposta da sequência ("existe pasta com número maior?"), o
 * UseCase faz a coisa certa — lápide preservando arquivo, ou exclusão real apagando arquivo.
 *
 * O que ele NÃO prova, de propósito: se a resposta da sequência está certa. Isso é expressão SQL
 * contra o Postgres e tem prova própria em ExcluirPastaLapideTest (funcional).
 */
#[CoversClass(ExcluirPastaUseCase::class)]
final class ExcluirPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArquivoStorageInterface&MockObject $storage;
    private NumeracaoDePastaInterface&MockObject $numeracao;
    private ExcluirPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em        = $this->createMock(EntityManagerInterface::class);
        $this->storage   = $this->createMock(ArquivoStorageInterface::class);
        $this->numeracao = $this->createMock(NumeracaoDePastaInterface::class);

        // O UseCase envolve tudo em transação; aqui a transação é o próprio callback.
        $this->em->method('wrapInTransaction')->willReturnCallback(
            fn (callable $fn) => $fn($this->em)
        );

        $this->useCase = new ExcluirPastaUseCase(
            $this->em,
            $this->storage,
            '/uploads/pastas',
            $this->numeracao,
        );

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
    }

    private function ehAUltima(bool $ehAUltima): void
    {
        $this->numeracao->method('existeNumeroMaiorQue')->willReturn(!$ehAUltima);
    }

    #[TestDox('Tenant diverge: recusa antes de tocar em qualquer coisa')]
    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $pasta = $this->criarPasta($this->tenant, []);

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('wrapInTransaction');
        $this->storage->expects($this->never())->method('excluir');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($pasta, $this->autor, new Tenant());
    }

    #[TestDox('É a última da sequência: apaga de verdade e o número volta a ser livre')]
    public function testUltimaDaSequenciaEhRemovidaDeVerdade(): void
    {
        $this->ehAUltima(true);
        $pasta = $this->criarPasta($this->tenant, []);

        $pasta->expects($this->never())->method('marcarExcluida');
        $this->em->expects($this->once())->method('remove')->with($pasta);
        $this->em->expects($this->once())->method('flush');

        self::assertSame(
            ResultadoExclusaoPasta::Removida,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
    }

    #[TestDox('É a última e tem documentos: apaga os arquivos do disco junto')]
    public function testUltimaComDocumentosApagaOsArquivos(): void
    {
        $this->ehAUltima(true);
        $pasta = $this->criarPasta($this->tenant, [
            $this->criarDocumento('arquivo1.pdf'),
            $this->criarDocumento('arquivo2.pdf'),
        ]);

        $this->storage->method('caminho')
            ->willReturnCallback(fn (string $dir, string $nome) => $dir . '/' . $nome);
        $this->storage->method('existe')->willReturn(true);

        $this->storage->expects($this->exactly(2))->method('excluir');
        $this->em->expects($this->once())->method('remove')->with($pasta);

        self::assertSame(
            ResultadoExclusaoPasta::Removida,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
    }

    #[TestDox('É a última e o arquivo já sumiu do disco: remove do banco sem chamar excluir')]
    public function testDocumentoInexistenteNaoChamaExcluirMasRemoveDoBanco(): void
    {
        $this->ehAUltima(true);
        $pasta = $this->criarPasta($this->tenant, [$this->criarDocumento('ausente.pdf')]);

        $this->storage->method('caminho')->willReturn('/uploads/pastas/ausente.pdf');
        $this->storage->method('existe')->willReturn(false);

        $this->storage->expects($this->never())->method('excluir');
        $this->em->expects($this->once())->method('remove')->with($pasta);

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }

    #[TestDox('TEM posterior: vira lápide — a linha NÃO é removida')]
    public function testComPosteriorViraLapideSemRemoverALinha(): void
    {
        $this->ehAUltima(false);
        $pasta = $this->criarPasta($this->tenant, []);

        $pasta->expects($this->once())->method('marcarExcluida')
              ->with($this->autor, $this->isInstanceOf(\DateTimeImmutable::class));
        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->once())->method('flush');

        self::assertSame(
            ResultadoExclusaoPasta::Lapide,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
    }

    #[TestDox('LÁPIDE PRESERVA OS ARQUIVOS: nenhum documento é apagado do disco')]
    public function testLapidePreservaOsArquivosNoDisco(): void
    {
        $this->ehAUltima(false);
        $pasta = $this->criarPasta($this->tenant, [
            $this->criarDocumento('contrato.pdf'),
            $this->criarDocumento('procuracao.pdf'),
        ]);

        // O ponto da decisão do dono: a pasta riscada tem que abrir e mostrar o que já foi feito.
        $this->storage->expects($this->never())->method('excluir');

        self::assertSame(
            ResultadoExclusaoPasta::Lapide,
            $this->useCase->executar($pasta, $this->autor, $this->tenant),
        );
    }

    #[TestDox('A sequência é travada ANTES de decidir, senão a decisão nasce errada em silêncio')]
    public function testTravaASequenciaAntesDeDecidir(): void
    {
        $ordem = [];
        $this->numeracao->method('travar')
             ->willReturnCallback(function () use (&$ordem): void { $ordem[] = 'travar'; });
        $this->numeracao->method('existeNumeroMaiorQue')
             ->willReturnCallback(function () use (&$ordem): bool { $ordem[] = 'decidir'; return true; });

        $this->useCase->executar($this->criarPasta($this->tenant, []), $this->autor, $this->tenant);

        self::assertSame(['travar', 'decidir'], $ordem);
    }

    #[TestDox('Excluir pasta que já é lápide é recusado')]
    public function testPastaJaExcluidaEhRecusada(): void
    {
        $pasta = $this->criarPasta($this->tenant, []);
        $pasta->method('estaExcluida')->willReturn(true);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\LogicException::class);

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }

    /** @param list<PastaDocumento> $documentos */
    private function criarPasta(Tenant $tenant, array $documentos): Pasta&MockObject
    {
        $pasta = $this->createMock(Pasta::class);
        $pasta->method('getTenant')->willReturn($tenant);
        $pasta->method('getNup')->willReturn('1238');
        $pasta->method('getDocumentos')->willReturn(new ArrayCollection($documentos));

        return $pasta;
    }

    private function criarDocumento(string $caminhoArquivo): PastaDocumento
    {
        $doc = $this->createMock(PastaDocumento::class);
        $doc->method('getCaminhoArquivo')->willReturn($caminhoArquivo);

        return $doc;
    }
}
