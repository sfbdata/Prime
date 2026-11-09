<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * Acrescenta um evento à trilha do documento, encadeado ao último (contrato da etapa 1, seção 3.2). Roda dentro da
 * transação de quem chama: a linha do documento fica travada até o COMMIT.
 *
 * Etapa 2 (seção 4.3): o evento leva também o signatário e a evidência da requisição (IP e navegador nas colunas, porta
 * em `dados.porta`); os tipos da etapa 2 levam `dados.chave` com a versão do segredo. A hora é a do relógio do
 * servidor. Código, token e link nunca entram em `dados`.
 */
final readonly class TrilhaDoDocumento
{
    public function __construct(
        private EventoDaTrilhaRepository $eventos,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @param array<string, mixed> $dados os parâmetros do texto do evento
     */
    public function acrescentar(
        DocumentoParaAssinar $documento,
        TipoDeEvento $tipo,
        array $dados,
        ?User $usuario,
        ?Signatario $signatario = null,
        ?EvidenciaDaRequisicao $evidencia = null,
    ): EventoDaTrilha {
        if ($tipo->levaVersaoDaChave()) {
            $dados['chave'] = ChaveDaAssinatura::VERSAO;
        }
        if ($evidencia?->porta !== null) {
            $dados['porta'] = $evidencia->porta;
        }

        $anterior = $this->eventos->ultimoHashTravando($documento);
        $evento = new EventoDaTrilha(
            $documento,
            $tipo,
            $dados,
            \DateTimeImmutable::createFromInterface($this->relogio->now()),
            $anterior,
            $usuario,
            $signatario,
            $evidencia?->ip,
            $evidencia?->navegador,
        );
        $this->eventos->salvar($evento);

        return $evento;
    }
}
