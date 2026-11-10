<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O vínculo à pasta no JSON da tela (spec assinatura-documentos-etapa-3, E3-10; desenho 17, `temPasta`, `pastaTxt` e
 * `dPastaTxt`): `vinculado` diz se o documento já tem pasta (o "Vincular à pasta" some), e `vinculo` traz o número, o
 * cliente, se já foi feito e o endereço da pasta. `vinculo` só sai para quem VÊ a pasta (permissão e cadeado); para os
 * outros é nulo, e nem o número nem o cliente aparecem.
 *
 * Na lista, uma consulta para os vínculos dos documentos da resposta (pelos ids, {@see prepararLinhas()}) e uma
 * conferência em lote da permissão; no detalhe, a entidade. Memória por requisição (zerada pelo `ResetInterface`).
 */
final class VinculoNaTela implements ResetInterface
{
    public const SEM_VINCULO = ['vinculado' => false, 'vinculo' => null];

    /** @var array<int, array{pasta: Pasta, pendente: bool}|null> por id do documento já consultado; nulo = sem vínculo */
    private array $dasLinhas = [];

    /** @var array<int, bool> por id da pasta: a pessoa da sessão vê a pasta */
    private array $visiveis = [];

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly DocumentoParaAssinarRepository $documentos,
        private readonly PermissionChecker $permissoes,
        private readonly UserTenantRepository $vinculosDaPessoa,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Lê, numa consulta só, os vínculos das linhas que a resposta vai montar (só esses ids, não o escritório inteiro), e
     * confere em lote quem vê as pastas deles. Os ids já lidos nesta requisição não são consultados de novo.
     *
     * @param list<int> $documentoIds
     */
    public function prepararLinhas(array $documentoIds): void
    {
        $faltam = array_values(array_unique(array_filter($documentoIds, fn (int $id): bool => !\array_key_exists($id, $this->dasLinhas))));
        if ($faltam === []) {
            return;
        }
        $quem = $this->acesso->quemPode();
        $achados = $quem === null ? [] : $this->documentos->vinculosDosDocumentos($quem[1], $faltam);
        foreach ($faltam as $id) {
            $this->dasLinhas[$id] = $achados[$id] ?? null;
        }
        if ($quem !== null && $achados !== []) {
            $this->conferirEmLote($quem[0], $quem[1], $achados);
        }
    }

    /**
     * O vínculo de uma linha da lista, pelo id do documento. A linha que não passou pelo {@see prepararLinhas()} consulta
     * só o próprio id.
     *
     * @return array{vinculado: bool, vinculo: array<string, mixed>|null}
     */
    public function daLinha(int $documentoId): array
    {
        if (!\array_key_exists($documentoId, $this->dasLinhas)) {
            $this->prepararLinhas([$documentoId]);
        }
        $linha = $this->dasLinhas[$documentoId] ?? null;

        return $linha === null ? self::SEM_VINCULO : $this->montar($linha['pasta'], $linha['pendente']);
    }

    /**
     * O vínculo do detalhe, pela entidade (vale logo depois de vincular, na mesma requisição).
     *
     * @return array{vinculado: bool, vinculo: array<string, mixed>|null}
     */
    public function doDocumento(DocumentoParaAssinar $documento): array
    {
        $pasta = $documento->getPasta();

        return $pasta === null ? self::SEM_VINCULO : $this->montar($pasta, $documento->isVinculoPendente());
    }

    public function reset(): void
    {
        $this->dasLinhas = [];
        $this->visiveis = [];
    }

    /** @return array{vinculado: bool, vinculo: array<string, mixed>|null} */
    private function montar(Pasta $pasta, bool $pendente): array
    {
        $id = (int) $pasta->getId();

        return ['vinculado' => true, 'vinculo' => $this->ve($pasta) ? [
            'pasta' => $id,
            'nup' => (string) $pasta->getNup(),
            'cliente' => (string) $pasta->getNomeCliente(),
            'feito' => !$pendente,
            'url' => $this->urls->generate('pasta_show', ['id' => $id]),
        ] : null];
    }

    private function ve(Pasta $pasta): bool
    {
        $id = (int) $pasta->getId();
        if (!isset($this->visiveis[$id])) {
            $quem = $this->acesso->quemPode();
            $this->visiveis[$id] = $quem !== null
                && $this->permissoes->canAccessResource($quem[0], $quem[1], AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_VIEW);
        }

        return $this->visiveis[$id];
    }

    /**
     * As pastas das linhas numa conferência: os cadeados numa consulta e os acessos item a item em outra.
     *
     * @param array<int, array{pasta: Pasta, pendente: bool}> $vinculos
     */
    private function conferirEmLote(User $pessoa, Tenant $tenant, array $vinculos): void
    {
        $pastas = [];
        foreach ($vinculos as $linha) {
            $id = (int) $linha['pasta']->getId();
            if (!isset($this->visiveis[$id])) {
                $pastas[$id] = $linha['pasta'];
            }
        }
        if ($pastas === []) {
            return;
        }
        $this->acessoAPasta->niveisEmLote($pessoa, $pastas);
        $vinculo = $this->vinculosDaPessoa->findAtivoPorUserETenant($pessoa, $tenant);
        $permitidas = array_flip($this->permissoes->idsPermitidosComVinculo($pessoa, $vinculo, AccessRequest::RESOURCE_PASTA, array_keys($pastas), AccessRequest::ACTION_VIEW));
        foreach (array_keys($pastas) as $id) {
            $this->visiveis[$id] = isset($permitidas[$id]);
        }
    }
}
