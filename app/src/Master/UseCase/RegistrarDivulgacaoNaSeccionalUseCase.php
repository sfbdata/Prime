<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\RegistrarDivulgacaoNaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Enum\StatusDaDivulgacao;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Registra a divulgação do BlueJus numa seccional da OAB: status, cor da paleta e data (spec
 * docs/specs/master-seccionais-oab.md, "Registro de divulgação"; desenho 05, Perfis 1.2, VS 21.10.26, L902: a marca
 * {cor, st, d} da UF e os seis status do tooltip do chip).
 *
 * Quem dispara: o Master, no menu de botão direito sobre a UF no mapa do Brasil, modo OAB (ROLE_SUPER_ADMIN no controller).
 * Objetivo: marcar o que já foi feito em cada seccional; o chip ganha o ponto na cor escolhida e o tooltip diz o status
 * e a data.
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404) e a linha da seccional existe.
 * Fluxo principal: carrega a seccional, valida status, cor e data (vazia = hoje), troca a marca da UF e grava dentro
 * do escopo de plataforma.
 * Fluxos alternativos: linha ausente é 404; status fora dos seis, cor que não é #rrggbb, data que não é AAAA-MM-DD
 * válida ou data no futuro são recusados com a mensagem (422) e nada muda.
 * Pós-condições: a marca nova substitui a anterior (só o estado atual; o histórico não aparece no desenho, padrão
 * adotado, a confirmar com o dono).
 * Auditoria: SeccionalOab é Auditavel; a linha do audit_log fica sem escritório (trilha do Master) e guarda o antes
 * e o depois da marca.
 */
final class RegistrarDivulgacaoNaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(RegistrarDivulgacaoNaSeccionalInput $input): SeccionalOab
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada(SeccionalNaoEncontrada::RECUSA);
        $status = StatusDaDivulgacao::tryFrom($input->status) ?? throw new SeccionalRecusada('Escolha o status da divulgação.');
        if (preg_match('/^#[0-9a-f]{6}$/i', $input->cor) !== 1) {
            throw new SeccionalRecusada('Escolha a cor da marca.');
        }

        $seccional->marcar($status, strtolower($input->cor), $this->dataValida($input->data, $this->relogio->now()));
        $this->escopoDePlataforma->executar(fn () => $this->seccionais->salvar($seccional, flush: true));

        return $seccional;
    }

    /** Vazia vale hoje; precisa ser AAAA-MM-DD de verdade e não pode passar de hoje. */
    private function dataValida(?string $valor, \DateTimeImmutable $agora): \DateTimeImmutable
    {
        $hoje = $agora->format('Y-m-d');
        $valor ??= $hoje;
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_DATA_FORMATO);
        }

        if ($valor > $hoje) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_DATA_NO_FUTURO);
        }

        return $data;
    }
}
