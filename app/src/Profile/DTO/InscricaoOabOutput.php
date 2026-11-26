<?php

declare(strict_types=1);

namespace App\Profile\DTO;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Master\Service\InscricaoOab as InscricaoNormalizada;
use App\Master\UseCase\ConsultarRevisaoOabUseCase;

/**
 * Uma linha do card "Registro OAB" (desenho "07 - Meu Perfil", VS 18.10.26, `oabs` e `ST`). A situação usa
 * os rótulos e as cores do desenho: Pendente (NaoVerificada), Não confirmada (Divergente), Confirmada.
 *
 * `motivoDivergencia` (só na Divergente, pelo mesmo {@see ConsultarRevisaoOabUseCase::motivo()} da Revisão do Master:
 * nao_encontrada, nome ou formato) escolhe o texto do aviso com o atalho para corrigir; `validada` (Confirmada) acende
 * o selo "OAB validada" ao lado do número.
 */
final readonly class InscricaoOabOutput
{
    public function __construct(
        public int $id,
        public string $numero,
        public string $uf,
        public bool $principal,
        public string $situacao,
        public string $situacaoClasse,
        public string $situacaoIcone,
        public ?\DateTimeImmutable $verificadaEm,
        public ?string $motivoDivergencia = null,
        public bool $validada = false,
    ) {
    }

    public static function fromEntity(InscricaoOab $i): self
    {
        [$rotulo, $classe, $icone] = match ($i->getStatus()) {
            StatusOab::Confirmada => ['Confirmada', 'confirmada', 'bi-patch-check-fill'],
            StatusOab::Divergente => ['Não confirmada', 'invalida', 'bi-x-circle-fill'],
            StatusOab::NaoVerificada => ['Pendente', 'pendente', 'bi-hourglass-split'],
        };

        return new self(
            (int) $i->getId(),
            $i->getNumero(),
            $i->getUf(),
            $i->ehPrincipal(),
            $rotulo,
            $classe,
            $icone,
            $i->getVerificadaEm(),
            ConsultarRevisaoOabUseCase::motivo($i->getStatus(), InscricaoNormalizada::normalizar($i->getNumero(), $i->getUf()), $i->getNomeOficial()),
            $i->getStatus() === StatusOab::Confirmada,
        );
    }
}
