<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;
use App\Master\Service\InscricaoOab as InscricaoNormalizada;
use App\Master\UseCase\ConsultarRevisaoOabUseCase;

/**
 * A OAB da própria pessoa que o Cadastro Nacional dos Advogados não achou (ou que não confere), para o aviso "Confira o
 * número" fora do Meu Perfil (pedido do dono, 26/11/2026). Divergente é o resultado registrado de "não encontrada", de
 * "nome ou situação não confere" e de "formato inválido": o motivo sai do mesmo {@see ConsultarRevisaoOabUseCase::motivo()}
 * da Revisão do Master e escolhe o texto. Pendente (Não verificada) não avisa, porque ninguém consultou ainda.
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

    /** @return array{id: ?int, numero: string, uf: string, motivo: string}|null */
    public function executar(User $usuario): ?array
    {
        // Sem OAB no espelho do User (já carregado) não há o que verificar: poupa a consulta em toda tela.
        if (trim((string) $usuario->getOabNumero()) === '') {
            return null;
        }
        $lista = $this->inscricoes->doUsuario($usuario);
        foreach ($lista as $inscricao) {
            if ($inscricao->getStatus() === StatusOab::Divergente) {
                return self::aviso($inscricao->getId(), $inscricao->getNumero(), $inscricao->getUf(), $inscricao->getNomeOficial());
            }
        }
        if ($lista !== [] || $usuario->getOabStatus() !== StatusOab::Divergente) {
            return null;
        }
        $numero = trim((string) $usuario->getOabNumero());
        $uf     = strtoupper(trim((string) $usuario->getOabUf()));

        return $numero === '' || $uf === '' ? null : self::aviso(null, $numero, $uf, $usuario->getOabNomeOficial());
    }

    /** @return array{id: ?int, numero: string, uf: string, motivo: string} */
    private static function aviso(?int $id, string $numero, string $uf, ?string $nomeNoCna): array
    {
        $motivo = ConsultarRevisaoOabUseCase::motivo(StatusOab::Divergente, InscricaoNormalizada::normalizar($numero, $uf), $nomeNoCna);

        return ['id' => $id, 'numero' => $numero, 'uf' => $uf, 'motivo' => (string) $motivo];
    }
}
