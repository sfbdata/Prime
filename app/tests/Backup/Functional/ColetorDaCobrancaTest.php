<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDaCobranca;
use App\Backup\Coletor\ColetorDoBackup;
use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraDocumentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Cobrança no backup: o escritório inteiro (devedores incluídos) para quem tem o módulo `cobrancas`, como a tela.
 * Escritório 1: A (primário), C (colaborador com o módulo), S (sem módulo). Escritório 2: B.
 */
#[CoversClass(ColetorDaCobranca::class)]
final class ColetorDaCobrancaTest extends KernelTestCase
{
    use Factories;
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
        $this->c = $this->criarColaborador($this->t1, 'Carlos', ['modules.cobrancas.view']);
        $this->s = $this->criarColaborador($this->t1, 'Sem Modulo', []);
        $this->em()->flush();

        $this->carteira($this->t1, 'CARTEIRA DO ESCRITORIO UM', 'DEVEDOR UM', 'DOC UM');
        $this->carteira($this->t2, 'CARTEIRA DO ESCRITORIO DOIS', 'DEVEDOR DOIS', 'DOC DOIS');
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDaCobranca(static::getContainer()->get(PermissionChecker::class), $this->em(), $lote)];
    }

    private function carteira(Tenant $tenant, string $nome, string $devedor, string $conteudo): void
    {
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'nome' => $nome, 'cliente' => ClientePFFactory::createOne(['tenant' => $tenant])]);
        $objeto   = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => 'OBJETO ' . $nome]);
        $pessoa   = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $devedor]);
        CasoCobrancaFactory::createOne(['tenant' => $tenant, 'objeto' => $objeto, 'pessoaCobradaAtual' => $pessoa]);
        $documento = CarteiraDocumentoFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira, 'nomeOriginal' => 'contrato.pdf', 'titulo' => 'TITULO ' . $nome]);
        $this->duble->memoria->semear(ChavesDeCobranca::documentoDeCarteira($documento->_real()), $conteudo);
    }

    #[TestDox('Quem tem o módulo (primário e colaborador) leva o escritório inteiro, com devedores e documentos; nada do escritório 2')]
    public function testEscritorioInteiro(): void
    {
        foreach ([$this->a, $this->c] as $quem) {
            $zip  = $this->backupDe($quem, $this->t1);
            $tudo = self::tudo($zip);

            foreach (['CARTEIRA DO ESCRITORIO UM', 'OBJETO CARTEIRA DO ESCRITORIO UM', 'DEVEDOR UM', 'DOC UM'] as $esperado) {
                self::assertStringContainsString($esperado, $tudo, $esperado);
            }
            foreach (['ESCRITORIO DOIS', 'DEVEDOR DOIS', 'DOC DOIS'] as $fora) {
                self::assertStringNotContainsString($fora, $tudo, $fora);
            }
        }
    }

    #[TestDox('B (escritório 2) leva só o escritório 2')]
    public function testOutroEscritorio(): void
    {
        $tudo = self::tudo($this->backupDe($this->b, $this->t2));

        self::assertStringContainsString('DEVEDOR DOIS', $tudo);
        self::assertStringContainsString('DOC DOIS', $tudo);
        self::assertStringNotContainsString('DEVEDOR UM', $tudo);
        self::assertStringNotContainsString('DOC UM', $tudo);
    }

    #[TestDox('Quem não tem o módulo não leva nada (devedores só para quem tem o módulo)')]
    public function testSemModulo(): void
    {
        $zip = $this->backupDe($this->s, $this->t1);

        self::assertArrayNotHasKey('dados/cobranca.json', $zip);
        self::assertStringNotContainsString('DEVEDOR UM', self::tudo($zip));
    }

    #[TestDox('Lote de 1 gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
