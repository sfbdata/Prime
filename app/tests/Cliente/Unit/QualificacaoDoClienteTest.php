<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Service\QualificacaoDoCliente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Copiar qualificação": o texto sai só com o que o cadastro tem. O teste do
 * caso vazio é o que importa — campo ausente NÃO pode virar "nº ___" nem valor
 * padrão inventado.
 */
#[CoversClass(QualificacaoDoCliente::class)]
final class QualificacaoDoClienteTest extends TestCase
{
    #[TestDox('PF completa: nome, estado civil, profissão, nascimento, RG, CPF, endereço e contatos, na ordem do desenho')]
    public function testPfCompleta(): void
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('  maria   das dores ')
            ->setCpf('12345678901')
            ->setRg('1234567')
            ->setRgOrgaoExpedidor('SSP/DF')
            ->setEstadoCivil('CASADO')
            ->setProfissao('Professora')
            ->setDataNascimento(new \DateTimeImmutable('1980-03-09'));
        $cliente->setEmail('maria@exemplo.com')
            ->setTelefoneCelular('(61) 99999-0000')
            ->setTelefoneFixo('(61) 3333-0000')
            ->setCep('70000-000')
            ->setEndereco('SQS 110 Bloco A')
            ->setComplemento('Apto 101')
            ->setCidade('Brasília')
            ->setEstado('df');

        self::assertSame(
            'MARIA DAS DORES, casado(a), professora, nascido(a) em 09/03/1980, portador(a) do RG nº 1234567, '
            . 'inscrito(a) no CPF nº 123.456.789-01, residente e domiciliado(a) na SQS 110 Bloco A, Apto 101, '
            . 'Brasília/DF, CEP 70000-000, telefone (61) 99999-0000, telefone (61) 3333-0000, e-mail maria@exemplo.com.',
            (new QualificacaoDoCliente())->de($cliente)
        );
    }

    #[TestDox('PF só com nome: nada inventado no lugar do que falta')]
    public function testPfSoComNome(): void
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('João')->setCpf('')->setRg('')->setRgOrgaoExpedidor('');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        self::assertSame('JOÃO.', (new QualificacaoDoCliente())->de($cliente));
    }

    #[TestDox('estado civil gravado fora da lista do formulário entra como veio, em minúsculas')]
    public function testEstadoCivilDesconhecido(): void
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Ana')->setCpf('')->setRg('')->setRgOrgaoExpedidor('')->setEstadoCivil('Separada');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        self::assertSame('ANA, separada.', (new QualificacaoDoCliente())->de($cliente));
    }

    #[TestDox('PJ: razão social, CNPJ, sede e representante — sem afirmar natureza jurídica')]
    public function testPj(): void
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

        $texto = (new QualificacaoDoCliente())->de($cliente);

        self::assertSame(
            'CONDOMÍNIO SOL NASCENTE, inscrita no CNPJ nº 12.345.678/0001-90, com sede na Rua A, 1, Brasília/DF, '
            . 'CEP 70000-000, e-mail adm@sol.com.br, neste ato representada por JOÃO SÍNDICO, síndico, '
            . 'inscrito(a) no CPF nº 111.222.333-44.',
            $texto
        );
        self::assertStringNotContainsString('direito privado', $texto);
    }
}
