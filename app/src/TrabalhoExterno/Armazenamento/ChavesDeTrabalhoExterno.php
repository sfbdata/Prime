<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Armazenamento;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;

/**
 * Traduz o documento de uma demanda em {@see ChaveDeArquivo}. O escopo é SEMPRE o escritório
 * solicitante (dono da demanda), mesmo quando quem anexa é o Master: o arquivo é do pedido, e a
 * purga daquele escritório o leva junto.
 *
 * Os arquivos do currículo têm escopo GLOBAL (D1), como a foto de perfil: o currículo é do usuário,
 * da plataforma, e não de um escritório. A autorização (quem baixa o de quem) fica na rota, antes
 * de montar a chave (INV-5).
 */
final class ChavesDeTrabalhoExterno
{
    private function __construct()
    {
    }

    public static function documento(DocumentoDemanda $documento): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($documento->getDemanda()),
            CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO,
            $documento->getNomeArmazenado(),
        );
    }

    public static function novoDocumento(DemandaExterna $demanda, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($demanda), CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO, $extensao);
    }

    public static function arquivoDoCurriculo(Curriculo $curriculo, TipoArquivoDoCurriculo $tipo): ChaveDeArquivo
    {
        $nome = $curriculo->chaveDoArquivo($tipo);
        if ($nome === null) {
            throw new ChaveDeArquivoInvalida('Este currículo não tem o arquivo pedido; não há chave a montar.');
        }

        return self::arquivoDoCurriculoPorNome($nome);
    }

    /**
     * Arquivo que o currículo JÁ NÃO referencia (o substituído ou o removido), apagado depois do
     * COMMIT. O escopo é global de qualquer jeito, então o nome solto não abre porta para outro dono.
     */
    public static function arquivoDoCurriculoPorNome(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO, $nome);
    }

    /** Arquivo novo do currículo: não há escritório a consultar, o escopo é global por definição (D1). */
    public static function novoArquivoDoCurriculo(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO, $extensao);
    }

    private static function escopoDe(DemandaExterna $demanda): EscopoDeArquivo
    {
        $tenantId = $demanda->getTenant()->getId();
        if ($tenantId === null) {
            throw new ChaveDeArquivoInvalida('Não dá para montar a chave do documento sem o escritório solicitante.');
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
