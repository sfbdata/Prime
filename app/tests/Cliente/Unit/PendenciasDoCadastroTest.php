<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Service\PendenciasDoCadastro;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regra `cliPend` do desenho 1.2.3 adaptada aos campos reais. Cada pendência tem
 * um caso que a acende SOZINHA a partir de um cadastro completo — assim o teste
 * prova que o serviço olha aquele campo, e não que "algo" falta.
 */
#[CoversClass(PendenciasDoCadastro::class)]
final class PendenciasDoCadastroTest extends TestCase
{
    private PendenciasDoCadastro $sut;

    protected function setUp(): void
    {
        $this->sut = new PendenciasDoCadastro();
    }

    #[TestDox('cadastro PF completo, com os dois anexos, não tem pendência nenhuma')]
    public function testPfCompletoNaoTemPendencia(): void
    {
        $cliente = $this->pfCompleto();

        self::assertSame([], $this->sut->de($cliente));
        self::assertTrue($this->sut->estaCompleto($cliente));
    }

    /**
     * @return iterable<string, array{0: callable(ClientePF): void, 1: string}>
     */
    public static function camposDoPf(): iterable
    {
        yield 'nome'         => [static fn (ClientePF $c) => $c->setNomeCompleto('  '), 'Nome completo não informado'];
        yield 'estado civil' => [static fn (ClientePF $c) => $c->setEstadoCivil(null), 'Estado civil não informado'];
        yield 'profissão'    => [static fn (ClientePF $c) => $c->setProfissao(''), 'Profissão não informada'];
        yield 'RG'           => [static fn (ClientePF $c) => $c->setRg(''), 'RG não informado'];
        yield 'CPF'          => [static fn (ClientePF $c) => $c->setCpf(''), 'CPF não informado'];
        yield 'endereço'     => [static fn (ClientePF $c) => $c->setEndereco(' '), 'Endereço não informado'];
        yield 'cidade'       => [static fn (ClientePF $c) => $c->setCidade(''), 'Cidade não informada'];
        yield 'UF'           => [static fn (ClientePF $c) => $c->setEstado(''), 'UF não informada'];
        yield 'CEP'          => [static fn (ClientePF $c) => $c->setCep(''), 'CEP não informado'];
    }

    /** @param callable(ClientePF): void $apagar */
    #[DataProvider('camposDoPf')]
    #[TestDox('campo obrigatório vazio acende exatamente a pendência dele: $esperada')]
    public function testCampoVazioAcendeSoAPendenciaDele(callable $apagar, string $esperada): void
    {
        $cliente = $this->pfCompleto();
        $apagar($cliente);

        self::assertSame([$esperada], $this->sut->de($cliente));
        self::assertFalse($this->sut->estaCompleto($cliente));
    }

    #[TestDox('sem celular, fixo e e-mail: "Nenhum contato informado"')]
    public function testSemNenhumContato(): void
    {
        $cliente = $this->pfCompleto();
        $cliente->setTelefoneCelular(null)->setTelefoneFixo('  ')->setEmail('');

        self::assertSame(['Nenhum contato informado'], $this->sut->de($cliente));
    }

    /** @return iterable<string, array{0: ?string, 1: ?string, 2: string}> */
    public static function umContatoBasta(): iterable
    {
        yield 'só celular' => ['(61) 99999-0000', null, ''];
        yield 'só fixo'    => [null, '(61) 3333-0000', ''];
        yield 'só e-mail'  => [null, null, 'a@b.com'];
    }

    #[DataProvider('umContatoBasta')]
    #[TestDox('um contato qualquer basta para não acender a pendência de contato')]
    public function testUmContatoBasta(?string $celular, ?string $fixo, string $email): void
    {
        $cliente = $this->pfCompleto();
        $cliente->setTelefoneCelular($celular)->setTelefoneFixo($fixo)->setEmail($email);

        self::assertSame([], $this->sut->de($cliente));
    }

    #[TestDox('sem anexo de identificação: "Documento de identificação não anexado"')]
    public function testSemIdentificacao(): void
    {
        $cliente = $this->pfComAnexos([ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA]);

        self::assertSame(['Documento de identificação não anexado'], $this->sut->de($cliente));
    }

    #[TestDox('sem comprovante de residência: "Comprovante de residência não anexado"')]
    public function testSemComprovante(): void
    {
        $cliente = $this->pfComAnexos([ClienteDocumento::CATEGORIA_IDENTIFICACAO]);

        self::assertSame(['Comprovante de residência não anexado'], $this->sut->de($cliente));
    }

    #[TestDox('anexo de OUTRA categoria (procuração, contrato, demais) não conta como identificação nem comprovante')]
    public function testOutraCategoriaNaoConta(): void
    {
        $cliente = $this->pfComAnexos([
            ClienteDocumento::CATEGORIA_PROCURACAO,
            ClienteDocumento::CATEGORIA_CONTRATO,
            ClienteDocumento::CATEGORIA_DEMAIS,
        ]);

        self::assertSame(
            ['Documento de identificação não anexado', 'Comprovante de residência não anexado'],
            $this->sut->de($cliente)
        );
    }

    #[TestDox('cadastro PF vazio acende TUDO, na ordem do desenho')]
    public function testPfVazioAcendeTudo(): void
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('')->setCpf('')->setRg('')->setRgOrgaoExpedidor('');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        self::assertSame([
            'Nome completo não informado',
            'Estado civil não informado',
            'Profissão não informada',
            'RG não informado',
            'CPF não informado',
            'Endereço não informado',
            'Cidade não informada',
            'UF não informada',
            'CEP não informado',
            'Nenhum contato informado',
            'Documento de identificação não anexado',
            'Comprovante de residência não anexado',
        ], $this->sut->de($cliente));
    }

    #[TestDox('PJ completo não tem pendência: estado civil, profissão e RG não se aplicam')]
    public function testPjCompletoNaoCobraCamposDePessoaFisica(): void
    {
        $cliente = $this->pjCompleto();

        self::assertSame([], $this->sut->de($cliente));
    }

    #[TestDox('PJ vazio acende razão social, CNPJ, endereço, contato e anexos — nada de PF')]
    public function testPjVazioAcendeSoOQueSeAplica(): void
    {
        $cliente = new ClientePJ();
        $cliente->setRazaoSocial('')->setCnpj('')->setEnderecSede('')->setRepresentanteLegal('')
            ->setRepresentanteCpf('')->setRepresentanteRg('')->setRepresentanteCargo('');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        self::assertSame([
            'Razão social não informada',
            'CNPJ não informado',
            'Endereço não informado',
            'Cidade não informada',
            'UF não informada',
            'CEP não informado',
            'Nenhum contato informado',
            'Documento de identificação não anexado',
            'Comprovante de residência não anexado',
        ], $this->sut->de($cliente));
    }

    // ----------------------------------------------------------------- helpers

    private function pfCompleto(): ClientePF
    {
        return $this->pfComAnexos([
            ClienteDocumento::CATEGORIA_IDENTIFICACAO,
            ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA,
        ]);
    }

    /** @param list<string> $categorias */
    private function pfComAnexos(array $categorias): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Maria das Dores')
            ->setCpf('12345678901')
            ->setRg('1234567')
            ->setRgOrgaoExpedidor('SSP/DF')
            ->setEstadoCivil('CASADO')
            ->setProfissao('Professora');
        $cliente->setEmail('maria@exemplo.com')
            ->setTelefoneCelular('(61) 99999-0000')
            ->setCep('70000-000')
            ->setEndereco('SQS 110 Bloco A, 101')
            ->setCidade('Brasília')
            ->setEstado('DF');

        foreach ($categorias as $categoria) {
            $cliente->addDocumento($this->anexo($categoria));
        }

        return $cliente;
    }

    private function pjCompleto(): ClientePJ
    {
        $cliente = new ClientePJ();
        $cliente->setRazaoSocial('Condomínio Sol Nascente')
            ->setCnpj('12345678000190')
            ->setEnderecSede('Rua A, 1')
            ->setRepresentanteLegal('João Síndico')
            ->setRepresentanteCpf('11122233344')
            ->setRepresentanteRg('998877')
            ->setRepresentanteCargo('Síndico');
        $cliente->setEmail('adm@sol.com.br')
            ->setCep('70000-000')
            ->setEndereco('Rua A, 1')
            ->setCidade('Brasília')
            ->setEstado('DF');
        $cliente->addDocumento($this->anexo(ClienteDocumento::CATEGORIA_IDENTIFICACAO));
        $cliente->addDocumento($this->anexo(ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA));

        return $cliente;
    }

    private function anexo(string $categoria): ClienteDocumento
    {
        $doc = new ClienteDocumento();
        $doc->setCategoria($categoria);

        return $doc;
    }
}
