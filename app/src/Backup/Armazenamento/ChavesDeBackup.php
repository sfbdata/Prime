<?php

declare(strict_types=1);

namespace App\Backup\Armazenamento;

use App\Backup\Entity\PedidoDeBackup;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * As chaves de arquivo do ZIP de backup. O escopo sai do PEDIDO: a chave de um escritório nunca é montada com o id de
 * outro. Categoria isolada em disco (`uploads/pastas/backups/<tenantId>/`), nunca espelhada no R2.
 */
final class ChavesDeBackup
{
    private function __construct()
    {
    }

    /** ZIP novo de um pedido: o nome é cunhado pelo storage (D8). */
    public static function novoZip(PedidoDeBackup $pedido): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($pedido), CategoriaDeArquivo::BACKUP_ZIP, 'zip');
    }

    /** O ZIP de um pedido pronto. Falha se o pedido não aponta para arquivo (antes de pronto ou depois de vencido). */
    public static function zip(PedidoDeBackup $pedido): ChaveDeArquivo
    {
        $nome = $pedido->getChaveArquivo() ?? throw new \LogicException('O pedido de backup não tem arquivo.');

        return new ChaveDeArquivo(self::escopoDe($pedido), CategoriaDeArquivo::BACKUP_ZIP, $nome);
    }

    /** O ZIP recém-gravado de um pedido que ainda não aponta para ele (para apagar o órfão se o `pronto` não gravou). */
    public static function zipPorNome(PedidoDeBackup $pedido, string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(self::escopoDe($pedido), CategoriaDeArquivo::BACKUP_ZIP, $nome);
    }

    private static function escopoDe(PedidoDeBackup $pedido): EscopoDeArquivo
    {
        return EscopoDeArquivo::deTenant((int) $pedido->getTenant()->getId());
    }
}
