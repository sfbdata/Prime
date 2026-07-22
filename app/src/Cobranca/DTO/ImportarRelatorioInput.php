<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada do upload do relatório de inadimplência para importação em massa numa Carteira (Etapa 8C,
 * SPEC §21). É a data_class do formulário de upload. A Carteira dona vem da rota (guarda multi-tenant
 * no controller/UseCase); aqui só se valida o arquivo — formato e tamanho — antes de qualquer efeito.
 * O parse/leitura fica no adapter TOPLIFE; a idempotência, no UseCase.
 *
 * O formato NÃO é validado por whitelist de mime (era, e dava falso negativo em planilha legítima): o
 * `finfo` reconhece o arquivo como "Microsoft OOXML" mas não consegue nomear o subtipo quando o gerador
 * grava o zip em Zip64/streaming, e então devolve `application/octet-stream`. Aconteceu com o relatório
 * real de 22/07/2026 — 587 linhas que o PhpSpreadsheet lê sem qualquer problema foram barradas com
 * "Envie uma planilha do Excel". Como o subtipo depende de a string `xl/` cair ou não dentro da janela
 * que o libmagic inspeciona, o resultado é loteria de bytes, não uma exceção rara.
 *
 * No lugar, valida-se o que é determinístico: a EXTENSÃO enviada e a ASSINATURA dos primeiros bytes.
 * Isso continua barrando o PDF/HTML renomeado para `.xlsx` (é o que vários sistemas exportam como
 * "planilha"), e quem dá a palavra final sobre o conteúdo é a leitura no controller, que já trata falha
 * com mensagem própria.
 */
final class ImportarRelatorioInput
{
    /** Extensões aceitas no nome enviado pelo cliente — o `accept` do input é só UX, não vale como guarda. */
    private const EXTENSOES = ['xlsx', 'xls'];

    /** Assinaturas de planilha: OOXML (.xlsx) é um zip; o .xls antigo é um container OLE2/CFB. */
    private const ASSINATURAS = ["PK\x03\x04", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"];

    private const MENSAGEM_FORMATO = 'Envie uma planilha do Excel (.xlsx ou .xls).';

    #[Assert\NotNull(message: 'Selecione o arquivo do relatório (.xlsx).')]
    #[Assert\File(maxSize: '10M')]
    public ?UploadedFile $arquivo = null;

    #[Assert\Callback]
    public function validarFormatoDePlanilha(ExecutionContextInterface $context): void
    {
        // Arquivo ausente ou com erro de upload já é reportado pelo NotNull/File — não duplicar violação.
        if ($this->arquivo === null || !$this->arquivo->isValid()) {
            return;
        }

        $extensao = strtolower($this->arquivo->getClientOriginalExtension());
        $inicio = (string) file_get_contents($this->arquivo->getPathname(), false, null, 0, 8);

        $assinaturaConfere = false;
        foreach (self::ASSINATURAS as $assinatura) {
            if (str_starts_with($inicio, $assinatura)) {
                $assinaturaConfere = true;
                break;
            }
        }

        if (!in_array($extensao, self::EXTENSOES, true) || !$assinaturaConfere) {
            $context->buildViolation(self::MENSAGEM_FORMATO)->atPath('arquivo')->addViolation();
        }
    }
}
