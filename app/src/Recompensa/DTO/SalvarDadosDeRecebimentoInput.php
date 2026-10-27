<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use Symfony\Component\Validator\Constraints as Assert;

/** "Dados para pagamento" do indicador (PUT /master/recompensas/usuarios/{id}/recebimento). O documento é validado na entidade. */
final readonly class SalvarDadosDeRecebimentoInput
{
    public function __construct(
        /** Quem salva: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        #[Assert\Length(max: 120, maxMessage: 'Informe o nome de quem recebe (até 120 caracteres).')]
        public string $nome,
        public string $documento,
        #[Assert\Length(max: 140, maxMessage: 'A chave PIX deve ter até 140 caracteres.')]
        public ?string $chavePix,
    ) {
    }

    /** @param array<mixed> $corpo `{nome, documento, chavePix}` */
    public static function doJson(?User $ator, int $userId, array $corpo): self
    {
        $chave = is_string($corpo['chavePix'] ?? null) ? trim($corpo['chavePix']) : null;

        return new self(
            $ator,
            $userId,
            is_string($corpo['nome'] ?? null) ? trim($corpo['nome']) : '',
            is_string($corpo['documento'] ?? null) ? $corpo['documento'] : '',
            $chave === '' ? null : $chave,
        );
    }
}
