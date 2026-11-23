<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\Backup\Coletor\ColetorDaAssinatura;
use App\Backup\Coletor\ColetorDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Assinatura eletrônica no backup: os documentos do escritório (sem a lixeira) para quem tem o módulo, sem a
 * evidência de terceiros (IP, GPS, navegador). Escritório 1: A (primário), C (módulo), S (sem módulo). Escritório 2: B.
 */
#[CoversClass(ColetorDaAssinatura::class)]
final class ColetorDaAssinaturaTest extends KernelTestCase
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
        $this->c = $this->criarColaborador($this->t1, 'Carlos', ['modules.assinatura_eletronica.view']);
        $this->s = $this->criarColaborador($this->t1, 'Sem Modulo', []);

        $this->documento($this->t1, $this->a, 'CONTRATO UM', 'PDF-DO-UM', false);
        $this->documento($this->t1, $this->c, 'CONTRATO DO COLEGA', 'PDF-DO-COLEGA', false);
        $this->documento($this->t1, $this->a, 'CONTRATO NA LIXEIRA', 'PDF-DA-LIXEIRA', true);
        $this->documento($this->t2, $this->b, 'CONTRATO DO ESCRITORIO DOIS', 'PDF-DO-DOIS', false);
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDaAssinatura(static::getContainer()->get(PermissionChecker::class), $this->em(), $lote)];
    }

    private function documento(Tenant $tenant, User $dono, string $nome, string $pdf, bool $lixeira): void
    {
        $agora = new \DateTimeImmutable('2026-11-01 10:00');
        $doc   = new DocumentoParaAssinar($tenant, $dono, $agora);
        $campo = new \ReflectionProperty(DocumentoParaAssinar::class, 'nome');
        $campo->setValue($doc, $nome);
        $chave = 'ass-' . uniqid() . '.pdf';
        $doc->definirArquivo($chave, $nome . '.pdf', 1);
        $this->em()->persist($doc);
        $this->em()->flush();
        $this->duble->memoria->semear(ChavesDeAssinaturaEletronica::arquivo($doc), $pdf);

        $signatario = new Signatario($doc, 1, 'SIGNATARIO ' . $nome, 'sig@example.com', null, 'Contratante', NivelDeAssinatura::Simples);
        foreach (['tokenHash' => 'TOKEN-HASH-SECRETO-' . md5($nome), 'codigoHash' => 'CODIGO-HASH-SECRETO', 'ip' => '203.0.113.9', 'userAgent' => 'NAVEGADOR-SECRETO', 'latitude' => '-23.5111', 'longitude' => '-46.6222', 'precisao' => '77.7', 'selfieChave' => 'SELFIE-CHAVE-SECRETA', 'selfieSha256' => 'SELFIE-SHA-SECRETO', 'rubricaChave' => 'RUBRICA-CHAVE-SECRETA'] as $campo => $valor) {
            (new \ReflectionProperty(Signatario::class, $campo))->setValue($signatario, $valor);
        }
        $this->em()->persist($signatario);
        $evidencias = ['metodo' => 'simples', 'rubrica_sha256' => 'RUBRICA-SHA-SECRETO', 'selfie_sha256' => 'SELFIE-SHA-SECRETO', 'selfie_versao' => 1, 'latitude' => '-23.5111', 'longitude' => '-46.6222', 'precisao' => '77.7', 'porta' => 'PORTA-SECRETA', 'ip' => '203.0.113.9', 'chave_nova_de_evidencia' => 'CHAVE-FUTURA-SECRETA', 'aceite' => ['versao' => 'v1', 'sha256' => 'ACEITE-SHA', 'ip' => 'IP-NO-ACEITE'], 'nota' => 'NOTA ' . $nome];
        $this->em()->persist(new EventoDaTrilha($doc, TipoDeEvento::DocumentoCriado, $evidencias, $agora, null, null, $signatario, '203.0.113.9', 'NAVEGADOR-SECRETO'));
        if ($lixeira) {
            $doc->moverParaLixeira($dono, $agora);
        }
        $this->em()->flush();
    }

    #[TestDox('Primário e colaborador levam os documentos do escritório com PDF, signatário e trilha; sem lixeira e sem o escritório 2')]
    public function testDocumentosDoEscritorio(): void
    {
        foreach ([$this->a, $this->c] as $quem) {
            $zip  = $this->backupDe($quem, $this->t1);
            $tudo = self::tudo($zip);

            foreach (['CONTRATO UM', 'CONTRATO DO COLEGA', 'SIGNATARIO CONTRATO UM', 'ACEITE-SHA'] as $esperado) {
                self::assertStringContainsString($esperado, $tudo, $esperado);
            }
            self::assertSame('PDF-DO-UM', self::entradaCom($zip, 'assinaturas/CONTRATO UM/'));
            foreach (['CONTRATO NA LIXEIRA', 'PDF-DA-LIXEIRA', 'ESCRITORIO DOIS', 'PDF-DO-DOIS'] as $fora) {
                self::assertStringNotContainsString($fora, $tudo, $fora);
            }
        }
    }

    #[TestDox('IP, GPS e navegador dos signatários nunca entram')]
    public function testSemEvidenciaDeTerceiros(): void
    {
        $tudo = self::tudo($this->backupDe($this->a, $this->t1));

        foreach (['203.0.113.9', 'NAVEGADOR-SECRETO', '-23.5', '-46.6', '77.7', 'latitude', 'longitude', 'precisao', 'TOKEN-HASH', 'CODIGO-HASH', 'SELFIE', 'RUBRICA', 'rubrica', 'selfie', 'PORTA-SECRETA', 'porta', 'CHAVE-FUTURA', 'IP-NO-ACEITE', 'token_hash'] as $proibido) {
            self::assertStringNotContainsString($proibido, $tudo, $proibido);
        }
        // O que é inofensivo da trilha continua saindo (allowlist): método e o sha256 do aceite.
        self::assertStringContainsString('ACEITE-SHA', $tudo);
    }

    #[TestDox('B (escritório 2) leva só o dele')]
    public function testOutroEscritorio(): void
    {
        $tudo = self::tudo($this->backupDe($this->b, $this->t2));

        self::assertStringContainsString('CONTRATO DO ESCRITORIO DOIS', $tudo);
        self::assertStringContainsString('PDF-DO-DOIS', $tudo);
        self::assertStringNotContainsString('CONTRATO UM', $tudo);
        self::assertStringNotContainsString('PDF-DO-UM', $tudo);
    }

    #[TestDox('Sem o módulo não leva nada')]
    public function testSemModulo(): void
    {
        self::assertArrayNotHasKey('dados/assinaturas.json', $this->backupDe($this->s, $this->t1));
    }

    #[TestDox('Lote de 1 gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
