<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\SalvarNotaDaSeccionalInput;
use App\Master\Entity\NotaDaCidadeOab;
use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\NotaDaCidadeOabRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Grava (ou apaga) a nota explicativa de uma cidade na janela da OAB da cidade (spec
 * docs/specs/master-oab-estados-que-faltam.md, "NOTA EXPLICATIVA"; desenho 05, Perfis 1.2, VS 21.10.26, L896: chave
 * `c:<UF>:<cidade>`). A nota do estado continua no {@see SalvarNotaDaSeccionalUseCase}.
 *
 * Quem dispara: o Master, no "Salvar nota" da janela aberta por uma capital que falta (ROLE_SUPER_ADMIN no controller).
 * Objetivo: deixar para os outros Masters o que já se sabe daquela cidade.
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404), a linha da seccional existe e o pedido
 * traz a cidade.
 * Fluxo principal: carrega a seccional; dentro do escopo de plataforma e com a seccional travada
 * ({@see NotaDaCidadeOabRepository::gravarComTrava()}), lê a nota da cidade; com texto, cria ou reescreve com quem e
 * quando; sem texto, apaga a nota que houver.
 * Fluxos alternativos: linha ausente é 404; texto acima de 1000 ou cidade acima de 120 caracteres são recusados (422)
 * e nada muda; texto vazio sem nota gravada não faz nada; duas gravações ao mesmo tempo ficam em fila e a última vence.
 * Pós-condições: a nota (uma por seccional e cidade) vale para todos os Masters e a capital que falta mostra o ícone.
 * Auditoria: NotaDaCidadeOab é Auditavel; a linha do audit_log fica sem escritório (trilha do Master).
 */
final class SalvarNotaDaCidadeOabUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly NotaDaCidadeOabRepository $notas,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(SalvarNotaDaSeccionalInput $input): SeccionalOab
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada('Seccional não encontrada.');
        $cidade = $input->cidade ?? throw new SeccionalRecusada('Informe a cidade da nota.');
        $this->exigirTamanhos($cidade, $input->texto);

        $agora = $this->relogio->now();
        $this->escopoDePlataforma->executar(fn () => $this->notas->gravarComTrava(
            $seccional,
            $cidade,
            fn (?NotaDaCidadeOab $nota) => $this->aplicar($nota, $seccional, $cidade, $input, $agora),
        ));

        return $seccional;
    }

    private function exigirTamanhos(string $cidade, ?string $texto): void
    {
        if (mb_strlen($cidade) > NotaDaCidadeOab::CIDADE_MAXIMA) {
            throw new SeccionalRecusada(sprintf('A cidade passa de %d caracteres.', NotaDaCidadeOab::CIDADE_MAXIMA));
        }

        if (mb_strlen((string) $texto) > NotaDaSeccional::TEXTO_MAXIMO) {
            throw new SeccionalRecusada(sprintf('A nota passa de %d caracteres.', NotaDaSeccional::TEXTO_MAXIMO));
        }
    }

    /** A nota que vale é a lida DEPOIS da trava da seccional: se outra gravação a criou antes, ela é reescrita. */
    private function aplicar(?NotaDaCidadeOab $nota, SeccionalOab $seccional, string $cidade, SalvarNotaDaSeccionalInput $input, \DateTimeImmutable $agora): void
    {
        if ($input->texto === null) {
            if ($nota !== null) {
                $this->notas->remover($nota);
            }

            return;
        }

        if ($nota === null) {
            $this->notas->salvar(new NotaDaCidadeOab($seccional, $cidade, $input->texto, $input->ator, $agora));

            return;
        }

        $nota->reescrever($input->texto, $input->ator, $agora);
    }
}
