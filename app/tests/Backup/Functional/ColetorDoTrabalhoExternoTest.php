<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\Coletor\ColetorDoTrabalhoExterno;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoMensagem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Trabalho Externo no backup: só as demandas que a PRÓPRIA pessoa criou. Escritório 1: A (primário), C (colega com o
 * módulo), S (sem módulo). Escritório 2: B.
 */
#[CoversClass(ColetorDoTrabalhoExterno::class)]
final class ColetorDoTrabalhoExternoTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $s;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos', ['modules.trabalho_externo.view']);
        $this->s = $this->criarColaborador($this->t1, 'Sem Modulo', []);

        $this->demanda($this->t1, $this->a, 'DEMANDA-DO-A', 'arq-a.pdf', 'CONTEUDO-DOC-A');
        $this->demanda($this->t1, $this->c, 'DEMANDA-DO-C', 'arq-c.pdf', 'CONTEUDO-DOC-C');
        $this->demanda($this->t2, $this->b, 'DEMANDA-DO-B', 'arq-b.pdf', 'CONTEUDO-DOC-B');
        $this->em()->flush();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDoTrabalhoExterno(static::getContainer()->get(PermissionChecker::class), $this->em(), $lote)];
    }

    private function demanda(Tenant $tenant, User $dono, string $texto, string $arquivo, string $conteudo): void
    {
        $d = new DemandaExterna($tenant, $dono);
        $dados = new DadosDaSolicitacao();
        $dados->escritorio = 'ESCRITORIO ' . $texto;
        $dados->email = 'x@example.com';
        $dados->descricao = $texto;
        $d->preencher($dados);
        $this->em()->persist($d);

        $autor = new Autor($dono, $dono->getFullName() ?? 'x', Perfil::Solicitante);
        $d->registrar($autor, 'MENSAGEM ' . $texto, TipoMensagem::Normal);
        $d->registrar(new Autor(null, 'Equipe', Perfil::Master), 'NOTA-INTERNA ' . $texto, TipoMensagem::Interna);
        $doc = new DocumentoDemanda($d, $arquivo, 'te-' . uniqid() . '.pdf', strlen($conteudo), 'application/pdf', $autor);
        $d->anexar($doc);
        $this->em()->persist($doc);
        $this->em()->flush();
        $this->duble->memoria->semear(ChavesDeTrabalhoExterno::documento($doc), $conteudo);
    }

    #[TestDox('A leva só a demanda dele, com documento e mensagem, sem nota interna; nada do colega nem do B')]
    public function testSoAsDemandasDaPropriaPessoa(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);

        foreach (['DEMANDA-DO-A', 'MENSAGEM DEMANDA-DO-A'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        self::assertSame('CONTEUDO-DOC-A', self::entradaCom($zip, 'trabalho_externo/'));
        foreach (['DEMANDA-DO-C', 'CONTEUDO-DOC-C', 'DEMANDA-DO-B', 'CONTEUDO-DOC-B', 'NOTA-INTERNA'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('O colega C leva só a dele; B (escritório 2) leva só a dele')]
    public function testColegaEOutroEscritorio(): void
    {
        $c = self::tudo($this->backupDe($this->c, $this->t1));
        self::assertStringContainsString('DEMANDA-DO-C', $c);
        self::assertStringNotContainsString('DEMANDA-DO-A', $c);

        $b = self::tudo($this->backupDe($this->b, $this->t2));
        self::assertStringContainsString('DEMANDA-DO-B', $b);
        self::assertStringNotContainsString('DEMANDA-DO-A', $b);
        self::assertStringNotContainsString('DEMANDA-DO-C', $b);
    }

    #[TestDox('Sem o módulo não leva nada')]
    public function testSemModulo(): void
    {
        self::assertArrayNotHasKey('dados/trabalho_externo.json', $this->backupDe($this->s, $this->t1));
    }

    #[TestDox('Lote de 1 gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
