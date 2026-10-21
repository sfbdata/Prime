<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Pasta\Service\ClientesDuplicadosDaPasta;
use App\Pasta\Twig\ClientesDuplicadosExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A chave que vai em `data-doc-chave` da linha do cliente: o JS refaz com ela a comparação do serviço
 * (só dígitos) sem que o documento cru vá para o HTML.
 */
#[CoversClass(ClientesDuplicadosExtension::class)]
#[CoversClass(ClientesDuplicadosDaPasta::class)]
final class ClientesDuplicadosExtensionTest extends TestCase
{
    private function extensao(string $segredo = 'segredo-de-teste'): ClientesDuplicadosExtension
    {
        return new ClientesDuplicadosExtension(new ClientesDuplicadosDaPasta(), $segredo);
    }

    private function pf(string $cpf): ClientePF
    {
        return (new ClientePF())->setCpf($cpf);
    }

    #[TestDox('Mesmos dígitos com máscaras diferentes dão a mesma chave; outro documento, outra chave')]
    public function testMesmaChaveParaOsMesmosDigitos(): void
    {
        $ext = $this->extensao();

        $chave = $ext->chaveDoDocumento($this->pf('006.462.251-70'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $chave);
        self::assertSame($chave, $ext->chaveDoDocumento($this->pf('00646225170')));
        self::assertNotSame($chave, $ext->chaveDoDocumento($this->pf('006.462.251-71')));
    }

    #[TestDox('A chave não contém os dígitos e depende do segredo da aplicação')]
    public function testChaveNaoExpoeODocumento(): void
    {
        $cliente = $this->pf('00646225170');
        $chave = $this->extensao()->chaveDoDocumento($cliente);

        self::assertStringNotContainsString('00646225170', $chave);
        self::assertNotSame($chave, $this->extensao('outro-segredo')->chaveDoDocumento($cliente));
    }

    #[TestDox('PJ usa o CNPJ; sem documento, a chave é vazia (não junta ninguém)')]
    public function testCnpjESemDocumento(): void
    {
        $ext = $this->extensao();

        self::assertSame(
            $ext->chaveDoDocumento((new ClientePJ())->setCnpj('12.345.678/0001-90')),
            $ext->chaveDoDocumento((new ClientePJ())->setCnpj('12345678000190')),
        );
        self::assertSame('', $ext->chaveDoDocumento($this->pf('...-')));
    }
}
