<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;

/**
 * "Vincular a uma pasta" (e "Trocar a pasta vinculada") na linha da publicação do Push (desenho 11).
 *
 * O vínculo é do PROCESSO com a pasta: todas as publicações do mesmo número passam a aparecer como
 * Vinculadas. Se o escritório ainda não cadastrou o processo, ele nasce do que a própria publicação traz
 * (órgão e classe; o assunto fica "Não informado" e se completa depois na pasta), pelo mesmo caminho do
 * "Vincular processo" da pasta. Só vincula quem EDITA a pasta (cadeado incluído).
 */
final class VincularPublicacaoAPastaUseCase
{
    private const NAO_INFORMADO = 'Não informado';

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly ProcessoRepository $processos,
        private readonly AcessoAPasta $acesso,
        private readonly CadastrarProcessoPelaPastaUseCase $cadastrarProcesso,
        private readonly VincularProcessoUseCase $vincular,
        private readonly DesvincularProcessoUseCase $desvincular,
        private readonly ReconciliarPublicacoesComProcessosUseCase $reconciliar,
    ) {
    }

    /**
     * @param int|null $trocarDaPastaId pasta de onde o processo sai, quando o pedido é "Trocar a pasta vinculada"
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, int $pastaId, ?int $trocarDaPastaId = null): Pasta
    {
        $publicacao = $this->publicacoes->findOneByIdDoTenant($publicacaoId, $tenant)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        $pasta = $this->pastaEditavel($tenant, $usuario, $pastaId);
        $processo = $publicacao->getProcesso() ?? $this->processoDaPublicacao($tenant, $usuario, $publicacao);

        if ($trocarDaPastaId !== null && $trocarDaPastaId !== $pastaId) {
            $anterior = $this->pastaEditavel($tenant, $usuario, $trocarDaPastaId);
            $this->desvincular->executar($anterior, $processo, $usuario);
        }

        $this->vincular->executar($pasta, $processo, $usuario);
        $this->reconciliar->executar($tenant);

        return $pasta;
    }

    private function pastaEditavel(Tenant $tenant, User $usuario, int $pastaId): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || $pasta->getExcluidaEm() !== null) {
            throw new VinculoDaPublicacaoInvalidoException('Pasta não encontrada.');
        }
        if (!$this->acesso->podeEditar($usuario, $pasta)) {
            throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para alterar esta pasta.');
        }

        return $pasta;
    }

    private function processoDaPublicacao(Tenant $tenant, User $usuario, PublicacaoDjen $publicacao): Processo
    {
        $numero = $publicacao->getNumeroProcesso();
        $existente = $this->processos->findByNumeroProcessoDoTenant($numero, $tenant);
        if ($existente !== null) {
            return $existente;
        }

        $entrada = new CadastroDoProcessoInput(
            numeroProcesso: $numero,
            orgaoJulgador: trim((string) $publicacao->getNomeOrgao()) ?: self::NAO_INFORMADO,
            classeProcessual: trim((string) $publicacao->getNomeClasse()) ?: self::NAO_INFORMADO,
            assuntoProcessual: self::NAO_INFORMADO,
        );
        try {
            return $this->cadastrarProcesso->executar($entrada, $tenant, $usuario)->processo;
        } catch (DadosDoProcessoInvalidosException $e) {
            throw new VinculoDaPublicacaoInvalidoException($e->getMessage(), 0, $e);
        }
    }
}
