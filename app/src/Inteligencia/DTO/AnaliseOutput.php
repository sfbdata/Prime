<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDePonto;

/**
 * Uma análise como a tela e o JSON a veem. `erroMotivo` é a mensagem técnica: o template só a
 * mostra para quem tem `admin.inteligencia.manage`. `aviso` só existe na resposta da solicitação
 * ("nada novo desde a última análise", "já existe uma em andamento").
 */
final readonly class AnaliseOutput
{
    /**
     * @param list<array{tipo: string, rotulo: string, texto: string}> $pontos
     */
    public function __construct(
        public int $id,
        public string $tipo,
        public string $status,
        public string $statusRotulo,
        public bool $emAndamento,
        public bool $terminal,
        public \DateTimeImmutable $criadaEm,
        public ?\DateTimeImmutable $concluidaEm,
        public ?string $resumo,
        public array $pontos,
        public ?string $quemAge,
        public bool $internaDoEscritorio,
        public bool $lida,
        public ?string $erroMotivo,
        public ?string $provedor,
        public ?string $modelo,
        public ?int $tokensEntrada,
        public ?int $tokensSaida,
        public ?int $duracaoMs,
        public int $tentativas,
        public string $versaoDoPrompt,
        public int $totalMovimentacoes,
        public int $movimentacoesNovas,
        public ?string $solicitanteNome,
        public ?string $aviso = null,
        /** Agente da pasta (`analise_pasta`); nulo no Push. */
        public ?string $agente = null,
        public ?string $agenteNome = null,
        /** Análise integral do agente (formato do Designer); nulo no Push. */
        public ?string $textoDaAnalise = null,
    ) {
    }

    public static function fromEntity(AnaliseDeInteligencia $analise, ?string $aviso = null): self
    {
        $status = $analise->getStatus();
        $resumoDoContexto = $analise->getContextoResumo();

        $pontos = [];
        foreach ($analise->getPontos() ?? [] as $ponto) {
            if (!is_array($ponto) || !isset($ponto['texto']) || !is_string($ponto['texto'])) {
                continue;
            }
            $tipo = TipoDePonto::deString($ponto['tipo'] ?? null);
            $pontos[] = ['tipo' => $tipo->value, 'rotulo' => $tipo->rotulo(), 'texto' => $ponto['texto']];
        }

        return new self(
            id: (int) $analise->getId(),
            tipo: $analise->getTipo()->value,
            status: $status->value,
            statusRotulo: $status->rotulo(),
            emAndamento: $status->emAndamento(),
            terminal: $status->terminal(),
            criadaEm: $analise->getCriadaEm(),
            concluidaEm: $analise->getConcluidaEm(),
            resumo: $analise->getResumo(),
            pontos: $pontos,
            quemAge: $analise->getQuemAge(),
            internaDoEscritorio: $analise->isInternaDoEscritorio(),
            lida: $analise->foiLida(),
            erroMotivo: $analise->getErroMotivo(),
            provedor: $analise->getProvedor(),
            modelo: $analise->getModelo(),
            tokensEntrada: $analise->getTokensEntrada(),
            tokensSaida: $analise->getTokensSaida(),
            duracaoMs: $analise->getDuracaoMs(),
            tentativas: $analise->getTentativas(),
            versaoDoPrompt: $analise->getVersaoDoPrompt(),
            totalMovimentacoes: (int) ($resumoDoContexto['total'] ?? 0),
            movimentacoesNovas: (int) ($resumoDoContexto['novas'] ?? 0),
            solicitanteNome: $analise->getSolicitante()?->getFullName(),
            aviso: $aviso,
            agente: $analise->getAgente()?->value,
            agenteNome: $analise->getAgente()?->nome(),
            textoDaAnalise: $analise->getTextoDaAnalise(),
        );
    }

    /** Forma do JSON de status/solicitação (polling da aba Push). */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'statusRotulo' => $this->statusRotulo,
            'emAndamento' => $this->emAndamento,
            'terminal' => $this->terminal,
            'totalMovimentacoes' => $this->totalMovimentacoes,
            'aviso' => $this->aviso,
            'agente' => $this->agente,
        ];
    }
}
