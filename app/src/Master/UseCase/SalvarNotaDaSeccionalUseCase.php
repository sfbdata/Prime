<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\SalvarNotaDaSeccionalInput;
use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Grava (ou apaga) a nota explicativa de uma seccional da OAB (spec docs/specs/master-oab-estados-que-faltam.md,
 * "NOTA EXPLICATIVA"; desenho 05, Perfis 1.2, VS 21.10.26, L491 e L493: "Salvar nota").
 *
 * Quem dispara: o Master, no "Salvar nota" da janela da OAB (ROLE_SUPER_ADMIN no controller).
 * Objetivo: deixar para os outros Masters o que já se sabe daquele estado (contato, próximo passo).
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404) e a linha da seccional existe.
 * Fluxo principal: carrega a seccional; dentro do escopo de plataforma e com a seccional travada
 * ({@see NotaDaSeccionalRepository::gravarComTrava()}), lê a nota que ela já tem; com texto, cria ou reescreve a nota
 * com quem e quando; sem texto, apaga a nota que houver.
 * Fluxos alternativos: linha ausente é 404; texto acima de 1000 caracteres é recusado com a mensagem (422) e nada
 * muda; texto vazio sem nota gravada não faz nada; duas gravações ao mesmo tempo na mesma UF ficam em fila na trava
 * e a última vence (sem 500 pela unicidade da nota por seccional).
 * Pós-condições: a nota (uma por UF) vale para todos os Masters e o item do estado que falta mostra o ícone de nota.
 * Auditoria: NotaDaSeccional é Auditavel; a linha do audit_log fica sem escritório (trilha do Master).
 */
final class SalvarNotaDaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly NotaDaSeccionalRepository $notas,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(SalvarNotaDaSeccionalInput $input): SeccionalOab
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada(SeccionalNaoEncontrada::RECUSA);
        if (mb_strlen((string) $input->texto) > NotaDaSeccional::TEXTO_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_NOTA_LONGA, ['limite' => NotaDaSeccional::TEXTO_MAXIMO]);
        }

        $agora = $this->relogio->now();
        $this->escopoDePlataforma->executar(fn () => $this->notas->gravarComTrava(
            $seccional,
            fn (?NotaDaSeccional $nota) => $this->aplicar($nota, $seccional, $input, $agora),
        ));

        return $seccional;
    }

    /** A nota que vale é a lida DEPOIS da trava da seccional: se outra gravação a criou antes, ela é reescrita. */
    private function aplicar(?NotaDaSeccional $nota, SeccionalOab $seccional, SalvarNotaDaSeccionalInput $input, \DateTimeImmutable $agora): void
    {
        if ($input->texto === null) {
            if ($nota !== null) {
                $this->notas->remover($nota);
            }

            return;
        }

        if ($nota === null) {
            $this->notas->salvar(new NotaDaSeccional($seccional, $input->texto, $input->ator, $agora));

            return;
        }

        $nota->reescrever($input->texto, $input->ator, $agora);
    }
}
