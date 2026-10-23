<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\DigitoVerificador;

/**
 * As regras do "Encontrados no menu Documentos" que valem na tela e no POST de "Vincular ao cadastro" (spec
 * cliente-inteligencia-cadastral §2.3): o bloco só existe com CPF/CNPJ válido, e só aparece documento da categoria que
 * o cadastro ainda não mostra a quem olha.
 */
final class RegrasDosEncontrados
{
    public function __construct(
        private readonly DocumentosVisiveisDoCadastro $documentosDoCadastro,
    ) {
    }

    /** Os dígitos do CPF/CNPJ quando o dígito verificador confere; senão null (o bloco não aparece). */
    public static function documentoValido(Cliente $cliente): ?string
    {
        $documento = match (true) {
            $cliente instanceof ClientePF => $cliente->getCpf(),
            $cliente instanceof ClientePJ => $cliente->getCnpj(),
            default => '',
        };
        $valido = $cliente instanceof ClientePF ? DigitoVerificador::cpfValido($documento) : DigitoVerificador::cnpjValido($documento);

        return $valido ? DocumentoDoCliente::digitos($documento) : null;
    }

    /** @return list<string> as categorias que o cadastro já mostra a este usuário (anexo próprio ou vínculo visível) */
    public function categoriasNoCadastro(Cliente $cliente, User $usuario, Tenant $tenant): array
    {
        return array_values(array_unique(array_map(
            static fn (ClienteDocumento $d): string => $d->getCategoria(),
            $this->documentosDoCadastro->de($cliente, $usuario, $tenant),
        )));
    }
}
