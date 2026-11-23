<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDeKanban;
use App\Backup\Coletor\ColetorDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Kanban no backup (spec backup, seções 2 e 4). Escritório 1: A (primário, criador dos quadros), C (participante do
 * quadro 1), D (do escritório, mas fora de todos os quadros). Escritório 2: B.
 */
#[CoversClass(ColetorDeKanban::class)]
final class ColetorDeKanbanTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $d;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos Participante', ['modules.kanban.view']);
        $this->d = $this->criarColaborador($this->t1, 'Diana De Fora', ['modules.kanban.view']);

        $this->semear();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDeKanban(static::getContainer()->get(PermissionChecker::class), $this->em(), $lote)];
    }

    private function semear(): void
    {
        $q1 = $this->quadro($this->t1, 'QUADRO UM', $this->a, 'um.pdf', 'CONTEUDO ANEXO UM');
        $q1->adicionarParticipante($this->c);
        $this->quadro($this->t1, 'QUADRO DOIS SO DO PRIMARIO', $this->a, 'dois.pdf', 'CONTEUDO ANEXO DOIS');
        $this->quadro($this->t2, 'QUADRO DO ESCRITORIO B', $this->b, 'b.pdf', 'CONTEUDO ANEXO B');
        $this->em()->flush();
    }

    private function quadro(Tenant $tenant, string $nome, User $dono, string $anexo, string $conteudo): KanbanBoard
    {
        $quadro = new KanbanBoard($nome, $tenant, $dono);
        $this->em()->persist($quadro);
        $coluna = new KanbanColuna('COLUNA DE ' . $nome, 'normal', 0, $quadro);
        $this->em()->persist($coluna);
        $cartao = new KanbanCard('CARTAO DE ' . $nome, $coluna, $quadro, $dono);
        $this->em()->persist($cartao);
        $this->em()->persist(new KanbanComentario('COMENTARIO DE ' . $nome, $cartao, $dono));
        $lista = new KanbanChecklist('CHECKLIST DE ' . $nome, $cartao);
        $this->em()->persist($lista);
        $this->em()->persist(new KanbanChecklistItem('ITEM DE ' . $nome, $lista));
        $arquivo = new KanbanAnexo($anexo, 'kb-' . uniqid() . '.pdf', strlen($conteudo), 'application/pdf', $cartao, $dono);
        $this->em()->persist($arquivo);
        $this->em()->flush();
        $this->duble->memoria->semear(ChavesDeKanban::anexo($arquivo), $conteudo);

        return $quadro;
    }

    #[TestDox('Criador leva os quadros do seu escritório com cartões, comentários, checklists e anexos, e nada do outro')]
    public function testCriadorLevaSeusQuadrosENadaDoOutroEscritorio(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);
        $json = self::json($zip, 'dados/kanban.json');

        self::assertCount(2, $json);
        foreach (['QUADRO UM', 'QUADRO DOIS', 'CARTAO DE QUADRO UM', 'COMENTARIO DE QUADRO UM', 'CHECKLIST DE QUADRO UM', 'ITEM DE QUADRO UM', 'COLUNA DE QUADRO UM'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        self::assertSame('CONTEUDO ANEXO UM', self::entradaCom($zip, 'kanban/QUADRO UM/CARTAO DE QUADRO UM/um.pdf'));
        self::assertSame('CONTEUDO ANEXO DOIS', self::entradaCom($zip, 'kanban/QUADRO DOIS SO DO PRIMARIO/CARTAO DE QUADRO DOIS SO DO PRIMARIO/dois.pdf'));
        self::assertStringContainsString('Quadros do Kanban: 2', $zip['LEIA-ME.txt']);
        foreach (['ESCRITORIO B', 'ANEXO B', 'b.pdf'] as $deB) {
            self::assertStringNotContainsString($deB, $tudo, $deB);
        }
    }

    #[TestDox('B (escritório 2) leva só o quadro do escritório 2')]
    public function testOutroEscritorioLevaSoOSeu(): void
    {
        $zip  = $this->backupDe($this->b, $this->t2);
        $tudo = self::tudo($zip);

        self::assertSame('CONTEUDO ANEXO B', self::entradaCom($zip, 'b.pdf'));
        foreach (['QUADRO UM', 'QUADRO DOIS', 'ANEXO UM', 'ANEXO DOIS'] as $do1) {
            self::assertStringNotContainsString($do1, $tudo, $do1);
        }
    }

    #[TestDox('Participante leva só o quadro de que participa; o outro quadro do escritório não entra')]
    public function testParticipanteSoLevaOQuadroDele(): void
    {
        $zip  = $this->backupDe($this->c, $this->t1);
        $tudo = self::tudo($zip);

        self::assertStringContainsString('QUADRO UM', $tudo);
        self::assertNotNull(self::entradaCom($zip, 'um.pdf'));
        foreach (['QUADRO DOIS', 'CARTAO DE QUADRO DOIS', 'ANEXO DOIS', 'dois.pdf'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('Quem não é criador nem participante de nenhum quadro leva a lista vazia e nenhum arquivo')]
    public function testQuemEstaForaDosQuadrosNaoLevaNada(): void
    {
        $zip = $this->backupDe($this->d, $this->t1);

        self::assertSame([], self::json($zip, 'dados/kanban.json'));
        self::assertEqualsCanonicalizing(['LEIA-ME.txt', 'manifesto.json', 'dados/kanban.json'], array_keys($zip));
    }

    #[TestDox('Sem o módulo kanban o coletor não roda: o ZIP traz só LEIA-ME e manifesto')]
    public function testSemModuloNaoRoda(): void
    {
        $semModulo = $this->criarColaborador($this->t1, 'Sem Modulo', ['resources.pasta.view']);
        $this->em()->flush();

        self::assertSame(['LEIA-ME.txt', 'manifesto.json'], array_keys($this->backupDe($semModulo, $this->t1)));
    }

    #[TestDox('Lote de 1 (clear a cada quadro): o ZIP sai igual para A, C, D e B')]
    public function testLoteDeUmGeraOMesmoZip(): void
    {
        foreach ([[$this->a, $this->t1], [$this->c, $this->t1], [$this->d, $this->t1], [$this->b, $this->t2]] as [$usuario, $tenant]) {
            $this->assertLoteDeUmIgual($usuario, $tenant);
        }
    }
}
