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

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (idioma-ponto-php B2) */
    public const CHAVE_DO_TEXTO = [
        self::MENSAGEM_CHOQUE => 'ponto_servidor.batida.choque.choque',
        self::MENSAGEM_PADRAO => 'ponto_servidor.batida.choque.padrao',
        self::MENSAGEM_BATIDA => 'ponto_servidor.batida.choque.batida',
    ];

    public function __construct(
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /** A chave no catálogo de uma das constantes MENSAGEM_*; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $texto): string
    {
        return self::CHAVE_DO_TEXTO[$texto] ?? throw new \LogicException(sprintf('Texto do choque sem chave no catálogo: "%s".', $texto));
    }

    /**
     * O texto de uma das constantes MENSAGEM_*, com a chave para o controller traduzir.
     *
     * @param array<string, string> $parametros
     */
    public static function texto(string $texto, array $parametros = []): TextoDoPonto
    {
        return TextoDoPonto::de(self::chaveDe($texto), $texto, $parametros);
    }

    /**
     * A recusa para os trechos propostos da pessoa em `$escritorio`, em português, ou null quando não há choque.
     *
     * @param list<IntervaloDeTrabalho> $propostos
     */
    public function mensagem(User $user, Tenant $escritorio, array $propostos): ?string
    {
        return $this->textoDoChoque($user, $escritorio, $propostos)?->portugues;
    }

    /**
     * A mesma recusa de {@see self::mensagem()}, com a chave (`{inicio}` e `{fim}`) para quem a leva à tela traduzir.
     *
     * @param list<IntervaloDeTrabalho> $propostos
     */
    public function textoDoChoque(User $user, Tenant $escritorio, array $propostos): ?TextoDoPonto
    {
        $conflito = $this->primeiroConflito($user, $escritorio, $propostos);

        return $conflito === null ? null : self::texto(self::MENSAGEM_CHOQUE, self::horarioDo($conflito));
    }

    /** @return array{inicio: string, fim: string} */
    private static function horarioDo(IntervaloDeTrabalho $trecho): array
    {
        [$inicio, $fim] = $trecho->horario();

        return ['inicio' => $inicio, 'fim' => $fim];
    }

    /**
     * A recusa da batida em `$escritorio` quando `$agora` cai num trecho de trabalho de hoje de outro escritório onde a
     * pessoa registra ponto e NÃO cai num trecho de hoje deste escritório. Quando os dois cobrem o momento (um choque que
     * entrou sem conferência: vínculo novo com a chave nascida ligada, ou gravação concorrente), a batida passa: ninguém
     * fica travado (revisão de 23/10).
     */
    public function naBatida(User $user, Tenant $escritorio, \DateTimeImmutable $agora): ?string
    {
        return $this->textoNaBatida($user, $escritorio, $agora)?->portugues;
    }

    /** A mesma recusa de {@see self::naBatida()}, com a chave (`{inicio}` e `{fim}`) para o controller traduzir. */
    public function textoNaBatida(User $user, Tenant $escritorio, \DateTimeImmutable $agora): ?TextoDoPonto
    {
        $dia = (int) $agora->format('N');
        $segundos = (int) $agora->format('H') * 3600 + (int) $agora->format('i') * 60 + (int) $agora->format('s');
        $doOutro = $this->trechoDeOutroEscritorio($user, $escritorio, $dia, $segundos);
        if ($doOutro === null || $this->cobre($this->intervalos->daPessoaNoEscritorio($user, $escritorio), $dia, $segundos)) {
            return null;
        }

        return self::texto(self::MENSAGEM_BATIDA, self::horarioDo($doOutro));
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
        return array_map(
            static fn (array $afetada): array => ['nome' => $afetada['nome'], 'mensagem' => $afetada['texto']->portugues],
            $this->pessoasEmChoqueComPadraoComChave($escritorio, $padraoProposto),
        );
    }

    /**
     * As mesmas pessoas de {@see self::pessoasEmChoqueComPadrao()}, com o texto traduzível do choque de cada uma.
     *
     * @return list<array{nome: string, texto: TextoDoPonto}>
     */
    public function pessoasEmChoqueComPadraoComChave(Tenant $escritorio, JornadaTenant $padraoProposto): array
    {
        $afetadas = [];
        foreach ($this->vinculos->findAtivosQueRegistramPonto($escritorio) as $vinculo) {
            $pessoa = $vinculo->getUser();
            $trechos = $this->intervalos->daJornada($this->jornadas->findByUserETenant($pessoa, $escritorio), $padraoProposto);
            $texto = $this->textoDoChoque($pessoa, $escritorio, $trechos);
            if ($texto !== null) {
                $afetadas[] = ['nome' => (string) $pessoa->getFullName(), 'texto' => $texto];
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
