<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use App\Processo\Entity\NotaTecnica;

/**
 * O que a tela precisa de uma nota técnica. `conteudo` é o HTML já limpo na entrada; a exibição
 * passa de novo pelo filtro `texto_rico` (defesa em profundidade, como as observações da pasta).
 *
 * `movimentacaoRotulo` existe para a aba Processo dizer de qual movimentação do Push a nota é
 * ("Intimação · 20/08/2026") — dentro do teor da própria publicação ele é redundante e a tela
 * não o mostra.
 */
final readonly class NotaTecnicaOutput
{
    public function __construct(
        public int $id,
        public int $processoId,
        public ?int $publicacaoId,
        public ?int $autorId,
        public string $autorNome,
        public string $conteudo,
        public \DateTimeImmutable $criadaEm,
        public ?\DateTimeImmutable $editadaEm,
        public ?string $movimentacaoRotulo,
    ) {
    }

    public static function fromEntity(NotaTecnica $nota): self
    {
        $autor      = $nota->getAutor();
        $publicacao = $nota->getPublicacaoDjen();

        $rotulo = null;
        if ($publicacao !== null) {
            $rotulo = $publicacao->getTipoComunicacao() ?: 'Publicação';
            $data   = $publicacao->getDataDisponibilizacao();
            if ($data !== null) {
                $rotulo .= ' · ' . $data->format('d/m/Y');
            }
        }

        return new self(
            id: (int) $nota->getId(),
            processoId: (int) $nota->getProcesso()->getId(),
            publicacaoId: $publicacao?->getId(),
            autorId: $autor?->getId(),
            // Colaborador desvinculado do escritório deixa a nota sem autor (SET NULL).
            autorNome: $autor?->getFullName() ?: ($autor?->getEmail() ?: 'Autor desconhecido'),
            conteudo: $nota->getConteudo(),
            criadaEm: $nota->getCriadaEm(),
            editadaEm: $nota->getEditadaEm(),
            movimentacaoRotulo: $rotulo,
        );
    }
}
