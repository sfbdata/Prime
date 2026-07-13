<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AcordoOutput;
use App\Cobranca\DTO\CasoDetalheOutput;
use App\Cobranca\DTO\EventoHistoricoOutput;
use App\Cobranca\DTO\LiquidacaoOutput;
use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\DTO\PagamentoOutput;
use App\Cobranca\DTO\ProximaAcaoOutput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraSaldo;

/**
 * Leitura: monta o detalhe completo do Caso — a tela central (SPEC §9/§26, Etapa 8). Agrega o
 * cabeçalho operacional (saldo derivado, estado, pessoa cobrada, próxima ação, alertas) e as
 * coleções das abas (obrigações, pagamentos, liquidações, acordos, histórico) a partir dos repos
 * tenant-scoped e dos serviços de derivação. O caso já vem resolvido por tenant no controller;
 * nada aqui recalcula regra de negócio — só lê e formata via Output DTOs. Documentos entram na 8C.
 */
final class MontarDetalheCasoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly AlertasCobranca $alertasCobranca,
    ) {
    }

    public function executar(CasoCobranca $caso): CasoDetalheOutput
    {
        $hoje = new \DateTimeImmutable('today');

        $objeto = $caso->getObjeto();
        $carteira = $objeto?->getCarteira();
        $pessoa = $caso->getPessoaCobradaAtual();
        $status = $caso->getStatus();
        $saldoExigivel = $this->calculadoraSaldo->saldoExigivel($caso);

        $acaoAtiva = $this->proximaAcaoRepository->findAtivaDoCaso($caso);

        return new CasoDetalheOutput(
            id: $caso->getId() ?? 0,
            objetoIdentificacao: $objeto?->getIdentificacao() ?? '—',
            objetoDescricao: $objeto?->getDescricao(),
            carteiraId: $carteira?->getId() ?? 0,
            carteiraNome: $carteira?->getNome() ?? '—',
            pessoaCobradaNome: $pessoa?->getNome() ?? '—',
            pessoaCobradaCpf: $pessoa?->getCpf(),
            pessoaCobradaCnpj: $pessoa?->getCnpj(),
            pessoaCobradaEmail: $pessoa?->getEmail(),
            pessoaCobradaTelefone: $pessoa?->getTelefone(),
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            encerrado: $status === StatusCaso::Encerrado,
            prontoParaEncerrar: $status !== StatusCaso::Encerrado && $saldoExigivel === 0,
            saldoExigivel: $saldoExigivel,
            saldoVencido: $this->calculadoraSaldo->saldoVencido($caso, $hoje),
            formaHonorariosLabel: $caso->getFormaHonorarios()->label(),
            percentualHonorarios: $caso->getPercentualHonorarios(),
            pastaJudicialId: $caso->getPastaJudicial()?->getId(),
            proximaAcao: $acaoAtiva !== null ? ProximaAcaoOutput::fromEntity($acaoAtiva, $hoje) : null,
            // Dedupe: reusa o saldoExigivel e a ação ativa já computados acima (evita o recálculo interno
            // do saldo e a re-busca da ação que `alertasDoCaso` faria).
            alertas: $this->alertasCobranca->alertasComContexto($caso, $saldoExigivel, $acaoAtiva, $hoje),
            obrigacoes: array_map(ObrigacaoOutput::fromEntity(...), $this->obrigacaoRepository->doCaso($caso)),
            pagamentos: array_map(PagamentoOutput::fromEntity(...), $this->pagamentoRepository->doCaso($caso)),
            liquidacoes: array_map(LiquidacaoOutput::fromEntity(...), $this->liquidacaoRepository->doCaso($caso)),
            acordos: array_map(AcordoOutput::fromEntity(...), $this->acordoRepository->doCaso($caso)),
            historico: array_map(EventoHistoricoOutput::fromEntity(...), $this->eventoRepository->doCaso($caso)),
        );
    }
}
