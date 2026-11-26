<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;

/**
 * A OAB da própria pessoa que o Cadastro Nacional dos Advogados não achou (ou que não confere), para o aviso "Confira o
 * número" fora do Meu Perfil (pedido do dono, 26/11/2026). Divergente é o resultado registrado de "não encontrada" e de
 * "nome ou situação não confere"; Pendente (Não verificada) não avisa, porque ninguém consultou ainda.
 *
 * Só lê as inscrições DA PESSOA (filtro por usuário; a OAB é da pessoa, não do escritório). A principal vem primeiro.
 * Quem tem a OAB só no espelho User.oab* (ainda sem inscrição) é conferido pelo espelho, sem criar nada: o aviso é
 * leitura, e o Meu Perfil cria a principal na primeira abertura.
 */
final class ObterAvisoDaOabUseCase
{
    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
    ) {
    }

    /** @return array{id: ?int, numero: string, uf: string}|null */
    public function executar(User $usuario): ?array
    {
        $lista = $this->inscricoes->doUsuario($usuario);
        foreach ($lista as $inscricao) {
            if ($inscricao->getStatus() === StatusOab::Divergente) {
                return ['id' => $inscricao->getId(), 'numero' => $inscricao->getNumero(), 'uf' => $inscricao->getUf()];
            }
        }
        if ($lista !== [] || $usuario->getOabStatus() !== StatusOab::Divergente) {
            return null;
        }
        $numero = trim((string) $usuario->getOabNumero());
        $uf     = strtoupper(trim((string) $usuario->getOabUf()));

        return $numero === '' || $uf === '' ? null : ['id' => null, 'numero' => $numero, 'uf' => $uf];
    }
}
