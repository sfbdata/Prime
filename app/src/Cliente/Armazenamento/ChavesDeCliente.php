<?php

declare(strict_types=1);

namespace App\Cliente\Armazenamento;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz o documento do cliente em {@see ChaveDeArquivo} (E2.2).
 *
 * O escopo sai da entidade e só dela (R1); o nome vai byte a byte (D8). A justificativa completa
 * mora em `App\Pasta\Armazenamento\ChavesDePasta` — aqui vale a mesma regra, para a categoria
 * `CLIENTE_DOCUMENTO`, cujo diretório em disco é plano e compartilhado entre escritórios: é
 * exatamente o caso em que um tenant errado passaria despercebido no backend local.
 */
final class ChavesDeCliente
{
    private function __construct()
    {
    }

    public static function documento(ClienteDocumento $documento): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($documento->getTenant()?->getId(), 'documento de cliente'),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $documento->getCaminhoArquivo(),
        );
    }

    /**
     * Arquivo novo (E2.4A): o nome é cunhado pelo storage (D8); o escopo sai do cliente, de quem o
     * documento copia o escritório na criação.
     */
    public static function novoDocumento(Cliente $cliente, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($cliente->getTenant()?->getId(), 'documento novo de cliente'),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $extensao,
        );
    }

    /**
     * A foto atual do cliente (cabeçalho da pasta). Escopo do escritório do cliente, como o documento: a
     * categoria é plana no disco, e um tenant errado só apareceria no backend remoto (R1).
     */
    public static function foto(Cliente $cliente): ChaveDeArquivo
    {
        $nome = $cliente->getFoto();

        if ($nome === null) {
            throw new ChaveDeArquivoInvalida('Este cliente não possui foto; não há chave a montar.');
        }

        return self::fotoPorNome($cliente, $nome);
    }

    /**
     * Foto que o cliente JÁ NÃO referencia (a anterior, removida depois do COMMIT da troca ou da remoção). Existe
     * pelo mesmo motivo de `ChavesDePerfil::fotoPorNome()`: depois da troca, {@see foto()} devolveria a chave da
     * NOVA. O escopo continua saindo do cliente, nunca de um tenant solto.
     */
    public static function fotoPorNome(Cliente $cliente, string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($cliente->getTenant()?->getId(), 'foto de cliente'),
            CategoriaDeArquivo::CLIENTE_FOTO,
            $nome,
        );
    }

    /** Foto nova (D8): o nome é cunhado pelo storage; o escopo sai do cliente. */
    public static function novaFoto(Cliente $cliente, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($cliente->getTenant()?->getId(), 'foto nova de cliente'),
            CategoriaDeArquivo::CLIENTE_FOTO,
            $extensao,
        );
    }

    private static function escopoDe(?int $tenantId, string $oQue): EscopoDeArquivo
    {
        if ($tenantId === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
