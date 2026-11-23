<?php

declare(strict_types=1);

namespace App\Backup\UseCase;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Service\ZipDoBackup;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Monta o ZIP do backup de um pedido (spec backup-e-exportacao-de-dados, seções 2, 4 e 5).
 *
 * Roda no worker, sem sessão: usa só `$pedido->getUsuario()` e `$pedido->getTenant()`, e cada
 * {@see ColetorDoBackup} recebe os dois explícitos. O ZIP nasce numa {@see AreaTemporariaPrivada} (qualquer parcial
 * some com ela, inclusive se a gravação falhar) e vai ao armazenamento pela chave {@see ChavesDeBackup::novoZip()}.
 *
 * Não muda a situação do pedido nem dá flush: isso é do handler. Devolve o {@see ResultadoDoBackup} para ele.
 */
final class MontarBackupUseCase
{
    private const FINALIDADE_DA_AREA = 'backup';

    /** @param iterable<ColetorDoBackup> $coletores em ordem estável (prioridade da tag) */
    public function __construct(
        private readonly MaterializadorDeArquivo $materializador,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ClockInterface $clock,
        #[AutowireIterator(ColetorDoBackup::TAG)]
        private readonly iterable $coletores,
    ) {
    }

    public function executar(PedidoDeBackup $pedido): ResultadoDoBackup
    {
        $usuario = $pedido->getUsuario();
        $tenant  = $pedido->getTenant();
        $agora   = $this->clock->now();

        $area = AreaTemporariaPrivada::criar(self::FINALIDADE_DA_AREA);
        $zip  = null;

        try {
            $caminhoDoZip = $area->caminho() . '/backup.zip';
            $zip          = ZipDoBackup::criar($caminhoDoZip, $this->materializador, 'backup-bluejus-' . $agora->format('Y-m-d'));

            foreach ($this->coletores as $coletor) {
                $coletor->coletar($usuario, $tenant, $zip);
            }
            $zip->finalizar($usuario, $tenant, $agora);

            $sha256 = @hash_file('sha256', $caminhoDoZip);
            if ($sha256 === false) {
                throw new FalhaNoTemporario('Não foi possível calcular o sha256 do ZIP do backup.');
            }

            $armazenado = $this->armazenamento->gravar(
                ChavesDeBackup::novoZip($pedido),
                FonteDeConteudo::deArquivoLocal($caminhoDoZip, consumirOrigem: true),
            );

            return new ResultadoDoBackup(
                chaveArquivo: $armazenado->chave->nome,
                tamanhoBytes: $armazenado->tamanhoBytes,
                sha256: $sha256,
                arquivosIncluidos: $zip->arquivosIncluidos(),
                arquivosFora: $zip->arquivosFora(),
            );
        } finally {
            $zip?->descartar();
            $area->liberar();
        }
    }
}
