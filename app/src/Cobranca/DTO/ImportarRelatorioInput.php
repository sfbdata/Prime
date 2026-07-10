<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do upload do relatório de inadimplência para importação em massa numa Carteira (Etapa 8C,
 * SPEC §21). É a data_class do formulário de upload. A Carteira dona vem da rota (guarda multi-tenant
 * no controller/UseCase); aqui só se valida o arquivo — tipo (planilha xlsx/xls) e tamanho — antes de
 * qualquer efeito. O parse/leitura fica no adapter TOPLIFE; a idempotência, no UseCase.
 */
final class ImportarRelatorioInput
{
    #[Assert\NotNull(message: 'Selecione o arquivo do relatório (.xlsx).')]
    #[Assert\File(
        maxSize: '10M',
        mimeTypes: [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/zip',
        ],
        mimeTypesMessage: 'Envie uma planilha do Excel (.xlsx ou .xls).',
    )]
    public ?UploadedFile $arquivo = null;
}
