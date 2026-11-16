<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\AvisoDeContaSuspensa;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\Service\ResultadoDasBarreiras;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As barreiras do escritório, sem sessão (spec mcp-remoto-oauth 4.1; ordem da C13 de perfis-1.2-contas). O mesmo
 * comportamento que o TenantContextValidatorListener tinha; os testes funcionais do listener seguem cobrindo o navegador.
 */
#[CoversClass(BarreirasDoEscritorio::class)]
#[CoversClass(ResultadoDasBarreiras::class)]
final class BarreirasDoEscritorioTest extends TestCase
{
    #[TestDox('conta ativa, vínculo ativo e aprovado: liberado')]
    public function testLiberado(): void
    {
        $conta = $this->conta();
        $resultado = $this->barreiras(false)->conferir($this->vinculo($conta), $conta, $this->pessoa(), true);

        self::assertTrue($resultado->estaLiberado());
        self::assertNull($resultado->recusa());
        self::assertFalse($resultado->ePrimarioNaContaSuspensaAPedido());
    }

    #[TestDox('(a) conta suspensa e conta excluída: a mensagem da C9 e da C9b, também para o Master')]
    public function testConta(): void
    {
        $suspensa = $this->conta(ativa: false);
        $excluida = $this->conta(ativa: false, excluida: true);
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);

        self::assertSame(AvisoDeContaSuspensa::para('Conta Teste'), $this->barreiras(false)->conferir($this->vinculo($suspensa), $suspensa, $master, true)->recusa());
        self::assertSame(AvisoDeContaSuspensa::paraExcluida('Conta Teste'), $this->barreiras(false)->conferir($this->vinculo($excluida), $excluida, $master, true)->recusa());
    }

    #[TestDox('(a) conta já purgada (nula): acesso indisponível')]
    public function testContaPurgada(): void
    {
        self::assertSame(BarreirasDoEscritorio::ACESSO_INDISPONIVEL, $this->barreiras(false)->conferir(null, null, $this->pessoa(), true)->recusa());
    }

    #[TestDox('(a) antes de (c): vínculo ausente com a conta suspensa dá a mensagem da conta')]
    public function testContaAntesDoVinculo(): void
    {
        $suspensa = $this->conta(ativa: false);

        self::assertSame(AvisoDeContaSuspensa::para('Conta Teste'), $this->barreiras(false)->conferir(null, $suspensa, $this->pessoa(), true)->recusa());
    }

    #[TestDox('(b) pedido de exclusão pendente: a equipe é recusada com a mensagem do pedido')]
    public function testPedidoDeExclusaoRecusaAEquipe(): void
    {
        $conta = $this->conta();
        $resultado = $this->barreiras(true)->conferir($this->vinculo($conta), $conta, $this->pessoa(), true);

        self::assertSame(BloqueioPorPedidoDeExclusao::MENSAGEM, $resultado->recusa());
        self::assertFalse($resultado->ePrimarioNaContaSuspensaAPedido());
    }

    #[TestDox('(b) pedido de exclusão pendente: o primário é o caso à parte, e a recusa dele é a mesma mensagem do pedido')]
    public function testPrimarioNaContaSuspensaAPedido(): void
    {
        $conta = $this->conta();
        $resultado = $this->barreiras(true)->conferir($this->vinculo($conta, primario: true), $conta, $this->pessoa(), true);

        self::assertTrue($resultado->ePrimarioNaContaSuspensaAPedido());
        self::assertFalse($resultado->estaLiberado());
        self::assertSame(BloqueioPorPedidoDeExclusao::MENSAGEM, $resultado->recusa());
    }

    #[TestDox('(b) o primário com o vínculo inativo não é o caso à parte: segue para a (c) e a (d)')]
    public function testPrimarioComVinculoInativoSegue(): void
    {
        $conta = $this->conta();
        $vinculo = $this->vinculo($conta, primario: true, situacao: SituacaoNaEquipe::Suspenso);
        $resultado = $this->barreiras(true)->conferir($vinculo, $conta, $this->pessoa(), true);

        self::assertFalse($resultado->ePrimarioNaContaSuspensaAPedido());
        self::assertSame(SituacaoNaEquipe::Suspenso->mensagemDeAcesso('Conta Teste'), $resultado->recusa());
    }

    #[TestDox('(c) vínculo ausente e vínculo inativo de legado (aprovado): acesso indisponível')]
    public function testVinculo(): void
    {
        $conta = $this->conta();
        $legado = $this->vinculo($conta);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($legado, false);

        self::assertSame(BarreirasDoEscritorio::ACESSO_INDISPONIVEL, $this->barreiras(false)->conferir(null, $conta, $this->pessoa(), true)->recusa());
        self::assertSame(BarreirasDoEscritorio::ACESSO_INDISPONIVEL, $this->barreiras(false)->conferir($legado, $conta, $this->pessoa(), true)->recusa());
    }

    #[TestDox('(d) em análise na equipe: a mensagem da situação; com o passe (navegador), o Master não passa por esta barreira')]
    public function testSituacao(): void
    {
        $conta = $this->conta();
        $emAnalise = $this->vinculo($conta, situacao: SituacaoNaEquipe::EmAnalise);

        self::assertSame(SituacaoNaEquipe::EmAnalise->mensagemDeAcesso('Conta Teste'), $this->barreiras(false)->conferir($emAnalise, $conta, $this->pessoa(), true)->recusa());
        self::assertTrue($this->barreiras(false)->conferir($emAnalise, $conta, $this->pessoa(['ROLE_SUPER_ADMIN']), true)->estaLiberado());
    }

    #[TestDox('(d) sem o passe do Master (conector): o Master com vínculo em análise é recusado com a mensagem da situação')]
    public function testSituacaoSemOPasseDoMaster(): void
    {
        $conta = $this->conta();
        $emAnalise = $this->vinculo($conta, situacao: SituacaoNaEquipe::EmAnalise);

        self::assertSame(
            SituacaoNaEquipe::EmAnalise->mensagemDeAcesso('Conta Teste'),
            $this->barreiras(false)->conferir($emAnalise, $conta, $this->pessoa(['ROLE_SUPER_ADMIN']), false)->recusa(),
        );
    }

    private function barreiras(bool $comPedido): BarreirasDoEscritorio
    {
        $pedidos = $this->createStub(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->method('existePendente')->willReturn($comPedido);
        $regra = new RegraDosPrimarios($this->createStub(UserTenantRepository::class));

        return new BarreirasDoEscritorio(new BloqueioPorPedidoDeExclusao($pedidos, $regra), $regra);
    }

    private function conta(bool $ativa = true, bool $excluida = false): Tenant
    {
        $conta = (new Tenant())->setName('Conta Teste')->setIsActive($ativa)->setExcluidoEm($excluida ? new \DateTimeImmutable() : null);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($conta, 7);

        return $conta;
    }

    /** @param list<string> $papeis */
    private function pessoa(array $papeis = ['ROLE_USER']): User
    {
        return (new User())->setIsActive(true)->setFullName('Pessoa')->setRoles($papeis);
    }

    private function vinculo(Tenant $conta, bool $primario = false, SituacaoNaEquipe $situacao = SituacaoNaEquipe::Aprovado): UserTenant
    {
        $vinculo = (new UserTenant($this->pessoa(), $conta))->setTenantRole((new TenantRole())->setIsSystem($primario));
        $vinculo->alterarSituacao($situacao);

        return $vinculo;
    }
}
