<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Responsável da pasta (chip do cabeçalho). Quando de fato muda, o Registro ganha o cartão do sistema do desenho 02
 * (`escolher`, dc L3118): "Responsável: alterado de "Ana" para "Bruno".", com "Sem responsável" no lado vazio.
 * Escolher quem já é o responsável não grava nada. Quem resolve o colaborador (do escritório, atribuível) e checa a
 * permissão de editar é o controller; aqui fica a guarda do escritório.
 */
final class AlterarResponsavelDaPastaUseCase
{
    public const SEM_RESPONSAVEL = 'Sem responsável';
    public const SEM_NOME = 'Usuário sem nome';

    public function __construct(
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Devolve se o responsável mudou. */
    public function executar(Pasta $pasta, ?User $novo, User $autor, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $atual = $pasta->getResponsavel();
        if (self::mesmo($atual, $novo)) {
            return false;
        }

        $pasta->setResponsavel($novo);
        $this->registro->registrar($pasta, $autor, 'resp', sprintf('alterado de "%s" para "%s".', self::nome($atual), self::nome($novo)));
        $this->em->flush();

        return true;
    }

    private static function mesmo(?User $a, ?User $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return $a !== null && $b !== null && $a->getId() !== null && $a->getId() === $b->getId();
    }

    private static function nome(?User $usuario): string
    {
        if ($usuario === null) {
            return self::SEM_RESPONSAVEL;
        }

        // Nunca o e-mail: o cartão fica no Registro de toda a equipe e na análise da I.A.
        return $usuario->getFullName() ?: self::SEM_NOME;
    }
}
