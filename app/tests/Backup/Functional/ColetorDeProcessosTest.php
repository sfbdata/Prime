<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDeProcessos;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\Entity\DocumentoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Processos no backup (spec backup, seções 2 e 4): escritório 1 com A (primário), C (colaborador comum) e R (acesso
 * restrito, responsável só pela pasta P1); escritório 2 com B. Roda SEM sessão, como o worker.
 */
#[CoversClass(ColetorDeProcessos::class)]
final class ColetorDeProcessosTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private const PERMISSOES = ['modules.processos.view', 'resources.processo.view', 'modules.pastas.view', 'resources.pasta.view'];

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $r;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos Comum', self::PERMISSOES);
        $this->r = $this->criarColaborador($this->t1, 'Rita Restrita', self::PERMISSOES);
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->r, 'tenant' => $this->t1])?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');

        $this->semear();
    }

    /** @return list<\App\Backup\Coletor\ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        $c = static::getContainer();

        return [new ColetorDeProcessos($c->get(PermissionChecker::class), $c->get(AcessoAPasta::class), $this->em(), $lote)];
    }

    private function semear(): void
    {
        $p1 = $this->pasta($this->t1, 'P1 DA RITA', $this->r);
        $p2 = $this->pasta($this->t1, 'P2 DO PRIMARIO', $this->a);
        $p3 = $this->pasta($this->t1, 'P3 COM CADEADO', $this->r);
        $pb = $this->pasta($this->t2, 'PB DO B', $this->b);

        $this->processo($this->t1, '0000001-00.2026.8.26.0001', $p1, 'PARTE DO PROCESSO UM');
        $this->processo($this->t1, '0000002-00.2026.8.26.0002', $p2, 'PARTE DO PROCESSO DOIS');
        $this->processo($this->t1, '0000003-00.2026.8.26.0003', $p3, 'PARTE DO PROCESSO TRES');
        $this->processo($this->t1, '0000004-00.2026.8.26.0004', null, 'PARTE DO PROCESSO SEM PASTA');
        $this->processo($this->t2, '0000009-00.2026.8.26.0009', $pb, 'PARTE DO PROCESSO DO B');

        $this->trancar($p3, $this->a);
        $this->em()->flush();
    }

    private function pasta(Tenant $tenant, string $nome, User $responsavel): Pasta
    {
        $pasta = $this->criarPasta($tenant, $nome);
        $pasta->setResponsavel($responsavel);
        $this->em()->flush();

        return $pasta;
    }

    private function processo(Tenant $tenant, string $numero, ?Pasta $pasta, string $parte): Processo
    {
        $processo = (new Processo())->setNumeroProcesso($numero)->setTenant($tenant)->setSiglaTribunal('TJSP')->setClasseProcessual('CLASSE ' . $numero);
        $this->em()->persist($processo);

        $this->em()->persist((new ParteProcesso())->setTipo('autor')->setNome($parte)->setProcesso($processo)->setTenant($tenant));
        $this->em()->persist((new MovimentacaoProcesso())->setDescricao('MOVIMENTO ' . $numero)->setDataMovimentacao(new \DateTime('2026-10-01'))->setProcesso($processo)->setTenant($tenant));
        $this->em()->persist((new DocumentoProcesso())->setTipo('peticao')->setNomeOriginal('doc-' . $numero . '.pdf')->setCaminhoArquivo('x.pdf')->setProcesso($processo)->setTenant($tenant));
        $this->em()->persist(new NotaTecnica($tenant, $processo, $tenant === $this->t1 ? $this->a : $this->b, 'NOTA ' . $numero));
        if ($pasta !== null) {
            $this->em()->persist(new PastaProcesso($pasta, $processo));
        }
        $this->em()->flush();

        return $processo;
    }

    #[TestDox('Primário leva todos os processos do seu escritório, com partes, movimentações, notas e documentos, e nada do outro')]
    public function testPrimarioLevaTudoENadaDoOutroEscritorio(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);
        $json = self::json($zip, 'dados/processos.json');

        self::assertCount(4, $json);
        foreach (['0000001-00', '0000002-00', '0000003-00', '0000004-00', 'PARTE DO PROCESSO SEM PASTA', 'MOVIMENTO 0000001', 'NOTA 0000001', 'doc-0000001'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        self::assertStringContainsString('Processos: 4', $zip['LEIA-ME.txt']);
        foreach (['0000009', 'DO B'] as $deB) {
            self::assertStringNotContainsString($deB, $tudo, $deB);
        }
        self::assertStringNotContainsString('caminho_arquivo', $tudo);
        self::assertStringNotContainsString('x.pdf', $tudo, 'o caminho de origem do documento não sai');
    }

    #[TestDox('B (escritório 2) leva só o processo do escritório 2')]
    public function testOutroEscritorioLevaSoOSeu(): void
    {
        $json = self::json($this->backupDe($this->b, $this->t2), 'dados/processos.json');

        self::assertCount(1, $json);
        self::assertSame('0000009-00.2026.8.26.0009', $json[0]['numero']);
    }

    #[TestDox('Colaborador comum: o processo só da pasta com cadeado fica fora, os demais entram')]
    public function testCadeadoEscondeOProcesso(): void
    {
        $tudo = self::tudo($this->backupDe($this->c, $this->t1));

        self::assertStringContainsString('0000001-00', $tudo);
        self::assertStringContainsString('0000002-00', $tudo);
        self::assertStringContainsString('0000004-00', $tudo);
        foreach (['0000003-00', 'PARTE DO PROCESSO TRES', 'MOVIMENTO 0000003', 'NOTA 0000003', 'doc-0000003'] as $oculto) {
            self::assertStringNotContainsString($oculto, $tudo, $oculto);
        }
    }

    #[TestDox('R (acesso restrito): só o processo da pasta sob a responsabilidade dela; sem o alheio, o do cadeado e o sem pasta')]
    public function testRestritoSoVeOEscopo(): void
    {
        $zip  = $this->backupDe($this->r, $this->t1);
        $json = self::json($zip, 'dados/processos.json');

        self::assertSame(['0000001-00.2026.8.26.0001'], array_column($json, 'numero'));
        $tudo = self::tudo($zip);
        foreach (['0000002', '0000003', '0000004', 'DOIS', 'SEM PASTA'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('Sem o módulo de processos o coletor não roda: o ZIP traz só LEIA-ME e manifesto')]
    public function testSemModuloNaoRoda(): void
    {
        $semModulo = $this->criarColaborador($this->t1, 'Sem Modulo', ['resources.processo.view']);

        self::assertSame(['LEIA-ME.txt', 'manifesto.json'], array_keys($this->backupDe($semModulo, $this->t1)));
    }

    #[TestDox('Sem o recurso processo:view o processo não entra, mesmo com o módulo')]
    public function testSemORecursoNaoEntra(): void
    {
        $semRecurso = $this->criarColaborador($this->t1, 'Sem Recurso', ['modules.processos.view']);

        self::assertSame([], self::json($this->backupDe($semRecurso, $this->t1), 'dados/processos.json'));
    }

    #[TestDox('Lote de 1 (clear a cada processo): o ZIP sai igual para A, C, R e B')]
    public function testLoteDeUmGeraOMesmoZip(): void
    {
        foreach ([[$this->a, $this->t1], [$this->c, $this->t1], [$this->r, $this->t1], [$this->b, $this->t2]] as [$usuario, $tenant]) {
            $this->assertLoteDeUmIgual($usuario, $tenant);
        }
    }
}
