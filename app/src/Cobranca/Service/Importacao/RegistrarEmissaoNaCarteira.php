<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Tenant\Tenant;

/**
 * Guarda na carteira a emissão do relatório recém-importado, para a tela poder dizer até quando os
 * dados estão em dia.
 *
 * 🔑 Roda no COMANDO, depois da importação confirmada — não dentro dos UseCases. Eles são caminho de
 * dinheiro, e isto é informação de tela: uma data de rodapé ilegível não pode ter chance de derrubar
 * uma importação de 8 mil recebimentos. Por isso também engole a própria falha.
 */
final class RegistrarEmissaoNaCarteira
{
    public const CADASTRO = 'cadastro';
    public const INADIMPLENCIA = 'inadimplencia';
    public const RECEITAS = 'receitas';
    public const ACORDOS = 'acordos';

    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly LeitorEmissaoDoRelatorio $leitor,
    ) {
    }

    /** Devolve a emissão registrada, ou null se o arquivo não trouxer a linha `Emissão:`. */
    public function registrar(int $carteiraId, Tenant $tenant, string $tipo, string $arquivo): ?\DateTimeImmutable
    {
        try {
            $emissao = $this->leitor->ler($arquivo);
            if ($emissao === null) {
                return null;
            }

            $carteira = $this->carteiraRepository->findOneBy(['id' => $carteiraId, 'tenant' => $tenant]);
            if ($carteira === null) {
                return null;
            }

            $carteira->registrarEmissaoImportada($tipo, $emissao);
            $this->carteiraRepository->salvar($carteira, true);

            return $emissao;
        } catch (\Throwable) {
            return null;
        }
    }
}
