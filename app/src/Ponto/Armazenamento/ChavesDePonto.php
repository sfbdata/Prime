<?php

declare(strict_types=1);

namespace App\Ponto\Armazenamento;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

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
        return new ChaveDeArquivo(
            self::escopoDe($dona->getTenant()),
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO,
            $nomeArquivo,
        );
    }

    /**
     * Anexo novo que SUBSTITUI o de uma justificativa existente (E2.4A). O nome é cunhado pelo
     * storage (D8); o escopo sai da justificativa dona, como em {@see anexoDeJustificativaPorNome()}.
     */
    public static function novoAnexoDeJustificativa(JustificativaPonto $dona, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($dona->getTenant()), CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $extensao);
    }

    /**
     * Anexo novo de um LOTE que ainda não existe (E2.4A) — a única forma que recebe `Tenant`.
     *
     * Nas duas portas de criação (`PontoController::novaJustificativa`,
     * `TenantController::novaJustificativaAdmin`) o arquivo é gravado **antes** das N
     * justificativas que vão apontar para ele: essa ordem é da E1 (arquivo antes do banco,
     * remoção no catch do flush) e não muda aqui. Não há entidade dona para consultar. O tenant
     * informado é a mesma variável que, linhas abaixo, vai para `setTenant()` de cada
     * justificativa do lote — e é dela que {@see anexoDeJustificativa()} tira o escopo na leitura.
     * Onde a justificativa já existe, use {@see novoAnexoDeJustificativa()}.
     */
    public static function novoAnexoDeLote(Tenant $tenant, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($tenant), CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $extensao);
    }

    private static function escopoDe(?Tenant $tenant): EscopoDeArquivo
    {
        $id = $tenant?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(
                'Não dá para montar a chave de armazenamento do anexo de justificativa sem o escritório dono.',
            );
        }

        return EscopoDeArquivo::deTenant($id);
    }
}
