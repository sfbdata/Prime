<?php

declare(strict_types=1);

namespace App\Termo\UseCase;

use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Registra o aceite da versão vigente dos Termos de Uso.
 *
 * Idempotente: se o usuário já aceitou a versão vigente, não cria registro duplicado
 * (também protegido pelo índice único user+versao). Agnóstico de framework — recebe
 * apenas primitivos/entidades, nunca a Request.
 */
final class RegistrarAceiteTermoUseCase
{
    public function __construct(
        private readonly AceiteTermoRepository $aceiteTermoRepository,
        private readonly TermoVigente $termoVigente,
    ) {}

    public function executar(RegistrarAceiteTermoInput $input): void
    {
        $versao = $this->termoVigente->getVersao();

        if ($this->aceiteTermoRepository->existeAceiteVigente($input->user, $versao)) {
            return;
        }

        $aceite = new AceiteTermo(
            user: $input->user,
            versao: $versao,
            ip: $input->ip,
            userAgent: $input->userAgent,
            tenant: $input->tenant,
        );

        try {
            $this->aceiteTermoRepository->salvar($aceite, flush: true);
        } catch (UniqueConstraintViolationException) {
            // Corrida (duplo-submit): outra requisição já registrou o mesmo aceite.
            // O índice único garante a integridade; aqui apenas tratamos como sucesso idempotente.
        }
    }
}
