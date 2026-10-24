<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Carteira da pasta: a área do direito em que ela é tocada ("Mover para outra carteira" do menu ⋮ do cabeçalho;
 * desenho 02 - EXPEDIENTES VS 18.10.26, `menuPasta('mover')`, dc L4577).
 *
 * São as seis do desenho, nesta ordem (a do submenu), e só elas: decisão do dono. Não confundir com a Carteira da
 * Cobrança (`App\Cobranca\Entity\Carteira`), que é outro conceito. Pasta sem carteira (todas as anteriores a esta
 * entrega) fica com NULL e a tela diz "Sem carteira"; não há como voltar a esse estado pelo menu.
 *
 * O valor gravado é curto e estável: trocar o rótulo um dia não muda o que já está no banco.
 */
enum CarteiraDaPasta: string
{
    case ContenciosoCivel   = 'contencioso_civel';
    case Condominial        = 'condominial';
    case FamiliaESucessoes  = 'familia_sucessoes';
    case Trabalhista        = 'trabalhista';
    case Previdenciario     = 'previdenciario';
    case Empresarial        = 'empresarial';

    /** Rótulo da pasta que ainda não foi posta em carteira nenhuma. */
    public const ROTULO_SEM_CARTEIRA = 'Sem carteira';

    public function rotulo(): string
    {
        return match ($this) {
            self::ContenciosoCivel  => 'Contencioso cível',
            self::Condominial       => 'Condominial',
            self::FamiliaESucessoes => 'Família e sucessões',
            self::Trabalhista       => 'Trabalhista',
            self::Previdenciario    => 'Previdenciário',
            self::Empresarial       => 'Empresarial',
        };
    }

    public static function rotuloDe(?self $carteira): string
    {
        return $carteira?->rotulo() ?? self::ROTULO_SEM_CARTEIRA;
    }
}
