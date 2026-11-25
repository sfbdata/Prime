<?php

declare(strict_types=1);

namespace App\Voto\Armazenamento;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;

/**
 * As chaves de arquivo do Voto (a foto 3x4 da candidatura e o PDF do edital da assembleia). O escopo sai da ENTIDADE (a pauta, a opção ou a assembleia), nunca de um
 * escritório solto: a chave de um escritório não é montada com o id de outro.
 */
final class ChavesDoVoto
{
    /** O nome que o storage cunha: 32 hexadecimais e a extensão da imagem (a rota de entrega confere o mesmo formato). */
    public const PADRAO_DO_NOME = '/^[0-9a-f]{32}\.(?:jpe?g|png|webp)$/';

    private function __construct()
    {
    }

    /** A foto atual da candidatura. Lança se a opção não tem foto: quem chama confere antes. */
    public static function fotoDaCandidatura(OpcaoDaPauta $opcao): ChaveDeArquivo
    {
        $nome = $opcao->getFoto();
        if (null === $nome) {
            throw new ChaveDeArquivoInvalida('Esta candidatura não possui foto; não há chave a montar.');
        }

        return new ChaveDeArquivo(self::escopoDe($opcao->getTenant()->getId(), 'foto de candidatura'), CategoriaDeArquivo::VOTO_FOTO_CANDIDATURA, $nome);
    }

    /** Foto nova (D8): o nome é cunhado pelo storage; o escopo sai da pauta, que já existe quando o arquivo é gravado. */
    public static function novaFotoDaCandidatura(PautaDaAssembleia $pauta, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($pauta->getTenant()->getId(), 'foto nova de candidatura'),
            CategoriaDeArquivo::VOTO_FOTO_CANDIDATURA,
            $extensao,
        );
    }

    /** O edital atual da assembleia. Lança se ela não tem edital: quem chama confere antes. */
    public static function edital(Assembleia $assembleia): ChaveDeArquivo
    {
        $nome = $assembleia->getEditalChave();
        if (null === $nome) {
            throw new ChaveDeArquivoInvalida('Esta assembleia não possui edital; não há chave a montar.');
        }

        return new ChaveDeArquivo(self::escopoDe($assembleia->getTenant()->getId(), 'edital'), CategoriaDeArquivo::VOTO_EDITAL, $nome);
    }

    /** Edital novo: o nome é cunhado pelo storage; o escopo sai da assembleia, que já existe quando o arquivo é gravado. */
    public static function novoEdital(Assembleia $assembleia, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($assembleia->getTenant()->getId(), 'edital novo'),
            CategoriaDeArquivo::VOTO_EDITAL,
            $extensao,
        );
    }

    private static function escopoDe(?int $tenantId, string $oQue): EscopoDeArquivo
    {
        if (null === $tenantId) {
            throw new ChaveDeArquivoInvalida(sprintf('Não dá para montar a chave de armazenamento de %s sem o escritório dono.', $oQue));
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
