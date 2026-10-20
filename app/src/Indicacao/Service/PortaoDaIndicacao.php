<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use Psr\Clock\ClockInterface;

/**
 * O que impede alguém de indicar (spec `indicar-bluejus.md`, comportamentos 2 e 3), com os textos do desenho
 * (bj-contas.js L105 e L108). Usado pela janela (para mostrar) e pelos envios (para barrar, dentro da trava).
 */
final class PortaoDaIndicacao
{
    public const NOME_COMPLETO = 'nome completo';
    public const TELEFONE = 'telefone';
    public const CPF = 'CPF';

    public const PAUSADO_PARA_TODOS = 'Os convites estão pausados pela administração do BlueJus no momento.';
    public const PAUSADO_PARA_VOCE = 'Seus envios de convite foram pausados pela administração do BlueJus.';

    public function __construct(
        private readonly IndicacaoConfigRepository $configs,
        private readonly IndicacaoPausaRepository $pausas,
        private readonly IndicacaoRepository $indicacoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * O que falta no cadastro para o convite poder levar o nome da pessoa: nome com 2 palavras ou mais, telefone e CPF
     * (o telefone se preenche no Meu Perfil do desenho 07).
     *
     * @return list<string>
     */
    public function pendenciasDoCadastro(User $user): array
    {
        $perfil = $user->getProfile();
        $falta = [];
        if (count(self::palavras((string) $user->getFullName())) < 2) {
            $falta[] = self::NOME_COMPLETO;
        }
        if ($perfil !== null && trim((string) $perfil->getTelefone()) === '') {
            $falta[] = self::TELEFONE;
        }
        if (trim((string) $perfil?->getCpf()) === '') {
            $falta[] = self::CPF;
        }

        return $falta;
    }

    /**
     * "Por segurança, o convite sempre leva o seu nome, então precisamos do seu {lista}." (bj-contas.js L117).
     *
     * @param list<string> $pendencias
     */
    public static function textoDasPendencias(array $pendencias): ?string
    {
        if ($pendencias === []) {
            return null;
        }

        return 'Por segurança, o convite sempre leva o seu nome, então precisamos do seu ' . implode(', ', $pendencias) . '.';
    }

    /**
     * A trava vigente, na ordem do desenho: pausa global, pausa da pessoa, limite de 24h, limite de 30 dias (janelas
     * móveis, os três canais contam). Nulo quando pode enviar.
     */
    public function motivoDaTrava(User $user): ?string
    {
        $config = $this->configs->obter();
        if ($config->isPausadoParaTodos()) {
            return self::PAUSADO_PARA_TODOS;
        }
        $userId = (int) $user->getId();
        if ($this->pausas->estaPausado($userId)) {
            return self::PAUSADO_PARA_VOCE;
        }
        $agora = $this->relogio->now();
        if ($this->indicacoes->contarDoUsuarioDesde($userId, $agora->modify('-24 hours')) >= $config->getLimiteDia()) {
            return sprintf('Você chegou ao limite de %d convites por dia. Tente novamente amanhã.', $config->getLimiteDia());
        }
        if ($this->indicacoes->contarDoUsuarioDesde($userId, $agora->modify('-30 days')) >= $config->getLimiteMes()) {
            return sprintf('Você chegou ao limite de %d convites por mês.', $config->getLimiteMes());
        }

        return null;
    }

    /** @return list<string> */
    private static function palavras(string $nome): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($nome)) ?: [], static fn (string $p): bool => $p !== ''));
    }
}
