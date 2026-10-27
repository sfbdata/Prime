<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use App\Recompensa\Enum\FormaDePagamento;
use App\Recompensa\Exception\RecompensaRecusada;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Dar baixa" num pagamento feito fora do sistema (POST multipart /master/recompensas/usuarios/{id}/baixa). A chave de
 * idempotência é o UUID que o navegador gera ao abrir o diálogo. O comprovante é opcional.
 */
final readonly class DarBaixaDeRecompensaInput
{
    public function __construct(
        /** Quem dá a baixa: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        public int $valorCentavos,
        public FormaDePagamento $forma,
        #[Assert\Length(max: 255, maxMessage: 'A observação deve ter até 255 caracteres.')]
        public ?string $observacao,
        #[Assert\NotBlank(message: 'Pedido sem identificador válido. Feche a janela e abra de novo.')]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9-]{16,64}$/', message: 'Pedido sem identificador válido. Feche a janela e abra de novo.')]
        public string $chaveDeIdempotencia,
        public ?UploadedFile $comprovante,
    ) {
    }

    /**
     * @param array<string, mixed> $campos `{valor ("150,00"), forma, observacao, chave}` do formulário
     *
     * @throws RecompensaRecusada valor que não é número, forma desconhecida
     */
    public static function doFormulario(?User $ator, int $userId, array $campos, ?UploadedFile $comprovante): self
    {
        $valor = LeitorDeValoresDeRecompensa::centavosDePagamento($campos['valor'] ?? null);
        $forma = is_string($campos['forma'] ?? null) ? FormaDePagamento::tryFrom($campos['forma']) : null;
        $observacao = is_string($campos['observacao'] ?? null) ? trim($campos['observacao']) : null;

        return new self(
            $ator,
            $userId,
            $valor,
            $forma ?? throw new RecompensaRecusada('Escolha a forma de pagamento.'),
            $observacao === '' ? null : $observacao,
            is_string($campos['chave'] ?? null) ? $campos['chave'] : '',
            $comprovante,
        );
    }
}
