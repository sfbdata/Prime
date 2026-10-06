<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Command\CalcularHashDosDocumentosCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:documentos:calcular-hash` — o preenchimento do acervo anterior à coluna `sha256`.
 *
 * O que se prova: `--dry-run` lê e não grava; só linhas NULL são tocadas (o hash que já existe
 * não é relido nem regravado — idempotente); `--limite` e `--tenant` recortam a fila; arquivo
 * ausente fica NULL sem derrubar o lote; pane de leitura fica NULL, o resto segue e o código de
 * saída avisa. O armazenamento é o dublê em memória: a leitura é pela CHAVE, e o dublê registra
 * quais foram lidas.
 */
#[CoversClass(CalcularHashDosDocumentosCommand::class)]
final class CalcularHashDosDocumentosCommandTest extends KernelTestCase
{
    private const JA_TINHA = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    #[TestDox('--dry-run lê os arquivos e calcula, mas não grava nada')]
    public function testDryRunNaoGravaNada(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo A');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo B');

        $tester = $this->tester($memoria);
        $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('modo=simulacao candidatos=2 calculados=2 ja_tinham=0 ausentes=0 invalidos=0 falhas=0', $saida);
        self::assertStringContainsString('restantes=2', $saida, 'nada foi gravado: os dois continuam na fila');
        self::assertNull($this->shaNoBanco($d1));
        self::assertNull($this->shaNoBanco($d2));
        self::assertCount(2, $memoria->lidas, 'o dry-run lê de verdade — é assim que se mede o custo');
    }

    #[TestDox('preenche só quem está NULL, não relê quem já tem hash, e rodar de novo não muda nada')]
    public function testPreencheSoOsNulosEEhIdempotente(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo A');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo B');
        // Já tem hash — e o arquivo tem OUTRO conteúdo, para provar que ele não é relido.
        $d3      = $this->documento($pasta, $tenant, self::JA_TINHA, $memoria, 'conteúdo que não bate com o hash');
        // Sem arquivo no armazenamento.
        $d4      = $this->documento($pasta, $tenant, null, null, '');

        $tester = $this->tester($memoria);
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'arquivo ausente não é pane: ' . $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('modo=gravacao candidatos=3 calculados=2 ja_tinham=0 ausentes=1 invalidos=0 falhas=0', $saida);
        self::assertStringContainsString('restantes=1', $saida);

        self::assertSame(hash('sha256', 'conteúdo A'), $this->shaNoBanco($d1));
        self::assertSame(hash('sha256', 'conteúdo B'), $this->shaNoBanco($d2));
        self::assertSame(self::JA_TINHA, $this->shaNoBanco($d3), 'quem já tinha hash não é tocado');
        self::assertNull($this->shaNoBanco($d4), 'arquivo ausente fica NULL');

        self::assertNotContains($this->chaveDe($d3)->comoTexto(), $memoria->lidas, 'quem já tinha hash não é relido');
        self::assertContains($this->chaveDe($d1)->comoTexto(), $memoria->lidas);

        // Segunda execução: a fila é só o ausente; nada do que foi gravado muda.
        $memoria->lidas = [];
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('candidatos=1 calculados=0 ja_tinham=0 ausentes=1', $this->saida($tester));
        self::assertSame([$this->chaveDe($d4)->comoTexto()], $memoria->lidas, 'só o ausente volta à fila');
        self::assertSame(hash('sha256', 'conteúdo A'), $this->shaNoBanco($d1));
        self::assertSame(self::JA_TINHA, $this->shaNoBanco($d3));
    }

    #[TestDox('--limite recorta a fila em ordem de id e --tenant restringe ao escritório')]
    public function testLimiteETenantRecortamAFila(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant1 = $this->criarTenant();
        $tenant2 = $this->criarTenant();
        $pasta1  = $this->criarPasta($tenant1);
        $pasta2  = $this->criarPasta($tenant2);
        $d1      = $this->documento($pasta1, $tenant1, null, $memoria, 'um');
        $d2      = $this->documento($pasta1, $tenant1, null, $memoria, 'dois');
        $d5      = $this->documento($pasta2, $tenant2, null, $memoria, 'cinco');

        $tester = $this->tester($memoria);
        $tester->execute(['--tenant' => (string) $tenant1->getId(), '--limite' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(hash('sha256', 'um'), $this->shaNoBanco($d1), 'o mais antigo (menor id) entra primeiro');
        self::assertNull($this->shaNoBanco($d2), 'fora do limite');
        self::assertNull($this->shaNoBanco($d5), 'outro escritório');

        $tester->execute(['--tenant' => (string) $tenant2->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(hash('sha256', 'cinco'), $this->shaNoBanco($d5));
        self::assertNull($this->shaNoBanco($d2), 'o escritório 1 não foi tocado nesta execução');
    }

    #[TestDox('pane de leitura num arquivo: ele fica NULL, os outros são preenchidos e o código de saída avisa')]
    public function testPaneDeLeituraNaoDerrubaOLoteMasAvisa(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'quebrado');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'íntegro');

        $quebrado = $this->chaveDe($d1);
        $tester   = $this->tester($this->falhaAoAbrir($memoria, $quebrado));
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('ERRO: documento #' . $d1->getId() . ': disco com erro de leitura', $saida);
        self::assertStringContainsString('candidatos=2 calculados=1 ja_tinham=0 ausentes=0 invalidos=0 falhas=1', $saida);
        self::assertNull($this->shaNoBanco($d1));
        self::assertSame(hash('sha256', 'íntegro'), $this->shaNoBanco($d2), 'o lote segue depois da pane');
    }

    #[TestDox('--limite que não é inteiro positivo é recusado antes de ler qualquer coisa')]
    public function testOpcaoInvalidaEhRecusada(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();

        $tester = $this->tester($memoria);
        $tester->execute(['--limite' => 'dez']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--limite precisa ser um inteiro positivo', $this->saida($tester));
        self::assertSame([], $memoria->lidas);
    }

    // ----------------------------------------------------------------- helpers

    private function tester(ArmazenamentoDeArquivos $armazenamento): CommandTester
    {
        $container = static::getContainer();

        return new CommandTester(new CalcularHashDosDocumentosCommand(
            $container->get(EntityManagerInterface::class),
            $container->get(PastaDocumentoRepository::class),
            $armazenamento,
            new NullLogger(),
        ));
    }

    /** A saída com o espaço em branco normalizado (progress bar e tabela quebram linhas). */
    private function saida(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HASH ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('HASH-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /**
     * Uma linha de documento; com `$memoria`, o arquivo é semeado na chave que a leitura monta.
     * Sem `$memoria`, a linha aponta para um arquivo que não existe.
     */
    private function documento(Pasta $pasta, Tenant $tenant, ?string $sha256, ?ArmazenamentoEmMemoria $memoria, string $conteudo): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo('Doc ' . uniqid())
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf')
            ->setNomeOriginal('doc.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(\strlen($conteudo))
            ->setSha256($sha256);
        $em->persist($doc);
        $em->flush();

        if ($memoria !== null) {
            $memoria->semear(ChavesDePasta::documento($doc), $conteudo);
        }

        return $doc;
    }

    private function chaveDe(PastaDocumento $doc): ChaveDeArquivo
    {
        return ChavesDePasta::documentoPorNome((int) $doc->getTenant()?->getId(), $doc->getCaminhoArquivo());
    }

    /** Direto do banco: o comando faz `clear()`, então a entidade do teste não serve de espelho. */
    private function shaNoBanco(PastaDocumento $doc): ?string
    {
        $valor = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT sha256 FROM pasta_documento WHERE id = :id',
            ['id' => (int) $doc->getId()],
        );

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** O dublê, com `abrir()` quebrado numa chave específica — pane de I/O, não ausência. */
    private function falhaAoAbrir(ArmazenamentoEmMemoria $memoria, ChaveDeArquivo $quebrada): ArmazenamentoDeArquivos
    {
        return new class($memoria, $quebrada) implements ArmazenamentoDeArquivos {
            public function __construct(
                private readonly ArmazenamentoEmMemoria $memoria,
                private readonly ChaveDeArquivo $quebrada,
            ) {
            }

            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                return $this->memoria->gravar($destino, $fonte);
            }

            public function abrir(ChaveDeArquivo $chave): mixed
            {
                if ($chave->ehIgualA($this->quebrada)) {
                    throw new FalhaDeArmazenamento('disco com erro de leitura');
                }

                return $this->memoria->abrir($chave);
            }

            public function ler(ChaveDeArquivo $chave): string
            {
                return $this->memoria->ler($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->memoria->existe($chave);
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                $this->memoria->excluir($chave);
            }

            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
            {
                return $this->memoria->metadados($chave);
            }
        };
    }
}
