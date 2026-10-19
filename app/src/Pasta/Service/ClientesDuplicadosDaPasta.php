<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Pasta\Entity\Pasta;

/**
 * Selo "CPF duplicado" do trilho de Clientes da pasta (desenho 02, VS 18.10.26, L1509 e
 * L6527-6536): um cliente cujo documento aparece em OUTRO cliente vinculado à MESMA pasta.
 *
 * A comparação é só pelos dígitos. O cadastro guarda o documento como foi digitado e a
 * unicidade do banco é por texto, então "006.462.251-70" e "00646225170" convivem como dois
 * clientes. É exatamente o caso que o selo existe para mostrar.
 *
 * Só olha os clientes da pasta recebida (a coleção já carregada): não consulta o banco nem
 * compara com clientes de outra pasta ou de outro escritório.
 */
final class ClientesDuplicadosDaPasta
{
    /**
     * Ids dos clientes da pasta que têm o documento repetido em outro cliente dela.
     *
     * @return list<int>
     */
    public function idsDe(Pasta $pasta): array
    {
        /** @var array<string, list<int>> $porDocumento */
        $porDocumento = [];
        foreach ($pasta->getClientes() as $cliente) {
            $id      = $cliente->getId();
            $digitos = $this->digitosDoDocumento($cliente);
            if ($id === null || $digitos === '') {
                continue;
            }
            $porDocumento[$digitos][] = $id;
        }

        $ids = [];
        foreach ($porDocumento as $doMesmoDocumento) {
            if (\count($doMesmoDocumento) > 1) {
                array_push($ids, ...$doMesmoDocumento);
            }
        }

        return array_values(array_unique($ids));
    }

    public function temDuplicado(Pasta $pasta, Cliente $cliente): bool
    {
        return \in_array($cliente->getId(), $this->idsDe($pasta), true);
    }

    private function digitosDoDocumento(Cliente $cliente): string
    {
        $documento = match (true) {
            $cliente instanceof ClientePF => $cliente->getCpf(),
            $cliente instanceof ClientePJ => $cliente->getCnpj(),
            default                       => '',
        };

        return (string) preg_replace('/\D+/', '', $documento);
    }
}
