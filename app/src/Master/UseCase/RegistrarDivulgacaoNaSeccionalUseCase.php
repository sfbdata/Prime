<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\RegistrarDivulgacaoNaSeccionalInput;
use App\Master\Entity\DivulgacaoNaSeccional;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\DivulgacaoNaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Registra uma divulgação do BlueJus numa seccional da OAB (spec docs/specs/master-seccionais-oab.md,
 * "Registro de divulgação"; desenho 05, Perfis 1.2, VS 21.10.26: "botão direito no mapa registra a
 * divulgação (material enviado, reunião, parceria)").
 *
 * Quem dispara: o Master, no menu de botão direito sobre a UF no mapa do Brasil (ROLE_SUPER_ADMIN no controller).
 * Objetivo: guardar o que já foi feito em cada seccional, para o painel mostrar quais já foram trabalhadas
 * (ponto colorido no chip) e o histórico recente no cartão da sede.
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404) e a linha da seccional existe.
 * Fluxo principal: carrega a seccional, valida tipo, data (vazia = hoje) e nota, cria a divulgação com o autor
 * e o momento, e grava dentro do escopo de plataforma.
 * Fluxos alternativos: linha ausente é 404; tipo fora dos três, data que não é AAAA-MM-DD válida, data no
 * futuro ou nota acima de 500 caracteres são recusados com a mensagem (422) e nada é gravado.
 * Pós-condições: a divulgação passa a ser a última da seccional se for a de data mais recente.
 * Auditoria: DivulgacaoNaSeccional é Auditavel; a linha do audit_log fica sem escritório (trilha do Master).
 */
final class RegistrarDivulgacaoNaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly DivulgacaoNaSeccionalRepository $divulgacoes,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(RegistrarDivulgacaoNaSeccionalInput $input): DivulgacaoNaSeccional
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada('Seccional não encontrada.');
        $agora = $this->relogio->now();
        $tipo = TipoDeDivulgacao::tryFrom($input->tipo) ?? throw new SeccionalRecusada('Escolha o tipo: material enviado, reunião ou parceria.');
        $data = $this->dataValida($input->data, $agora);
        $this->exigirNotaCurta($input->nota);

        $divulgacao = new DivulgacaoNaSeccional($seccional, $tipo, $data, $input->nota, $input->ator, $agora);
        $this->escopoDePlataforma->executar(fn () => $this->divulgacoes->salvar($divulgacao, flush: true));

        return $divulgacao;
    }

    /** Vazia vale hoje; precisa ser AAAA-MM-DD de verdade e não pode passar de hoje. */
    private function dataValida(?string $valor, \DateTimeImmutable $agora): \DateTimeImmutable
    {
        $hoje = $agora->format('Y-m-d');
        $valor ??= $hoje;
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new SeccionalRecusada('Informe a data no formato dia, mês e ano.');
        }

        if ($valor > $hoje) {
            throw new SeccionalRecusada('A data da divulgação não pode ser no futuro.');
        }

        return $data;
    }

    private function exigirNotaCurta(?string $nota): void
    {
        if (mb_strlen((string) $nota) > DivulgacaoNaSeccional::NOTA_MAXIMA) {
            throw new SeccionalRecusada(sprintf('A nota passa de %d caracteres.', DivulgacaoNaSeccional::NOTA_MAXIMA));
        }
    }
}
