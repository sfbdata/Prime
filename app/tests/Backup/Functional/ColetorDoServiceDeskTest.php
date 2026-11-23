<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\Coletor\ColetorDoServiceDesk;
use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\ServiceDesk\ChamadoInteracao;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\ServiceDesk\Armazenamento\ChavesDeServiceDesk;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Entity\HistoricoDoChamado;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Service Desk no backup. Escritório 1: A (primário), C (módulo), S (sem módulo). Escritório 2: B.
 * Chamados novos: todos do escritório. Chamados antigos: só os que a pessoa abriu, sem nota interna.
 */
#[CoversClass(ColetorDoServiceDesk::class)]
final class ColetorDoServiceDeskTest extends KernelTestCase
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
        $this->c = $this->criarColaborador($this->t1, 'Carlos', ['modules.servicedesk.view']);
        $this->s = $this->criarColaborador($this->t1, 'Sem Modulo', []);

        $this->novo($this->t1, 1, $this->a, 'ASSUNTO NOVO DO A', 'HISTORICO DO NOVO A');
        $this->novo($this->t1, 2, $this->c, 'ASSUNTO NOVO DO C', 'HISTORICO DO NOVO C');
        $this->novo($this->t2, 1, $this->b, 'ASSUNTO NOVO DO B', 'HISTORICO DO NOVO B');
        $this->antigo($this->t1, $this->a, 'TITULO ANTIGO DO A', 'anexo-a.pdf', 'CONTEUDO ANEXO A');
        $this->antigo($this->t1, $this->c, 'TITULO ANTIGO DO C', 'anexo-c.pdf', 'CONTEUDO ANEXO C');
        $this->antigo($this->t2, $this->b, 'TITULO ANTIGO DO B', 'anexo-b.pdf', 'CONTEUDO ANEXO B');
        $this->em()->flush();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDoServiceDesk(static::getContainer()->get(PermissionChecker::class), $this->em(), $lote)];
    }

    private function novo(Tenant $tenant, int $numero, User $solicitante, string $assunto, string $historico): void
    {
        $chamado = ChamadoDeSuporte::abrir($tenant, $numero, $solicitante, TipoDeChamado::Suporte, PrioridadeDoChamado::Media, $assunto, 'DESCRICAO ' . $assunto, null, null, null);
        $this->em()->persist($chamado);
        $this->em()->persist(new HistoricoDoChamado($tenant, $chamado, $historico));
    }

    private function antigo(Tenant $tenant, User $solicitante, string $titulo, string $anexo, string $conteudo): void
    {
        $chamado = (new Chamado())->setTitulo($titulo)->setDescricao('DESCRICAO ' . $titulo)->setTenant($tenant)->setSolicitante($solicitante);
        $this->em()->persist($chamado);

        $publica = (new ChamadoInteracao())->setChamado($chamado)->setAutor($solicitante)->setMensagem('RESPOSTA PUBLICA ' . $titulo);
        $interna = (new ChamadoInteracao())->setChamado($chamado)->setAutor($solicitante)->setMensagem('NOTA INTERNA ' . $titulo)->setInterno(true);
        $this->em()->persist($publica);
        $this->em()->persist($interna);

        $arquivo = (new ChamadoAnexo())->setChamado($chamado)->setEnviadoPor($solicitante)->setNomeOriginal($anexo)->setNomeArquivo('sd-' . uniqid() . '.pdf')->setMimeType('application/pdf')->setTamanho(strlen($conteudo));
        $this->em()->persist($arquivo);
        $this->em()->flush();
        $this->duble->memoria->semear(ChavesDeServiceDesk::anexoDeChamado($arquivo), $conteudo);
    }

    #[TestDox('A leva os chamados novos do escritório e só os antigos dele, com anexo e sem nota interna; nada do B')]
    public function testPrimario(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);

        foreach (['ASSUNTO NOVO DO A', 'ASSUNTO NOVO DO C', 'HISTORICO DO NOVO C', 'TITULO ANTIGO DO A', 'RESPOSTA PUBLICA TITULO ANTIGO DO A'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        self::assertSame('CONTEUDO ANEXO A', self::entradaCom($zip, 'service_desk/'));
        foreach (['TITULO ANTIGO DO C', 'CONTEUDO ANEXO C', 'NOTA INTERNA', 'NOVO DO B', 'ANTIGO DO B', 'CONTEUDO ANEXO B'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('B (escritório 2) leva só os dele')]
    public function testOutroEscritorio(): void
    {
        $tudo = self::tudo($this->backupDe($this->b, $this->t2));

        self::assertStringContainsString('ASSUNTO NOVO DO B', $tudo);
        self::assertStringContainsString('CONTEUDO ANEXO B', $tudo);
        self::assertStringNotContainsString('NOVO DO A', $tudo);
        self::assertStringNotContainsString('ANTIGO DO A', $tudo);
        self::assertStringNotContainsString('NOVO DO C', $tudo);
    }

    #[TestDox('Sem o módulo não leva nada')]
    public function testSemModulo(): void
    {
        self::assertArrayNotHasKey('dados/service_desk.json', $this->backupDe($this->s, $this->t1));
    }

    #[TestDox('Lote de 1 gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
