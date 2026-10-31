<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\RotuloDeEtapa;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\RotuloDeEtapaRepository;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "+" entre duas fases da linha do tempo (desenho 02 de 31/10/2026): o advogado dá um nome à etapa que acontece entre
 * elas ("Triagem" entre Abertura e Documentação) ou o remove (texto vazio). O nome é da pasta e da via, de todo o
 * escritório, tem até 12 letras e o Registro conta a mudança. Precisa poder EDITAR a pasta, como mudar de fase.
 */
final class NomearEtapaDaPastaUseCase
{
    public function __construct(
        private readonly AndamentoDaPasta $andamento,
        private readonly RotuloDeEtapaRepository $rotulos,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly PermissionChecker $permissionChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string|null o texto registrado, ou null quando nada mudou */
    public function executar(Pasta $pasta, User $usuario, Tenant $tenant, int $origem, string $texto): ?string
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        $fases = $this->andamento->estado($pasta, $tenant)['fases'];
        if ($origem < 0 || $origem >= count($fases) - 1) {
            throw new \DomainException('Etapa inválida.');
        }
        $texto = $this->normalizar($texto);

        $frase = $this->gravar($pasta, $tenant, $origem, $texto);
        if ($frase === null) {
            return null;
        }

        $registro = strtr($frase, ['{de}' => '"' . $fases[$origem][0] . '"', '{para}' => '"' . $fases[$origem + 1][0] . '"']);
        $this->registro->registrar($pasta, $usuario, 'fase', $registro);
        $this->em->flush();

        return $registro;
    }

    private function normalizar(string $texto): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) > RotuloDeEtapa::TAMANHO_MAXIMO) {
            throw new \DomainException('O nome da etapa tem no máximo ' . RotuloDeEtapa::TAMANHO_MAXIMO . ' letras.');
        }
        if (preg_match('/[\p{Cc}<>]/u', $texto) === 1) {
            throw new \DomainException('O nome da etapa tem caracteres inválidos.');
        }

        return $texto;
    }

    /** Persiste a mudança e devolve a frase (com {de} e {para} para as fases); null quando nada mudou. */
    private function gravar(Pasta $pasta, Tenant $tenant, int $origem, string $texto): ?string
    {
        $via = AndamentoDaPasta::via($pasta);
        $atual = $this->rotulos->daOrigem($pasta, $via, $origem, $tenant);
        if ($texto === '') {
            if ($atual === null) {
                return null;
            }
            $this->em->remove($atual);

            return 'Etapa entre {de} e {para}: nome "' . $atual->getTexto() . '" removido.';
        }
        if ($atual === null) {
            $this->em->persist(new RotuloDeEtapa($tenant, $pasta, $via, $origem, $texto));

            return 'Etapa entre {de} e {para} nomeada "' . $texto . '".';
        }
        if ($atual->getTexto() === $texto) {
            return null;
        }
        $frase = 'Etapa entre {de} e {para}: nome "' . $atual->getTexto() . '" trocado por "' . $texto . '".';
        $atual->renomear($texto);

        return $frase;
    }
}
