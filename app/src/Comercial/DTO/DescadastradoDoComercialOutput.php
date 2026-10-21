<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\ItemDeDescadastro;

/**
 * Uma linha da lista de Descadastrados do Comercial (desenho 05 VS 21.10.26, L248 e L694): e-mail, data, motivo e
 * origem com os rótulos do desenho ("Não informado", "Link no e-mail", "Manual"). O `assunto` vai junto porque o
 * "Excluir" reativa exatamente ele (`comercial_campanhas` ou `todos`).
 */
final readonly class DescadastradoDoComercialOutput
{
    private const ORIGENS = [
        'link_do_email' => 'Link no e-mail',
        'one_click' => 'Link no e-mail',
        DescadastroDeEmailInterface::ORIGEM_MANUAL_MASTER => 'Manual',
    ];

    public function __construct(
        public string $email,
        public string $assunto,
        public \DateTimeImmutable $em,
        public string $motivo,
        public string $origem,
    ) {
    }

    public static function deItem(ItemDeDescadastro $item): self
    {
        return new self($item->email, $item->assunto, $item->em, $item->motivo ?? 'Não informado', self::ORIGENS[$item->origem] ?? 'Outro');
    }

    /** @return array{email: string, assunto: string, em: string, motivo: string, origem: string} */
    public function paraJson(): array
    {
        return ['email' => $this->email, 'assunto' => $this->assunto, 'em' => $this->em->format(\DATE_ATOM), 'motivo' => $this->motivo, 'origem' => $this->origem];
    }
}
