<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDeTarefas;
use App\Backup\Coletor\ColetorDoBackup;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
use App\Tarefa\Armazenamento\ChavesDeTarefa;
use App\Tarefa\Service\AcessoAMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tarefas no backup (spec backup, seções 2 e 4). Escritório 1: A (primário), C (colaborador que vê as pastas), D
 * (colaborador com o módulo mas sem ver pasta) e R (acesso restrito: não tem o módulo Tarefas). Escritório 2: B.
 */
#[CoversClass(ColetorDeTarefas::class)]
final class ColetorDeTarefasTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $d;
    private User $r;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos Comum', ['modules.tarefas.view', 'modules.pastas.view', 'resources.pasta.view']);
        $this->d = $this->criarColaborador($this->t1, 'Diana Sem Pasta', ['modules.tarefas.view']);
        $this->r = $this->criarColaborador($this->t1, 'Rita Restrita', ['modules.tarefas.view', 'modules.pastas.view', 'resources.pasta.view']);
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->r, 'tenant' => $this->t1])?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');

        $this->semear();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        $c = static::getContainer();

        return [new ColetorDeTarefas($c->get(PermissionChecker::class), $c->get(AcessoAPasta::class), $c->get(AcessoAMeta::class), $this->em(), $lote)];
    }

    private function semear(): void
    {
        $p1 = $this->criarPasta($this->t1, 'P1 ABERTA');
        $p3 = $this->criarPasta($this->t1, 'P3 COM CADEADO');
        $p4 = $this->criarPasta($this->t1, 'P4 NA LIXEIRA');
        $pb = $this->criarPasta($this->t2, 'PB DO B');
        $p5 = $this->criarPasta($this->t1, 'P5 SEM RESPONSAVEL');
        foreach ([$p1, $p3, $p4] as $pasta) {
            $pasta->setResponsavel($this->a);
        }
        $pb->setResponsavel($this->b);
        $p4->marcarExcluida($this->a, new \DateTimeImmutable());
        $this->trancar($p3, $this->a);

        $this->meta($this->t1, $p1, 'META UM DO CARLOS', $this->a, $this->c, 'um.pdf', 'CONTEUDO DO ANEXO UM');
        $this->meta($this->t1, $p1, 'META DOIS DO PRIMARIO', $this->a, $this->a, 'dois.pdf', 'CONTEUDO DO ANEXO DOIS');
        $this->meta($this->t1, $p3, 'META TRES NO CADEADO', $this->a, $this->c);
        $this->meta($this->t1, $p4, 'META QUATRO NA LIXEIRA', $this->a, $this->c);
        $this->meta($this->t1, $p1, 'META CINCO DA DIANA', $this->a, $this->d);
        $seis = $this->meta($this->t1, $p1, 'META SEIS SO ACOMPANHADA', $this->a, $this->a);
        $seis->alternarAcompanhamento($this->d);
        $this->meta($this->t1, $p5, 'META SETE PASTA SEM DONO', $this->a, $this->a);
        $this->meta($this->t2, $pb, 'META DO ESCRITORIO B', $this->b, $this->b, 'b.pdf', 'CONTEUDO DO ANEXO B');
        $this->em()->flush();
    }

    private function meta(Tenant $tenant, Pasta $pasta, string $titulo, User $criador, User $responsavel, ?string $anexo = null, string $conteudo = ''): Tarefa
    {
        $meta = (new Tarefa())->setTitulo($titulo)->setDescricao('DESCRICAO ' . $titulo)->setPasta($pasta)->setTenant($tenant)->setCriadoPor($criador);
        $meta->addResponsavel($responsavel);
        $this->em()->persist($meta);

        $mensagem = (new TarefaMensagem())->setTarefa($meta)->setTenant($tenant)->setUsuario($criador)->setMensagem('MENSAGEM DA ' . $titulo);
        if ($anexo !== null) {
            $mensagem->setArquivoAnexo(ChavesDeTarefa::PREFIXO_DA_COLUNA . 'anx-' . uniqid() . '.pdf');
            $this->duble->memoria->semear(ChavesDeTarefa::anexoDeMensagem($mensagem), $conteudo);
        }
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $meta;
    }

    #[TestDox('Primário leva as metas do escritório (inclusive a do cadeado) e os anexos; sem a lixeira e sem o escritório B')]
    public function testPrimarioLevaTudoMenosLixeiraENadaDoB(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);

        foreach (['META UM DO CARLOS', 'META DOIS DO PRIMARIO', 'META TRES NO CADEADO', 'META CINCO DA DIANA', 'META SEIS', 'MENSAGEM DA META UM'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        self::assertSame('CONTEUDO DO ANEXO UM', self::entradaCom($zip, 'tarefas/META UM DO CARLOS/anexos/'));
        self::assertSame('CONTEUDO DO ANEXO DOIS', self::entradaCom($zip, 'tarefas/META DOIS DO PRIMARIO/anexos/'));
        self::assertStringContainsString('Tarefas: 5', $zip['LEIA-ME.txt']);
        foreach (['META QUATRO NA LIXEIRA', 'ESCRITORIO B', 'ANEXO B', 'P4 NA LIXEIRA', 'META SETE'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('B (escritório 2) leva só a meta e o anexo do escritório 2')]
    public function testOutroEscritorioLevaSoOSeu(): void
    {
        $zip  = $this->backupDe($this->b, $this->t2);
        $tudo = self::tudo($zip);

        self::assertSame('CONTEUDO DO ANEXO B', self::entradaCom($zip, 'tarefas/META DO ESCRITORIO B/anexos/'));
        foreach (['META UM', 'META DOIS', 'META TRES', 'ANEXO UM', 'ANEXO DOIS'] as $do1) {
            self::assertStringNotContainsString($do1, $tudo, $do1);
        }
    }

    #[TestDox('C vê as pastas abertas: leva as metas delas e não a da pasta com cadeado nem a da lixeira')]
    public function testCadeadoELixeiraFicamFora(): void
    {
        $zip  = $this->backupDe($this->c, $this->t1);
        $tudo = self::tudo($zip);

        foreach (['META UM DO CARLOS', 'META DOIS DO PRIMARIO', 'META CINCO DA DIANA', 'META SEIS'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        foreach (['META TRES', 'META QUATRO', 'CONTEUDO DO ANEXO B'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('D (sem ver pasta): só a meta de que cuida; a dos outros e a só acompanhada ficam fora, com seus anexos')]
    public function testSemVerAPastaSoALevaQuemCuida(): void
    {
        $zip  = $this->backupDe($this->d, $this->t1);
        $tudo = self::tudo($zip);

        self::assertStringContainsString('META CINCO DA DIANA', $tudo);
        foreach (['META UM', 'META DOIS', 'META SEIS', 'ANEXO UM', 'ANEXO DOIS', 'META TRES', 'META QUATRO'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('R (acesso restrito) não tem o módulo Tarefas: o coletor não roda')]
    public function testRestritoNaoTemOModulo(): void
    {
        self::assertSame(['LEIA-ME.txt', 'manifesto.json'], array_keys($this->backupDe($this->r, $this->t1)));
    }

    #[TestDox('Sem o módulo de tarefas o coletor não roda: o ZIP traz só LEIA-ME e manifesto')]
    public function testSemModuloNaoRoda(): void
    {
        $semModulo = $this->criarColaborador($this->t1, 'Sem Modulo', ['resources.pasta.view']);

        self::assertSame(['LEIA-ME.txt', 'manifesto.json'], array_keys($this->backupDe($semModulo, $this->t1)));
    }

    #[TestDox('Anexo antigo com caminho inválido não derruba: vai ao LEIA-ME; documento juntado da pasta não vira anexo')]
    public function testAnexoInvalidoVaiAoLeiaMe(): void
    {
        $pasta = $this->criarPasta($this->t1, 'P1 ABERTA');
        $pasta->setResponsavel($this->a);
        $meta  = $this->meta($this->t1, $pasta, 'META COM ANEXO RUIM', $this->a, $this->a);
        $this->em()->persist((new TarefaMensagem())->setTarefa($meta)->setTenant($this->t1)->setUsuario($this->a)->setMensagem('ruim')->setArquivoAnexo('/uploads/tarefas/admin/x.pdf'));
        $this->em()->persist((new TarefaMensagem())->setTarefa($meta)->setTenant($this->t1)->setUsuario($this->a)->setMensagem('juntado')->juntarDocumentoDaPasta(99));
        $this->em()->flush();

        $zip = $this->backupDe($this->a, $this->t1);

        self::assertStringContainsString('META COM ANEXO RUIM', self::tudo($zip));
        self::assertStringContainsString('anexo-', $zip['LEIA-ME.txt']);
        self::assertStringNotContainsString('pasta_documento', self::tudo($zip));
    }

    #[TestDox('Lote de 1 (clear a cada meta): o ZIP sai igual para A, C, D e B')]
    public function testLoteDeUmGeraOMesmoZip(): void
    {
        foreach ([[$this->a, $this->t1], [$this->c, $this->t1], [$this->d, $this->t1], [$this->b, $this->t2]] as [$usuario, $tenant]) {
            $this->assertLoteDeUmIgual($usuario, $tenant);
        }
    }
}
