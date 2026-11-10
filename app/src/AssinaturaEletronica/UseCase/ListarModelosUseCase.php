<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Entity\ModeloDoEscritorio;
use App\AssinaturaEletronica\Repository\ModeloDoEscritorioRepository;
use App\Entity\Tenant\Tenant;

/**
 * A aba Modelos e o passo 1 (contrato da etapa 4, seção 5): os modelos do escritório no formato `Modelo`, do alterado
 * por último para o mais antigo. Os prontos vêm do catálogo em JS, não daqui.
 */
final readonly class ListarModelosUseCase
{
    public function __construct(private ModeloDoEscritorioRepository $modelos)
    {
    }

    /** @return array{modelos: list<array<string, mixed>>} */
    public function executar(Tenant $tenant): array
    {
        return ['modelos' => array_map(self::paraATela(...), $this->modelos->doEscritorioEmOrdem($tenant))];
    }

    /** @return array<string, mixed> */
    private static function paraATela(ModeloDoEscritorio $modelo): array
    {
        return [
            'id' => $modelo->getId(),
            'chave' => $modelo->chave(),
            'nome' => $modelo->getNome(),
            'categoria' => $modelo->getCategoria(),
            'layout' => $modelo->getLayout(),
            'lateral' => $modelo->getLateral() ?? '',
            'folhaBranca' => $modelo->isFolhaBranca(),
            'campos' => $modelo->getCampos(),
            'signatarios' => $modelo->getSignatarios(),
            'corpo' => $modelo->getCorpo(),
            'alteradoEm' => $modelo->getAlteradoEm()->format(\DATE_ATOM),
        ];
    }
}
