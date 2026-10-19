<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\ClientesDuplicadosDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Selo "CPF duplicado" do trilho de Clientes (desenho 02 VS 18.10.26, L1509 e L6527-6536): o documento
 * de um cliente aparece em OUTRO cliente vinculado à MESMA pasta, comparado só pelos dígitos.
 */
#[CoversClass(ClientesDuplicadosDaPasta::class)]
final class ClientesDuplicadosDaPastaTest extends TestCase
{
    private ClientesDuplicadosDaPasta $servico;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->servico = new ClientesDuplicadosDaPasta();
        $this->tenant  = $this->createStub(Tenant::class);
    }

    #[TestDox('CPF com máscara diferente ("006.462.251-70" e "00646225170") é o mesmo documento: os dois ganham o selo')]
    public function testCpfComMascaraDiferenteEDuplicado(): void
    {
        $pasta = $this->pastaCom(
            $this->pf(1, '006.462.251-70'),
            $this->pf(2, '00646225170'),
            $this->pf(3, '022.547.725-46'),
        );

        self::assertSame([1, 2], $this->ordenado($this->servico->idsDe($pasta)));
    }

    #[TestDox('CNPJ repetido entre clientes PJ, com e sem máscara, também conta')]
    public function testCnpjRepetidoEDuplicado(): void
    {
        $pasta = $this->pastaCom(
            $this->pj(10, '12.345.678/0001-90'),
            $this->pj(11, '12345678000190'),
            $this->pj(12, '98765432000110'),
        );

        self::assertSame([10, 11], $this->ordenado($this->servico->idsDe($pasta)));
    }

    #[TestDox('Três clientes com o mesmo CPF: os três ganham o selo')]
    public function testTresComOMesmoCpf(): void
    {
        $pasta = $this->pastaCom(
            $this->pf(1, '022.547.725-46'),
            $this->pf(2, '02254772546'),
            $this->pf(3, '022 547 725 46'),
        );

        self::assertSame([1, 2, 3], $this->ordenado($this->servico->idsDe($pasta)));
    }

    #[TestDox('Documentos diferentes (até por um dígito) não são duplicados')]
    public function testDocumentosDiferentesNaoSaoDuplicados(): void
    {
        $pasta = $this->pastaCom(
            $this->pf(1, '022.547.725-46'),
            $this->pf(2, '022.547.725-45'),
            $this->pj(3, '12.345.678/0001-90'),
        );

        self::assertSame([], $this->servico->idsDe($pasta));
    }

    #[TestDox('Só dentro da pasta: o mesmo CPF em cliente de OUTRA pasta não marca ninguém')]
    public function testSoDentroDaPasta(): void
    {
        $aqui = $this->pf(1, '006.462.251-70');
        $la   = $this->pf(2, '00646225170');
        $pasta = $this->pastaCom($aqui, $this->pf(3, '111.222.333-44'));
        $this->pastaCom($la);

        self::assertSame([], $this->servico->idsDe($pasta));
        self::assertFalse($this->servico->temDuplicado($pasta, $aqui));
    }

    #[TestDox('Documento vazio ou só com máscara não junta clientes como "duplicados"')]
    public function testDocumentoVazioNaoConta(): void
    {
        $pasta = $this->pastaCom(
            $this->pf(1, ''),
            $this->pf(2, '...-'),
        );

        self::assertSame([], $this->servico->idsDe($pasta));
    }

    #[TestDox('temDuplicado responde por cliente')]
    public function testTemDuplicadoPorCliente(): void
    {
        $a = $this->pf(1, '006.462.251-70');
        $b = $this->pf(2, '00646225170');
        $c = $this->pf(3, '022.547.725-46');
        $pasta = $this->pastaCom($a, $b, $c);

        self::assertTrue($this->servico->temDuplicado($pasta, $a));
        self::assertTrue($this->servico->temDuplicado($pasta, $b));
        self::assertFalse($this->servico->temDuplicado($pasta, $c));
    }

    private function pastaCom(Cliente ...$clientes): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        foreach ($clientes as $cliente) {
            $pasta->addCliente($cliente);
        }

        return $pasta;
    }

    private function pf(int $id, string $cpf): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($this->tenant);
        $cliente->setNomeCompleto('Cliente ' . $id);
        $cliente->setCpf($cpf);
        $this->darId($cliente, $id);

        return $cliente;
    }

    private function pj(int $id, string $cnpj): ClientePJ
    {
        $cliente = new ClientePJ();
        $cliente->setTenant($this->tenant);
        $cliente->setRazaoSocial('Empresa ' . $id);
        $cliente->setCnpj($cnpj);
        $this->darId($cliente, $id);

        return $cliente;
    }

    /** Fora do banco o id só entra por reflexão (o serviço responde por id). */
    private function darId(Cliente $cliente, int $id): void
    {
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($cliente, $id);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function ordenado(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
