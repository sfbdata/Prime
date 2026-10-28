<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento\Exception;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O arquivo existe, mas não pôde ser obtido agora: a cópia local foi liberada (ou sumiu) e a cópia
 * remota não respondeu, não conferiu ou não coube no cache de leitura.
 *
 * Não é {@see ArquivoNaoEncontrado}: há prova de que o arquivo existe (o envio `verificado` da outbox),
 * então a borda HTTP responde 503, nunca 404. É uma {@see FalhaDeArmazenamento} para quem já captura a
 * base continuar tratando como pane, sem mudar de comportamento.
 *
 * A mensagem leva só categoria, escritório e a CLASSE da causa, nunca o nome do arquivo (nos legados ele
 * tem nome de pessoa). Por isso a causa não é encadeada como `previous`: a mensagem dela (a de
 * `FalhaNoEspelhoRemoto`, a do DBAL) traz a chave remota, com o nome. Quem precisa da causa lê
 * {@see $classeDaCausa}.
 */
final class ArquivoIndisponivelNoMomento extends FalhaDeArmazenamento
{
    /** @var class-string<\Throwable>|null */
    public readonly ?string $classeDaCausa;

    private function __construct(string $mensagem, ?string $classeDaCausa)
    {
        parent::__construct($mensagem);
        $this->classeDaCausa = $classeDaCausa;
    }

    public static function para(ChaveDeArquivo $chave, ?\Throwable $causa = null): self
    {
        $classe = $causa === null ? null : $causa::class;

        return new self(
            sprintf(
                'Arquivo temporariamente indisponível (categoria=%s, escritório=%s%s).',
                $chave->categoria->value,
                $chave->escopo->comoTexto(),
                $classe === null ? '' : ', causa=' . $classe,
            ),
            $classe,
        );
    }
}
