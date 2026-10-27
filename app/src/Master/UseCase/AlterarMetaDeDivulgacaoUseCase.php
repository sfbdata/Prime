<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlterarMetaDeDivulgacaoInput;
use App\Master\Entity\MetaDeDivulgacao;
use App\Master\Exception\MetaRecusada;
use App\Master\Repository\MetaDeDivulgacaoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Muda as Metas de divulgação (desenho 05 Perfis 1.2 VS 25.10.26: Ajustar, chips de meta, atualização automática,
 * avanço de fase, meta por estado e por cidade, cor de destaque do estado).
 *
 * Quem dispara: o Master, no bloco "Metas de divulgação" da Visão geral de /master (ROLE_SUPER_ADMIN no controller).
 * Pré-condições: ação conhecida; os números, a UF, a cor e a frequência passam pelas regras da entidade.
 * Fluxo principal: carrega a linha única (ou cria), aplica a ação, registra quem alterou e grava, no escopo de
 * plataforma. "Atualizar agora" só marca a atualização como pendente: quem faz a conta é a próxima leitura.
 * Fluxos alternativos: ação desconhecida, falta de parâmetro ou valor fora da regra recusa (422), sem gravar.
 * Auditoria: MetaDeDivulgacao é Auditavel; cada mudança entra na trilha do Master.
 */
final class AlterarMetaDeDivulgacaoUseCase
{
    public function __construct(
        private readonly MetaDeDivulgacaoRepository $metas,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AlterarMetaDeDivulgacaoInput $input): void
    {
        $meta = $this->metas->buscarUnica() ?? new MetaDeDivulgacao();
        $agora = $this->relogio->now();

        $this->aplicar($meta, $input, $agora);

        $meta->registrarAlteracao($input->ator, $agora);
        $this->escopoDePlataforma->executar(fn () => $this->metas->salvar($meta, flush: true));
    }

    private function aplicar(MetaDeDivulgacao $meta, AlterarMetaDeDivulgacaoInput $in, \DateTimeImmutable $agora): void
    {
        match ($in->acao) {
            'ajustar' => $meta->ajustar($this->exigir($in->mercado), $this->exigir($in->valor), $agora),
            'alvo' => $meta->definirAlvo($this->exigir($in->valor)),
            'automatica' => $meta->ligarAutomatica($this->exigirLigado($in)),
            'frequencia' => $meta->definirFrequencia((string) $in->texto),
            'fase' => $meta->ligarAvancoDeFase($this->exigirLigado($in)),
            'atualizar_agora' => $meta->pedirAtualizacao(),
            'voltar_automatico' => $meta->voltarAoAutomatico(),
            'meta_uf' => $meta->definirMetaDaUf((string) $in->uf, $in->valor),
            'meta_cidade' => $meta->definirMetaDaCidade((string) $in->uf, (string) $in->cidade, $in->valor),
            'destaque' => $meta->definirDestaque((string) $in->uf, $in->texto),
            default => throw new MetaRecusada('Ação desconhecida.'),
        };
    }

    private function exigir(?int $valor): int
    {
        return $valor ?? throw new MetaRecusada('Informe os valores em números inteiros.');
    }

    private function exigirLigado(AlterarMetaDeDivulgacaoInput $in): bool
    {
        return $in->ligado ?? throw new MetaRecusada('Informe se fica ligado ou desligado.');
    }
}
