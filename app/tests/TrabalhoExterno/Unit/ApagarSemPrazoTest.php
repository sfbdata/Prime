<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Enum\PoderSemPrazo;
use App\Tests\Tenant\Support\CriaPoderSemPrazo;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Edição sem prazo no "Apagar para todos" do Trabalho Externo (spec `edicao-sem-prazo-master-primario.md`, item 8):
 * o primário que vale é o do escritório DONO da demanda; o Master do TE segue como sempre (sem selo).
 */
#[CoversClass(PoliticaDaDemanda::class)]
final class ApagarSemPrazoTest extends TestCase
{
    use CriaPoderSemPrazo;

    private Tenant $dono;
    private User $primario;
    private MensagemDemanda $alheia;
    private \DateTimeImmutable $depois;

    protected function setUp(): void
    {
        $this->dono = Apoio::tenant(1);
        $this->primario = Apoio::usuario(60);
        // Mensagem de outra pessoa (o usuário 50 de `Apoio::autor`), vista três dias depois: fora dos 60 min.
        $this->alheia = new MensagemDemanda(Apoio::demanda(Situacao::Analise, $this->dono), Apoio::autor(Perfil::Solicitante), TipoMensagem::Normal, 'Olá');
        $this->depois = new \DateTimeImmutable('+3 days');
    }

    #[TestDox('O primário do escritório dono apaga a mensagem alheia fora do prazo, com o selo, se a opção estiver ligada')]
    public function testPrimarioDoDonoComAOpcao(): void
    {
        $politica = new PoliticaDaDemanda($this->poderSemPrazo($this->vinculo($this->dono), true));
        $ctx = Apoio::contexto(Perfil::Solicitante, $this->dono, usuario: $this->primario);

        self::assertTrue($politica->podeApagarMensagem($ctx, $this->alheia, $this->depois));
        self::assertFalse($politica->apagaPelaRegraDeSempre($ctx, $this->alheia, $this->depois));
        self::assertSame(PoderSemPrazo::Primario, $politica->seloDoApagar($ctx, $this->alheia, $this->depois));
    }

    #[TestDox('Com a opção desligada, o primário volta à regra de sempre')]
    public function testPrimarioComAOpcaoDesligada(): void
    {
        $politica = new PoliticaDaDemanda($this->poderSemPrazo($this->vinculo($this->dono), false));
        $ctx = Apoio::contexto(Perfil::Solicitante, $this->dono, usuario: $this->primario);

        self::assertFalse($politica->podeApagarMensagem($ctx, $this->alheia, $this->depois));
        self::assertNull($politica->seloDoApagar($ctx, $this->alheia, $this->depois));
    }

    #[TestDox('O primário de OUTRO escritório (a Equipe da Central, por exemplo) não ganha o poder na demanda alheia')]
    public function testPrimarioDeOutroEscritorio(): void
    {
        $sede = Apoio::tenant(2);
        $politica = new PoliticaDaDemanda($this->poderSemPrazo($this->vinculo($sede), true));
        $ctx = Apoio::contexto(Perfil::Equipe, $sede, usuario: $this->primario);

        self::assertFalse($politica->podeApagarMensagem($ctx, $this->alheia, $this->depois));
    }

    #[TestDox('O Master do TE apaga como sempre, sem selo; na prévia e no registro do sistema, ninguém')]
    public function testMasterSemSeloEPreviaSemPoder(): void
    {
        $politica = new PoliticaDaDemanda($this->poderSemPrazo($this->vinculo($this->dono), true));
        $master = Apoio::contexto(Perfil::Master, usuario: Apoio::usuario(70, ['ROLE_SUPER_ADMIN']));
        self::assertTrue($politica->podeApagarMensagem($master, $this->alheia, $this->depois));
        self::assertNull($politica->seloDoApagar($master, $this->alheia, $this->depois));

        $ctx = Apoio::contexto(Perfil::Solicitante, $this->dono, usuario: $this->primario);
        $previa = new Contexto($ctx->usuario, $ctx->tenant, Perfil::Solicitante, $ctx->configuracao, previa: true);
        self::assertFalse($politica->podeApagarMensagem($previa, $this->alheia, $this->depois));
        $sistema = new MensagemDemanda(Apoio::demanda(Situacao::Analise, $this->dono), Apoio::autor(), TipoMensagem::Sistema, 'Registro');
        self::assertFalse($politica->podeApagarMensagem($ctx, $sistema, $this->depois));
    }

    private function vinculo(Tenant $tenant): UserTenant
    {
        return (new UserTenant($this->primario, $tenant))->setTenantRole((new TenantRole())->setTenant($tenant)->setIsSystem(true));
    }
}
