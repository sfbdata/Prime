<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlterarConvenioDaUfInput;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Service\AvisoDeConvenioDaUf;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Muda o convênio OAB de um estado (desenho 05 Perfis 1.2 VS 25.10.26): grátis, valor diferenciado ou sem convênio.
 *
 * Quem dispara: o Master, no painel "Convênio OAB por estado" da seção Contas (ROLE_SUPER_ADMIN no controller).
 * Pré-condições: UF conhecida; em `dif` e `sem`, valor de 0 a 999.999,99 e data AAAA-MM-DD.
 * Fluxo principal: `livre` apaga a linha do estado; `dif` e `sem` criam ou trocam a linha. Grava no escopo de
 * plataforma. Auditoria: ConvenioDaUf é Auditavel, cada criação, troca e remoção entra na trilha do Master.
 * Depois de gravar, enfileira um e-mail a cada primário ativo dos escritórios do estado ({@see AvisoDeConvenioDaUf}, P4c),
 * só se o convênio realmente mudou; passar do teto de destinatários recusa antes de gravar. Não muda cobrança (frente própria).
 * Fluxos alternativos: dado fora da regra recusa (422), sem gravar.
 */
final class AlterarConvenioDaUfUseCase
{
    public function __construct(
        private readonly ConvenioDaUfRepository $convenios,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly AvisoDeConvenioDaUf $aviso,
    ) {
    }

    /** @return int quantos avisos por e-mail entraram na fila (0 quando nada mudou) */
    public function executar(AlterarConvenioDaUfInput $input): int
    {
        $uf = ConvenioDaUf::exigirUf($input->uf);
        $atual = $this->convenios->buscar($uf);

        if ($input->modo === 'livre') {
            return $atual === null ? 0 : $this->tornarGratuito($uf, $atual);
        }

        $valor = $input->valor ?? throw new ConvenioRecusado('Valor inválido.');
        $desde = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->desde);
        if ($desde === false || $desde->format('Y-m-d') !== $input->desde) {
            throw new ConvenioRecusado('Data inválida. Use AAAA-MM-DD.');
        }

        $agora = $this->relogio->now();
        $novo = new ConvenioDaUf($uf, $input->modo, $valor, $desde, $input->motivo, $input->ator, $agora);
        if ($atual?->igualA($novo)) {
            return 0;
        }

        $destinatarios = $this->aviso->destinatarios($uf);
        if ($atual === null) {
            $atual = $novo;
        } else {
            $atual->definir($input->modo, $valor, $desde, $novo->getMotivo(), $input->ator, $agora);
        }
        $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($atual, flush: true));

        return $this->aviso->avisar($uf, $atual, $destinatarios);
    }

    private function tornarGratuito(string $uf, ConvenioDaUf $atual): int
    {
        $destinatarios = $this->aviso->destinatarios($uf);
        $this->escopoDePlataforma->executar(fn () => $this->convenios->remover($atual, flush: true));

        return $this->aviso->avisar($uf, null, $destinatarios);
    }
}
