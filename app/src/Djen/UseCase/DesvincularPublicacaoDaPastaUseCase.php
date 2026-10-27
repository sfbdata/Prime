<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Processo\Repository\ProcessoRepository;

/**
 * "Desvincular da pasta" na linha da publicação (desenho 11). Tira o PROCESSO da pasta; ele continua
 * cadastrado e as publicações voltam a aparecer como Avulsas. Só quem edita a pasta.
 */
final class DesvincularPublicacaoDaPastaUseCase
{
    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acesso,
        private readonly DesvincularProcessoUseCase $desvincular,
        private readonly ProcessoRepository $processos,
    ) {
    }

    /** @throws VinculoDaPublicacaoInvalidoException */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, int $pastaId): Pasta
    {
        $publicacao = $this->publicacoes->findOneByIdDoTenant($publicacaoId, $tenant)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        $processo = $publicacao->getProcesso()
            ?? throw new VinculoDaPublicacaoInvalidoException('A publicação não está vinculada a uma pasta.');
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || !$pasta->temProcesso($processo)) {
            throw new VinculoDaPublicacaoInvalidoException('A publicação não está vinculada a esta pasta.');
        }
        if (!$this->acesso->podeEditar($usuario, $pasta)) {
            throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para alterar esta pasta.');
        }

        $this->desvincular->executar($pasta, $processo, $usuario);

        return $pasta;
    }

    /**
     * Aba Processos da OAB: "Desvincular da pasta" pelo número do processo.
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executarPorNumero(Tenant $tenant, User $usuario, string $numero, int $pastaId): Pasta
    {
        $numero = preg_replace('/\D+/', '', $numero) ?? '';
        $processo = $this->processos->findByNumeroProcessoDoTenant($numero, $tenant)
            ?? throw new VinculoDaPublicacaoInvalidoException('O processo não está vinculado a uma pasta.');
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || !$pasta->temProcesso($processo)) {
            throw new VinculoDaPublicacaoInvalidoException('O processo não está vinculado a esta pasta.');
        }
        if (!$this->acesso->podeEditar($usuario, $pasta)) {
            throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para alterar esta pasta.');
        }

        $this->desvincular->executar($pasta, $processo, $usuario);

        return $pasta;
    }
}
