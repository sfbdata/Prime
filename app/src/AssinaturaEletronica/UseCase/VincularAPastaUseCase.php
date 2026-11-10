<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\PastaRecusadaNoVinculo;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * "Vincular à pasta" da lista, do menu e do detalhe (spec assinatura-documentos-etapa-3, E3-10 e E3-11; desenho 17,
 * `vincExec`). Sempre pelo id da pasta: do escritório, fora da lápide e que quem vincula EDITA (permissão e cadeado);
 * documento aguardando ou concluído, fora da Lixeira, sem vínculo ainda (a entidade recusa o resto).
 *
 * Aguardando: o vínculo fica pendente e a trilha ganha "Definido o vínculo à pasta {nup} ao concluir". Concluído: o
 * vínculo é aplicado na hora pelo {@see VinculoComAPasta}, que escreve "Documento assinado vinculado à pasta {nup}" ou,
 * na falha, "Vínculo à pasta {nup} não aplicado: {motivo}" (um evento só por vínculo, como no desenho). Na falha o
 * vínculo se desfaz: o JSON volta com `vinculado: false` e o "Vincular à pasta" reaparece (desenho, `vincExec`).
 */
final readonly class VincularAPastaUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private PastaRepository $pastas,
        private PermissionChecker $permissoes,
        private EventoDaTrilhaRepository $eventos,
        private TrilhaDoDocumento $trilha,
        private VinculoComAPasta $vinculo,
        private ApresentacaoDoDocumento $apresentacao,
        private ClockInterface $relogio,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{documento: array<string, mixed>, aplicado: bool} o `Detalhe` e se o arquivo já foi para a pasta
     *
     * @throws DocumentoNaoEncontrado
     * @throws PastaRecusadaNoVinculo
     * @throws AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, int $documentoId, int $pastaId): array
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($documentoId, $tenant) ?? throw new DocumentoNaoEncontrado();
        $pasta = $this->pastaQueEdita($ator, $tenant, $pastaId);

        $this->documentos->emTransacao(function () use ($documento, $pasta, $ator, $tenant): void {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }
            $documento->definirVinculo($pasta, $ator, \DateTimeImmutable::createFromInterface($this->relogio->now()));
            if ($documento->getSituacao() !== SituacaoDoDocumento::Concluido) {
                $this->trilha->acrescentar($documento, TipoDeEvento::VinculoDefinido, ['nup' => (string) $pasta->getNup()], $ator);
            }
        });

        return [
            'aplicado' => $this->aplicarSeConcluido($documento),
            'documento' => $this->apresentacao->detalhe($documento, $this->eventos->doDocumento($documento)),
        ];
    }

    /** Pasta de outro escritório, inexistente ou que a pessoa nem vê: 404. Vê e não edita: 403. Na lápide: 422. */
    private function pastaQueEdita(User $ator, Tenant $tenant, int $pastaId): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        $pode = fn (string $acao): bool => $this->permissoes->canAccessResource($ator, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, $acao);
        if ($pasta === null || !$pode(AccessRequest::ACTION_VIEW)) {
            throw PastaRecusadaNoVinculo::naoEncontrada();
        }
        if (!$pode(AccessRequest::ACTION_EDIT)) {
            throw PastaRecusadaNoVinculo::semPermissao();
        }
        if ($pasta->estaExcluida()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.vinculo_pasta_excluida');
        }

        return $pasta;
    }

    /**
     * A recusa e a falha conhecida desfazem o vínculo no {@see VinculoComAPasta}, com o evento na trilha. O erro
     * inesperado vai para o log e o vínculo fica como estava.
     */
    private function aplicarSeConcluido(DocumentoParaAssinar $documento): bool
    {
        if ($documento->getSituacao() !== SituacaoDoDocumento::Concluido) {
            return false;
        }
        try {
            return $this->vinculo->aplicar($documento);
        } catch (\Throwable $e) {
            $this->logger->error('Vínculo da assinatura: documento {id} vinculado, mas não aplicado: {erro}', ['id' => $documento->getId(), 'erro' => $e->getMessage()]);

            return false;
        }
    }
}
