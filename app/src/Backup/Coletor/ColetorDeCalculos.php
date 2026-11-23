<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Calculo\Entity\CalculoEmitido;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Cálculos no backup: `dados/calculos.json` com o registro dos cálculos que a PRÓPRIA pessoa emitiu (código de
 * verificação, data, atualizado até, total e hash do PDF).
 *
 * Só roda para quem tem o módulo `calculo` (`CalculoController.php:102`). Filtra por `usuario` (a pessoa) e por
 * `tenant_id` escritos na mão: cálculo emitido por colega não entra. O sistema NÃO guarda o PDF (ele é gerado na hora
 * e só o hash fica registrado), então não há arquivo a copiar: o ZIP leva o registro e o código que permite conferir o PDF.
 */
#[AsTaggedItem(priority: 2)]
final class ColetorDeCalculos implements ColetorDoBackup
{
    use PercorreEmPaginasTrait;

    public const MODULO = 'calculo';

    public const REGISTROS_POR_PAGINA = 200;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
        private readonly int $porPagina = self::REGISTROS_POR_PAGINA,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $usuarioId = (int) $usuario->getId();
        $ajuste    = static fn ($qb) => $qb->andWhere('IDENTITY(e.usuario) = :usuario')->setParameter('usuario', $usuarioId);
        $linhas    = [];
        foreach ($this->paginas(CalculoEmitido::class, (int) $tenant->getId(), $this->porPagina, $ajuste) as $pagina) {
            foreach ($pagina as $c) {
                \assert($c instanceof CalculoEmitido);
                $linhas[] = [
                    'codigo'         => $c->getCodigo(),
                    'emitido_em'     => ValoresDoBackup::data($c->getEmitidoEm()),
                    'atualizado_ate' => ValoresDoBackup::dia($c->getAtualizadoAte()),
                    'total'          => $c->getTotal(),
                    'hash_do_pdf'    => $c->getHashPdf(),
                ];
                $zip->contar('Cálculos emitidos');
            }
        }

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/calculos.json', $linhas);
    }
}
