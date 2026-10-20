<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\ChavesDoAvisoDoPush;

/**
 * Quem recebe o aviso de UMA pasta nesta captação (spec push-avisar-quando-chegar §3), decidido sem
 * banco a partir das chaves e dos fatos já apurados:
 *
 *   - sino: "Responsável da pasta" ligado E a pasta tem responsável apto (ativo no escritório e que
 *     vê a pasta);
 *   - e-mail: o sino E "Por e-mail" ligado E o endereço do responsável não pediu descadastro;
 *   - IA: "Análise da BlueJus IA" ligada E a IA disponível no escritório E um responsável apto (é
 *     ele quem pede a análise; sem ele não há solicitante). A cota e a permissão do módulo de quem
 *     pede ficam com o SolicitarResumoDoPushUseCase.
 */
final readonly class DestinosDoAvisoDoPush
{
    private function __construct(
        public bool $sino,
        public bool $email,
        public bool $ia,
    ) {
    }

    public static function decidir(ChavesDoAvisoDoPush $chaves, bool $responsavelApto, bool $emailDescadastrado, bool $iaDisponivel): self
    {
        $sino = $chaves->responsavel && $responsavelApto;

        return new self(
            $sino,
            $sino && $chaves->email && !$emailDescadastrado,
            $chaves->ia && $iaDisponivel && $responsavelApto,
        );
    }

    public function algum(): bool
    {
        return $this->sino || $this->email || $this->ia;
    }
}
