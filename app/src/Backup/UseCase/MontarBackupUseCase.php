<?php

declare(strict_types=1);

namespace App\Backup\UseCase;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(PedidoDeBackup $pedido): ResultadoDoBackup
    {
        $usuarioId = (int) $pedido->getUsuario()->getId();
        $tenantId  = (int) $pedido->getTenant()->getId();
        $agora   = $this->clock->now();

        $area = AreaTemporariaPrivada::criar(self::FINALIDADE_DA_AREA);
        $zip  = null;

        try {
            $caminhoDoZip = $area->caminho() . '/backup.zip';
            $zip          = ZipDoBackup::criar($caminhoDoZip, $this->materializador, 'backup-bluejus-' . $agora->format('Y-m-d'));

            $this->coletar($zip, $usuarioId, $tenantId, $agora);

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

    /**
     * Um coletor pode soltar o EntityManager (`clear()`) em lotes: a pessoa e o escritório são recarregados por id
     * antes de CADA coletor e do `finalizar`, para nenhum receber entidade destacada.
     */
    private function coletar(ZipDoBackup $zip, int $usuarioId, int $tenantId, \DateTimeImmutable $agora): void
    {
        foreach ($this->coletores as $coletor) {
            [$usuario, $tenant] = $this->recarregar($usuarioId, $tenantId);
            $coletor->coletar($usuario, $tenant, $zip);
        }
        [$usuario, $tenant] = $this->recarregar($usuarioId, $tenantId);
        $zip->finalizar($usuario, $tenant, $agora);
    }

    /** @return array{0: User, 1: Tenant} */
    private function recarregar(int $usuarioId, int $tenantId): array
    {
        $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
        $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');

        return [$usuario, $tenant];
    }
}
