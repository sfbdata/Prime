<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Repository\UserTenantRepository;

/**
 * Sem choque de horário entre escritórios (spec `docs/specs/seguranca-b-jornada.md` regra 3 e §3.3, risco ALTO): os
 * trechos de trabalho da pessoa no escritório A, já com a mudança proposta, não podem bater, no mesmo dia da semana, com
 * os de outro escritório B onde ela registra ponto. Quem marcou primeiro fica com o horário: a recusa é sempre de quem
 * grava agora. A mensagem não diz o nome do outro escritório.
 *
 * "Outro escritório onde ela registra ponto" = vínculo ativo, escritório ativo e a chave "Registra ponto eletrônico"
 * ligada. A leitura da jornada de B é cross-tenant de propósito, com o escritório escrito na consulta.
 */
final class ChoqueDeJornadaEntreEscritorios
{
    /** A frase do dono (23/10). */
    public const MENSAGEM_CHOQUE = 'Esta pessoa já registra ponto das %s às %s em outro escritório.';

    /** O começo da recusa da jornada padrão, seguido de uma linha por pessoa afetada ("Nome: MENSAGEM_CHOQUE"). */
    public const MENSAGEM_PADRAO = 'A jornada padrão bate com o horário de outro escritório para estas pessoas deste escritório:';

    /** A recusa da batida dentro do horário de trabalho de hoje em outro escritório. */
    public const MENSAGEM_BATIDA = 'Agora é seu horário de trabalho em outro escritório (das %s às %s). O ponto não pode ser registrado aqui.';

    public function __construct(
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /**
     * A recusa para os trechos propostos da pessoa em `$escritorio`, ou null quando não há choque.
     *
     * @param list<IntervaloDeTrabalho> $propostos
     */
    public function mensagem(User $user, Tenant $escritorio, array $propostos): ?string
    {
        $conflito = $this->primeiroConflito($user, $escritorio, $propostos);

        return $conflito === null ? null : sprintf(self::MENSAGEM_CHOQUE, ...$conflito->horario());
    }

    /**
     * A recusa da batida em `$escritorio` quando `$agora` cai num trecho de trabalho de hoje de outro escritório onde a
     * pessoa registra ponto e NÃO cai num trecho de hoje deste escritório. Quando os dois cobrem o momento (um choque que
     * entrou sem conferência: vínculo novo com a chave nascida ligada, ou gravação concorrente), a batida passa: ninguém
     * fica travado (revisão de 23/10).
     */
    public function naBatida(User $user, Tenant $escritorio, \DateTimeImmutable $agora): ?string
    {
        $dia = (int) $agora->format('N');
        $segundos = (int) $agora->format('H') * 3600 + (int) $agora->format('i') * 60 + (int) $agora->format('s');
        $doOutro = $this->trechoDeOutroEscritorio($user, $escritorio, $dia, $segundos);
        if ($doOutro === null || $this->cobre($this->intervalos->daPessoaNoEscritorio($user, $escritorio), $dia, $segundos)) {
            return null;
        }

        return sprintf(self::MENSAGEM_BATIDA, ...$doOutro->horario());
    }

    private function trechoDeOutroEscritorio(User $user, Tenant $escritorio, int $dia, int $segundos): ?IntervaloDeTrabalho
    {
        foreach ($this->outrosEscritoriosComPonto($user, $escritorio) as $outro) {
            foreach ($this->intervalos->daPessoaNoEscritorio($user, $outro) as $trecho) {
                if ($trecho->contem($dia, $segundos)) {
                    return $trecho;
                }
            }
        }

        return null;
    }

    /** @param list<IntervaloDeTrabalho> $trechos */
    private function cobre(array $trechos, int $dia, int $segundos): bool
    {
        foreach ($trechos as $trecho) {
            if ($trecho->contem($dia, $segundos)) {
                return true;
            }
        }

        return false;
    }

    /**
     * As pessoas do escritório com o ponto ligado para quem a jornada padrão proposta bate com outro escritório. Quem
     * tem jornada pessoal em blocos não usa a padrão e por isso não aparece.
     *
     * @return list<array{nome: string, mensagem: string}>
     */
    public function pessoasEmChoqueComPadrao(Tenant $escritorio, JornadaTenant $padraoProposto): array
    {
        $afetadas = [];
        foreach ($this->vinculos->findAtivosQueRegistramPonto($escritorio) as $vinculo) {
            $pessoa = $vinculo->getUser();
            $trechos = $this->intervalos->daJornada($this->jornadas->findByUserETenant($pessoa, $escritorio), $padraoProposto);
            $mensagem = $this->mensagem($pessoa, $escritorio, $trechos);
            if ($mensagem !== null) {
                $afetadas[] = ['nome' => (string) $pessoa->getFullName(), 'mensagem' => $mensagem];
            }
        }

        return $afetadas;
    }

    /** @param list<IntervaloDeTrabalho> $propostos */
    private function primeiroConflito(User $user, Tenant $escritorio, array $propostos): ?IntervaloDeTrabalho
    {
        if ($propostos === []) {
            return null;
        }
        foreach ($this->outrosEscritoriosComPonto($user, $escritorio) as $outro) {
            foreach ($this->intervalos->daPessoaNoEscritorio($user, $outro) as $trecho) {
                foreach ($propostos as $proposto) {
                    if ($proposto->sobrepoe($trecho)) {
                        return $trecho;
                    }
                }
            }
        }

        return null;
    }

    /** @return list<Tenant> os outros escritórios ativos com vínculo ativo e a chave do ponto ligada */
    private function outrosEscritoriosComPonto(User $user, Tenant $escritorio): array
    {
        $outros = [];
        foreach ($this->vinculos->findActiveByUser($user) as $vinculo) {
            if ($vinculo->getTenant()->getId() !== $escritorio->getId() && $vinculo->isRegistraPonto()) {
                $outros[] = $vinculo->getTenant();
            }
        }

        return $outros;
    }
}
