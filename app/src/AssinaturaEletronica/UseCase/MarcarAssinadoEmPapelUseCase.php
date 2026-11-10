<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Função PROVISÓRIA "Marcar como assinado em papel" (pedida pelo Samuel até a Assinatura completa sair): o escritório
 * imprimiu o documento, os signatários assinaram à mão e alguém do escritório confirma. O documento passa a Concluído e
 * a trilha diz "Assinado em papel, confirmado por {nome} em {data}". O sistema NÃO confere a assinatura: não há
 * assinatura digital, código de validação nem evidência de signatário. A permissão (módulo, sem Master) é conferida pelo
 * controller; o escritório, aqui, pela busca por escritório. Só rascunho e aguardando, fora da Lixeira, e a situação é
 * conferida com a linha travada e relida, então dois cliques não registram duas vezes.
 *
 * Para remover a função depois: apague este UseCase, o `TipoDeEvento::AssinadoEmPapel`, o
 * `DocumentoParaAssinar::concluirEmPapel`, o controller, o template e o JS de `papel`.
 */
final readonly class MarcarAssinadoEmPapelUseCase
{
    private const FUSO = 'America/Sao_Paulo';

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private TrilhaDoDocumento $trilha,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @throws DocumentoNaoEncontrado
     * @throws \App\AssinaturaEletronica\Exception\AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, int $id): void
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $this->documentos->emTransacao(function () use ($documento, $tenant, $ator, $agora): void {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }

            $documento->concluirEmPapel($agora);
            $this->trilha->acrescentar($documento, TipoDeEvento::AssinadoEmPapel, [
                'nome' => self::nomeDe($ator),
                'data' => $agora->setTimezone(new \DateTimeZone(self::FUSO))->format('d/m/Y H:i'),
            ], $ator);
        });
    }

    private static function nomeDe(User $ator): string
    {
        $nome = trim((string) $ator->getFullName());

        return $nome !== '' ? $nome : (string) $ator->getEmail();
    }
}
