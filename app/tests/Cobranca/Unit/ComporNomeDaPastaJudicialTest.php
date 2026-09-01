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
 * Padrão do nome da pasta aberta pela judicialização: `<fantasia do cliente da carteira> - <pessoa
 * cobrada>` (decisão do dono, 2026-09-01). Antes disso a pasta nascia só com o nome da pessoa, e o
 * escritório vinha digitando o prefixo à mão — duas das três pastas judiciais de produção já se
 * chamavam `APLC TOP LIFE 1 - <NOME>`; a terceira escapou, e foi ela que motivou o padrão.
 *
 * O prefixo só existe quando o cliente da carteira é PJ E tem nome fantasia. Sem fantasia o nome
 * cai para a pessoa sozinha — que é o comportamento anterior, não um erro.
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
    #[TestDox('Carteira de cliente PJ: o nome fantasia vira prefixo da pessoa cobrada')]
    public function carteiraDeClientePjPrefixaAPessoaComONomeFantasia(): void
    {
        $caso = $this->caso('CLAUDIO SILVA DA CRUZ', $this->clientePj('APLC TOP LIFE 1'));

        self::assertSame('APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ', $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Carteira de cliente PF: sem fantasia, o nome é só o da pessoa cobrada')]
    public function carteiraDeClientePfDevolveSoONomeDaPessoa(): void
    {
        $caso = $this->caso('CLAUDIO SILVA DA CRUZ', new ClientePF());

        self::assertSame('CLAUDIO SILVA DA CRUZ', $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Cliente PJ sem nome fantasia: o nome é só o da pessoa cobrada')]
    public function clientePjSemNomeFantasiaDevolveSoONomeDaPessoa(): void
    {
        $caso = $this->caso('CLAUDIO SILVA DA CRUZ', $this->clientePj(null));

        self::assertSame('CLAUDIO SILVA DA CRUZ', $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Caso sem pessoa cobrada: não há nome a compor')]
    public function casoSemPessoaCobradaDevolveNull(): void
    {
        $caso = new CasoCobranca();
        $caso->setObjeto($this->objeto($this->clientePj('APLC TOP LIFE 1')));

        self::assertNull($this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Pessoa com nome em branco: devolve nulo em vez de um prefixo solto')]
    public function pessoaComNomeEmBrancoDevolveNull(): void
    {
        $caso = $this->caso('   ', $this->clientePj('APLC TOP LIFE 1'));

        self::assertNull($this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Composição acima de 255: cai para o nome da pessoa, sem cortar no meio')]
    public function composicaoAcimaDoLimiteDaColunaDevolveSoONomeDaPessoa(): void
    {
        // `pasta.nome_cliente` tem 255; `cliente_pj.nome_fantasia` também. Juntos cabem 513 — nome
        // cortado no meio é pior que nome curto, e o campo segue editável no modal.
        $nome = str_repeat('B', 100);
        $caso = $this->caso($nome, $this->clientePj(str_repeat('A', 200)));

        self::assertSame($nome, $this->sut->paraCaso($caso));
    }

    #[Test]
    #[TestDox('Composição exatamente em 255: cabe na coluna e é mantida')]
    public function composicaoNoLimiteExatoDaColunaEhMantida(): void
    {
        // 200 + 3 (' - ') + 52 = 255, o maior nome que a coluna aceita inteiro.
        $fantasia = str_repeat('A', 200);
        $nome = str_repeat('B', 52);
        $caso = $this->caso($nome, $this->clientePj($fantasia));

        $composto = $this->sut->paraCaso($caso);

        self::assertSame(255, mb_strlen((string) $composto));
        self::assertSame($fantasia . ' - ' . $nome, $composto);
    }

    #[Test]
    #[TestDox('A regra também aceita valores soltos, para o mapa em lote da listagem')]
    public function comporAceitaFantasiaENomeSoltos(): void
    {
        // A listagem resolve o identificador de 50 pastas numa consulta só e recebe COLUNAS, não
        // entidades. A regra tem de ser a mesma dos dois lados — duplicá-la em PHP seria a receita
        // para a tela e o modal divergirem com o tempo.
        self::assertSame(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            $this->sut->compor('APLC TOP LIFE 1', 'CLAUDIO SILVA DA CRUZ'),
        );
    }

    #[Test]
    #[TestDox('Valores soltos seguem as MESMAS quedas da entidade')]
    public function comporSegueAsMesmasQuedas(): void
    {
        self::assertSame('CLAUDIO', $this->sut->compor(null, 'CLAUDIO'), 'sem fantasia, só a pessoa');
        self::assertSame('CLAUDIO', $this->sut->compor('   ', 'CLAUDIO'), 'fantasia em branco, só a pessoa');
        self::assertNull($this->sut->compor('APLC TOP LIFE 1', '  '), 'sem pessoa não há nome a compor');
        self::assertNull($this->sut->compor(null, null), 'sem nada, nada');
        self::assertSame(
            str_repeat('B', 100),
            $this->sut->compor(str_repeat('A', 200), str_repeat('B', 100)),
            'acima de 255 cai para o nome da pessoa',
        );
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

        return $cliente;
    }
}
