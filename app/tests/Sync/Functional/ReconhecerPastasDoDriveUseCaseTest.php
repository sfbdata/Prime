<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Sync\Entity\ReconhecimentoPendente;
use App\Sync\Enum\EstadoReconhecimento;
use App\Sync\Message\ReconhecerPastasDoDrive;
use App\Sync\MessageHandler\ReconhecerPastasDoDriveHandler;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\LigadorDeArquivosDoDrive;
use App\Sync\UseCase\ReconhecerPastasDoDriveUseCase;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use App\Tests\Sync\Support\FakeGoogleDriveClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(ReconhecerPastasDoDriveUseCase::class)]
#[CoversClass(LigadorDeArquivosDoDrive::class)]
#[CoversClass(ReconhecerPastasDoDriveHandler::class)]
final class ReconhecerPastasDoDriveUseCaseTest extends KernelTestCase
{
    use Factories;

    private const ROOT = 'ROOT';

    private FakeGoogleDriveClient $fake;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fake = new FakeGoogleDriveClient();
        self::getContainer()->set(GoogleDriveClientFactoryInterface::class, new FakeGoogleDriveClientFactory($this->fake, self::ROOT));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reconhecer(Tenant $tenant): \App\Sync\DTO\ResultadoReconhecimento
    {
        return self::getContainer()->get(ReconhecerPastasDoDriveUseCase::class)->executar($tenant);
    }

    private function tenant(): Tenant
    {
        return TenantFactory::createOne()->_real();
    }

    /** @param array<string, mixed> $atributos */
    private function pasta(Tenant $tenant, array $atributos): Pasta
    {
        return PastaFactory::createOne(['tenant' => $tenant] + $atributos)->_real();
    }

    private function documento(Pasta $pasta, string $nome, int $tamanho, ?string $driveFileId = null): int
    {
        $doc = (new PastaDocumento())
            ->setTitulo($nome)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo('sem-arquivo-' . uniqid() . '.pdf')
            ->setNomeOriginal($nome)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes($tamanho)
            ->setPasta($pasta)
            ->setTenant($pasta->getTenant());
        $doc->setDriveFileId($driveFileId);
        $this->em()->persist($doc);
        $this->em()->flush();

        return (int) $doc->getId();
    }

    private function recarregarPasta(int $id): Pasta
    {
        $this->em()->clear();
        $pasta = $this->em()->find(Pasta::class, $id);
        self::assertNotNull($pasta);

        return $pasta;
    }

    private function driveFileId(int $documentoId): ?string
    {
        return $this->em()->find(PastaDocumento::class, $documentoId)?->getDriveFileId();
    }

    /** @return list<ReconhecimentoPendente> */
    private function pendencias(Tenant $tenant): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(ReconhecimentoPendente::class)->findBy(['tenant' => $tenant->getId()], ['id' => 'ASC']);
    }

    private function assertNadaMudouNoDrive(int $pastasAntes): void
    {
        self::assertCount($pastasAntes, $this->fake->pastas, 'o reconhecimento criou pasta no Drive');
        self::assertSame([], $this->fake->renomeacoes, 'o reconhecimento renomeou pasta no Drive');
        self::assertSame([], $this->fake->conteudosEnviados, 'o reconhecimento enviou arquivo ao Drive');
    }

    #[TestDox('casamento único pelo nome do envio liga a pasta e os documentos (nome sem caixa + tamanho, inclusive em subpasta)')]
    public function testCasamentoUnicoLigaPastaEArquivos(): void
    {
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, ['nup' => '10', 'nomeCliente' => 'José da Silva', 'nomeAcao' => 'Trabalhista']);
        $peticao = $this->documento($pasta, 'Peticao.pdf', 10);
        $procuracao = $this->documento($pasta, 'procuracao.pdf', 20);
        $tamanhoDiferente = $this->documento($pasta, 'contrato.pdf', 30);
        $semPar = $this->documento($pasta, 'outro.pdf', 99);

        $this->fake->seedPasta('D10', '  10 -  jose da silva - TRABALHISTA ', self::ROOT);
        $this->fake->seedPasta('D10-SUB', 'PROCURACOES', 'D10');
        $this->fake->seedArquivo('F-PET', 'peticao.PDF', 'D10', 10);
        $this->fake->seedArquivo('F-PROC', 'procuracao.pdf', 'D10-SUB', 20);
        $this->fake->seedArquivo('F-CONT', 'contrato.pdf', 'D10', 31);

        $resultado = $this->reconhecer($tenant);

        self::assertSame(1, $resultado->pastasLigadas);
        self::assertSame(2, $resultado->arquivosLigados);
        $recarregada = $this->recarregarPasta((int) $pasta->getId());
        self::assertSame('D10', $recarregada->getDriveFolderId());
        self::assertFalse($recarregada->isDriveManterNome(), 'o reconhecimento não mexe no "manter o nome do Drive"');
        self::assertSame('F-PET', $this->driveFileId($peticao));
        self::assertSame('F-PROC', $this->driveFileId($procuracao));
        self::assertNull($this->driveFileId($tamanhoDiferente));
        self::assertNull($this->driveFileId($semPar));
        self::assertSame([], $this->pendencias($tenant));
        $this->assertNadaMudouNoDrive(2);
    }

    #[TestDox('casamento único pelo nome do cliente sozinho, ignorando o número da frente do Drive')]
    public function testCasamentoPeloCliente(): void
    {
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, ['nup' => '55', 'nomeCliente' => 'MARIA SOUZA', 'nomeAcao' => 'Cível']);
        $this->fake->seedPasta('D-MARIA', '001 - Maria  Souza', self::ROOT);

        $this->reconhecer($tenant);

        self::assertSame('D-MARIA', $this->recarregarPasta((int) $pasta->getId())->getDriveFolderId());
    }

    #[TestDox('duas candidatas: grava a dúvida com as duas e não liga nenhuma')]
    public function testDuasCandidatasGeraPendencia(): void
    {
        $tenant = $this->tenant();
        $a = $this->pasta($tenant, ['nup' => '1', 'nomeCliente' => 'ANA', 'nomeAcao' => null]);
        $b = $this->pasta($tenant, ['nup' => '2', 'nomeCliente' => 'ANA', 'nomeAcao' => null]);
        $this->fake->seedPasta('D-ANA', 'Ana', self::ROOT);

        $resultado = $this->reconhecer($tenant);

        self::assertSame(1, $resultado->pendenciasNovas);
        $pendencias = $this->pendencias($tenant);
        self::assertCount(1, $pendencias);
        self::assertSame('D-ANA', $pendencias[0]->getDriveFolderId());
        self::assertSame('Ana', $pendencias[0]->getNomeNoDrive());
        self::assertSame([(int) $a->getId(), (int) $b->getId()], $pendencias[0]->getCandidatos());
        self::assertSame(EstadoReconhecimento::Pendente, $pendencias[0]->getEstado());
        self::assertNull($this->recarregarPasta((int) $a->getId())->getDriveFolderId());
        self::assertNull($this->recarregarPasta((int) $b->getId())->getDriveFolderId());
    }

    #[TestDox('nenhuma candidata: grava a dúvida sem candidatas; pasta de outro escritório não conta')]
    public function testNenhumaCandidataGeraPendencia(): void
    {
        $tenant = $this->tenant();
        $this->pasta($this->tenant(), ['nup' => '9', 'nomeCliente' => 'DESCONHECIDO', 'nomeAcao' => null]);
        $this->fake->seedPasta('D-X', 'Desconhecido', self::ROOT);

        $this->reconhecer($tenant);

        $pendencias = $this->pendencias($tenant);
        self::assertCount(1, $pendencias);
        self::assertSame([], $pendencias[0]->getCandidatos());
        $this->assertNadaMudouNoDrive(1);
    }

    #[TestDox('pasta candidata de duas subpastas: as duas viram dúvida')]
    public function testPastaDisputadaGeraPendencias(): void
    {
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, ['nup' => '3', 'nomeCliente' => 'CARLOS', 'nomeAcao' => 'X']);
        $this->fake->seedPasta('D-C1', '3 - Carlos - X', self::ROOT);
        $this->fake->seedPasta('D-C2', '002 - Carlos', self::ROOT);

        $this->reconhecer($tenant);

        self::assertCount(2, $this->pendencias($tenant));
        self::assertNull($this->recarregarPasta((int) $pasta->getId())->getDriveFolderId());
    }

    #[TestDox('subpasta do Drive já ligada é ignorada, e pasta já ligada não é candidata')]
    public function testJaLigadasSaoIgnoradas(): void
    {
        $tenant = $this->tenant();
        $this->pasta($tenant, ['nup' => '4', 'nomeCliente' => 'BRUNO', 'nomeAcao' => null, 'driveFolderId' => 'D-JA']);
        $this->fake->seedPasta('D-JA', 'Qualquer nome', self::ROOT);
        $this->fake->seedPasta('D-BRUNO', 'Bruno', self::ROOT);

        $this->reconhecer($tenant);

        $pendencias = $this->pendencias($tenant);
        self::assertCount(1, $pendencias, 'a subpasta já ligada não pode virar dúvida');
        self::assertSame('D-BRUNO', $pendencias[0]->getDriveFolderId());
        self::assertSame([], $pendencias[0]->getCandidatos(), 'pasta já ligada não é candidata');
    }

    #[TestDox('reexecutar não duplica a dúvida, e a dúvida decidida não volta')]
    public function testIdempotente(): void
    {
        $tenant = $this->tenant();
        $this->fake->seedPasta('D-Y', 'Ninguem', self::ROOT);

        $this->reconhecer($tenant);
        $segunda = $this->reconhecer($tenant);
        self::assertSame(0, $segunda->pendenciasNovas);
        self::assertSame(1, $segunda->jaRegistradas);

        $pendencia = $this->pendencias($tenant)[0];
        $pendencia->ignorar(null);
        $this->em()->flush();
        $this->reconhecer($tenant);

        $pendencias = $this->pendencias($tenant);
        self::assertCount(1, $pendencias);
        self::assertSame(EstadoReconhecimento::Ignorado, $pendencias[0]->getEstado());
    }

    #[TestDox('arquivo do Drive já ligado a outro documento não é ligado de novo')]
    public function testArquivoJaLigadoEmOutroDocumentoEPulado(): void
    {
        $tenant = $this->tenant();
        $outra = $this->pasta($tenant, ['nup' => '70', 'nomeCliente' => 'OUTRA', 'nomeAcao' => null, 'driveFolderId' => 'D-OUTRA']);
        $this->documento($outra, 'laudo.pdf', 15, 'F-LAUDO');
        $pasta = $this->pasta($tenant, ['nup' => '71', 'nomeCliente' => 'LUIZ', 'nomeAcao' => null]);
        $laudo = $this->documento($pasta, 'laudo.pdf', 15);
        $this->fake->seedPasta('D-LUIZ', '71 - Luiz', self::ROOT);
        $this->fake->seedArquivo('F-LAUDO', 'laudo.pdf', 'D-LUIZ', 15);

        $this->reconhecer($tenant);

        self::assertSame('D-LUIZ', $this->recarregarPasta((int) $pasta->getId())->getDriveFolderId());
        self::assertNull($this->driveFileId($laudo));
    }

    #[TestDox('o handler do worker roda o reconhecimento do escritório da mensagem')]
    public function testHandlerRodaOReconhecimento(): void
    {
        $tenant = $this->tenant();
        $this->fake->seedPasta('D-H', 'Sem par', self::ROOT);

        self::getContainer()->get(ReconhecerPastasDoDriveHandler::class)(new ReconhecerPastasDoDrive((int) $tenant->getId()));

        self::assertCount(1, $this->pendencias($tenant));
    }
}
