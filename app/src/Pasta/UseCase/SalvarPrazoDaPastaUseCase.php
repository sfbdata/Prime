<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Novo prazo ou audiência, ou edição de um (desenho 1.2.3, `pzSalvar`).
 *
 * História: no trilho da pasta, o advogado clica "+", escolhe Prazo ou Audiência, escreve a descrição, a data,
 * a hora (opcional) e o responsável, e salva. O prazo entra em "Próximos prazos" e o Registro ganha o cartão
 * "Prazo adicionado: …" ou "Audiência agendada: …" (na edição, "Prazo editado: …"). Descrição e data são
 * obrigatórias ("Informe a descrição e a data"). Precisa poder EDITAR a pasta; a lápide recusa (listener).
 */
final class SalvarPrazoDaPastaUseCase
{
    public function __construct(
        private readonly PastaPrazoRepository $repository,
        private readonly PermissionChecker $permissionChecker,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(
        Pasta $pasta,
        User $usuario,
        Tenant $tenant,
        ?int $prazoId,
        string $tipo,
        string $titulo,
        string $data,
        ?string $hora,
        ?string $responsavel,
    ): PastaPrazo {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if ($dia === false || trim($titulo) === '') {
            throw new \DomainException('Informe a descrição e a data');
        }

        if ($prazoId !== null) {
            $prazo = $this->repository->findDaPasta($prazoId, $pasta, $tenant);
            if ($prazo === null) {
                throw new \DomainException('Prazo não encontrado.');
            }
        } else {
            $prazo = new PastaPrazo($tenant, $pasta, $usuario);
            $this->em->persist($prazo);
        }

        $prazo->definir($tipo, $titulo, $dia, $hora, $responsavel);

        $inicio = $prazoId !== null ? 'Prazo editado: ' : ($prazo->isAudiencia() ? 'Audiência agendada: ' : 'Prazo adicionado: ');
        $this->registro->registrar($pasta, $usuario, 'prazo', $inicio . $prazo->getTitulo() . ' · ' . $prazo->getData()->format('d/m/Y')
            . ($prazo->getHora() ? ' ' . $prazo->getHora() : ''));
        $this->em->flush();

        return $prazo;
    }
}
