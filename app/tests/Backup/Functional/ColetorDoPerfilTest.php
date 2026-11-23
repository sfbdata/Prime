<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\Coletor\ColetorDoPerfil;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Armazenamento\ChavesDePerfil;
use App\Profile\Entity\UserProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Perfil no backup: só os dados da pessoa que pediu; nunca senha, tokens ou o perfil de outra pessoa. */
#[CoversClass(ColetorDoPerfil::class)]
final class ColetorDoPerfilTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private User $a;
    private User $c;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos Colega', []);

        $perfil = new UserProfile($this->a);
        $perfil->setTelefone('11999990000');
        $perfil->setFotoUrl('foto-da-paula.png');
        $this->a->setProfile($perfil);
        $this->a->setOabNumero('12345');
        $this->a->setOabUf('SP');
        $this->a->setInvitationToken('TOKEN-SECRETO-DE-CONVITE');
        $this->em()->persist($perfil);
        $this->em()->flush();
        $this->duble->memoria->semear(ChavesDePerfil::foto($perfil), 'BYTES DA FOTO');
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDoPerfil($this->em())];
    }

    #[TestDox('Leva nome, e-mail, OAB, telefone e a foto da própria pessoa, sem senha nem token')]
    public function testDadosDaPropriaPessoa(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $dado = self::json($zip, 'dados/perfil.json');

        self::assertSame('Paula Primária', $dado['nome']);
        self::assertSame($this->a->getEmail(), $dado['email']);
        self::assertSame('12345', $dado['oab']['numero']);
        self::assertSame('11999990000', $dado['telefone']);
        self::assertSame('BYTES DA FOTO', self::entradaCom($zip, 'perfil/foto-da-paula'));
        $tudo = self::tudo($zip);
        self::assertStringNotContainsString('TOKEN-SECRETO', $tudo);
        self::assertStringNotContainsString((string) $this->a->getPassword(), $tudo);
    }

    #[TestDox('O colega leva só o dele: nada da Paula')]
    public function testColegaNaoLevaOPerfilDeOutro(): void
    {
        $zip  = $this->backupDe($this->c, $this->t1);
        $tudo = self::tudo($zip);

        self::assertSame('Carlos Colega', self::json($zip, 'dados/perfil.json')['nome']);
        self::assertStringNotContainsString('Paula', $tudo);
        self::assertStringNotContainsString('11999990000', $tudo);
        self::assertStringNotContainsString('BYTES DA FOTO', $tudo);
    }

    #[TestDox('Lote de 1 gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
