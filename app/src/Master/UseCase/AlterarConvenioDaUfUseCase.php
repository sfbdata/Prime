<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlterarConvenioDaUfInput;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Muda o convênio OAB de um estado (desenho 05 Perfis 1.2 VS 25.10.26): grátis, valor diferenciado ou sem convênio.
 *
 * Quem dispara: o Master, no painel "Convênio OAB por estado" da seção Contas (ROLE_SUPER_ADMIN no controller).
 * Pré-condições: UF conhecida; em `dif` e `sem`, valor de 0 a 999.999,99 e data AAAA-MM-DD.
 * Fluxo principal: `livre` apaga a linha do estado; `dif` e `sem` criam ou trocam a linha. Grava no escopo de
 * plataforma. Auditoria: ConvenioDaUf é Auditavel, cada criação, troca e remoção entra na trilha do Master.
 * Não envia aviso aos primários nem muda cobrança: são frentes próprias (spec, P4c).
 * Fluxos alternativos: dado fora da regra recusa (422), sem gravar.
 */
final class AlterarConvenioDaUfUseCase
{
    public function __construct(
        private readonly ConvenioDaUfRepository $convenios,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AlterarConvenioDaUfInput $input): void
    {
        $uf = ConvenioDaUf::exigirUf($input->uf);
        $atual = $this->convenios->buscar($uf);

        if ($input->modo === 'livre') {
            if ($atual !== null) {
                $this->escopoDePlataforma->executar(fn () => $this->convenios->remover($atual, flush: true));
            }

            return;
        }

        $valor = $input->valor ?? throw new ConvenioRecusado('Valor inválido.');
        $desde = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->desde);
        if ($desde === false || $desde->format('Y-m-d') !== $input->desde) {
            throw new ConvenioRecusado('Data inválida. Use AAAA-MM-DD.');
        }

        $agora = $this->relogio->now();
        if ($atual === null) {
            $atual = new ConvenioDaUf($uf, $input->modo, $valor, $desde, $input->motivo, $input->ator, $agora);
        } else {
            $atual->definir($input->modo, $valor, $desde, $input->motivo, $input->ator, $agora);
        }

        $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($atual, flush: true));
    }
}
