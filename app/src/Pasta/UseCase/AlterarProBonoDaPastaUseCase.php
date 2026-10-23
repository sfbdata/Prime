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
 * Pró-bono do Financeiro. Quando a marcação de fato muda, o Registro ganha o cartão do sistema do desenho 02
 * (`toggleProBono`, dc L6986): "Pró-bono: pasta marcada como pró-bono." ou "Pró-bono: marcação de pró-bono
 * retirada.". Mandar o mesmo valor não grava nada. A permissão de editar fica no controller; aqui, a guarda do
 * escritório.
 */
final class AlterarProBonoDaPastaUseCase
{
    public function __construct(
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Devolve se a marcação mudou. */
    public function executar(Pasta $pasta, bool $proBono, User $autor, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if ($pasta->isProBono() === $proBono) {
            return false;
        }

        $pasta->setProBono($proBono);
        $this->registro->registrar($pasta, $autor, 'probono', $proBono ? 'pasta marcada como pró-bono.' : 'marcação de pró-bono retirada.');
        $this->em->flush();

        return true;
    }
}
