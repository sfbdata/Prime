<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Editar nome rápido" (F2) da Lista do Expediente, desenho 1.1 (E12).
 *
 * História: na lista, o advogado vê que o nome que identifica a pasta está errado, aperta o botão direito,
 * F2, corrige e Enter. Muda só `pasta.nome_cliente` (o identificador da pasta; o cadastro do cliente não
 * é tocado), em caixa alta como o resto do sistema, com espaços repetidos tirados. Nome vazio é recusado.
 * Não mexe na situação: pasta arquivada continua arquivada (o caminho do formulário completo reativaria).
 * Pasta excluída (lápide) é somente leitura e é recusada pelo PastaSomenteLeituraListener no flush.
 */
final class RenomearClienteDaPastaUseCase
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, string $nome, User $usuario, Tenant $tenant): string
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        $nome = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $nome)));
        if ($nome === '') {
            throw new \DomainException('O nome do cliente não pode ficar vazio');
        }
        if (mb_strlen($nome) > 255) {
            throw new \DomainException('O nome do cliente pode ter no máximo 255 caracteres');
        }

        $pasta->setNomeCliente($nome);
        $this->em->flush();

        return $nome;
    }
}
