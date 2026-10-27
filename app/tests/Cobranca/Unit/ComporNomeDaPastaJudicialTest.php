<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cliente\Entity\Cliente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Nome da pasta aberta pela judicialização: só o CLIENTE DA CARTEIRA (decisão do dono, 2026-10-27, no
 * lugar de `<fantasia> - <pessoa cobrada>`). Nome fantasia primeiro; sem ele, razão social (PJ) ou
 * nome completo (PF). A pessoa cobrada não entra.
 */
#[CoversClass(ComporNomeDaPastaJudicial::class)]
final class ComporNomeDaPastaJudicialTest extends TestCase
{
    private ComporNomeDaPastaJudicial $sut;

    protected function setUp(): void
    {
        $this->sut = new ComporNomeDaPastaJudicial();
    }

    #[Test]
    #[TestDox('Carteira de cliente PJ: o nome é a fantasia, sem a pessoa cobrada')]
    public function carteiraDeClientePjDevolveSoAFantasia(): void
    {
        $caso = $this->caso('CLAUDIO SILVA DA CRUZ', $this->clientePj('APLC TOP LIFE 1'));

        self::assertSame('APLC TOP LIFE 1', $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Cliente PJ sem nome fantasia: cai para a razão social')]
    public function clientePjSemFantasiaDevolveARazaoSocial(): void
    {
        $caso = $this->caso('CLAUDIO SILVA DA CRUZ', $this->clientePj(null));

        self::assertSame('APLC EMPREENDIMENTOS LTDA', $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Carteira de cliente PF: o nome é o nome completo do cliente')]
    public function carteiraDeClientePfDevolveONomeCompleto(): void
    {
        $pf = new ClientePF();
        $pf->setNomeCompleto('MARIA DO CARMO');

        self::assertSame('MARIA DO CARMO', $this->sut->paraCaso($this->caso('CLAUDIO', $pf)));
    }

    #[Test]
    #[TestDox('Caso sem carteira ou sem cliente: não há nome a compor')]
    public function casoSemClienteDeCarteiraDevolveNull(): void
    {
        self::assertNull($this->sut->paraCaso(new CasoCobranca()));
    }

    #[Test]
    #[TestDox('A pessoa cobrada não entra no nome, nem quando falta o cliente')]
    public function casoSemPessoaCobradaAindaTemONomeDoCliente(): void
    {
        $caso = new CasoCobranca();
        $caso->setObjeto($this->objeto($this->clientePj('APLC TOP LIFE 1')));

        self::assertSame('APLC TOP LIFE 1', $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Valores soltos (mapa em lote da listagem) seguem as MESMAS quedas da entidade')]
    public function comporSegueAsMesmasQuedas(): void
    {
        self::assertSame('APLC TOP LIFE 1', $this->sut->compor('APLC TOP LIFE 1', 'APLC LTDA'));
        self::assertSame('APLC LTDA', $this->sut->compor(null, 'APLC LTDA'), 'sem fantasia, a razão social');
        self::assertSame('APLC LTDA', $this->sut->compor('   ', ' APLC LTDA '), 'fantasia em branco, a razão social aparada');
        self::assertNull($this->sut->compor(null, '  '), 'sem nenhum dos dois, nada');
        self::assertNull($this->sut->compor(null, null));
    }

    #[Test]
    #[TestDox('Nome acima de 255 é cortado no limite da coluna, em vez de derrubar o flush')]
    public function nomeAcimaDoLimiteEhCortado(): void
    {
        self::assertSame(255, mb_strlen((string) $this->sut->compor(null, str_repeat('A', 300))));
    }

    private function caso(string $nomeDaPessoa, Cliente $clienteDaCarteira): CasoCobranca
    {
        $pessoa = new Pessoa();
        $pessoa->setNome($nomeDaPessoa);

        $caso = new CasoCobranca();
        $caso->setObjeto($this->objeto($clienteDaCarteira));
        $caso->setPessoaCobradaAtual($pessoa);

        return $caso;
    }

    private function objeto(Cliente $clienteDaCarteira): ObjetoCobranca
    {
        $carteira = new Carteira();
        $carteira->setCliente($clienteDaCarteira);

        $objeto = new ObjetoCobranca();
        $objeto->setCarteira($carteira);

        return $objeto;
    }

    private function clientePj(?string $nomeFantasia): ClientePJ
    {
        $cliente = new ClientePJ();
        $cliente->setNomeFantasia($nomeFantasia);
        $cliente->setRazaoSocial('APLC EMPREENDIMENTOS LTDA');

        return $cliente;
    }
}
