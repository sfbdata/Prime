<?php

declare(strict_types=1);

namespace App\Ponto\Armazenamento;

use App\Ponto\Entity\JustificativaPonto;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * Traduz o anexo (atestado) da justificativa de ponto em {@see ChaveDeArquivo} (E2.2, risco ALTO).
 *
 * Duas formas, e a diferença não é conveniência:
 *
 *  - `anexoDeJustificativa()` lê o nome do getter. Serve às rotas de download, onde a entidade
 *    veio do banco agora e o getter é a verdade.
 *  - `anexoDeJustificativaPorNome()` recebe o nome de fora e tira **só o escopo** da entidade
 *    dona. Serve ao lote (`SubstituirAnexoDoLoteUseCase`): ali o anexo antigo é lido por projeção
 *    escalar sob a trava — o getter pode estar velho, lição registrada no próprio UseCase — e o
 *    anexo novo, no rollback, ainda não foi persistido em ninguém.
 *
 * Nas duas, o escopo sai da entidade persistida, nunca do `$tenant` que o UseCase recebe por
 * parâmetro (R1). O diretório de justificativas é plano e compartilhado por todos os escritórios,
 * então um tenant errado aqui seria invisível no disco de hoje.
 */
final class ChavesDePonto
{
    private function __construct()
    {
    }

    public static function anexoDeJustificativa(JustificativaPonto $justificativa): ChaveDeArquivo
    {
        $anexo = $justificativa->getAnexoPath();

        if ($anexo === null) {
            throw new ChaveDeArquivoInvalida('Esta justificativa não possui anexo; não há chave a montar.');
        }

        return self::anexoDeJustificativaPorNome($justificativa, $anexo);
    }

    public static function anexoDeJustificativaPorNome(JustificativaPonto $dona, string $nomeArquivo): ChaveDeArquivo
    {
        $id = $dona->getTenant()?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(
                'Não dá para montar a chave de armazenamento do anexo de justificativa sem o escritório dono.',
            );
        }

        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant($id),
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO,
            $nomeArquivo,
        );
    }
}
