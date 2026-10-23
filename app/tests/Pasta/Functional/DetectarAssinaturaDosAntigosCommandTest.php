<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Command\DetectarAssinaturaDosAntigosCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\DetectorDeAssinaturaDigital;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:pasta:detectar-assinatura-antigos`: o selo "Assinado" no acervo anterior à coluna.
 *
 * O que se prova: o padrão (simulação) lê e não grava; `--aplicar` grava a origem digital só nos
 * assinados; a segunda execução não muda nada; a marca manual é preservada; arquivo ausente conta como
 * erro e o comando segue. A leitura é pela CHAVE, no dublê em memória.
 */
#[CoversClass(DetectarAssinaturaDosAntigosCommand::class)]
#[CoversClass(DetectorDeAssinaturaDigital::class)]
final class DetectarAssinaturaDosAntigosCommandTest extends KernelTestCase
{
    private const PDF_ASSINADO = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n"
        . "5 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 10 20 30] /Contents <3082> >>\nendobj\n"
        . "trailer << /Root 1 0 R >>\n%%EOF\n";

    private const PDF_COMUM = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    #[TestDox('sem --aplicar: lê, conta por escritório e não grava nada')]
    public function testSimulacaoNaoGrava(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $pdf     = $this->documento($pasta, $tenant, $memoria, self::PDF_ASSINADO);
        $p7s     = $this->documento($pasta, $tenant, $memoria, 'binario', 'contrato.pdf.p7s', 'application/pkcs7-signature');
        $this->documento($pasta, $tenant, $memoria, self::PDF_COMUM);

        $tester = $this->tester($memoria);
        $tester->execute(['--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('modo=simulacao escritorio=' . $tenant->getId() . ' lidos=3 assinados=2 pulados=0 erros=0', $this->saida($tester));
        self::assertStringContainsString($tenant->getName(), $this->saida($tester), 'a tabela por escritório traz o nome');
        self::assertNull($this->origemNoBanco($pdf));
        self::assertNull($this->origemNoBanco($p7s));
    }

    #[TestDox('--aplicar grava a origem digital só nos assinados; a segunda execução não muda nada; a marca manual fica')]
    public function testAplicarEhIdempotenteEPreservaManual(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $pdf     = $this->documento($pasta, $tenant, $memoria, self::PDF_ASSINADO);
        $comum   = $this->documento($pasta, $tenant, $memoria, self::PDF_COMUM);
        // Marcado à mão e com assinatura digital no conteúdo: a marca manual não pode virar digital.
        $manual  = $this->documento($pasta, $tenant, $memoria, self::PDF_ASSINADO);
        $autor   = $this->criarUsuario();
        $manual->marcarAssinadoManualmente($autor, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $ausente = $this->documento($pasta, $tenant, null, self::PDF_COMUM);
        $this->em()->flush();

        $tester = $this->tester($memoria);
        $tester->execute(['--escritorio' => (string) $tenant->getId(), '--aplicar' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'arquivo ausente não aborta: ' . $tester->getDisplay());
        self::assertStringContainsString('modo=aplicar escritorio=' . $tenant->getId() . ' lidos=3 assinados=1 pulados=1 erros=1', $this->saida($tester));
        self::assertSame('digital', $this->origemNoBanco($pdf));
        self::assertNull($this->origemNoBanco($comum));
        self::assertNull($this->origemNoBanco($ausente));
        self::assertSame('manual', $this->origemNoBanco($manual));
        self::assertSame('2026-10-01 10:00:00', $this->assinadoEmNoBanco($manual), 'a marca manual não foi tocada');

        $assinadoEmDoPdf = $this->assinadoEmNoBanco($pdf);
        $memoria->lidas  = [];
        $tester->execute(['--escritorio' => (string) $tenant->getId(), '--aplicar' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('lidos=2 assinados=0 pulados=2 erros=1', $this->saida($tester));
        self::assertNotContains(ChavesDePasta::documento($pdf)->comoTexto(), $memoria->lidas, 'o já marcado não é relido');
        self::assertNotContains(ChavesDePasta::documento($manual)->comoTexto(), $memoria->lidas);
        self::assertSame($assinadoEmDoPdf, $this->assinadoEmNoBanco($pdf));
        self::assertSame('manual', $this->origemNoBanco($manual));
    }

    #[TestDox('--escritorio recorta: outro escritório não é lido nem marcado')]
    public function testEscritorioRecorta(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant1 = $this->criarTenant();
        $tenant2 = $this->criarTenant();
        $doc1    = $this->documento($this->criarPasta($tenant1), $tenant1, $memoria, self::PDF_ASSINADO);
        $doc2    = $this->documento($this->criarPasta($tenant2), $tenant2, $memoria, self::PDF_ASSINADO);

        $tester = $this->tester($memoria);
        $tester->execute(['--escritorio' => (string) $tenant1->getId(), '--aplicar' => true]);

        self::assertSame('digital', $this->origemNoBanco($doc1));
        self::assertNull($this->origemNoBanco($doc2));
    }

    private function tester(ArmazenamentoEmMemoria $memoria): CommandTester
    {
        $container = static::getContainer();

        return new CommandTester(new DetectarAssinaturaDosAntigosCommand(
            $container->get(EntityManagerInterface::class),
            $container->get(PastaDocumentoRepository::class),
            $memoria,
            new DetectorDeAssinaturaDigital(),
            new NullLogger(),
        ));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function saida(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant SELO ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('selo_cmd_' . uniqid() . '@test.com');
        $user->setFullName('Quem marcou');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('SELO-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** Com `$memoria`, o arquivo é semeado na chave que a leitura monta; sem, a linha aponta para o nada. */
    private function documento(Pasta $pasta, Tenant $tenant, ?ArmazenamentoEmMemoria $memoria, string $conteudo, string $nome = 'contrato.pdf', string $mime = 'application/pdf'): PastaDocumento
    {
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo($nome)
            ->setCategoria(PastaDocumento::CATEGORIA_CONTRATO)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.' . pathinfo($nome, PATHINFO_EXTENSION))
            ->setNomeOriginal($nome)
            ->setMimeType($mime)
            ->setTamanhoBytes(\strlen($conteudo));
        $this->em()->persist($doc);
        $this->em()->flush();

        if ($memoria !== null) {
            $memoria->semear(ChavesDePasta::documento($doc), $conteudo);
        }

        return $doc;
    }

    /** Direto do banco: o comando faz `clear()`, então a entidade do teste não serve de espelho. */
    private function origemNoBanco(PastaDocumento $doc): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT assinatura_origem FROM pasta_documento WHERE id = :id', ['id' => (int) $doc->getId()]);

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    private function assinadoEmNoBanco(PastaDocumento $doc): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT assinado_em FROM pasta_documento WHERE id = :id', ['id' => (int) $doc->getId()]);

        return $valor === false || $valor === null ? null : (string) $valor;
    }
}
